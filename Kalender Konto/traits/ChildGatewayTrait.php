<?php

declare(strict_types=1);

trait KalenderKontoChildGatewayTrait
{
    public function ForwardData(string $JSONString): string
    {
        try {
            $request = json_decode($JSONString, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($request)) {
                throw new InvalidArgumentException('The request must be a JSON object.');
            }

            $operation = (string) ($request['Operation'] ?? '');
            $requestID = (string) ($request['RequestID'] ?? '');

            $payload = match ($operation) {
                'GetCalendars'      => json_decode($this->GetCalendars(), true, 512, JSON_THROW_ON_ERROR),
                'DiscoverCalendars' => $this->discoverCalendars(),
                'GetEvents'         => $this->getEventsForChild($request),
                'CreateEvent'       => $this->createEventForChild($request),
                'UpdateEvent'       => $this->updateEventForChild($request),
                'DeleteEvent'       => ['success' => $this->deleteEventForChild($request)],
                'Synchronize'       => ['success' => $this->Synchronize()],
                'TestConnection'    => json_decode($this->TestConnection(), true, 512, JSON_THROW_ON_ERROR),
                default             => throw new InvalidArgumentException('Unsupported operation: ' . $operation)
            };

            return $this->encodeResponse(true, $operation, $requestID, $payload);
        } catch (Throwable $exception) {
            $this->SendDebug('ForwardData', $this->sanitizeError($exception->getMessage()), 0);

            return $this->encodeResponse(
                false,
                isset($operation) ? $operation : '',
                isset($requestID) ? $requestID : '',
                null,
                $exception instanceof JsonException
                    ? $this->Translate('Invalid JSON data.')
                    : $this->translateErrorMessage($exception->getMessage())
            );
        }
    }

    /**
     * @param array<string, mixed> $request
     * @return list<array<string, mixed>>
     */
    private function getEventsForChild(array $request): array
    {
        $calendar = $this->resolveCalendar((string) ($request['CalendarID'] ?? ''));
        $startTimestamp = (int) ($request['Start'] ?? 0);
        $endTimestamp = (int) ($request['End'] ?? 0);
        if ($startTimestamp <= 0 || $endTimestamp <= $startTimestamp) {
            throw new InvalidArgumentException('The requested event time range is invalid.');
        }
        if (($endTimestamp - $startTimestamp) > 6 * 366 * 86400) {
            throw new InvalidArgumentException('The requested event time range is too large.');
        }

        return $this->createProvider()->getEvents(
            $this->calendarReference($calendar),
            new DateTimeImmutable('@' . $startTimestamp),
            new DateTimeImmutable('@' . $endTimestamp)
        );
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function createEventForChild(array $request): array
    {
        $calendar = $this->resolveCalendar((string) ($request['CalendarID'] ?? ''));
        $event = $request['Event'] ?? null;
        if (!is_array($event)) {
            throw new InvalidArgumentException('The event data is invalid.');
        }

        return $this->createProvider()->createEvent($this->calendarReference($calendar), $event);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function updateEventForChild(array $request): array
    {
        $calendar = $this->resolveCalendar((string) ($request['CalendarID'] ?? ''));
        $event = $request['Event'] ?? null;
        if (!is_array($event)) {
            throw new InvalidArgumentException('The event data is invalid.');
        }

        return $this->createProvider()->updateEvent(
            $this->calendarReference($calendar),
            trim((string) ($request['ResourceURL'] ?? '')),
            trim((string) ($request['ETag'] ?? '')),
            trim((string) ($request['UID'] ?? '')),
            $event
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private function deleteEventForChild(array $request): bool
    {
        $calendar = $this->resolveCalendar((string) ($request['CalendarID'] ?? ''));

        return $this->createProvider()->deleteEvent(
            $this->calendarReference($calendar),
            trim((string) ($request['ResourceURL'] ?? '')),
            trim((string) ($request['ETag'] ?? '')),
            trim((string) ($request['RecurrenceID'] ?? ''))
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveCalendar(string $calendarId): array
    {
        $calendars = json_decode($this->ReadAttributeString('CachedCalendars'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($calendars)) {
            $calendars = [];
        }
        if ($calendarId !== '') {
            foreach ($calendars as $calendar) {
                if (is_array($calendar) && (string) ($calendar['id'] ?? '') === $calendarId
                    && $this->calendarReference($calendar) !== '') {
                    return $calendar;
                }
            }
        }
        $fallback = $this->singleCalendarFallback($calendars);
        if ($fallback !== null) {
            return $fallback;
        }

        $calendars = $this->discoverCalendars();
        if ($calendarId !== '') {
            foreach ($calendars as $calendar) {
                if ((string) ($calendar['id'] ?? '') === $calendarId
                    && $this->calendarReference($calendar) !== '') {
                    return $calendar;
                }
            }
        }
        $fallback = $this->singleCalendarFallback($calendars);
        if ($fallback !== null) {
            return $fallback;
        }

        if ($calendarId === '') {
            throw new InvalidArgumentException('The calendar ID is missing.');
        }

        throw new RuntimeException('The selected calendar is no longer available in this account.');
    }

    /**
     * A single-feed ICS/Webcal account always exposes exactly one calendar.
     * Keep an existing child usable when its gateway or the feed URL changes
     * and its URL-derived calendar ID is missing or no longer matches.
     *
     * @param array<mixed> $calendars
     * @return array<string, mixed>|null
     */
    private function singleCalendarFallback(array $calendars): ?array
    {
        if ($this->ReadPropertyInteger('Provider') !== self::PROVIDER_ICS) {
            return null;
        }

        $available = array_values(array_filter(
            $calendars,
            fn(mixed $calendar): bool => is_array($calendar) && $this->calendarReference($calendar) !== ''
        ));
        if (count($available) !== 1) {
            return null;
        }

        $this->SendDebug(
            'CalendarResolution',
            'Using the only calendar exposed by the ICS/Webcal account because the stored calendar ID is missing or no longer matches.',
            0
        );

        return $available[0];
    }

    /**
     * @param array<string, mixed> $calendar
     */
    private function calendarReference(array $calendar): string
    {
        return trim((string) ($calendar['reference'] ?? $calendar['url'] ?? ''));
    }

    private function encodeResponse(
        bool $success,
        string $operation,
        string $requestID,
        mixed $payload = null,
        string $error = ''
    ): string {
        return json_encode(
            [
                'Success'   => $success,
                'Operation' => $operation,
                'RequestID' => $requestID,
                'Payload'   => $payload,
                'Error'     => $error
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
