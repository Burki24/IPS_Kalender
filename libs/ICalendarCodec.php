<?php

declare(strict_types=1);

namespace IPSKalender;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/ICalendarRecurrence.php';

final class ICalendarCodec
{
    /**
     * Parses VEVENT components from an iCalendar resource into normalized event records.
     *
     * @return list<array<string, mixed>>
     */
    public static function parseEvents(string $ical, string $resourceUrl, string $etag): array
    {
        $events = [];
        foreach (self::extractEventBlocks(self::unfoldLines($ical)) as $block) {
            $properties = self::readTopLevelProperties($block);
            $uid = self::propertyValue($properties, 'UID');
            $startProperty = self::firstProperty($properties, 'DTSTART');
            if ($uid === '' || $startProperty === null) {
                continue;
            }

            $start = self::parseDateProperty($startProperty);
            $endProperty = self::firstProperty($properties, 'DTEND');
            $end = $endProperty !== null
                ? self::parseDateProperty($endProperty)
                : self::endFromDuration($start, self::propertyValue($properties, 'DURATION'));
            $recurrenceIdProperty = self::firstProperty($properties, 'RECURRENCE-ID');
            $recurrenceId = $recurrenceIdProperty['value'] ?? '';
            $parsedRecurrenceId = $recurrenceIdProperty !== null
                ? self::parseDateProperty($recurrenceIdProperty)
                : null;

            $events[] = [
                'id'                => hash('sha256', $resourceUrl . '|' . $uid . '|' . $recurrenceId . '|' . $start['value']),
                'uid'               => $uid,
                'resourceUrl'       => $resourceUrl,
                'etag'              => $etag,
                'summary'           => self::unescapeText(self::propertyValue($properties, 'SUMMARY')),
                'description'       => self::unescapeText(self::propertyValue($properties, 'DESCRIPTION')),
                'location'          => self::unescapeText(self::propertyValue($properties, 'LOCATION')),
                'start'             => $start['value'],
                'end'               => $end['value'],
                'startTimestamp'    => $start['timestamp'],
                'endTimestamp'      => $end['timestamp'],
                'allDay'            => $start['allDay'],
                'timezone'          => $start['timezone'],
                'status'            => strtoupper(self::propertyValue($properties, 'STATUS')),
                'recurrenceRule'    => self::propertyValue($properties, 'RRULE'),
                'recurrenceId'      => $recurrenceId,
                'recurrenceIdTimestamp' => $parsedRecurrenceId['timestamp'] ?? null,
                'exceptionDates'    => self::parseDatePropertyList($properties['EXDATE'] ?? []),
                'recurrenceDates'   => self::parseDatePropertyList($properties['RDATE'] ?? []),
                'recurring'         => self::propertyValue($properties, 'RRULE') !== '' || $recurrenceId !== '',
                'sequence'          => (int) self::propertyValue($properties, 'SEQUENCE'),
                'created'           => self::parseOptionalDate(self::firstProperty($properties, 'CREATED')),
                'lastModified'      => self::parseOptionalDate(self::firstProperty($properties, 'LAST-MODIFIED')),
                'url'               => self::propertyValue($properties, 'URL')
            ];
        }

        return $events;
    }

    /**
     * Parses an iCalendar resource and expands recurring events within the requested range.
     *
     * @return list<array<string, mixed>>
     */
    public static function parseEventsInRange(
        string $ical,
        string $resourceUrl,
        string $etag,
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): array {
        return ICalendarRecurrence::expand(
            self::parseEvents($ical, $resourceUrl, $etag),
            $start,
            $end
        );
    }

    /**
     * Creates a standalone VCALENDAR document containing one VEVENT.
     *
     * @param array<string, mixed> $data
     * @return array{uid: string, ical: string}
     */
    public static function createEvent(array $data): array
    {
        $summary = trim((string) ($data['summary'] ?? ''));
        if ($summary === '') {
            throw new InvalidArgumentException('The event summary is missing.');
        }
        if (!array_key_exists('start', $data)) {
            throw new InvalidArgumentException('The event start is missing.');
        }

        $allDay = (bool) ($data['allDay'] ?? false);
        $start = self::inputDate($data['start'], $allDay);
        $end = array_key_exists('end', $data)
            ? self::inputDate($data['end'], $allDay)
            : ($allDay ? $start->add(new DateInterval('P1D')) : $start->add(new DateInterval('PT1H')));
        if ($end <= $start) {
            throw new InvalidArgumentException('The event end must be later than the start.');
        }

        $uid = bin2hex(random_bytes(16)) . '@ips-kalender';
        $now = gmdate('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//OpenCalendar//Calendar Module//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $now,
            'CREATED:' . $now,
            'LAST-MODIFIED:' . $now,
            'SEQUENCE:0',
            self::formatDateLine('DTSTART', $start, $allDay),
            self::formatDateLine('DTEND', $end, $allDay),
            'SUMMARY:' . self::escapeText($summary)
        ];

        foreach (['description' => 'DESCRIPTION', 'location' => 'LOCATION'] as $key => $property) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '') {
                $lines[] = $property . ':' . self::escapeText($value);
            }
        }

        $status = self::normalizeStatus((string) ($data['status'] ?? 'CONFIRMED'));
        if ($status !== '') {
            $lines[] = 'STATUS:' . $status;
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return ['uid' => $uid, 'ical' => self::foldLines($lines)];
    }

    /**
     * Updates a non-recurring VEVENT inside an existing iCalendar resource.
     *
     * @param array<string, mixed> $data
     */
    public static function updateEvent(string $ical, string $uid, array $data): string
    {
        $lines = self::unfoldLines($ical);
        $blocks = self::extractEventBlocksWithOffsets($lines);
        $target = null;

        foreach ($blocks as $block) {
            $properties = self::readTopLevelProperties($block['lines']);
            if (self::propertyValue($properties, 'UID') !== $uid) {
                continue;
            }
            if (self::propertyValue($properties, 'RRULE') !== ''
                || self::propertyValue($properties, 'RECURRENCE-ID') !== '') {
                throw new RuntimeException('Recurring events cannot be modified yet.');
            }
            $target = $block;
            break;
        }

        if ($target === null) {
            throw new RuntimeException('The event was not found in the calendar resource.');
        }

        $block = $target['lines'];
        if (array_key_exists('summary', $data)) {
            $summary = trim((string) $data['summary']);
            if ($summary === '') {
                throw new InvalidArgumentException('The event summary must not be empty.');
            }
            self::replaceProperty($block, 'SUMMARY', 'SUMMARY:' . self::escapeText($summary));
        }
        foreach (['description' => 'DESCRIPTION', 'location' => 'LOCATION'] as $key => $property) {
            if (array_key_exists($key, $data)) {
                $value = trim((string) $data[$key]);
                self::replaceProperty($block, $property, $value === '' ? null : $property . ':' . self::escapeText($value));
            }
        }
        if (array_key_exists('status', $data)) {
            $status = self::normalizeStatus((string) $data['status']);
            self::replaceProperty($block, 'STATUS', $status === '' ? null : 'STATUS:' . $status);
        }

        $properties = self::readTopLevelProperties($block);
        $currentStart = self::firstProperty($properties, 'DTSTART');
        $allDay = array_key_exists('allDay', $data)
            ? (bool) $data['allDay']
            : ($currentStart !== null && self::parseDateProperty($currentStart)['allDay']);

        if (array_key_exists('start', $data)) {
            $start = self::inputDate($data['start'], $allDay);
            self::replaceProperty($block, 'DTSTART', self::formatDateLine('DTSTART', $start, $allDay));
        }
        if (array_key_exists('end', $data)) {
            $end = self::inputDate($data['end'], $allDay);
            self::replaceProperty($block, 'DTEND', self::formatDateLine('DTEND', $end, $allDay));
        }

        $updatedProperties = self::readTopLevelProperties($block);
        $updatedStart = self::firstProperty($updatedProperties, 'DTSTART');
        $updatedEnd = self::firstProperty($updatedProperties, 'DTEND');
        if ($updatedStart !== null && $updatedEnd !== null
            && self::parseDateProperty($updatedEnd)['timestamp'] <= self::parseDateProperty($updatedStart)['timestamp']) {
            throw new InvalidArgumentException('The event end must be later than the start.');
        }

        $sequence = (int) self::propertyValue($updatedProperties, 'SEQUENCE');
        self::replaceProperty($block, 'SEQUENCE', 'SEQUENCE:' . ($sequence + 1));
        self::replaceProperty($block, 'DTSTAMP', 'DTSTAMP:' . gmdate('Ymd\THis\Z'));
        self::replaceProperty($block, 'LAST-MODIFIED', 'LAST-MODIFIED:' . gmdate('Ymd\THis\Z'));

        array_splice($lines, $target['start'], $target['end'] - $target['start'] + 1, $block);
        return self::foldLines($lines);
    }

    /**
     * @return list<string>
     */
    private static function unfoldLines(string $ical): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $ical);
        $physicalLines = explode("\n", $normalized);
        $lines = [];

        foreach ($physicalLines as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $lines !== []) {
                $lines[array_key_last($lines)] .= substr($line, 1);
                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     * @return list<list<string>>
     */
    private static function extractEventBlocks(array $lines): array
    {
        return array_map(
            static fn(array $block): array => $block['lines'],
            self::extractEventBlocksWithOffsets($lines)
        );
    }

    /**
     * @param list<string> $lines
     * @return list<array{start: int, end: int, lines: list<string>}>
     */
    private static function extractEventBlocksWithOffsets(array $lines): array
    {
        $blocks = [];
        $start = null;
        $depth = 0;

        foreach ($lines as $index => $line) {
            $upper = strtoupper($line);
            if ($upper === 'BEGIN:VEVENT' && $start === null) {
                $start = $index;
                $depth = 1;
                continue;
            }
            if ($start === null) {
                continue;
            }
            if (str_starts_with($upper, 'BEGIN:')) {
                $depth++;
            } elseif (str_starts_with($upper, 'END:')) {
                $depth--;
                if ($depth === 0) {
                    $blocks[] = [
                        'start' => $start,
                        'end'   => $index,
                        'lines' => array_slice($lines, $start, $index - $start + 1)
                    ];
                    $start = null;
                }
            }
        }

        return $blocks;
    }

    /**
     * @param list<string> $block
     * @return array<string, list<array{value: string, params: array<string, string>}>>
     */
    private static function readTopLevelProperties(array $block): array
    {
        $properties = [];
        $depth = 0;

        foreach ($block as $line) {
            $upper = strtoupper($line);
            if (str_starts_with($upper, 'BEGIN:')) {
                $depth++;
                continue;
            }
            if (str_starts_with($upper, 'END:')) {
                $depth--;
                continue;
            }
            if ($depth !== 1) {
                continue;
            }

            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $definition = substr($line, 0, $separator);
            $value = substr($line, $separator + 1);
            $parts = explode(';', $definition);
            $name = strtoupper((string) array_shift($parts));
            $params = [];
            foreach ($parts as $parameter) {
                $parameterSeparator = strpos($parameter, '=');
                if ($parameterSeparator !== false) {
                    $params[strtoupper(substr($parameter, 0, $parameterSeparator))] = trim(
                        substr($parameter, $parameterSeparator + 1),
                        "\""
                    );
                }
            }
            $properties[$name][] = ['value' => $value, 'params' => $params];
        }

        return $properties;
    }

    /**
     * @param array<string, list<array{value: string, params: array<string, string>}>> $properties
     * @return array{value: string, params: array<string, string>}|null
     */
    private static function firstProperty(array $properties, string $name): ?array
    {
        return $properties[strtoupper($name)][0] ?? null;
    }

    /**
     * @param array<string, list<array{value: string, params: array<string, string>}>> $properties
     */
    private static function propertyValue(array $properties, string $name): string
    {
        return (string) (self::firstProperty($properties, $name)['value'] ?? '');
    }

    /**
     * @param array{value: string, params: array<string, string>} $property
     * @return array{value: string, timestamp: int, allDay: bool, timezone: string}
     */
    private static function parseDateProperty(array $property): array
    {
        $raw = trim($property['value']);
        $allDay = strtoupper($property['params']['VALUE'] ?? '') === 'DATE'
            || preg_match('/^\d{8}$/', $raw) === 1;
        $timezoneName = $property['params']['TZID'] ?? '';

        try {
            if ($allDay) {
                $timezone = self::timezone($timezoneName);
                $date = DateTimeImmutable::createFromFormat('!Ymd', $raw, $timezone);
            } elseif (str_ends_with(strtoupper($raw), 'Z')) {
                $timezone = new DateTimeZone('UTC');
                $format = strlen($raw) === 14 ? '!Ymd\THi\Z' : '!Ymd\THis\Z';
                $date = DateTimeImmutable::createFromFormat($format, strtoupper($raw), $timezone);
            } else {
                $timezone = self::timezone($timezoneName);
                $format = strlen($raw) === 13 ? '!Ymd\THi' : '!Ymd\THis';
                $date = DateTimeImmutable::createFromFormat($format, $raw, $timezone);
            }
        } catch (Throwable) {
            $date = false;
        }

        if ($date === false) {
            throw new RuntimeException('The calendar contains an invalid date value: ' . $raw);
        }

        return [
            'value'     => $allDay ? $date->format('Y-m-d') : $date->format(DATE_ATOM),
            'timestamp' => $date->getTimestamp(),
            'allDay'    => $allDay,
            'timezone'  => $date->getTimezone()->getName()
        ];
    }

    /**
     * @param list<array{value: string, params: array<string, string>}> $properties
     * @return list<array{value: string, timestamp: int, allDay: bool, timezone: string}>
     */
    private static function parseDatePropertyList(array $properties): array
    {
        $result = [];
        foreach ($properties as $property) {
            foreach (explode(',', $property['value']) as $value) {
                $value = trim($value);
                if ($value === '' || str_contains($value, '/')) {
                    continue;
                }
                try {
                    $result[] = self::parseDateProperty([
                        'value'  => $value,
                        'params' => $property['params']
                    ]);
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return $result;
    }

    /**
     * @param array{value: string, timestamp: int, allDay: bool, timezone: string} $start
     * @return array{value: string, timestamp: int, allDay: bool, timezone: string}
     */
    private static function defaultEnd(array $start): array
    {
        if (!$start['allDay']) {
            return $start;
        }

        $timezone = self::timezone($start['timezone']);
        $end = (new DateTimeImmutable('@' . $start['timestamp']))
            ->setTimezone($timezone)
            ->add(new DateInterval('P1D'));

        return [
            'value'     => $end->format('Y-m-d'),
            'timestamp' => $end->getTimestamp(),
            'allDay'    => true,
            'timezone'  => $start['timezone']
        ];
    }

    /**
     * @param array{value: string, timestamp: int, allDay: bool, timezone: string} $start
     * @return array{value: string, timestamp: int, allDay: bool, timezone: string}
     */
    private static function endFromDuration(array $start, string $duration): array
    {
        $duration = strtoupper(trim($duration));
        if ($duration === '') {
            return self::defaultEnd($start);
        }

        try {
            if (str_starts_with($duration, '-')) {
                throw new RuntimeException('Negative event duration.');
            }
            $timezone = self::timezone($start['timezone']);
            $date = (new DateTimeImmutable('@' . $start['timestamp']))
                ->setTimezone($timezone)
                ->add(new DateInterval($duration));

            return [
                'value'     => $start['allDay'] ? $date->format('Y-m-d') : $date->format(DATE_ATOM),
                'timestamp' => $date->getTimestamp(),
                'allDay'    => $start['allDay'],
                'timezone'  => $date->getTimezone()->getName()
            ];
        } catch (Throwable) {
            return self::defaultEnd($start);
        }
    }

    /**
     * @param array{value: string, params: array<string, string>}|null $property
     */
    private static function parseOptionalDate(?array $property): string
    {
        if ($property === null) {
            return '';
        }

        try {
            return self::parseDateProperty($property)['value'];
        } catch (Throwable) {
            return '';
        }
    }

    private static function timezone(string $name): DateTimeZone
    {
        $name = trim($name, " \t\n\r\0\x0B\"");
        try {
            return new DateTimeZone($name !== '' ? $name : date_default_timezone_get());
        } catch (Throwable) {
            foreach (timezone_identifiers_list() as $identifier) {
                if (str_ends_with($name, '/' . $identifier)) {
                    return new DateTimeZone($identifier);
                }
            }

            return new DateTimeZone('UTC');
        }
    }

    private static function inputDate(mixed $value, bool $allDay): DateTimeImmutable
    {
        try {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                return (new DateTimeImmutable('@' . (string) $value))->setTimezone(new DateTimeZone('UTC'));
            }
            $text = trim((string) $value);
            if ($text === '') {
                throw new InvalidArgumentException('Empty date.');
            }
            if ($allDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text, self::timezone(''));
                if ($date !== false) {
                    return $date;
                }
            }
            return new DateTimeImmutable($text, self::timezone(''));
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The event contains an invalid date.', 0, $exception);
        }
    }

    private static function formatDateLine(string $property, DateTimeImmutable $date, bool $allDay): string
    {
        return $allDay
            ? $property . ';VALUE=DATE:' . $date->format('Ymd')
            : $property . ':' . $date->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private static function normalizeStatus(string $status): string
    {
        $status = strtoupper(trim($status));
        if ($status === '') {
            return '';
        }
        if (!in_array($status, ['TENTATIVE', 'CONFIRMED', 'CANCELLED'], true)) {
            throw new InvalidArgumentException('The event status is invalid.');
        }
        return $status;
    }

    /**
     * @param list<string> $block
     */
    private static function replaceProperty(array &$block, string $name, ?string $replacement): void
    {
        $name = strtoupper($name);
        $depth = 0;
        $matches = [];
        $insertAt = count($block) - 1;

        foreach ($block as $index => $line) {
            $upper = strtoupper($line);
            if (str_starts_with($upper, 'BEGIN:')) {
                if ($depth === 1 && $insertAt === count($block) - 1) {
                    $insertAt = $index;
                }
                $depth++;
                continue;
            }
            if (str_starts_with($upper, 'END:')) {
                $depth--;
                continue;
            }
            if ($depth !== 1) {
                continue;
            }
            $separator = strcspn($line, ';:');
            if (strtoupper(substr($line, 0, $separator)) === $name) {
                $matches[] = $index;
            }
        }

        if ($matches !== []) {
            $first = array_shift($matches);
            if ($replacement === null) {
                array_splice($block, $first, 1);
                $matches = array_map(static fn(int $index): int => $index - 1, $matches);
            } else {
                $block[$first] = $replacement;
            }
            foreach (array_reverse($matches) as $index) {
                array_splice($block, $index, 1);
            }
            return;
        }

        if ($replacement !== null) {
            array_splice($block, $insertAt, 0, [$replacement]);
        }
    }

    private static function escapeText(string $value): string
    {
        return str_replace(
            ["\\", "\r\n", "\r", "\n", ';', ','],
            ["\\\\", '\\n', '\\n', '\\n', '\\;', '\\,'],
            $value
        );
    }

    private static function unescapeText(string $value): string
    {
        return (string) preg_replace_callback(
            '/\\\\([nN,;\\\\])/',
            static fn(array $matches): string => match ($matches[1]) {
                'n', 'N' => "\n",
                default  => $matches[1]
            },
            $value
        );
    }

    /**
     * @param list<string> $lines
     */
    private static function foldLines(array $lines): string
    {
        $folded = [];
        foreach ($lines as $line) {
            $first = true;
            while (strlen($line) > ($first ? 75 : 74)) {
                $limit = $first ? 75 : 74;
                $part = function_exists('mb_strcut')
                    ? mb_strcut($line, 0, $limit, 'UTF-8')
                    : substr($line, 0, $limit);
                $folded[] = ($first ? '' : ' ') . $part;
                $line = substr($line, strlen($part));
                $first = false;
            }
            $folded[] = ($first ? '' : ' ') . $line;
        }

        return implode("\r\n", $folded) . "\r\n";
    }
}
