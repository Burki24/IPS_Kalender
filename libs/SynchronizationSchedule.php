<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;

final class SynchronizationSchedule
{
    public const CUSTOM = 0;
    public const EVERY_5_MINUTES = 1;
    public const EVERY_15_MINUTES = 2;
    public const EVERY_30_MINUTES = 3;
    public const HOURLY = 4;
    public const EVERY_6_HOURS = 5;
    public const EVERY_12_HOURS = 6;
    public const DAILY = 7;
    public const WEEKLY = 8;
    public const MONTHLY = 9;
    public const YEARLY = 10;
    public const MANUAL = 11;

    private const DAILY_TIMER_INTERVAL = 86_400_000;
    private const MAX_DIRECT_INTERVAL_MINUTES = 10_080;
    private const MAX_CUSTOM_INTERVAL_MINUTES = 525_600;

    public static function isValid(int $schedule): bool
    {
        return $schedule >= self::CUSTOM && $schedule <= self::MANUAL;
    }

    public static function timerInterval(int $schedule, int $customMinutes): int
    {
        if ($schedule === self::MANUAL) {
            return 0;
        }
        if (in_array($schedule, [self::MONTHLY, self::YEARLY], true)) {
            return self::DAILY_TIMER_INTERVAL;
        }

        $minutes = self::intervalMinutes($schedule, $customMinutes);
        if ($minutes > self::MAX_DIRECT_INTERVAL_MINUTES) {
            return self::DAILY_TIMER_INTERVAL;
        }

        return $minutes * 60 * 1000;
    }

    public static function isDue(
        int $schedule,
        int $customMinutes,
        int $lastSynchronization,
        ?int $now = null
    ): bool {
        if ($schedule === self::MANUAL) {
            return false;
        }
        if ($lastSynchronization <= 0) {
            return true;
        }

        $now ??= time();
        if ($schedule === self::MONTHLY || $schedule === self::YEARLY) {
            $modifier = $schedule === self::MONTHLY ? '+1 month' : '+1 year';
            $nextSynchronization = (new DateTimeImmutable('@' . $lastSynchronization))
                ->modify($modifier)
                ->getTimestamp();

            return $now >= $nextSynchronization;
        }

        return $now >= $lastSynchronization + (self::intervalMinutes($schedule, $customMinutes) * 60);
    }

    private static function intervalMinutes(int $schedule, int $customMinutes): int
    {
        return match ($schedule) {
            self::EVERY_5_MINUTES  => 5,
            self::EVERY_15_MINUTES => 15,
            self::EVERY_30_MINUTES => 30,
            self::HOURLY           => 60,
            self::EVERY_6_HOURS    => 360,
            self::EVERY_12_HOURS   => 720,
            self::DAILY            => 1_440,
            self::WEEKLY           => 10_080,
            default                => max(1, min(self::MAX_CUSTOM_INTERVAL_MINUTES, $customMinutes))
        };
    }
}
