<?php

declare(strict_types=1);

namespace IPSKalender;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class ICalendarRecurrence
{
    private const MAX_GENERATED_DAYS = 200_000;

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public static function expand(array $events, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd): array
    {
        if ($rangeEnd <= $rangeStart) {
            return [];
        }

        $groups = [];
        foreach ($events as $event) {
            $uid = trim((string) ($event['uid'] ?? ''));
            if ($uid !== '') {
                $groups[$uid][] = $event;
            }
        }

        $result = [];
        foreach ($groups as $group) {
            array_push($result, ...self::expandGroup($group, $rangeStart, $rangeEnd));
        }

        usort(
            $result,
            static fn(array $left, array $right): int => ((int) $left['startTimestamp'] <=> (int) $right['startTimestamp'])
                ?: strcasecmp((string) ($left['summary'] ?? ''), (string) ($right['summary'] ?? ''))
        );

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $group
     * @return list<array<string, mixed>>
     */
    private static function expandGroup(
        array $group,
        DateTimeImmutable $rangeStart,
        DateTimeImmutable $rangeEnd
    ): array {
        $masters = [];
        $overrides = [];
        foreach ($group as $event) {
            $recurrenceTimestamp = $event['recurrenceIdTimestamp'] ?? null;
            if (is_int($recurrenceTimestamp)) {
                if (!isset($overrides[$recurrenceTimestamp])
                    || self::isNewerEvent($event, $overrides[$recurrenceTimestamp])) {
                    $overrides[$recurrenceTimestamp] = $event;
                }
            } else {
                $masters[] = $event;
            }
        }

        if ($masters === []) {
            return array_values(array_filter(
                $overrides,
                static fn(array $event): bool => !self::isCancelled($event)
                    && self::overlapsRange($event, $rangeStart, $rangeEnd)
            ));
        }

        $master = array_shift($masters);
        foreach ($masters as $candidate) {
            if (self::isNewerEvent($candidate, $master)) {
                $master = $candidate;
            }
        }
        if (self::isCancelled($master)) {
            return [];
        }

        $result = [];
        $usedOverrides = [];
        foreach ([$master] as $master) {
            $rule = trim((string) ($master['recurrenceRule'] ?? ''));
            $recurrenceDates = is_array($master['recurrenceDates'] ?? null) ? $master['recurrenceDates'] : [];
            if ($rule === '' && $recurrenceDates === []) {
                if (!self::isCancelled($master) && self::overlapsRange($master, $rangeStart, $rangeEnd)) {
                    $result[] = $master;
                }
                continue;
            }

            $starts = self::generateRuleStarts($master, $rule, $rangeEnd);
            foreach ($recurrenceDates as $recurrenceDate) {
                if (is_array($recurrenceDate) && isset($recurrenceDate['timestamp'])) {
                    $starts[(int) $recurrenceDate['timestamp']] = self::dateAtTimestamp(
                        (int) $recurrenceDate['timestamp'],
                        (string) ($master['timezone'] ?? 'UTC')
                    );
                }
            }
            ksort($starts);

            $exceptions = [];
            foreach ((array) ($master['exceptionDates'] ?? []) as $exception) {
                if (is_array($exception) && isset($exception['timestamp'])) {
                    $exceptions[(int) $exception['timestamp']] = true;
                }
            }

            foreach ($starts as $originalTimestamp => $occurrenceStart) {
                if (isset($overrides[$originalTimestamp])) {
                    $usedOverrides[$originalTimestamp] = true;
                    $override = $overrides[$originalTimestamp];
                    if (!self::isCancelled($override)
                        && self::overlapsRange($override, $rangeStart, $rangeEnd)) {
                        $override['recurring'] = true;
                        $result[] = $override;
                    }
                    continue;
                }
                if (isset($exceptions[$originalTimestamp])) {
                    continue;
                }

                $occurrence = self::createOccurrence($master, $occurrenceStart);
                if (self::overlapsRange($occurrence, $rangeStart, $rangeEnd)) {
                    $result[] = $occurrence;
                }
            }
        }

        foreach ($overrides as $recurrenceTimestamp => $override) {
            if (!isset($usedOverrides[$recurrenceTimestamp])
                && !self::isCancelled($override)
                && self::overlapsRange($override, $rangeStart, $rangeEnd)) {
                $override['recurring'] = true;
                $result[] = $override;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $master
     * @return array<int, DateTimeImmutable>
     */
    private static function generateRuleStarts(
        array $master,
        string $ruleText,
        DateTimeImmutable $rangeEnd
    ): array {
        $timezone = self::timezone((string) ($master['timezone'] ?? 'UTC'));
        $seriesStart = (new DateTimeImmutable('@' . (int) $master['startTimestamp']))->setTimezone($timezone);
        $starts = [$seriesStart->getTimestamp() => $seriesStart];
        if ($ruleText === '') {
            return $starts;
        }

        $rule = self::parseRule($ruleText);
        $frequency = $rule['FREQ'][0] ?? '';
        if (!in_array($frequency, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
            return $starts;
        }

        $countLimit = isset($rule['COUNT'][0]) ? max(1, (int) $rule['COUNT'][0]) : null;
        $until = self::parseUntil($rule['UNTIL'][0] ?? '', $timezone);
        $day = $seriesStart->setTime(0, 0);
        $lastDay = $rangeEnd->setTimezone($timezone)->setTime(0, 0)->add(new DateInterval('P1D'));
        if ($until !== null && $until < $lastDay) {
            $lastDay = $until->setTimezone($timezone)->setTime(0, 0)->add(new DateInterval('P1D'));
        }

        $occurrenceCount = 0;
        $iterations = 0;
        while ($day <= $lastDay && $iterations++ < self::MAX_GENERATED_DAYS) {
            if (self::matchesRuleDate($day, $seriesStart, $rule, $frequency)) {
                $candidate = $day->setTime(
                    (int) $seriesStart->format('H'),
                    (int) $seriesStart->format('i'),
                    (int) $seriesStart->format('s')
                );
                if ($candidate >= $seriesStart && ($until === null || $candidate <= $until)) {
                    $occurrenceCount++;
                    if ($countLimit === null || $occurrenceCount <= $countLimit) {
                        $starts[$candidate->getTimestamp()] = $candidate;
                    }
                    if ($countLimit !== null && $occurrenceCount >= $countLimit) {
                        break;
                    }
                }
            }
            $day = $day->add(new DateInterval('P1D'));
        }

        return $starts;
    }

    /**
     * @param array<string, list<string>> $rule
     */
    private static function matchesRuleDate(
        DateTimeImmutable $date,
        DateTimeImmutable $seriesStart,
        array $rule,
        string $frequency,
        bool $applySetPosition = true
    ): bool {
        $interval = isset($rule['INTERVAL'][0]) ? max(1, (int) $rule['INTERVAL'][0]) : 1;
        $months = self::integerValues($rule['BYMONTH'] ?? []);
        if ($months !== [] && !in_array((int) $date->format('n'), $months, true)) {
            return false;
        }

        $matchesFrequency = match ($frequency) {
            'DAILY'   => self::calendarDayDifference($seriesStart, $date) % $interval === 0,
            'WEEKLY'  => self::matchesWeeklyInterval($date, $seriesStart, $rule, $interval),
            'MONTHLY' => self::calendarMonthDifference($seriesStart, $date) % $interval === 0,
            'YEARLY'  => ((int) $date->format('Y') - (int) $seriesStart->format('Y')) % $interval === 0,
            default   => false
        };
        if (!$matchesFrequency) {
            return false;
        }

        $monthDays = self::integerValues($rule['BYMONTHDAY'] ?? []);
        if ($monthDays !== [] && !self::matchesMonthDay($date, $monthDays)) {
            return false;
        }

        $byDays = self::stringValues($rule['BYDAY'] ?? []);
        if ($byDays !== [] && !self::matchesByDay($date, $byDays, $frequency, $months !== [])) {
            return false;
        }

        if ($frequency === 'WEEKLY' && $byDays === []
            && (int) $date->format('N') !== (int) $seriesStart->format('N')) {
            return false;
        }
        if ($frequency === 'MONTHLY' && $monthDays === [] && $byDays === []
            && (int) $date->format('j') !== (int) $seriesStart->format('j')) {
            return false;
        }
        if ($frequency === 'YEARLY') {
            if ($months === [] && !($byDays !== [] && self::containsOrdinalByDay($byDays))
                && (int) $date->format('n') !== (int) $seriesStart->format('n')) {
                return false;
            }
            if ($monthDays === [] && $byDays === []
                && (int) $date->format('j') !== (int) $seriesStart->format('j')) {
                return false;
            }
        }

        $setPositions = self::integerValues($rule['BYSETPOS'] ?? []);
        return !$applySetPosition
            || $setPositions === []
            || self::matchesSetPosition($date, $seriesStart, $rule, $frequency, $setPositions);
    }

    /**
     * @param array<string, list<string>> $rule
     */
    private static function matchesWeeklyInterval(
        DateTimeImmutable $date,
        DateTimeImmutable $seriesStart,
        array $rule,
        int $interval
    ): bool {
        $weekStart = self::weekdayNumber($rule['WKST'][0] ?? 'MO');
        $dateWeek = self::startOfWeek($date, $weekStart);
        $seriesWeek = self::startOfWeek($seriesStart, $weekStart);
        $weeks = intdiv(self::calendarDayDifference($seriesWeek, $dateWeek), 7);

        return $weeks >= 0 && $weeks % $interval === 0;
    }

    /**
     * @param list<string> $byDays
     */
    private static function matchesByDay(
        DateTimeImmutable $date,
        array $byDays,
        string $frequency,
        bool $hasByMonth
    ): bool {
        foreach ($byDays as $value) {
            if (preg_match('/^([+-]?\d+)?(MO|TU|WE|TH|FR|SA|SU)$/', strtoupper($value), $matches) !== 1) {
                continue;
            }
            $ordinal = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
            $weekday = self::weekdayNumber($matches[2]);
            if ((int) $date->format('N') !== $weekday) {
                continue;
            }
            if ($ordinal === 0 || $frequency === 'DAILY' || $frequency === 'WEEKLY') {
                return true;
            }
            if ($frequency === 'YEARLY' && !$hasByMonth) {
                if (self::matchesWeekdayOrdinalInYear($date, $ordinal)) {
                    return true;
                }
                continue;
            }
            if (self::matchesWeekdayOrdinalInMonth($date, $ordinal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $monthDays
     */
    private static function matchesMonthDay(DateTimeImmutable $date, array $monthDays): bool
    {
        $day = (int) $date->format('j');
        $daysInMonth = (int) $date->format('t');
        foreach ($monthDays as $monthDay) {
            $normalized = $monthDay < 0 ? $daysInMonth + $monthDay + 1 : $monthDay;
            if ($normalized === $day) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, list<string>> $rule
     * @param list<int> $setPositions
     */
    private static function matchesSetPosition(
        DateTimeImmutable $date,
        DateTimeImmutable $seriesStart,
        array $rule,
        string $frequency,
        array $setPositions
    ): bool {
        [$periodStart, $periodEnd] = match ($frequency) {
            'WEEKLY' => [
                self::startOfWeek($date, self::weekdayNumber($rule['WKST'][0] ?? 'MO')),
                self::startOfWeek($date, self::weekdayNumber($rule['WKST'][0] ?? 'MO'))->add(new DateInterval('P7D'))
            ],
            'MONTHLY' => [
                $date->modify('first day of this month')->setTime(0, 0),
                $date->modify('first day of next month')->setTime(0, 0)
            ],
            'YEARLY' => [
                $date->setDate((int) $date->format('Y'), 1, 1)->setTime(0, 0),
                $date->setDate((int) $date->format('Y') + 1, 1, 1)->setTime(0, 0)
            ],
            default => [$date->setTime(0, 0), $date->setTime(0, 0)->add(new DateInterval('P1D'))]
        };

        $matches = [];
        $candidate = $periodStart;
        while ($candidate < $periodEnd) {
            if ($candidate >= $seriesStart->setTime(0, 0)
                && self::matchesRuleDate($candidate, $seriesStart, $rule, $frequency, false)) {
                $matches[] = $candidate->format('Y-m-d');
            }
            $candidate = $candidate->add(new DateInterval('P1D'));
        }
        $position = array_search($date->format('Y-m-d'), $matches, true);
        if ($position === false) {
            return false;
        }

        $positivePosition = $position + 1;
        $negativePosition = $position - count($matches);
        return in_array($positivePosition, $setPositions, true)
            || in_array($negativePosition, $setPositions, true);
    }

    /**
     * @param array<string, mixed> $master
     * @return array<string, mixed>
     */
    private static function createOccurrence(array $master, DateTimeImmutable $occurrenceStart): array
    {
        $occurrence = $master;
        $allDay = (bool) ($master['allDay'] ?? false);
        $timezone = self::timezone((string) ($master['timezone'] ?? 'UTC'));
        $masterStart = (new DateTimeImmutable('@' . (int) $master['startTimestamp']))->setTimezone($timezone);
        $masterEnd = (new DateTimeImmutable('@' . (int) $master['endTimestamp']))->setTimezone($timezone);

        if ($allDay) {
            $durationDays = max(1, (int) $masterStart->diff($masterEnd)->format('%a'));
            $occurrenceEnd = $occurrenceStart->add(new DateInterval('P' . $durationDays . 'D'));
            $occurrence['start'] = $occurrenceStart->format('Y-m-d');
            $occurrence['end'] = $occurrenceEnd->format('Y-m-d');
            $recurrenceId = $occurrenceStart->format('Ymd');
        } else {
            $durationSeconds = max(0, (int) $master['endTimestamp'] - (int) $master['startTimestamp']);
            $occurrenceEnd = (new DateTimeImmutable('@' . ($occurrenceStart->getTimestamp() + $durationSeconds)))
                ->setTimezone($timezone);
            $occurrence['start'] = $occurrenceStart->format(DATE_ATOM);
            $occurrence['end'] = $occurrenceEnd->format(DATE_ATOM);
            $recurrenceId = $occurrenceStart->format('Ymd\THis');
        }

        $occurrence['startTimestamp'] = $occurrenceStart->getTimestamp();
        $occurrence['endTimestamp'] = $occurrenceEnd->getTimestamp();
        $occurrence['recurrenceId'] = $recurrenceId;
        $occurrence['recurrenceIdTimestamp'] = $occurrenceStart->getTimestamp();
        $occurrence['recurring'] = true;
        $occurrence['id'] = hash(
            'sha256',
            (string) ($master['resourceUrl'] ?? '') . '|'
                . (string) ($master['uid'] ?? '') . '|'
                . $occurrenceStart->getTimestamp()
        );

        return $occurrence;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function parseRule(string $rule): array
    {
        $result = [];
        foreach (explode(';', strtoupper(trim($rule))) as $part) {
            $separator = strpos($part, '=');
            if ($separator === false) {
                continue;
            }
            $name = trim(substr($part, 0, $separator));
            $values = array_values(array_filter(
                array_map('trim', explode(',', substr($part, $separator + 1))),
                static fn(string $value): bool => $value !== ''
            ));
            if ($name !== '' && $values !== []) {
                $result[$name] = $values;
            }
        }

        return $result;
    }

    private static function parseUntil(string $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return null;
        }

        try {
            if (preg_match('/^\d{8}$/', $value) === 1) {
                $date = DateTimeImmutable::createFromFormat('!Ymd', $value, $timezone);
                return $date === false ? null : $date->setTime(23, 59, 59);
            }
            if (str_ends_with($value, 'Z')) {
                $format = strlen($value) === 14 ? '!Ymd\THi\Z' : '!Ymd\THis\Z';
                $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
                return $date === false ? null : $date;
            }
            $format = strlen($value) === 13 ? '!Ymd\THi' : '!Ymd\THis';
            $date = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            return $date === false ? null : $date;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<string> $values
     * @return list<int>
     */
    private static function integerValues(array $values): array
    {
        return array_values(array_map('intval', self::stringValues($values)));
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function stringValues(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            foreach (explode(',', $value) as $part) {
                $part = strtoupper(trim($part));
                if ($part !== '') {
                    $result[] = $part;
                }
            }
        }

        return $result;
    }

    /**
     * @param list<string> $byDays
     */
    private static function containsOrdinalByDay(array $byDays): bool
    {
        foreach ($byDays as $value) {
            if (preg_match('/^[+-]?\d+(?:MO|TU|WE|TH|FR|SA|SU)$/', strtoupper($value)) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function matchesWeekdayOrdinalInMonth(DateTimeImmutable $date, int $ordinal): bool
    {
        $positive = intdiv((int) $date->format('j') - 1, 7) + 1;
        $negative = -(intdiv((int) $date->format('t') - (int) $date->format('j'), 7) + 1);

        return $ordinal === $positive || $ordinal === $negative;
    }

    private static function matchesWeekdayOrdinalInYear(DateTimeImmutable $date, int $ordinal): bool
    {
        $positive = intdiv((int) $date->format('z'), 7) + 1;
        $daysInYear = $date->format('L') === '1' ? 366 : 365;
        $negative = -(intdiv($daysInYear - 1 - (int) $date->format('z'), 7) + 1);

        return $ordinal === $positive || $ordinal === $negative;
    }

    private static function weekdayNumber(string $weekday): int
    {
        return match (strtoupper($weekday)) {
            'TU'    => 2,
            'WE'    => 3,
            'TH'    => 4,
            'FR'    => 5,
            'SA'    => 6,
            'SU'    => 7,
            default => 1
        };
    }

    private static function startOfWeek(DateTimeImmutable $date, int $weekStart): DateTimeImmutable
    {
        $offset = ((int) $date->format('N') - $weekStart + 7) % 7;
        return $date->setTime(0, 0)->sub(new DateInterval('P' . $offset . 'D'));
    }

    private static function calendarDayDifference(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return (int) $start->setTime(12, 0)->diff($end->setTime(12, 0))->format('%r%a');
    }

    private static function calendarMonthDifference(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return ((int) $end->format('Y') - (int) $start->format('Y')) * 12
            + (int) $end->format('n')
            - (int) $start->format('n');
    }

    private static function isCancelled(array $event): bool
    {
        return strtoupper((string) ($event['status'] ?? '')) === 'CANCELLED';
    }

    private static function overlapsRange(
        array $event,
        DateTimeImmutable $rangeStart,
        DateTimeImmutable $rangeEnd
    ): bool {
        return (int) ($event['endTimestamp'] ?? 0) > $rangeStart->getTimestamp()
            && (int) ($event['startTimestamp'] ?? 0) < $rangeEnd->getTimestamp();
    }

    private static function isNewerEvent(array $candidate, array $current): bool
    {
        $candidateSequence = (int) ($candidate['sequence'] ?? 0);
        $currentSequence = (int) ($current['sequence'] ?? 0);
        if ($candidateSequence !== $currentSequence) {
            return $candidateSequence > $currentSequence;
        }

        return strcmp((string) ($candidate['lastModified'] ?? ''), (string) ($current['lastModified'] ?? '')) > 0;
    }

    private static function dateAtTimestamp(int $timestamp, string $timezone): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(self::timezone($timezone));
    }

    private static function timezone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name !== '' ? $name : 'UTC');
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }
}
