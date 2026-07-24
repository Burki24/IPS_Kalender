<?php

declare(strict_types=1);

use IPSKalender\CalDAVOriginPolicy;
use IPSKalender\CalendarHttpClient;
use IPSKalender\CalendarHttpException;

require_once __DIR__ . '/../libs/CalendarHttpClient.php';

function assertHttpSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . PHP_EOL
            . 'Expected: ' . var_export($expected, true) . PHP_EOL
            . 'Actual:   ' . var_export($actual, true)
        );
    }
}

function assertHttpTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function readHttpLog(string $filename): array
{
    if (!is_file($filename)) {
        return [];
    }

    $entries = [];
    foreach (file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $entries[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }

    return $entries;
}

if (!function_exists('curl_init')) {
    throw new RuntimeException('The CalDAV HTTP integration test requires the PHP cURL extension.');
}

$primaryUrl = rtrim((string) ($argv[1] ?? ''), '/');
$secondaryUrl = rtrim((string) ($argv[2] ?? ''), '/');
$primaryLog = (string) ($argv[3] ?? '');
$secondaryLog = (string) ($argv[4] ?? '');
if ($primaryUrl === '' || $secondaryUrl === '' || $primaryLog === '' || $secondaryLog === '') {
    throw new RuntimeException('Usage: php tests/caldav-http.php PRIMARY_URL SECONDARY_URL PRIMARY_LOG SECONDARY_LOG');
}

$policy = new CalDAVOriginPolicy($primaryUrl . '/');
$client = new CalendarHttpClient(5, true, 'caldav-user', 'caldav-password', $policy);

// A same-origin redirect must preserve the DAV method, credentials and request body.
$body = 'sensitive-calendar-content';
$response = $client->request(
    'PUT',
    $primaryUrl . '/redirect-safe',
    ['Content-Type' => 'text/calendar; charset=utf-8'],
    $body
);
assertHttpSame(207, $response->statusCode, 'A trusted redirect must reach the final DAV endpoint.');
assertHttpSame($primaryUrl . '/sink', $response->effectiveUrl, 'The final effective URL must point to the trusted redirect target.');
$primaryEntries = readHttpLog($primaryLog);
assertHttpSame(2, count($primaryEntries), 'The primary server must see the authorized redirect request and the final request.');
assertHttpSame('/redirect-safe', $primaryEntries[0]['path'], 'The first authorized request must target the redirect endpoint.');
assertHttpSame('/sink', $primaryEntries[1]['path'], 'The second authorized request must target the trusted sink.');
assertHttpSame('PUT', $primaryEntries[1]['method'], 'A CalDAV PUT must remain a PUT across a trusted redirect.');
assertHttpSame($body, $primaryEntries[1]['body'], 'A trusted redirect must preserve the CalDAV request body.');
assertHttpSame(
    'Basic ' . base64_encode('caldav-user:caldav-password'),
    $primaryEntries[1]['authorization'],
    'The trusted redirect target must receive the configured CalDAV credentials.'
);

// A redirect to another port is another origin and must be blocked before cURL contacts it.
try {
    $client->request('PUT', $primaryUrl . '/redirect-untrusted', [], 'must-not-leak');
    throw new RuntimeException('The untrusted redirect unexpectedly succeeded.');
} catch (CalendarHttpException $exception) {
    assertHttpTrue(
        str_contains($exception->getMessage(), 'untrusted origin'),
        'The HTTP client must explain that the redirect origin was blocked.'
    );
}
assertHttpSame([], readHttpLog($secondaryLog), 'The untrusted redirect target must never receive credentials or request content.');

// Direct requests outside the configured origin must be rejected before any network access.
try {
    $client->request('GET', $secondaryUrl . '/sink');
    throw new RuntimeException('A direct request to an untrusted origin unexpectedly succeeded.');
} catch (CalendarHttpException $exception) {
    assertHttpTrue(
        str_contains($exception->getMessage(), 'untrusted origin'),
        'Direct cross-origin CalDAV requests must be blocked.'
    );
}
assertHttpSame([], readHttpLog($secondaryLog), 'A directly supplied untrusted URL must not be contacted.');

// Redirect loops must stop deterministically.
try {
    $client->request('PROPFIND', $primaryUrl . '/redirect-loop', ['Depth' => '0'], '<propfind/>');
    throw new RuntimeException('The redirect loop unexpectedly succeeded.');
} catch (CalendarHttpException $exception) {
    assertHttpTrue(
        str_contains($exception->getMessage(), 'Too many HTTP redirects'),
        'Redirect loops must stop at the configured limit.'
    );
}

echo "All CalDAV HTTP integration tests passed.\n";
