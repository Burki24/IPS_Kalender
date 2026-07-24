<?php

declare(strict_types=1);

namespace IPSKalender;

final class CalendarEventTranslation
{
    public const NONE = 0;
    public const GOOGLE_PUBLIC_CALENDARS_GERMAN = 1;

    /**
     * Checks whether a title translation profile is supported.
     */
    public static function isValidProfile(int $profile): bool
    {
        return in_array($profile, [self::NONE, self::GOOGLE_PUBLIC_CALENDARS_GERMAN], true);
    }

    /**
     * Applies the selected title translation profile to normalized events.
     *
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public static function translateEvents(array $events, int $profile): array
    {
        if ($profile === self::NONE) {
            return $events;
        }

        if ($profile !== self::GOOGLE_PUBLIC_CALENDARS_GERMAN) {
            return $events;
        }

        foreach ($events as &$event) {
            $summary = (string) ($event['summary'] ?? '');
            $translated = self::translateGoogleSummary($summary);
            if ($translated === $summary) {
                continue;
            }

            $event['originalSummary'] = $summary;
            $event['summary'] = $translated;
        }
        unset($event);

        return $events;
    }

    private static function translateGoogleSummary(string $summary): string
    {
        if (preg_match('/^Day\s+(\d{1,3})\s+of\s+(\d{4})$/i', trim($summary), $matches) === 1) {
            return sprintf('Tag %d von %d', (int) $matches[1], (int) $matches[2]);
        }

        $moonPhases = [
            'new moon'      => 'Neumond',
            'first quarter' => 'Erstes Viertel',
            'full moon'     => 'Vollmond',
            'third quarter' => 'Letztes Viertel',
            'last quarter'  => 'Letztes Viertel'
        ];
        if (preg_match(
            '/^(New Moon|First Quarter|Full Moon|Third Quarter|Last Quarter)(?:\s+(.+))?$/i',
            trim($summary),
            $matches
        ) !== 1) {
            return $summary;
        }

        $translated = $moonPhases[strtolower($matches[1])] ?? $summary;
        $suffix = trim((string) ($matches[2] ?? ''));
        if ($suffix === '') {
            return $translated;
        }

        return $translated . ' ' . self::translateEnglishTimes($suffix);
    }

    private static function translateEnglishTimes(string $text): string
    {
        $translated = preg_replace_callback(
            '/(?<!\d)(\d{1,2})(?::(\d{2}))?\s*([ap])\.?m\.?(?![a-z])/i',
            static function (array $matches): string {
                $hour = (int) $matches[1] % 12;
                if (strtolower($matches[3]) === 'p') {
                    $hour += 12;
                }
                $minute = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;

                return sprintf('%02d:%02d Uhr', $hour, $minute);
            },
            $text
        );

        return is_string($translated) ? $translated : $text;
    }
}
