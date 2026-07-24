<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;

interface CalendarProviderInterface
{
    /**
     * Verifies that the provider can be reached and authenticated with the current configuration.
     *
     * @return array<string, mixed> Provider-specific connection test details.
     */
    public function testConnection(): array;

    /**
     * Returns all calendars exposed by the provider.
     *
     * @return list<array<string, mixed>> Normalized calendar metadata.
     */
    public function getCalendars(): array;

    /**
     * Returns normalized events from a calendar within the requested time range.
     *
     * @param string $calendarReference Provider-specific calendar identifier or URL.
     * @return list<array<string, mixed>> Normalized calendar events.
     */
    public function getEvents(string $calendarReference, DateTimeImmutable $start, DateTimeImmutable $end): array;

    /**
     * Creates an event in the referenced calendar.
     *
     * @param string               $calendarReference Provider-specific calendar identifier or URL.
     * @param array<string, mixed> $event             Normalized event data.
     * @return array<string, mixed> Normalized created event data.
     */
    public function createEvent(string $calendarReference, array $event): array;

    /**
     * Updates an existing event in the referenced calendar.
     *
     * @param string               $calendarReference Provider-specific calendar identifier or URL.
     * @param string               $eventReference    Provider-specific event identifier or resource URL.
     * @param string               $etag              Current entity tag used for optimistic concurrency.
     * @param string               $uid               Calendar event UID.
     * @param array<string, mixed> $event             Normalized event changes.
     * @return array<string, mixed> Normalized updated event data.
     */
    public function updateEvent(
        string $calendarReference,
        string $eventReference,
        string $etag,
        string $uid,
        array $event
    ): array;

    /**
     * Deletes an event from the referenced calendar.
     *
     * @param string $calendarReference Provider-specific calendar identifier or URL.
     * @param string $eventReference    Provider-specific event identifier or resource URL.
     * @param string $etag              Current entity tag used for optimistic concurrency.
     * @param string $recurrenceId      Optional recurrence instance identifier.
     * @return bool True when the event was deleted successfully.
     */
    public function deleteEvent(
        string $calendarReference,
        string $eventReference,
        string $etag,
        string $recurrenceId = ''
    ): bool;
}
