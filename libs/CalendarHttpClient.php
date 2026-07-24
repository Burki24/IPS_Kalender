<?php

declare(strict_types=1);

namespace IPSKalender;

use RuntimeException;

require_once __DIR__ . '/CalDAVOriginPolicy.php';

final class CalendarHttpResponse
{
    /**
     * Represents an immutable HTTP response returned to calendar providers.
     *
     * @param array<string, string> $headers Normalized response headers keyed by lowercase name.
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $effectiveUrl
    ) {
    }
}

final class CalendarHttpException extends RuntimeException
{
}

interface CalendarHttpClientInterface
{
    /**
     * Executes an HTTP request and returns the normalized response.
     *
     * @param array<string, string> $headers Request headers keyed by header name.
     */
    public function request(string $method, string $url, array $headers = [], string $body = ''): CalendarHttpResponse;
}

final class CalendarHttpClient implements CalendarHttpClientInterface
{
    private const MAX_REDIRECTS = 5;

    /**
     * Creates an HTTP client for calendar provider requests.
     *
     * @param int                     $timeout      Request timeout in seconds.
     * @param bool                    $verifyTLS    Whether TLS certificates and hostnames must be verified.
     * @param string                  $username     Optional HTTP authentication username.
     * @param string                  $password     Optional HTTP authentication password.
     * @param CalDAVOriginPolicy|null $originPolicy Optional CalDAV trust policy used for guarded redirects.
     */
    public function __construct(
        private readonly int $timeout,
        private readonly bool $verifyTLS,
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly ?CalDAVOriginPolicy $originPolicy = null
    ) {
    }

    /**
     * Executes an HTTP request while enforcing the configured CalDAV redirect policy when present.
     *
     * @param array<string, string> $headers Request headers keyed by header name.
     */
    public function request(string $method, string $url, array $headers = [], string $body = ''): CalendarHttpResponse
    {
        if ($this->originPolicy === null) {
            return $this->executeRequest($method, $url, $headers, $body, true);
        }

        if (!$this->originPolicy->isAllowedUrl($url)) {
            throw new CalendarHttpException('The CalDAV request URL belongs to an untrusted origin.');
        }

        $currentUrl = $url;
        for ($redirectCount = 0; ; $redirectCount++) {
            $response = $this->executeRequest($method, $currentUrl, $headers, $body, false);
            if (!in_array($response->statusCode, [301, 302, 303, 307, 308], true)) {
                return $response;
            }

            $location = trim((string) ($response->headers['location'] ?? ''));
            if ($location === '') {
                return $response;
            }
            if ($redirectCount >= self::MAX_REDIRECTS) {
                throw new CalendarHttpException('Too many HTTP redirects.');
            }

            try {
                $redirectUrl = $this->originPolicy->resolveUrl(
                    $response->effectiveUrl !== '' ? $response->effectiveUrl : $currentUrl,
                    $location
                );
            } catch (\InvalidArgumentException $exception) {
                throw new CalendarHttpException('The CalDAV redirect URL is invalid.', 0, $exception);
            }

            if (!$this->originPolicy->isAllowedUrl($redirectUrl)) {
                throw new CalendarHttpException('A CalDAV redirect to an untrusted origin was blocked.');
            }

            $currentUrl = $redirectUrl;
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function executeRequest(
        string $method,
        string $url,
        array $headers,
        string $body,
        bool $followRedirects
    ): CalendarHttpResponse {
        if (!function_exists('curl_init')) {
            throw new CalendarHttpException('The PHP cURL extension is not available.');
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new CalendarHttpException('Could not initialize cURL.');
        }

        $responseHeaders = [];
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_URL             => $url,
            CURLOPT_CUSTOMREQUEST   => strtoupper($method),
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => $followRedirects,
            CURLOPT_MAXREDIRS       => self::MAX_REDIRECTS,
            CURLOPT_CONNECTTIMEOUT  => min($this->timeout, 15),
            CURLOPT_TIMEOUT         => $this->timeout,
            CURLOPT_SSL_VERIFYPEER  => $this->verifyTLS,
            CURLOPT_SSL_VERIFYHOST  => $this->verifyTLS ? 2 : 0,
            CURLOPT_HTTPHEADER      => $headerLines,
            CURLOPT_ENCODING        => '',
            CURLOPT_USERAGENT       => 'OpenCalendar/1.0',
            CURLOPT_HEADERFUNCTION  => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $trimmedLine = trim($line);

                if (str_starts_with($trimmedLine, 'HTTP/')) {
                    $responseHeaders = [];
                    return $length;
                }

                $separator = strpos($trimmedLine, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($trimmedLine, 0, $separator)));
                    $value = trim(substr($trimmedLine, $separator + 1));
                    $responseHeaders[$name] = $value;
                }

                return $length;
            }
        ];

        if ($body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        if ($this->username !== '') {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_ANY;
            $options[CURLOPT_USERPWD] = $this->username . ':' . $this->password;
        }

        if (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        curl_setopt_array($handle, $options);
        $responseBody = curl_exec($handle);

        if ($responseBody === false) {
            $message = curl_error($handle);
            $errorCode = curl_errno($handle);
            throw new CalendarHttpException(sprintf('HTTP request failed (%d): %s', $errorCode, $message));
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);

        return new CalendarHttpResponse($statusCode, $responseHeaders, (string) $responseBody, $effectiveUrl);
    }
}
