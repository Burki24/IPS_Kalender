<?php

declare(strict_types=1);

use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\CalendarEventTranslation;
use IPSKalender\GoogleCalendarProvider;
use IPSKalender\GoogleOAuthClient;
use IPSKalender\ICalendarCodec;
use IPSKalender\ICalendarFeedProvider;
use IPSKalender\ICalendarFeedProviderException;
use IPSKalender\ICalendarSubscriptionProvider;
use IPSKalender\SynchronizationSchedule;

require_once __DIR__ . '/../libs/GoogleCalendarProvider.php';
require_once __DIR__ . '/../libs/GoogleOAuthClient.php';
require_once __DIR__ . '/../libs/CalendarEventTranslation.php';
require_once __DIR__ . '/../libs/ICalendarFeedProvider.php';
require_once __DIR__ . '/../libs/ICalendarSubscriptionProvider.php';
require_once __DIR__ . '/../libs/SynchronizationSchedule.php';

final class FakeHttpClient implements CalendarHttpClientInterface
{
    /** @var list<CalendarHttpResponse|Throwable> */
    private array $responses;

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    /** @param list<CalendarHttpResponse|Throwable> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function request(string $method, string $url, array $headers = [], string $body = ''): CalendarHttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if ($this->responses === []) {
            throw new RuntimeException('No fake response was queued.');
        }
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}

function response(int $status, array|string $body = ''): CalendarHttpResponse
{
    return new CalendarHttpResponse(
        $status,
        [],
        is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body,
        'https://example.test'
    );
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . PHP_EOL
            . 'Expected: ' . var_export($expected, true) . PHP_EOL
            . 'Actual:   ' . var_export($actual, true));
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$calendarClient = new FakeHttpClient([
    response(200, [
        'items' => [
            [
                'id'              => 'owner@example.com',
                'summary'         => 'Primary',
                'backgroundColor' => '#1a73e8',
                'accessRole'      => 'owner',
                'primary'         => true
            ],
            [
                'id'         => 'availability@example.com',
                'summary'    => 'Availability',
                'accessRole' => 'freeBusyReader'
            ]
        ],
        'nextPageToken' => 'page-2'
    ]),
    response(200, [
        'items' => [[
            'id'              => 'shared@example.com',
            'summaryOverride' => 'Shared calendar',
            'backgroundColor' => '#34a853',
            'accessRole'      => 'reader'
        ]]
    ])
]);
$provider = new GoogleCalendarProvider($calendarClient, 'access-token');
$calendars = $provider->getCalendars();
assertSameValue(2, count($calendars), 'Calendar discovery must paginate and exclude free/busy-only entries.');
assertSameValue('owner@example.com', $calendars[0]['providerId'], 'The primary calendar must be listed first.');
assertSameValue(true, $calendars[0]['capabilities']['create'], 'Owners must have write access.');
assertSameValue(false, $calendars[1]['capabilities']['create'], 'Readers must not have write access.');
assertTrueValue(str_contains($calendarClient->requests[1]['url'], 'pageToken=page-2'), 'The second calendar page must be requested.');

$eventClient = new FakeHttpClient([
    response(200, [
        'timeZone' => 'Europe/Berlin',
        'items'    => [
            [
                'id'       => 'all-day-id',
                'iCalUID'  => 'all-day@example.com',
                'etag'     => '"etag-1"',
                'summary'  => 'Holiday',
                'status'   => 'confirmed',
                'start'    => ['date' => '2026-07-20'],
                'end'      => ['date' => '2026-07-21'],
                'htmlLink' => 'https://calendar.google.com/event?eid=1'
            ],
            [
                'id'               => 'instance-id',
                'iCalUID'          => 'series@example.com',
                'summary'           => 'Meeting',
                'status'            => 'confirmed',
                'recurringEventId'  => 'series-id',
                'start'             => ['dateTime' => '2026-07-20T10:00:00+02:00', 'timeZone' => 'Europe/Berlin'],
                'end'               => ['dateTime' => '2026-07-20T11:00:00+02:00', 'timeZone' => 'Europe/Berlin']
            ],
            [
                'id'     => 'deleted-id',
                'status' => 'cancelled',
                'start'  => ['date' => '2026-07-20']
            ]
        ]
    ])
]);
$provider = new GoogleCalendarProvider($eventClient, 'access-token');
$events = $provider->getEvents(
    'owner@example.com',
    new DateTimeImmutable('2026-07-19T00:00:00Z'),
    new DateTimeImmutable('2026-07-22T00:00:00Z')
);
assertSameValue(2, count($events), 'Cancelled events must be excluded.');
assertSameValue(true, $events[0]['allDay'], 'Google date values must map to all-day events.');
assertSameValue('2026-07-21', $events[0]['end'], 'The exclusive Google all-day end date must be retained.');
assertSameValue(true, $events[1]['recurring'], 'Expanded recurring instances must remain marked as recurring.');
assertSameValue('series-id', $events[1]['recurrenceId'], 'The recurring series ID must be retained.');
assertTrueValue(str_contains($eventClient->requests[0]['url'], 'owner%40example.com'), 'Calendar IDs must be URL encoded.');
assertSameValue('Bearer access-token', $eventClient->requests[0]['headers']['Authorization'], 'API requests must use Bearer authorization.');

$writeClient = new FakeHttpClient([
    response(200, ['id' => 'created-id', 'iCalUID' => 'created@example.com', 'etag' => '"new"']),
    response(200, ['id' => 'created-id', 'iCalUID' => 'created@example.com', 'etag' => '"updated"']),
    response(204)
]);
$provider = new GoogleCalendarProvider($writeClient, 'access-token');
$created = $provider->createEvent('owner@example.com', [
    'summary' => 'Test',
    'allDay'  => false,
    'start'   => '2026-07-20T10:00:00+02:00',
    'end'     => '2026-07-20T11:00:00+02:00',
    'location' => 'Berlin'
]);
assertSameValue('created-id', $created['eventReference'], 'The created Google event ID must be returned.');
assertSameValue('POST', $writeClient->requests[0]['method'], 'Events must be created via POST.');
$createBody = json_decode($writeClient->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
assertSameValue('Test', $createBody['summary'], 'The event summary must be sent.');
$provider->updateEvent(
    'owner@example.com',
    $created['resourceUrl'],
    '"new"',
    'created@example.com',
    ['summary' => 'Updated']
);
assertSameValue('PATCH', $writeClient->requests[1]['method'], 'Events must be updated without replacing unrelated Google fields.');
assertSameValue('"new"', $writeClient->requests[1]['headers']['If-Match'], 'Updates must use the ETag for conflict detection.');
assertTrueValue(
    $provider->deleteEvent('owner@example.com', $created['resourceUrl'], '"updated"'),
    'Event deletion must return true after HTTP 204.'
);
assertSameValue('DELETE', $writeClient->requests[2]['method'], 'Events must be deleted via DELETE.');

$personalOAuthHttpClient = new FakeHttpClient([
    response(200, [
        'access_token'  => 'personal-access-token',
        'refresh_token' => 'personal-refresh-token',
        'expires_in'    => 3600,
        'token_type'    => 'Bearer'
    ]),
    response(200, [
        'access_token' => 'personal-refreshed-access-token',
        'expires_in'   => 1800,
        'token_type'   => 'Bearer'
    ])
]);
$personalOAuth = new GoogleOAuthClient(
    $personalOAuthHttpClient,
    'personal-client-id',
    'personal-client-secret',
    'https://example.ipmagic.de/hook/ips-kalender-google-12345'
);
$authorizationUrl = $personalOAuth->getAuthorizationUrl('secure-state');
$authorizationQuery = [];
parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $authorizationQuery);
assertSameValue('personal-client-id', $authorizationQuery['client_id'], 'Personal OAuth must use the configured client ID.');
assertSameValue(
    'https://example.ipmagic.de/hook/ips-kalender-google-12345',
    $authorizationQuery['redirect_uri'],
    'Personal OAuth must use the instance-specific Symcon Connect callback.'
);
assertSameValue('secure-state', $authorizationQuery['state'], 'Personal OAuth must protect the callback with state.');
assertSameValue('offline', $authorizationQuery['access_type'], 'Personal OAuth must request offline access.');
assertSameValue('consent', $authorizationQuery['prompt'], 'Personal OAuth must request consent so Google returns a refresh token.');
assertTrueValue(
    str_contains($authorizationQuery['scope'], 'calendar.calendarlist.readonly')
        && str_contains($authorizationQuery['scope'], 'calendar.events'),
    'Personal OAuth must request calendar discovery and event access.'
);
assertTrueValue(
    !str_contains($authorizationUrl, 'personal-client-secret'),
    'The client secret must never be included in the authorization URL.'
);

$personalTokens = $personalOAuth->exchangeAuthorizationCode('personal-authorization-code');
assertSameValue('personal-refresh-token', $personalTokens['refreshToken'], 'The authorization exchange must retain the refresh token.');
$tokenRequest = $personalOAuthHttpClient->requests[0];
$tokenBody = [];
parse_str($tokenRequest['body'], $tokenBody);
assertSameValue('POST', $tokenRequest['method'], 'The authorization code must be exchanged via POST.');
assertSameValue('authorization_code', $tokenBody['grant_type'], 'The token exchange must use the authorization-code grant.');
assertSameValue('personal-client-secret', $tokenBody['client_secret'], 'The token exchange must authenticate the personal client.');
assertSameValue(
    'https://example.ipmagic.de/hook/ips-kalender-google-12345',
    $tokenBody['redirect_uri'],
    'The token exchange must repeat the exact callback URI.'
);

$personalTokens = $personalOAuth->refreshAccessToken('personal-refresh-token');
assertSameValue(
    'personal-refresh-token',
    $personalTokens['refreshToken'],
    'A refresh response may omit an unchanged personal refresh token.'
);
$refreshBody = [];
parse_str($personalOAuthHttpClient->requests[1]['body'], $refreshBody);
assertSameValue('refresh_token', $refreshBody['grant_type'], 'Token renewal must use the refresh-token grant.');
assertSameValue('personal-refresh-token', $refreshBody['refresh_token'], 'Token renewal must send the stored refresh token.');

$icalFeed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "X-WR-CALNAME:Google Privat\r\n"
    . "X-APPLE-CALENDAR-COLOR:#34AADCFF\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:inside@example.com\r\n"
    . "DTSTART:20260720T080000Z\r\n"
    . "DTEND:20260720T090000Z\r\n"
    . "SUMMARY:Included event\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:outside@example.com\r\n"
    . "DTSTART:20260820T080000Z\r\n"
    . "DTEND:20260820T090000Z\r\n"
    . "SUMMARY:Excluded event\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";
$feedClient = new FakeHttpClient([
    new CalendarHttpResponse(200, ['etag' => '"feed-1"'], $icalFeed, 'https://calendar.example/private.ics'),
    new CalendarHttpResponse(200, ['etag' => '"feed-1"'], $icalFeed, 'https://calendar.example/private.ics')
]);
$provider = new ICalendarFeedProvider($feedClient, 'webcal://calendar.example/private.ics');
$feedCalendars = $provider->getCalendars();
assertSameValue('Google Privat', $feedCalendars[0]['name'], 'The feed calendar name must be read from X-WR-CALNAME.');
assertSameValue('#34AADC', $feedCalendars[0]['color'], 'Eight-digit feed colors must be normalized.');
assertSameValue(false, $feedCalendars[0]['capabilities']['create'], 'iCalendar subscriptions must be read-only.');
assertSameValue('', $feedCalendars[0]['url'], 'Secret feed URLs must not be copied into child instance properties.');
$feedEvents = $provider->getEvents(
    $feedCalendars[0]['reference'],
    new DateTimeImmutable('2026-07-19T00:00:00Z'),
    new DateTimeImmutable('2026-07-22T00:00:00Z')
);
assertSameValue(1, count($feedEvents), 'Feed events outside the requested range must be excluded.');
assertSameValue('Included event', $feedEvents[0]['summary'], 'The event inside the range must be returned.');
assertTrueValue(
    !str_contains($feedEvents[0]['resourceUrl'], 'private.ics'),
    'Secret feed URLs must not be copied into event data.'
);
assertSameValue('https://calendar.example/private.ics', $feedClient->requests[0]['url'], 'Webcal URLs must be fetched over HTTPS.');
try {
    $provider->createEvent($feedCalendars[0]['reference'], ['summary' => 'Not allowed']);
    throw new RuntimeException('The read-only feed unexpectedly accepted an event.');
} catch (ICalendarFeedProviderException $exception) {
    assertTrueValue(str_contains($exception->getMessage(), 'read-only'), 'Write attempts must explain the read-only limitation.');
}

$persistentFeedCache = [];
$conditionalClient = new FakeHttpClient([
    new CalendarHttpResponse(
        200,
        [
            'etag'          => '"feed-cache-1"',
            'last-modified' => 'Fri, 24 Jul 2026 07:00:00 GMT'
        ],
        $icalFeed,
        'https://calendar.example/cached.ics'
    )
]);
$conditionalProvider = new ICalendarFeedProvider(
    $conditionalClient,
    'https://calendar.example/cached.ics',
    '',
    [],
    static function (array $cacheState) use (&$persistentFeedCache): void {
        $persistentFeedCache = $cacheState;
    }
);
$conditionalProvider->getCalendars();
assertSameValue('"feed-cache-1"', $persistentFeedCache['etag'], 'The feed ETag must be cached.');
assertSameValue(
    'Fri, 24 Jul 2026 07:00:00 GMT',
    $persistentFeedCache['lastModified'],
    'The Last-Modified validator must be cached.'
);
assertTrueValue($persistentFeedCache['lastDownload'] > 0, 'The successful download time must be cached.');
$initialChangeTimestamp = $persistentFeedCache['lastChange'];

$notModifiedClient = new FakeHttpClient([
    new CalendarHttpResponse(304, [], '', 'https://calendar.example/cached.ics')
]);
$notModifiedProvider = new ICalendarFeedProvider(
    $notModifiedClient,
    'https://calendar.example/cached.ics',
    '',
    $persistentFeedCache,
    static function (array $cacheState) use (&$persistentFeedCache): void {
        $persistentFeedCache = $cacheState;
    }
);
$notModifiedEvents = $notModifiedProvider->getEvents(
    'https://calendar.example/cached.ics',
    new DateTimeImmutable('2026-07-19T00:00:00Z'),
    new DateTimeImmutable('2026-07-22T00:00:00Z')
);
assertSameValue(1, count($notModifiedEvents), 'HTTP 304 must reuse the cached feed body.');
assertSameValue(
    '"feed-cache-1"',
    $notModifiedClient->requests[0]['headers']['If-None-Match'] ?? '',
    'A cached ETag must be sent with the next request.'
);
assertSameValue(
    'Fri, 24 Jul 2026 07:00:00 GMT',
    $notModifiedClient->requests[0]['headers']['If-Modified-Since'] ?? '',
    'A cached Last-Modified value must be sent with the next request.'
);
assertSameValue(
    $initialChangeTimestamp,
    $persistentFeedCache['lastChange'],
    'HTTP 304 must not change the last content change timestamp.'
);
assertSameValue(false, $persistentFeedCache['stale'], 'HTTP 304 is a successful cache validation.');

$invalidRefreshClient = new FakeHttpClient([
    new CalendarHttpResponse(200, [], '<html>Temporary error</html>', 'https://calendar.example/cached.ics')
]);
$invalidRefreshProvider = new ICalendarFeedProvider(
    $invalidRefreshClient,
    'https://calendar.example/cached.ics',
    '',
    $persistentFeedCache,
    static function (array $cacheState) use (&$persistentFeedCache): void {
        $persistentFeedCache = $cacheState;
    }
);
$fallbackEvents = $invalidRefreshProvider->getEvents(
    'https://calendar.example/cached.ics',
    new DateTimeImmutable('2026-07-19T00:00:00Z'),
    new DateTimeImmutable('2026-07-22T00:00:00Z')
);
assertSameValue(1, count($fallbackEvents), 'An invalid replacement must not overwrite the last valid feed.');
assertSameValue(true, $persistentFeedCache['stale'], 'Fallback data must be marked as stale.');
assertTrueValue(
    str_contains($persistentFeedCache['lastError'], 'not a valid iCalendar feed'),
    'The cache must retain the reason for using stale data.'
);

$temporaryFailureClient = new FakeHttpClient([
    new RuntimeException('Temporary network outage'),
    new RuntimeException('Temporary network outage')
]);
$temporaryFailureProvider = new ICalendarFeedProvider(
    $temporaryFailureClient,
    'https://calendar.example/cached.ics',
    '',
    $persistentFeedCache
);
assertSameValue(
    1,
    count($temporaryFailureProvider->getEvents(
        'https://calendar.example/cached.ics',
        new DateTimeImmutable('2026-07-19T00:00:00Z'),
        new DateTimeImmutable('2026-07-22T00:00:00Z')
    )),
    'A temporary transport failure must use the last valid feed.'
);
try {
    $temporaryFailureProvider->testConnection();
    throw new RuntimeException('The connection test unexpectedly hid a transport failure behind cached data.');
} catch (ICalendarFeedProviderException $exception) {
    assertTrueValue(
        str_contains($exception->getMessage(), 'Temporary network outage'),
        'Connection tests must report current transport failures.'
    );
}

$translationInput = [
    ['summary' => 'New Moon'],
    ['summary' => 'First quarter 11:06am'],
    ['summary' => 'Full Moon 1:30pm'],
    ['summary' => 'Third Quarter 12:05am'],
    ['summary' => 'Day 205 of 2026'],
    ['summary' => 'Team meeting']
];
assertSameValue(
    $translationInput,
    CalendarEventTranslation::translateEvents($translationInput, CalendarEventTranslation::NONE),
    'The disabled translation profile must leave all event data unchanged.'
);
$translatedEvents = CalendarEventTranslation::translateEvents(
    $translationInput,
    CalendarEventTranslation::GOOGLE_PUBLIC_CALENDARS_GERMAN
);
assertSameValue('Neumond', $translatedEvents[0]['summary'], 'New Moon must be translated.');
assertSameValue('Erstes Viertel 11:06 Uhr', $translatedEvents[1]['summary'], 'AM times must use German notation.');
assertSameValue('Vollmond 13:30 Uhr', $translatedEvents[2]['summary'], 'PM times must use 24-hour notation.');
assertSameValue('Letztes Viertel 00:05 Uhr', $translatedEvents[3]['summary'], 'Third-quarter midnight times must be converted correctly.');
assertSameValue('Tag 205 von 2026', $translatedEvents[4]['summary'], 'Day-of-year titles must be translated.');
assertSameValue('Full Moon 1:30pm', $translatedEvents[2]['originalSummary'], 'Translated events must retain their original title.');
assertSameValue('Team meeting', $translatedEvents[5]['summary'], 'Unrecognized titles must remain unchanged.');
assertTrueValue(
    !isset($translatedEvents[5]['originalSummary']),
    'Unchanged events must not receive an original title field.'
);

$secondIcalFeed = str_replace(
    ['Google Privat', '#34AADCFF', 'inside@example.com', 'Included event'],
    ['Moon phases', '#6D3A38FF', 'moon@example.com', 'First quarter 11:06am'],
    $icalFeed
);
$subscriptionFactoryCalls = [];
$subscriptionProvider = new ICalendarSubscriptionProvider(
    [
        [
            'url'            => 'https://calendar.example/private.ics',
            'name'           => 'Private',
            'username'       => '',
            'password'       => '',
            'color'          => '#112233',
            'updateSchedule' => SynchronizationSchedule::HOURLY,
            'updateInterval' => 15
        ],
        [
            'url'                => 'https://calendar.example/waste.ics',
            'name'               => 'Waste',
            'username'           => 'feed-user',
            'password'           => 'feed-password',
            'color'              => '',
            'translationProfile' => CalendarEventTranslation::GOOGLE_PUBLIC_CALENDARS_GERMAN,
            'updateSchedule'     => SynchronizationSchedule::WEEKLY,
            'updateInterval'     => 15
        ]
    ],
    static function (array $subscription) use (
        &$subscriptionFactoryCalls,
        $icalFeed,
        $secondIcalFeed
    ): ICalendarFeedProvider {
        $subscriptionFactoryCalls[] = $subscription;
        $body = str_contains((string) $subscription['url'], 'waste.ics') ? $secondIcalFeed : $icalFeed;

        return new ICalendarFeedProvider(
            new FakeHttpClient([
                new CalendarHttpResponse(200, [], $body, (string) $subscription['url'])
            ]),
            (string) $subscription['url'],
            (string) $subscription['name']
        );
    }
);
$subscriptionCalendars = $subscriptionProvider->getCalendars();
assertSameValue(2, count($subscriptionCalendars), 'All active iCalendar subscriptions must be exposed as calendars.');
assertSameValue('Private', $subscriptionCalendars[0]['name'], 'A configured subscription name must override the feed name.');
assertSameValue('#112233', $subscriptionCalendars[0]['color'], 'A configured subscription color must override the feed color.');
assertSameValue(
    SynchronizationSchedule::WEEKLY,
    $subscriptionCalendars[1]['updateSchedule'],
    'The subscription schedule must be passed to the calendar configurator.'
);
assertTrueValue(
    !str_contains($subscriptionCalendars[1]['reference'], 'waste.ics'),
    'Subscription references must not expose secret feed URLs.'
);
$subscriptionEvents = $subscriptionProvider->getEvents(
    $subscriptionCalendars[1]['reference'],
    new DateTimeImmutable('2026-07-19T00:00:00Z'),
    new DateTimeImmutable('2026-07-22T00:00:00Z')
);
assertSameValue(1, count($subscriptionEvents), 'The selected subscription must return its own events.');
assertSameValue(
    'Erstes Viertel 11:06 Uhr',
    $subscriptionEvents[0]['summary'],
    'Calendar references must be routed through the selected title translation profile.'
);
assertSameValue(
    'First quarter 11:06am',
    $subscriptionEvents[0]['originalSummary'],
    'Translated subscription events must preserve their original title.'
);
assertSameValue(
    'feed-user',
    $subscriptionFactoryCalls[2]['username'],
    'Per-subscription credentials must be passed only to the selected feed provider.'
);
$subscriptionConnection = $subscriptionProvider->testConnection();
assertSameValue(2, $subscriptionConnection['calendarCount'], 'A connection test must include every subscription.');
assertSameValue(4, $subscriptionConnection['eventCount'], 'A connection test must total all feed events.');
try {
    new ICalendarSubscriptionProvider(
        [
            ['url' => 'https://calendar.example/duplicate.ics'],
            ['url' => 'https://calendar.example/duplicate.ics']
        ],
        static fn(array $subscription): ICalendarFeedProvider => new ICalendarFeedProvider(
            new FakeHttpClient([]),
            (string) $subscription['url']
        )
    );
    throw new RuntimeException('Duplicate iCalendar subscriptions were unexpectedly accepted.');
} catch (InvalidArgumentException $exception) {
    assertTrueValue(
        str_contains($exception->getMessage(), 'more than once'),
        'Duplicate subscription URLs must produce an actionable validation error.'
    );
}
try {
    new ICalendarSubscriptionProvider(
        [[
            'url'                => 'https://calendar.example/invalid-translation.ics',
            'translationProfile' => 999
        ]],
        static fn(array $subscription): ICalendarFeedProvider => new ICalendarFeedProvider(
            new FakeHttpClient([]),
            (string) $subscription['url']
        )
    );
    throw new RuntimeException('An invalid title translation profile was unexpectedly accepted.');
} catch (InvalidArgumentException $exception) {
    assertTrueValue(
        str_contains($exception->getMessage(), 'translation profile'),
        'Invalid title translation profiles must produce an actionable validation error.'
    );
}

$recurringFeed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:weekly-series@example.com\r\n"
    . "DTSTART;TZID=Europe/Berlin:20260323T100000\r\n"
    . "DTEND;TZID=Europe/Berlin:20260323T110000\r\n"
    . "RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=4\r\n"
    . "EXDATE;TZID=Europe/Berlin:20260406T100000\r\n"
    . "RDATE;TZID=Europe/Berlin:20260408T100000\r\n"
    . "SUMMARY:Weekly meeting\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:weekly-series@example.com\r\n"
    . "RECURRENCE-ID;TZID=Europe/Berlin:20260330T100000\r\n"
    . "DTSTART;TZID=Europe/Berlin:20260331T140000\r\n"
    . "DTEND;TZID=Europe/Berlin:20260331T150000\r\n"
    . "SEQUENCE:2\r\n"
    . "SUMMARY:Moved meeting\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";
$recurringEvents = ICalendarCodec::parseEventsInRange(
    $recurringFeed,
    'https://calendar.example/recurring.ics',
    '"series"',
    new DateTimeImmutable('2026-03-20T00:00:00Z'),
    new DateTimeImmutable('2026-04-20T00:00:00Z')
);
assertSameValue(4, count($recurringEvents), 'RRULE, EXDATE, RDATE and moved overrides must form one recurrence set.');
assertSameValue('2026-03-23T10:00:00+01:00', $recurringEvents[0]['start'], 'The first occurrence must use winter time.');
assertSameValue('Moved meeting', $recurringEvents[1]['summary'], 'A RECURRENCE-ID override must replace its generated occurrence.');
assertSameValue('2026-03-31T14:00:00+02:00', $recurringEvents[1]['start'], 'Moved occurrences must retain their actual local time.');
assertSameValue('2026-04-08T10:00:00+02:00', $recurringEvents[2]['start'], 'RDATE must add an occurrence.');
assertSameValue('2026-04-13T10:00:00+02:00', $recurringEvents[3]['start'], 'Weekly recurrences must preserve wall time after DST.');
assertSameValue(true, $recurringEvents[3]['recurring'], 'Generated recurrence instances must be marked as recurring.');

$monthlyFeed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:first-monday@example.com\r\n"
    . "DTSTART;TZID=Europe/Berlin:20260105T090000\r\n"
    . "DTEND;TZID=Europe/Berlin:20260105T100000\r\n"
    . "RRULE:FREQ=MONTHLY;BYDAY=1MO;COUNT=3\r\n"
    . "SUMMARY:First Monday\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:last-workday@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260130\r\n"
    . "DTEND;VALUE=DATE:20260131\r\n"
    . "RRULE:FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1;COUNT=3\r\n"
    . "SUMMARY:Last workday\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";
$monthlyEvents = ICalendarCodec::parseEventsInRange(
    $monthlyFeed,
    'https://calendar.example/monthly.ics',
    '',
    new DateTimeImmutable('2026-01-01T00:00:00Z'),
    new DateTimeImmutable('2026-04-02T00:00:00Z')
);
$firstMondayDates = array_values(array_map(
    static fn(array $event): string => substr((string) $event['start'], 0, 10),
    array_filter($monthlyEvents, static fn(array $event): bool => $event['uid'] === 'first-monday@example.com')
));
$lastWorkdayDates = array_values(array_map(
    static fn(array $event): string => (string) $event['start'],
    array_filter($monthlyEvents, static fn(array $event): bool => $event['uid'] === 'last-workday@example.com')
));
assertSameValue(
    ['2026-01-05', '2026-02-02', '2026-03-02'],
    $firstMondayDates,
    'Ordinal BYDAY rules must generate the first Monday of each month.'
);
assertSameValue(
    ['2026-01-30', '2026-02-27', '2026-03-31'],
    $lastWorkdayDates,
    'BYSETPOS=-1 must select the final matching weekday of each month.'
);

$advancedFeed = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:daily-until@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260701\r\n"
    . "DTEND;VALUE=DATE:20260702\r\n"
    . "RRULE:FREQ=DAILY;UNTIL=20260703\r\n"
    . "SUMMARY:Daily until\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:daily-until@example.com\r\n"
    . "RECURRENCE-ID;VALUE=DATE:20260702\r\n"
    . "DTSTART;VALUE=DATE:20260702\r\n"
    . "DTEND;VALUE=DATE:20260703\r\n"
    . "STATUS:CANCELLED\r\n"
    . "SUMMARY:Cancelled day\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:last-month-day@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260131\r\n"
    . "DTEND;VALUE=DATE:20260201\r\n"
    . "RRULE:FREQ=MONTHLY;BYMONTHDAY=-1;COUNT=3\r\n"
    . "SUMMARY:Month end\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:yearly-sunday@example.com\r\n"
    . "DTSTART;VALUE=DATE:20260329\r\n"
    . "DTEND;VALUE=DATE:20260330\r\n"
    . "RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU;COUNT=2\r\n"
    . "SUMMARY:Last Sunday in March\r\n"
    . "END:VEVENT\r\n"
    . "BEGIN:VEVENT\r\n"
    . "UID:duration@example.com\r\n"
    . "DTSTART;TZID=Europe/Berlin:20260705T100000\r\n"
    . "DURATION:PT1H30M\r\n"
    . "SUMMARY:Duration event\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";
$advancedEvents = ICalendarCodec::parseEventsInRange(
    $advancedFeed,
    'https://calendar.example/advanced.ics',
    '',
    new DateTimeImmutable('2026-01-01T00:00:00Z'),
    new DateTimeImmutable('2028-01-01T00:00:00Z')
);
$dailyDates = array_values(array_map(
    static fn(array $event): string => (string) $event['start'],
    array_filter($advancedEvents, static fn(array $event): bool => $event['uid'] === 'daily-until@example.com')
));
$monthEndDates = array_values(array_map(
    static fn(array $event): string => (string) $event['start'],
    array_filter($advancedEvents, static fn(array $event): bool => $event['uid'] === 'last-month-day@example.com')
));
$yearlyDates = array_values(array_map(
    static fn(array $event): string => (string) $event['start'],
    array_filter($advancedEvents, static fn(array $event): bool => $event['uid'] === 'yearly-sunday@example.com')
));
assertSameValue(['2026-07-01', '2026-07-03'], $dailyDates, 'UNTIL must be inclusive and cancelled overrides must remove occurrences.');
assertSameValue(['2026-01-31', '2026-02-28', '2026-03-31'], $monthEndDates, 'Negative BYMONTHDAY values must count from month end.');
assertSameValue(['2026-03-29', '2027-03-28'], $yearlyDates, 'Yearly ordinal BYDAY rules must be expanded.');
$durationEvents = array_values(array_filter(
    $advancedEvents,
    static fn(array $event): bool => $event['uid'] === 'duration@example.com'
));
assertSameValue('2026-07-05T11:30:00+02:00', $durationEvents[0]['end'], 'DURATION must define the event end when DTEND is absent.');

assertSameValue(
    604800000,
    SynchronizationSchedule::timerInterval(SynchronizationSchedule::WEEKLY, 15),
    'Weekly synchronization must use a safe direct timer interval.'
);
assertSameValue(
    86400000,
    SynchronizationSchedule::timerInterval(SynchronizationSchedule::MONTHLY, 15),
    'Monthly synchronization must use a daily due-date timer.'
);
assertSameValue(
    0,
    SynchronizationSchedule::timerInterval(SynchronizationSchedule::MANUAL, 15),
    'Manual synchronization must disable the timer.'
);
$lastSynchronization = (new DateTimeImmutable('2026-01-15T12:00:00Z'))->getTimestamp();
assertSameValue(
    false,
    SynchronizationSchedule::isDue(
        SynchronizationSchedule::MONTHLY,
        15,
        $lastSynchronization,
        (new DateTimeImmutable('2026-02-15T11:59:59Z'))->getTimestamp()
    ),
    'Monthly synchronization must not run before the next month is reached.'
);
assertSameValue(
    true,
    SynchronizationSchedule::isDue(
        SynchronizationSchedule::MONTHLY,
        15,
        $lastSynchronization,
        (new DateTimeImmutable('2026-02-15T12:00:00Z'))->getTimestamp()
    ),
    'Monthly synchronization must become due after one calendar month.'
);
assertSameValue(
    false,
    SynchronizationSchedule::isDue(SynchronizationSchedule::MANUAL, 15, 0),
    'Manual synchronization must never be triggered by the scheduler.'
);

$calendarModuleSource = file_get_contents(__DIR__ . '/../Kalender/module.php');
$accountModuleSource = file_get_contents(__DIR__ . '/../Kalender Konto/module.php');
$viewModuleSource = file_get_contents(__DIR__ . '/../Kalender Ansicht/module.php');
$viewTemplateSource = file_get_contents(__DIR__ . '/../Kalender Ansicht/module.html');
assertTrueValue(
    is_string($accountModuleSource)
        && str_contains($accountModuleSource, "RegisterPropertyString('GoogleClientID'")
        && str_contains($accountModuleSource, "RegisterPropertyString('GoogleClientSecret'")
        && str_contains($accountModuleSource, 'RegisterHook($this->googleOAuthHookAddress())')
        && str_contains($accountModuleSource, 'protected function ProcessHookData(): void')
        && !str_contains($accountModuleSource, "RegisterOAuth('ipskalender_google')"),
    'The calendar account must use a personal Google OAuth client with an instance-specific webhook.'
);
assertTrueValue(
    is_string($calendarModuleSource)
        && str_contains($calendarModuleSource, 'RegisterMessage(0, IPS_KERNELSTARTED)')
        && str_contains($calendarModuleSource, "RegisterTimer('InitializationTimer'")
        && str_contains($calendarModuleSource, "RegisterAttributeBoolean('RuntimeReady', false)")
        && str_contains($calendarModuleSource, 'IPS_GetKernelRunlevel() !== KR_READY'),
    'The calendar module must defer parent communication until the kernel is ready.'
);
assertTrueValue(
    is_string($viewModuleSource)
        && str_contains($viewModuleSource, 'RegisterMessage(0, IPS_KERNELSTARTED)')
        && str_contains($viewModuleSource, "RegisterTimer('InitializationTimer'")
        && str_contains($viewModuleSource, "RegisterAttributeBoolean('RuntimeReady', false)")
        && str_contains($viewModuleSource, 'IPS_GetKernelRunlevel() !== KR_READY'),
    'The calendar view must defer cross-instance access until the kernel is ready.'
);
assertTrueValue(
    is_string($viewTemplateSource)
        && str_contains($viewTemplateSource, "t('CW')")
        && str_contains($viewTemplateSource, 'isoWeekNumber(start)')
        && str_contains($viewTemplateSource, 'Date.UTC'),
    'The weekly tile and IPSView title must include an ISO calendar week.'
);
assertTrueValue(
    is_string($viewModuleSource)
        && str_contains($viewModuleSource, "RegisterPropertyBoolean('ShowDayOfYear', true)")
        && is_string($viewTemplateSource)
        && str_contains($viewTemplateSource, 'formatDayHeading(')
        && str_contains($viewTemplateSource, 'dayOfYear(date)')
        && str_contains($viewTemplateSource, 'daysInYear(date)'),
    'Agenda, three-day and weekly headings must optionally show the day of year.'
);

echo "All IPS_Kalender tests passed.\n";
