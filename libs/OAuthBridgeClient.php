<?php

declare(strict_types=1);

namespace IPSKalender;

use RuntimeException;

require_once __DIR__ . '/CalendarHttpClient.php';

final class OAuthBridgeException extends RuntimeException
{
}

final class OAuthBridgeClient
{
    private const BASE_URL = 'https://oauth.ipmagic.de';

    public function __construct(
        private readonly CalendarHttpClientInterface $httpClient,
        private readonly string $identifier
    ) {
        if (preg_match('/^[a-z0-9_-]+$/', $identifier) !== 1) {
            throw new OAuthBridgeException('The OAuth handler identifier is invalid.');
        }
    }

    public function getAuthorizationUrl(string $licensee): string
    {
        return self::BASE_URL . '/authorize/' . rawurlencode($this->identifier)
            . '?username=' . rawurlencode($licensee);
    }

    /**
     * @return array{accessToken: string, refreshToken: string, expiresAt: int}
     */
    public function exchangeAuthorizationCode(string $code): array
    {
        if (trim($code) === '') {
            throw new OAuthBridgeException('The authorization code is missing.');
        }

        return $this->requestToken(['code' => $code], true);
    }

    /**
     * @return array{accessToken: string, refreshToken: string, expiresAt: int}
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        if (trim($refreshToken) === '') {
            throw new OAuthBridgeException('Google Calendar is not connected yet.');
        }

        return $this->requestToken(['refresh_token' => $refreshToken], false, $refreshToken);
    }

    /**
     * @param array<string, string> $fields
     * @return array{accessToken: string, refreshToken: string, expiresAt: int}
     */
    private function requestToken(array $fields, bool $requireRefreshToken, string $currentRefreshToken = ''): array
    {
        $response = $this->httpClient->request(
            'POST',
            self::BASE_URL . '/access_token/' . rawurlencode($this->identifier),
            [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            http_build_query($fields, '', '&', PHP_QUERY_RFC3986)
        );

        $data = json_decode($response->body, true);
        if (!is_array($data)) {
            throw new OAuthBridgeException('The OAuth service returned an invalid response.');
        }
        if ($response->statusCode < 200 || $response->statusCode >= 300 || isset($data['error'])) {
            $message = trim((string) ($data['error_description'] ?? $data['error'] ?? ''));
            throw new OAuthBridgeException($message !== '' ? $message : 'The OAuth token request failed.');
        }

        $accessToken = trim((string) ($data['access_token'] ?? ''));
        $tokenType = strtolower(trim((string) ($data['token_type'] ?? '')));
        $refreshToken = trim((string) ($data['refresh_token'] ?? $currentRefreshToken));
        if ($accessToken === '' || $tokenType !== 'bearer') {
            throw new OAuthBridgeException('The OAuth service did not return a Bearer access token.');
        }
        if ($requireRefreshToken && $refreshToken === '') {
            throw new OAuthBridgeException('Google did not return a refresh token. Revoke access and connect again.');
        }

        return [
            'accessToken'  => $accessToken,
            'refreshToken' => $refreshToken,
            'expiresAt'    => time() + max(60, (int) ($data['expires_in'] ?? 3600))
        ];
    }
}
