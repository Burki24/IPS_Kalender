<?php

declare(strict_types=1);

use IPSKalender\CalendarHttpClientInterface;
use IPSKalender\CalendarHttpResponse;
use IPSKalender\GoogleCalendarProvider;
use IPSKalender\ICalendarCodec;
use IPSKalender\ICalendarFeedProvider;
use IPSKalender\ICalendarFeedProviderException;
use IPSKalender\OAuthBridgeClient;
use IPSKalender\SynchronizationSchedule;

require_once __DIR__ . '/../libs/GoogleCalendarProvider.php';
require_once __DIR__ . '/../libs/ICalendarFeedProvider.php';
require_once __DIR__ . '/../libs/OAuthBridgeClient.php';
require_once __DIR__ . '/../libs/SynchronizationSchedule.php';

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

echo "All IPS_Kalender tests passed.\n";
