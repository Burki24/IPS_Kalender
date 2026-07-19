<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;

interface CalendarProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function testConnection(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getCalendars(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getEvents(string $calendarReference, DateTimeImmutable $start, DateTimeImmutable $end): array;

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function createEvent(string $calendarReference, array $event): array;

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function updateEvent(
        string $calendarReference,
        string $eventReference,
        string $etag,
        string $uid,
        array $event
    ): array;

    public function deleteEvent(
        string $calendarReference,
        string $eventReference,
        string $etag,
        string $recurrenceId = ''
    ): bool;
}
