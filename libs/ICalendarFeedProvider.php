<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

require_once __DIR__ . '/CalendarProviderInterface.php';
require_once __DIR__ . '/CalendarHttpClient.php';
require_once __DIR__ . '/ICalendarCodec.php';

final class ICalendarFeedProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}

final class ICalendarFeedProvider implements CalendarProviderInterface
{
    private const MAX_FEED_SIZE = 16 * 1024 * 1024;

    private string $feedUrl;

    public function __construct(
        private readonly CalendarHttpClientInterface $httpClient,
        string $feedUrl,
        private readonly string $configuredName = ''
    ) {
        $this->feedUrl = $this->normalizeUrl($feedUrl);
    }

    public function testConnection(): array
    {
        $feed = $this->fetchFeed();

        return [
            'success'       => true,
            'calendarCount' => 1,
            'eventCount'    => count(ICalendarCodec::parseEvents(
                $feed['body'],
                $this->eventResourceReference(),
                $feed['etag']
            )),
            'message'       => 'Connection successful.'
        ];
    }

    public function getCalendars(): array
    {
        $feed = $this->fetchFeed();
        $calendarId = hash('sha256', 'ics|' . $this->feedUrl);
        $name = trim($this->configuredName);
        if ($name === '') {
            $name = $this->calendarProperty($feed['body'], 'X-WR-CALNAME');
        }
        if ($name === '') {
            $name = 'iCalendar';
        }

        return [[
            'id'           => $calendarId,
            'providerId'   => $calendarId,
            'reference'    => $this->feedUrl,
            'url'          => '',
            'name'         => $name,
            'description'  => $this->calendarProperty($feed['body'], 'X-WR-CALDESC'),
            'color'        => $this->normalizeColor($this->calendarProperty($feed['body'], 'X-APPLE-CALENDAR-COLOR')),
            'etag'         => $feed['etag'],
            'components'   => ['VEVENT'],
            'capabilities' => [
                'read'   => true,
                'create' => false,
                'update' => false,
                'delete' => false
            ]
        ]];
    }

    public function getEvents(string $calendarReference, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if ($end <= $start) {
            throw new ICalendarFeedProviderException('The event query end must be later than the start.');
        }
        if ($this->normalizeUrl($calendarReference) !== $this->feedUrl) {
            throw new ICalendarFeedProviderException('The requested calendar does not belong to this feed.');
        }

        $feed = $this->fetchFeed();
        $events = ICalendarCodec::parseEventsInRange(
            $feed['body'],
            $this->eventResourceReference(),
            $feed['etag'],
            $start,
            $end
        );

        usort(
            $events,
            static fn(array $left, array $right): int => ($left['startTimestamp'] <=> $right['startTimestamp'])
                ?: strcasecmp((string) $left['summary'], (string) $right['summary'])
        );

        return $events;
    }

    public function createEvent(string $calendarReference, array $event): array
    {
        throw new ICalendarFeedProviderException('iCalendar subscriptions are read-only.');
    }

    public function updateEvent(
        string $calendarReference,
        string $eventReference,
        string $etag,
        string $uid,
        array $event
    ): array {
        throw new ICalendarFeedProviderException('iCalendar subscriptions are read-only.');
    }

    public function deleteEvent(
        string $calendarReference,
        string $eventReference,
        string $etag,
        string $recurrenceId = ''
    ): bool {
        throw new ICalendarFeedProviderException('iCalendar subscriptions are read-only.');
    }

    /**
     * @return array{body: string, etag: string}
     */
    private function fetchFeed(): array
    {
        $response = $this->httpClient->request(
            'GET',
            $this->feedUrl,
            ['Accept' => 'text/calendar, */*;q=0.1']
        );

        if (in_array($response->statusCode, [401, 403], true)) {
            throw new ICalendarFeedProviderException('Authentication failed or calendar access was denied.', $response->statusCode);
        }
        if ($response->statusCode !== 200) {
            throw new ICalendarFeedProviderException(
                sprintf('The calendar feed returned HTTP status %d.', $response->statusCode),
                $response->statusCode
            );
        }
        if (strlen($response->body) > self::MAX_FEED_SIZE) {
            throw new ICalendarFeedProviderException('The calendar feed is too large.');
        }
        if (preg_match('/(?:^|\R)BEGIN:VCALENDAR(?:\R|$)/i', $response->body) !== 1
            || preg_match('/(?:^|\R)END:VCALENDAR(?:\R|$)/i', $response->body) !== 1) {
            throw new ICalendarFeedProviderException('The server response is not a valid iCalendar feed.');
        }

        return [
            'body' => $response->body,
            'etag' => trim((string) ($response->headers['etag'] ?? ''))
        ];
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (str_starts_with(strtolower($url), 'webcal://')) {
            $url = 'https://' . substr($url, 9);
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException('The iCalendar URL is invalid.');
        }

        return $url;
    }

    private function calendarProperty(string $ical, string $property): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $ical);
        $unfolded = preg_replace("/\n[ \t]/", '', $normalized);
        if (!is_string($unfolded)
            || preg_match('/(?:^|\n)' . preg_quote($property, '/') . '(?:;[^:]*)?:(.*)$/mi', $unfolded, $matches) !== 1) {
            return '';
        }

        return trim(str_replace(['\\n', '\\N', '\\,', '\\;'], ["\n", "\n", ',', ';'], $matches[1]));
    }

    private function normalizeColor(string $color): string
    {
        $color = trim($color);
        if (preg_match('/^#[0-9a-f]{6}(?:[0-9a-f]{2})?$/i', $color) !== 1) {
            return '';
        }

        return strtoupper(substr($color, 0, 7));
    }

    private function eventResourceReference(): string
    {
        return 'urn:ips-kalender:ics:' . hash('sha256', $this->feedUrl);
    }
}
