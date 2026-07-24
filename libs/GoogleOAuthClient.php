<?php

declare(strict_types=1);

namespace IPSKalender;

use RuntimeException;

require_once __DIR__ . '/CalendarHttpClient.php';

final class GoogleOAuthException extends RuntimeException
{
}

final class GoogleOAuthClient
{
    private const AUTHORIZATION_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** @var list<string> */
    private const SCOPES = [
        'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
        'https://www.googleapis.com/auth/calendar.events'
    ];

    public function __construct(
        private readonly CalendarHttpClientInterface $httpClient,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri
    ) {
        if (trim($this->clientId) === '') {
            throw new GoogleOAuthException('The Google OAuth client ID is missing.');
        }
        if (trim($this->clientSecret) === '') {
            throw new GoogleOAuthException('The Google OAuth client secret is missing.');
        }
        if (filter_var($this->redirectUri, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($this->redirectUri, PHP_URL_SCHEME)) !== 'https') {
            throw new GoogleOAuthException('The Google OAuth redirect URI is invalid.');
        }
    }

    public function getAuthorizationUrl(string $state): string
    {
        if (trim($state) === '') {
            throw new GoogleOAuthException('The OAuth state is missing.');
        }

        return self::AUTHORIZATION_URL . '?' . http_build_query(
            [
                'client_id'                => trim($this->clientId),
                'redirect_uri'             => $this->redirectUri,
                'response_type'            => 'code',
                'scope'                    => implode(' ', self::SCOPES),
                'access_type'              => 'offline',
                'include_granted_scopes'   => 'true',
                'prompt'                   => 'consent',
                'state'                    => $state
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    /**
     * @return array{accessToken: string, refreshToken: string, expiresAt: int}
     */
    public function exchangeAuthorizationCode(string $code): array
    {
        if (trim($code) === '') {
            throw new GoogleOAuthException('The authorization code is missing.');
        }

        return $this->requestToken(
            [
                'code'          => $code,
                'redirect_uri'  => $this->redirectUri,
                'grant_type'    => 'authorization_code'
            ],
            true
        );
    }

    /**
     * @return array{accessToken: string, refreshToken: string, expiresAt: int}
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        if (trim($refreshToken) === '') {
            throw new GoogleOAuthException('Google Calendar is not connected yet.');
        }

        return $this->requestToken(
            [
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token'
            ],
            false,
            $refreshToken
        );
    }

    /**
     * @param array<string, string> $fields
     * @return array{accessToken: string, refreshToken: string, expiresAt: int}
     */
    private function requestToken(
        array $fields,
        bool $requireRefreshToken,
        string $currentRefreshToken = ''
    ): array {
        $response = $this->httpClient->request(
            'POST',
            self::TOKEN_URL,
            [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            http_build_query(
                array_merge(
                    $fields,
                    [
                        'client_id'     => trim($this->clientId),
                        'client_secret' => $this->clientSecret
                    ]
                ),
                '',
                '&',
                PHP_QUERY_RFC3986
            )
        );

        $data = json_decode($response->body, true);
        if (!is_array($data)) {
            throw new GoogleOAuthException('Google returned an invalid OAuth response.');
        }
        if ($response->statusCode < 200 || $response->statusCode >= 300 || isset($data['error'])) {
            $message = trim((string) ($data['error_description'] ?? $data['error'] ?? ''));
            throw new GoogleOAuthException($message !== '' ? $message : 'The Google OAuth token request failed.');
        }

        $accessToken = trim((string) ($data['access_token'] ?? ''));
        $tokenType = strtolower(trim((string) ($data['token_type'] ?? '')));
        $refreshToken = trim((string) ($data['refresh_token'] ?? $currentRefreshToken));
        if ($accessToken === '' || $tokenType !== 'bearer') {
            throw new GoogleOAuthException('Google did not return a Bearer access token.');
        }
        if ($requireRefreshToken && $refreshToken === '') {
            throw new GoogleOAuthException(
                'Google did not return a refresh token. Revoke access and connect again.'
            );
        }

        return [
            'accessToken'  => $accessToken,
            'refreshToken' => $refreshToken,
            'expiresAt'    => time() + max(60, (int) ($data['expires_in'] ?? 3600))
        ];
    }
}
