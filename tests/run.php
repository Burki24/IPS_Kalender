<?php

declare(strict_types=1);

use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\GoogleCalendarProvider;
use IPSKalender\ICalendarFeedProvider;
use IPSKalender\ICalendarFeedProviderException;
use IPSKalender\OAuthBridgeClient;

require_once __DIR__ . '/../libs/GoogleCalendarProvider.php';
require_once __DIR__ . '/../libs/ICalendarFeedProvider.php';
require_once __DIR__ . '/../libs/OAuthBridgeClient.php';

final class FakeHttpClient implements CalendarHttpClientInterface
{
    /** @var list<CalendarHttpResponse> */
    private array $responses;

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    /** @param list<CalendarHttpResponse> $responses */
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
        return array_shift($this->responses);
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

$oauthClient = new FakeHttpClient([
    response(200, [
        'access_token'  => 'new-access-token',
        'refresh_token' => 'new-refresh-token',
        'expires_in'    => 3600,
        'token_type'    => 'Bearer'
    ]),
    response(200, [
        'access_token' => 'refreshed-access-token',
        'expires_in'   => 3600,
        'token_type'   => 'Bearer'
    ])
]);
$bridge = new OAuthBridgeClient($oauthClient, 'ipskalender_google');
$tokens = $bridge->exchangeAuthorizationCode('authorization-code');
assertSameValue('new-refresh-token', $tokens['refreshToken'], 'The initial refresh token must be retained.');
$tokens = $bridge->refreshAccessToken('new-refresh-token');
assertSameValue('new-refresh-token', $tokens['refreshToken'], 'Refresh responses may omit an unchanged refresh token.');
assertTrueValue(str_contains($bridge->getAuthorizationUrl('User Name'), 'User%20Name'), 'The licensee must be URL encoded.');

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
$feedEvents = $provider->getEvents(
    $feedCalendars[0]['reference'],
    new DateTimeImmutable('2026-07-19T00:00:00Z'),
    new DateTimeImmutable('2026-07-22T00:00:00Z')
);
assertSameValue(1, count($feedEvents), 'Feed events outside the requested range must be excluded.');
assertSameValue('Included event', $feedEvents[0]['summary'], 'The event inside the range must be returned.');
assertSameValue('https://calendar.example/private.ics', $feedClient->requests[0]['url'], 'Webcal URLs must be fetched over HTTPS.');
try {
    $provider->createEvent($feedCalendars[0]['reference'], ['summary' => 'Not allowed']);
    throw new RuntimeException('The read-only feed unexpectedly accepted an event.');
} catch (ICalendarFeedProviderException $exception) {
    assertTrueValue(str_contains($exception->getMessage(), 'read-only'), 'Write attempts must explain the read-only limitation.');
}

echo "All IPS_Kalender tests passed.\n";
