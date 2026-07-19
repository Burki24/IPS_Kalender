<?php

declare(strict_types=1);

namespace IPSKalender;

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
}
