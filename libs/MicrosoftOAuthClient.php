<?php

declare(strict_types=1);

namespace IPSKalender;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/CalendarHttpClient.php';

final class MicrosoftOAuthException extends RuntimeException
{
}

/**
 * OAuth client for Microsoft authorization routed through the Symcon OAuth service.
 *
 * The Microsoft application secret stays on the Symcon OAuth backend. OpenCalendar
 * only stores the user-specific refresh token returned for the connected account.
 */
final class MicrosoftOAuthClient
{
    private const OAUTH_BASE_URL = 'https://oauth.ipmagic.de';

    /**
     * Creates a Microsoft OAuth client for the centrally registered Symcon OAuth identifier.
     */
    public function __construct(
        private readonly CalendarHttpClientInterface $httpClient,
        private readonly string $identifier
    ) {
        if (preg_match('/^[a-z0-9_]+$/', $this->identifier) !== 1) {
            throw new MicrosoftOAuthException('The Microsoft OAuth identifier is invalid.');
        }
    }

    /**
     * Returns the Symcon OAuth authorization URL for the current Symcon license account.
     */
    public function getAuthorizationUrl(string $licensee): string
    {
        $licensee = trim($licensee);
        if ($licensee === '') {
            throw new MicrosoftOAuthException('The Symcon license account is unavailable.');
        }

        return self::OAUTH_BASE_URL . '/authorize/' . rawurlencode($this->identifier) . '?' . http_build_query(
            ['username' => $licensee],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    /**
     * Exchanges the authorization code forwarded by the Symcon OAuth Control.
     *
     * @return array{accessToken: string, refreshToken: string, expiresAt: int}
     */
    public function exchangeAuthorizationCode(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new MicrosoftOAuthException('The authorization code is missing.');
        }

        return $this->requestToken(['code' => $code], true);
    }

    /**
     * Refreshes a Microsoft access token through the Symcon OAuth service.
     *
     * @return array{accessToken: string, refreshToken: string, expiresAt: int}
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $refreshToken = trim($refreshToken);
        if ($refreshToken === '') {
            throw new MicrosoftOAuthException('Microsoft 365 is not connected yet.');
        }

        return $this->requestToken(['refresh_token' => $refreshToken], false, $refreshToken);
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
            self::OAUTH_BASE_URL . '/access_token/' . rawurlencode($this->identifier),
            [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            http_build_query($fields, '', '&', PHP_QUERY_RFC3986)
        );

        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new MicrosoftOAuthException('Symcon OAuth returned an invalid Microsoft token response.');
        }
        if (!is_array($data)) {
            throw new MicrosoftOAuthException('Symcon OAuth returned an invalid Microsoft token response.');
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300 || isset($data['error'])) {
            $message = trim((string) ($data['error_description'] ?? $data['error'] ?? ''));
            throw new MicrosoftOAuthException(
                $message !== '' ? $message : 'The Microsoft OAuth token request failed.'
            );
        }

        $accessToken = trim((string) ($data['access_token'] ?? ''));
        $tokenType = strtolower(trim((string) ($data['token_type'] ?? 'bearer')));
        $refreshToken = trim((string) ($data['refresh_token'] ?? $currentRefreshToken));
        if ($accessToken === '' || $tokenType !== 'bearer') {
            throw new MicrosoftOAuthException('Microsoft did not return a Bearer access token.');
        }
        if ($requireRefreshToken && $refreshToken === '') {
            throw new MicrosoftOAuthException(
                'Microsoft did not return a refresh token. Disconnect the account and connect it again.'
            );
        }

        return [
            'accessToken'  => $accessToken,
            'refreshToken' => $refreshToken,
            'expiresAt'    => time() + max(60, (int) ($data['expires_in'] ?? 3600))
        ];
    }
}
