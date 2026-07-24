<?php

declare(strict_types=1);

use IPSKalender\CalendarEventTranslation;

trait KalenderKontoICalendarAccountTrait
{
    /**
     * @return list<array{
     *     url: string,
     *     name: string,
     *     username: string,
     *     password: string,
     *     color: string,
     *     translationProfile: int,
     *     updateSchedule: int,
     *     updateInterval: int
     * }>
     */
    private function iCalendarSubscriptions(): array
    {
        try {
            $configured = json_decode(
                $this->ReadPropertyString('ICalendarFeeds'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            $configured = [];
        }

        $subscriptions = [];
        $configuredUrls = [];
        if (is_array($configured)) {
            foreach ($configured as $feed) {
                if (!is_array($feed)
                    || !(bool) ($feed['Active'] ?? $feed['active'] ?? true)) {
                    continue;
                }
                $url = trim((string) ($feed['URL'] ?? $feed['url'] ?? ''));
                $subscriptions[] = [
                    'url'                => $url,
                    'name'               => trim((string) ($feed['Name'] ?? $feed['name'] ?? '')),
                    'username'           => trim((string) ($feed['Username'] ?? $feed['username'] ?? '')),
                    'password'           => (string) ($feed['Password'] ?? $feed['password'] ?? ''),
                    'color'              => trim((string) ($feed['Color'] ?? $feed['color'] ?? '')),
                    'translationProfile' => (int) (
                        $feed['TranslationProfile']
                        ?? $feed['translationProfile']
                        ?? CalendarEventTranslation::NONE
                    ),
                    'updateSchedule'     => (int) (
                        $feed['UpdateSchedule']
                        ?? $feed['updateSchedule']
                        ?? $this->ReadPropertyInteger('UpdateSchedule')
                    ),
                    'updateInterval'     => (int) (
                        $feed['UpdateInterval']
                        ?? $feed['updateInterval']
                        ?? $this->ReadPropertyInteger('UpdateInterval')
                    )
                ];
                $configuredUrls[$this->iCalendarUrlKey($url)] = true;
            }
        }

        $legacyUrl = trim($this->ReadPropertyString('ServerURL'));
        if ($legacyUrl !== ''
            && $legacyUrl !== self::APPLE_CALDAV_URL
            && !isset($configuredUrls[$this->iCalendarUrlKey($legacyUrl)])) {
            array_unshift($subscriptions, [
                'url'                => $legacyUrl,
                'name'               => trim($this->ReadPropertyString('CalendarName')),
                'username'           => trim($this->ReadPropertyString('Username')),
                'password'           => $this->ReadPropertyString('Password'),
                'color'              => '',
                'translationProfile' => $this->ReadPropertyInteger('ICalendarTranslationProfile'),
                'updateSchedule'     => $this->ReadPropertyInteger('UpdateSchedule'),
                'updateInterval'     => $this->ReadPropertyInteger('UpdateInterval')
            ]);
        }

        return $subscriptions;
    }

    private function iCalendarSummary(): string
    {
        $subscriptions = $this->iCalendarSubscriptions();
        if (count($subscriptions) > 1) {
            return sprintf($this->Translate('%d subscriptions'), count($subscriptions));
        }

        return trim((string) ($subscriptions[0]['name'] ?? ''));
    }

    private function iCalendarUrlKey(string $url): string
    {
        $url = trim($url);
        if (str_starts_with(strtolower($url), 'webcal://')) {
            $url = 'https://' . substr($url, 9);
        }

        return $url;
    }

    /**
     * @return array<string, mixed>
     */
    private function readICalendarFeedCache(string $subscriptionId): array
    {
        if ($subscriptionId === '') {
            return [];
        }

        $entries = $this->readICalendarFeedCacheEntries();
        $entry = $entries[$subscriptionId] ?? null;
        if (!is_array($entry)) {
            return [];
        }

        $encodedBody = (string) ($entry['bodyData'] ?? '');
        $body = base64_decode($encodedBody, true);
        if (!is_string($body)) {
            return [];
        }
        if (($entry['encoding'] ?? '') === 'gzip-base64') {
            if (!function_exists('gzdecode')) {
                return [];
            }
            $decoded = gzdecode($body);
            if (!is_string($decoded)) {
                return [];
            }
            $body = $decoded;
        } elseif (($entry['encoding'] ?? '') !== 'base64') {
            return [];
        }

        unset($entry['bodyData'], $entry['encoding']);
        $entry['body'] = $body;

        return $entry;
    }

    /**
     * @param array<string, mixed> $cacheState
     */
    private function writeICalendarFeedCache(string $subscriptionId, array $cacheState): void
    {
        if ($subscriptionId === '') {
            return;
        }

        $body = (string) ($cacheState['body'] ?? '');
        if ($body === '') {
            return;
        }

        $encoding = 'base64';
        $bodyData = $body;
        if (function_exists('gzencode')) {
            $compressed = gzencode($body, 6);
            if (is_string($compressed) && strlen($compressed) < strlen($body)) {
                $encoding = 'gzip-base64';
                $bodyData = $compressed;
            }
        }

        unset($cacheState['body']);
        $cacheState['encoding'] = $encoding;
        $cacheState['bodyData'] = base64_encode($bodyData);

        $entries = $this->readICalendarFeedCacheEntries();
        $entries[$subscriptionId] = $cacheState;
        $this->WriteAttributeString(
            'ICalendarFeedCache',
            json_encode(
                $entries,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );
        $this->WriteAttributeString('LastError', $this->iCalendarCacheWarning());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readICalendarFeedCacheEntries(): array
    {
        try {
            $entries = json_decode(
                $this->ReadAttributeString('ICalendarFeedCache'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            return is_array($entries) ? array_filter($entries, 'is_array') : [];
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * @param list<string> $activeIds
     */
    private function pruneICalendarFeedCache(array $activeIds): void
    {
        $activeIds = array_fill_keys(array_filter($activeIds), true);
        $entries = array_intersect_key($this->readICalendarFeedCacheEntries(), $activeIds);
        $this->WriteAttributeString(
            'ICalendarFeedCache',
            json_encode(
                $entries,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function iCalendarCacheStatus(): array
    {
        $entries = $this->readICalendarFeedCacheEntries();
        $status = [];
        foreach ($this->iCalendarSubscriptions() as $subscription) {
            $id = hash('sha256', 'ics|' . $this->iCalendarUrlKey((string) ($subscription['url'] ?? '')));
            $entry = is_array($entries[$id] ?? null) ? $entries[$id] : [];
            $status[] = [
                'id'           => $id,
                'name'         => (string) ($subscription['name'] ?? ''),
                'lastCheck'    => (int) ($entry['lastCheck'] ?? 0),
                'lastDownload' => (int) ($entry['lastDownload'] ?? 0),
                'lastChange'   => (int) ($entry['lastChange'] ?? 0),
                'stale'        => (bool) ($entry['stale'] ?? false),
                'lastError'    => $this->translateErrorMessage((string) ($entry['lastError'] ?? ''))
            ];
        }

        return $status;
    }

    private function iCalendarCacheWarning(): string
    {
        $staleFeeds = array_values(array_filter(
            $this->iCalendarCacheStatus(),
            static fn(array $status): bool => (bool) ($status['stale'] ?? false)
        ));
        if ($staleFeeds === []) {
            return '';
        }

        $names = array_map(
            static fn(array $status): string => trim((string) ($status['name'] ?? '')) ?: 'iCalendar',
            $staleFeeds
        );

        return sprintf(
            $this->Translate('Using the last valid cached data for: %s.'),
            implode(', ', $names)
        );
    }
}
