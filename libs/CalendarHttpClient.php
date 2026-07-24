<?php

declare(strict_types=1);

namespace IPSKalender;

use RuntimeException;

final class CalendarHttpResponse
{
    /**
     * @param array<string, string> $headers
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
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $headers = [], string $body = ''): CalendarHttpResponse;
}

final class CalendarHttpClient implements CalendarHttpClientInterface
{
    public function __construct(
        private readonly int $timeout,
        private readonly bool $verifyTLS,
        private readonly string $username = '',
        private readonly string $password = ''
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $headers = [], string $body = ''): CalendarHttpResponse
    {
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
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 5,
            CURLOPT_CONNECTTIMEOUT  => min($this->timeout, 15),
            CURLOPT_TIMEOUT         => $this->timeout,
            CURLOPT_SSL_VERIFYPEER  => $this->verifyTLS,
            CURLOPT_SSL_VERIFYHOST  => $this->verifyTLS ? 2 : 0,
            CURLOPT_HTTPHEADER      => $headerLines,
            CURLOPT_ENCODING        => '',
            CURLOPT_USERAGENT       => 'IPS_Kalender/1.0',
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
