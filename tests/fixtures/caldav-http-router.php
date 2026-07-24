<?php

declare(strict_types=1);

$logFile = getenv('CALDAV_TEST_LOG') ?: '';
$secondaryPort = (int) (getenv('CALDAV_TEST_SECONDARY_PORT') ?: 0);
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/health') {
    http_response_code(200);
    echo 'ok';
    return;
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$expectedAuthorization = 'Basic ' . base64_encode('caldav-user:caldav-password');
if ($authorization !== $expectedAuthorization) {
    header('WWW-Authenticate: Basic realm="OpenCalendar test"');
    http_response_code(401);
    echo 'Authentication required';
    return;
}

if ($logFile !== '') {
    $entry = [
        'path'          => $path,
        'method'        => $_SERVER['REQUEST_METHOD'] ?? '',
        'authorization' => $authorization,
        'body'          => file_get_contents('php://input') ?: ''
    ];
    file_put_contents($logFile, json_encode($entry, JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

switch ($path) {
    case '/redirect-safe':
        header('Location: /sink');
        http_response_code(302);
        echo 'redirect';
        break;

    case '/redirect-untrusted':
        if ($secondaryPort <= 0) {
            http_response_code(500);
            echo 'Secondary port missing';
            break;
        }
        header('Location: http://127.0.0.1:' . $secondaryPort . '/sink');
        http_response_code(302);
        echo 'redirect';
        break;

    case '/redirect-loop':
        header('Location: /redirect-loop');
        http_response_code(302);
        echo 'redirect';
        break;

    case '/sink':
        header('Content-Type: application/xml; charset=utf-8');
        http_response_code(207);
        echo '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"/>';
        break;

    default:
        http_response_code(404);
        echo 'Not found';
        break;
}
