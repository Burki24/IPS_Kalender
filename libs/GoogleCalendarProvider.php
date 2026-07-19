<?php

declare(strict_types=1);

namespace IPSKalender;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/CalendarProviderInterface.php';
require_once __DIR__ . '/CalendarHttpClient.php';

final class GoogleCalendarProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly string $reason = ''
    ) {
        parent::__construct($message);
    }
}

final class GoogleCalendarProvider implements CalendarProviderInterface
{
    private const API_URL = 'https://www.googleapis.com/calendar/v3';

    public function __construct(
        private readonly CalendarHttpClientInterface $httpClient,
        private readonly string $accessToken
    ) {
        if (trim($accessToken) === '') {
            throw new GoogleCalendarProviderException('Google Calendar is not connected yet.', 401);
        }
    }

    public function testConnection(): array
    {
        $calendars = $this->getCalendars();

        return [
            'success'       => true,
            'calendarCount' => count($calendars),
            'message'       => 'Connection successful.'
        ];
    }

    public function getCalendars(): array
    {
        $calendars = [];
        $pageToken = '';

        do {
            $query = ['maxResults' => '250'];
            if ($pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }
            $data = $this->requestJson('GET', '/users/me/calendarList?' . http_build_query($query));

            foreach (($data['items'] ?? []) as $item) {
                if (!is_array($item) || (bool) ($item['deleted'] ?? false)) {
                    continue;
                }
                $providerId = trim((string) ($item['id'] ?? ''));
                $accessRole = trim((string) ($item['accessRole'] ?? ''));
                if ($providerId === '' || $accessRole === 'freeBusyReader') {
                    continue;
                }
                $canRead = in_array($accessRole, ['reader', 'writer', 'owner'], true);
                $canWrite = in_array($accessRole, ['writer', 'owner'], true);
                if (!$canRead) {
                    continue;
                }

                $name = trim((string) ($item['summaryOverride'] ?? ''));
                if ($name === '') {
                    $name = trim((string) ($item['summary'] ?? $providerId));
                }
                $reference = $this->calendarUrl($providerId);
                $calendars[] = [
                    'id'           => hash('sha256', 'google|' . $providerId),
                    'providerId'   => $providerId,
                    'reference'    => $providerId,
                    'url'          => $reference,
                    'name'         => $name,
                    'description'  => trim((string) ($item['description'] ?? '')),
                    'color'        => $this->normalizeColor((string) ($item['backgroundColor'] ?? '')),
                    'etag'         => trim((string) ($item['etag'] ?? '')),
                    'syncToken'    => '',
                    'timezone'     => trim((string) ($item['timeZone'] ?? '')),
                    'primary'      => (bool) ($item['primary'] ?? false),
                    'accessRole'   => $accessRole,
                    'components'   => ['VEVENT'],
                    'capabilities' => [
                        'read'   => true,
                        'create' => $canWrite,
                        'update' => $canWrite,
                        'delete' => $canWrite
                    ]
                ];
            }

            $pageToken = trim((string) ($data['nextPageToken'] ?? ''));
        } while ($pageToken !== '');

        usort($calendars, static function (array $left, array $right): int {
            return ((int) ($right['primary'] ?? false) <=> (int) ($left['primary'] ?? false))
                ?: strcasecmp((string) $left['name'], (string) $right['name']);
        });

        return $calendars;
    }

    public function getEvents(string $calendarReference, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if ($end <= $start) {
            throw new GoogleCalendarProviderException('The event query end must be later than the start.');
        }

        $calendarId = $this->calendarId($calendarReference);
        $events = [];
        $pageToken = '';
        do {
            $query = [
                'timeMin'      => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'timeMax'      => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'singleEvents' => 'true',
                'orderBy'      => 'startTime',
                'showDeleted'  => 'false',
                'maxResults'   => '2500'
            ];
            if ($pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }
            $data = $this->requestJson(
                'GET',
                '/calendars/' . rawurlencode($calendarId) . '/events?' . http_build_query($query)
            );
            foreach (($data['items'] ?? []) as $item) {
                if (!is_array($item) || ($item['status'] ?? '') === 'cancelled') {
                    continue;
                }
                $mapped = $this->mapEvent($calendarId, $item, (string) ($data['timeZone'] ?? ''));
                if ($mapped !== null) {
                    $events[] = $mapped;
                }
            }
            $pageToken = trim((string) ($data['nextPageToken'] ?? ''));
        } while ($pageToken !== '');

        usort(
            $events,
            static fn(array $left, array $right): int => ($left['startTimestamp'] <=> $right['startTimestamp'])
                ?: strcasecmp((string) $left['summary'], (string) $right['summary'])
        );

        return $events;
    }

    public function createEvent(string $calendarReference, array $event): array
    {
        $calendarId = $this->calendarId($calendarReference);
        $payload = $this->buildEventPayload($event, true);
        $created = $this->requestJson(
            'POST',
            '/calendars/' . rawurlencode($calendarId) . '/events',
            $payload,
            [],
            [200]
        );

        return $this->writeResult($calendarId, $created);
    }

    public function updateEvent(
        string $calendarReference,
        string $eventReference,
        string $etag,
        string $uid,
        array $event
    ): array {
        $calendarId = $this->calendarId($calendarReference);
        $eventId = $this->eventId($eventReference);
        $headers = $etag !== '' ? ['If-Match' => $etag] : [];
        $updated = $this->requestJson(
            'PATCH',
            '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId),
            $this->buildEventPayload($event, false),
            $headers,
            [200]
        );

        return $this->writeResult($calendarId, $updated);
    }

    public function deleteEvent(
        string $calendarReference,
        string $eventReference,
        string $etag,
        string $recurrenceId = ''
    ): bool {
        $calendarId = $this->calendarId($calendarReference);
        $eventId = $this->eventId($eventReference);
        $headers = $etag !== '' ? ['If-Match' => $etag] : [];
        $this->requestJson(
            'DELETE',
            '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId),
            null,
            $headers,
            [204]
        );

        return true;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function mapEvent(string $calendarId, array $item, string $calendarTimezone): ?array
    {
        $eventId = trim((string) ($item['id'] ?? ''));
        $startData = is_array($item['start'] ?? null) ? $item['start'] : [];
        $endData = is_array($item['end'] ?? null) ? $item['end'] : [];
        if ($eventId === '' || ($startData === [])) {
            return null;
        }

        $allDay = isset($startData['date']);
        $start = $this->parseEventDate($startData, $calendarTimezone, $allDay);
        if ($endData === []) {
            $end = $allDay ? $start->add(new DateInterval('P1D')) : $start;
        } else {
            $end = $this->parseEventDate($endData, $calendarTimezone, $allDay);
        }
        $timezone = trim((string) ($startData['timeZone'] ?? $calendarTimezone));
        if ($timezone === '') {
            $timezone = $start->getTimezone()->getName();
        }

        $recurrence = is_array($item['recurrence'] ?? null) ? $item['recurrence'] : [];
        $recurrenceRule = '';
        foreach ($recurrence as $rule) {
            if (is_string($rule) && str_starts_with(strtoupper($rule), 'RRULE:')) {
                $recurrenceRule = substr($rule, 6);
                break;
            }
        }
        $recurringEventId = trim((string) ($item['recurringEventId'] ?? ''));
        $resourceUrl = $this->eventUrl($calendarId, $eventId);

        return [
            'id'             => hash('sha256', 'google|' . $calendarId . '|' . $eventId),
            'uid'            => trim((string) ($item['iCalUID'] ?? $eventId)),
            'eventReference' => $eventId,
            'resourceUrl'    => $resourceUrl,
            'etag'           => trim((string) ($item['etag'] ?? '')),
            'summary'        => trim((string) ($item['summary'] ?? '')),
            'description'    => (string) ($item['description'] ?? ''),
            'location'       => trim((string) ($item['location'] ?? '')),
            'start'          => $allDay ? $start->format('Y-m-d') : $start->format(DATE_ATOM),
            'end'            => $allDay ? $end->format('Y-m-d') : $end->format(DATE_ATOM),
            'startTimestamp' => $start->getTimestamp(),
            'endTimestamp'   => $end->getTimestamp(),
            'allDay'         => $allDay,
            'timezone'       => $timezone,
            'status'         => strtoupper(trim((string) ($item['status'] ?? ''))),
            'recurrenceRule' => $recurrenceRule,
            'recurrenceId'   => $recurringEventId,
            'recurring'      => $recurrence !== [] || $recurringEventId !== '',
            'sequence'       => (int) ($item['sequence'] ?? 0),
            'created'        => trim((string) ($item['created'] ?? '')),
            'lastModified'   => trim((string) ($item['updated'] ?? '')),
            'url'            => trim((string) ($item['htmlLink'] ?? ''))
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildEventPayload(array $data, bool $creating): array
    {
        $payload = [];
        if ($creating || array_key_exists('summary', $data)) {
            $summary = trim((string) ($data['summary'] ?? ''));
            if ($summary === '') {
                throw new InvalidArgumentException('The event summary is missing.');
            }
            $payload['summary'] = $summary;
        }
        foreach (['description', 'location'] as $property) {
            if (array_key_exists($property, $data)) {
                $payload[$property] = (string) $data[$property];
            }
        }
        if (array_key_exists('status', $data)) {
            $status = strtolower(trim((string) $data['status']));
            if (!in_array($status, ['confirmed', 'tentative', 'cancelled'], true)) {
                throw new InvalidArgumentException('The event status is invalid.');
            }
            $payload['status'] = $status;
        }

        $hasStart = array_key_exists('start', $data);
        $hasEnd = array_key_exists('end', $data);
        if ($creating && !$hasStart) {
            throw new InvalidArgumentException('The event start is missing.');
        }
        if ($hasStart || $hasEnd) {
            if (!$hasStart || !$hasEnd) {
                throw new InvalidArgumentException('The event start and end must be changed together.');
            }
            $allDay = (bool) ($data['allDay'] ?? false);
            $start = $this->inputDate($data['start'], $allDay);
            $end = $this->inputDate($data['end'], $allDay);
            if ($end <= $start) {
                throw new InvalidArgumentException('The event end must be later than the start.');
            }
            if ($allDay) {
                $payload['start'] = ['date' => $start->format('Y-m-d')];
                $payload['end'] = ['date' => $end->format('Y-m-d')];
            } else {
                $payload['start'] = ['dateTime' => $start->format(DATE_RFC3339)];
                $payload['end'] = ['dateTime' => $end->format(DATE_RFC3339)];
            }
        } elseif (array_key_exists('allDay', $data)) {
            throw new InvalidArgumentException('Changing all-day mode requires a start and end.');
        }

        if ($payload === []) {
            throw new InvalidArgumentException('No event changes were supplied.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @param list<int> $expectedStatusCodes
     * @return array<string, mixed>
     */
    private function requestJson(
        string $method,
        string $path,
        ?array $body = null,
        array $headers = [],
        array $expectedStatusCodes = [200]
    ): array {
        $headers['Accept'] = 'application/json';
        $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        $encodedBody = '';
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json; charset=utf-8';
            $encodedBody = json_encode(
                $body,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }

        $response = $this->httpClient->request($method, self::API_URL . $path, $headers, $encodedBody);
        if (!in_array($response->statusCode, $expectedStatusCodes, true)) {
            $this->throwApiError($response);
        }
        if ($response->body === '') {
            return [];
        }

        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new GoogleCalendarProviderException('Google Calendar returned invalid JSON.', $response->statusCode);
        }
        if (!is_array($data)) {
            throw new GoogleCalendarProviderException('Google Calendar returned invalid data.', $response->statusCode);
        }

        return $data;
    }

    private function throwApiError(CalendarHttpResponse $response): never
    {
        $data = json_decode($response->body, true);
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        $details = is_array($error['errors'] ?? null) ? $error['errors'] : [];
        $reason = is_array($details[0] ?? null) ? trim((string) ($details[0]['reason'] ?? '')) : '';
        $message = trim((string) ($error['message'] ?? ''));

        if ($response->statusCode === 401) {
            $message = 'Google authorization expired. Connect the account again.';
        } elseif ($response->statusCode === 412) {
            $message = 'The event was changed by another client. Synchronize the calendar and try again.';
        } elseif ($message === '') {
            $message = sprintf('Google Calendar request failed with HTTP %d.', $response->statusCode);
        }

        throw new GoogleCalendarProviderException($message, $response->statusCode, $reason);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function parseEventDate(array $value, string $fallbackTimezone, bool $allDay): DateTimeImmutable
    {
        try {
            if ($allDay) {
                $timezone = $this->timezone((string) ($value['timeZone'] ?? $fallbackTimezone));
                $rawDate = trim((string) ($value['date'] ?? ''));
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate, $timezone);
                if ($date === false || $date->format('Y-m-d') !== $rawDate) {
                    throw new RuntimeException();
                }
                return $date;
            }

            $rawDateTime = trim((string) ($value['dateTime'] ?? ''));
            if ($rawDateTime === '') {
                throw new RuntimeException();
            }
            return new DateTimeImmutable(
                $rawDateTime,
                $this->timezone((string) ($value['timeZone'] ?? $fallbackTimezone))
            );
        } catch (Throwable) {
            throw new GoogleCalendarProviderException('Google Calendar returned an invalid event date.');
        }
    }

    private function inputDate(mixed $value, bool $allDay): DateTimeImmutable
    {
        try {
            $rawValue = trim((string) $value);
            if ($rawValue === '') {
                throw new RuntimeException();
            }
            if ($allDay) {
                $rawDate = substr($rawValue, 0, 10);
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate);
                if ($date === false || $date->format('Y-m-d') !== $rawDate) {
                    throw new RuntimeException();
                }
                return $date;
            }
            return new DateTimeImmutable($rawValue);
        } catch (Throwable) {
            throw new InvalidArgumentException('The event contains an invalid date.');
        }
    }

    private function timezone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name !== '' ? $name : date_default_timezone_get());
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, string>
     */
    private function writeResult(string $calendarId, array $event): array
    {
        $eventId = trim((string) ($event['id'] ?? ''));
        if ($eventId === '') {
            throw new GoogleCalendarProviderException('Google Calendar did not return an event ID.');
        }

        return [
            'uid'            => trim((string) ($event['iCalUID'] ?? $eventId)),
            'eventReference' => $eventId,
            'resourceUrl'    => $this->eventUrl($calendarId, $eventId),
            'etag'           => trim((string) ($event['etag'] ?? ''))
        ];
    }

    private function calendarId(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new GoogleCalendarProviderException('The Google calendar ID is missing.');
        }
        $path = (string) (parse_url($reference, PHP_URL_PATH) ?? '');
        if (preg_match('~/calendars/([^/]+)$~', $path, $matches) === 1) {
            return rawurldecode($matches[1]);
        }
        return $reference;
    }

    private function eventId(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new GoogleCalendarProviderException('The Google event ID is missing.');
        }
        $path = (string) (parse_url($reference, PHP_URL_PATH) ?? '');
        if (preg_match('~/events/([^/]+)$~', $path, $matches) === 1) {
            return rawurldecode($matches[1]);
        }
        return $reference;
    }

    private function calendarUrl(string $calendarId): string
    {
        return self::API_URL . '/calendars/' . rawurlencode($calendarId);
    }

    private function eventUrl(string $calendarId, string $eventId): string
    {
        return $this->calendarUrl($calendarId) . '/events/' . rawurlencode($eventId);
    }

    private function normalizeColor(string $color): string
    {
        $color = strtoupper(trim($color));
        return preg_match('/^#[0-9A-F]{6}$/', $color) === 1 ? $color : '';
    }
}
