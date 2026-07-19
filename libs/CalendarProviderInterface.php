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
    public function getEvents(string $calendarUrl, DateTimeImmutable $start, DateTimeImmutable $end): array;

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function createEvent(string $calendarUrl, array $event): array;

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function updateEvent(string $calendarUrl, string $resourceUrl, string $etag, string $uid, array $event): array;

    public function deleteEvent(string $calendarUrl, string $resourceUrl, string $etag, string $recurrenceId = ''): bool;
}
