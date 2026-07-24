<?php

declare(strict_types=1);

namespace IPSKalender;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

require_once __DIR__ . '/CalendarProviderInterface.php';
require_once __DIR__ . '/CalendarEventTranslation.php';
require_once __DIR__ . '/ICalendarFeedProvider.php';
require_once __DIR__ . '/SynchronizationSchedule.php';

/**
 * Combines multiple independent read-only iCalendar feeds into one account.
 */
final class ICalendarSubscriptionProvider implements CalendarProviderInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $subscriptions = [];

    /** @var Closure(array<string, mixed>): ICalendarFeedProvider */
    private Closure $providerFactory;

    /**
     * @param list<array<string, mixed>> $subscriptions
     * @param callable(array<string, mixed>): ICalendarFeedProvider $providerFactory
     */
    public function __construct(array $subscriptions, callable $providerFactory)
    {
        $this->providerFactory = Closure::fromCallable($providerFactory);

        foreach ($subscriptions as $subscription) {
            if (!is_array($subscription)) {
                continue;
            }
            $normalized = $this->normalizeSubscription($subscription);
            if (isset($this->subscriptions[$normalized['id']])) {
                throw new InvalidArgumentException(sprintf(
                    'The iCalendar subscription URL for "%s" is configured more than once.',
                    $normalized['name']
                ));
            }
            $this->subscriptions[$normalized['id']] = $normalized;
        }

        if ($this->subscriptions === []) {
            throw new InvalidArgumentException('At least one active iCalendar subscription is required.');
        }
    }

    public function testConnection(): array
    {
        $eventCount = 0;
        foreach ($this->subscriptions as $subscription) {
            $result = $this->provider($subscription)->testConnection();
            $eventCount += (int) ($result['eventCount'] ?? 0);
        }

        return [
            'success'       => true,
            'calendarCount' => count($this->subscriptions),
            'eventCount'    => $eventCount,
            'message'       => 'Connection successful.'
        ];
    }

    public function getCalendars(): array
    {
        $calendars = [];
        foreach ($this->subscriptions as $subscription) {
            $calendar = $this->provider($subscription)->getCalendars()[0] ?? null;
            if (!is_array($calendar)) {
                throw new ICalendarFeedProviderException('The iCalendar subscription did not expose a calendar.');
            }

            $calendar['id'] = $subscription['id'];
            $calendar['providerId'] = $subscription['id'];
            $calendar['reference'] = $this->reference($subscription['id']);
            $calendar['url'] = '';
            $calendar['name'] = $subscription['name'] !== ''
                ? $subscription['name']
                : (string) ($calendar['name'] ?? 'iCalendar');
            if ($subscription['color'] !== '') {
                $calendar['color'] = $subscription['color'];
            }
            $calendar['updateSchedule'] = $subscription['updateSchedule'];
            $calendar['updateInterval'] = $subscription['updateInterval'];
            $calendars[] = $calendar;
        }

        return $calendars;
    }

    public function getEvents(string $calendarReference, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $subscription = $this->resolveSubscription($calendarReference);

        return CalendarEventTranslation::translateEvents(
            $this->provider($subscription)->getEvents($subscription['url'], $start, $end),
            (int) $subscription['translationProfile']
        );
    }

    public function createEvent(string $calendarReference, array $event): array
    {
        throw new ICalendarFeedProviderException('iCalendar subscriptions are read-only.');
    }

    public function updateEvent(
        string $calendarReference,
        string $eventReference,
        string $etag,
        string $uid,
        array $event
    ): array {
        throw new ICalendarFeedProviderException('iCalendar subscriptions are read-only.');
    }

    public function deleteEvent(
        string $calendarReference,
        string $eventReference,
        string $etag,
        string $recurrenceId = ''
    ): bool {
        throw new ICalendarFeedProviderException('iCalendar subscriptions are read-only.');
    }

    /**
     * @param array<string, mixed> $subscription
     * @return array{
     *     id: string,
     *     url: string,
     *     name: string,
     *     username: string,
     *     password: string,
     *     color: string,
     *     translationProfile: int,
     *     updateSchedule: int,
     *     updateInterval: int
     * }
     */
    private function normalizeSubscription(array $subscription): array
    {
        $url = $this->normalizeUrl((string) ($subscription['url'] ?? ''));
        $id = hash('sha256', 'ics|' . $url);
        $color = strtoupper(trim((string) ($subscription['color'] ?? '')));
        if ($color !== '' && preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'The configured color for iCalendar subscription "%s" is invalid.',
                trim((string) ($subscription['name'] ?? ''))
            ));
        }

        $updateSchedule = (int) ($subscription['updateSchedule'] ?? SynchronizationSchedule::CUSTOM);
        if (!SynchronizationSchedule::isValid($updateSchedule)) {
            throw new InvalidArgumentException(sprintf(
                'The synchronization schedule for iCalendar subscription "%s" is invalid.',
                trim((string) ($subscription['name'] ?? ''))
            ));
        }
        $translationProfile = (int) ($subscription['translationProfile'] ?? CalendarEventTranslation::NONE);
        if (!CalendarEventTranslation::isValidProfile($translationProfile)) {
            throw new InvalidArgumentException(sprintf(
                'The title translation profile for iCalendar subscription "%s" is invalid.',
                trim((string) ($subscription['name'] ?? ''))
            ));
        }

        return [
            'id'                 => $id,
            'url'                => $url,
            'name'               => trim((string) ($subscription['name'] ?? '')),
            'username'           => trim((string) ($subscription['username'] ?? '')),
            'password'           => (string) ($subscription['password'] ?? ''),
            'color'              => $color,
            'translationProfile' => $translationProfile,
            'updateSchedule'     => $updateSchedule,
            'updateInterval'     => max(1, min(525600, (int) ($subscription['updateInterval'] ?? 15)))
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSubscription(string $reference): array
    {
        $reference = trim($reference);
        $prefix = 'urn:ips-kalender:ics-subscription:';
        $id = str_starts_with($reference, $prefix) ? substr($reference, strlen($prefix)) : $reference;
        if (isset($this->subscriptions[$id])) {
            return $this->subscriptions[$id];
        }

        if (preg_match('#^(?:https?|webcal)://#i', $reference) === 1) {
            $legacyId = hash('sha256', 'ics|' . $this->normalizeUrl($reference));
            if (isset($this->subscriptions[$legacyId])) {
                return $this->subscriptions[$legacyId];
            }
        }

        throw new ICalendarFeedProviderException('The selected iCalendar subscription is no longer available.');
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function provider(array $subscription): ICalendarFeedProvider
    {
        $provider = ($this->providerFactory)($subscription);
        if (!$provider instanceof ICalendarFeedProvider) {
            throw new ICalendarFeedProviderException('The iCalendar subscription provider could not be created.');
        }

        return $provider;
    }

    private function reference(string $id): string
    {
        return 'urn:ips-kalender:ics-subscription:' . $id;
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (str_starts_with(strtolower($url), 'webcal://')) {
            $url = 'https://' . substr($url, 9);
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException('An iCalendar subscription URL is invalid.');
        }

        return $url;
    }
}
