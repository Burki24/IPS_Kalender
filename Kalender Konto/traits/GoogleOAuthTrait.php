<?php

declare(strict_types=1);

use IPSKalender\GoogleOAuthClient;
use IPSKalender\GoogleOAuthException;

trait KalenderKontoGoogleOAuthTrait
{
    /**
     * Starts the Google OAuth flow and returns the authorization URL.
     *
     * @return string Authorization URL, or a localized error message when startup fails.
     */
    public function ConnectGoogle(): string
    {
        try {
            $state = bin2hex(random_bytes(32));
            $this->WriteAttributeString(
                'GoogleOAuthState',
                json_encode(
                    ['value' => $state, 'createdAt' => time()],
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            );
            $this->SetBuffer('GoogleAccessToken', '');

            return $this->createGoogleOAuthClient()->getAuthorizationUrl($state);
        } catch (Throwable $exception) {
            $this->WriteAttributeString('GoogleOAuthState', '');
            return $this->Translate('Google authorization could not be started') . ': '
                . $this->handleProviderError($exception);
        }
    }

    /**
     * Returns the Symcon Connect callback URI used for Google OAuth.
     *
     * @return string Redirect URI, or a localized error message when it is unavailable.
     */
    public function GetGoogleRedirectURI(): string
    {
        try {
            return $this->googleOAuthRedirectUri();
        } catch (Throwable $exception) {
            return $this->translateErrorMessage($exception->getMessage());
        }
    }

    /**
     * Revokes the stored Google authorization when possible and clears local OAuth state.
     *
     * @return bool Always true after local Google authorization data has been cleared.
     */
    public function DisconnectGoogle(): bool
    {
        $refreshToken = $this->ReadAttributeString('GoogleRefreshToken');
        if ($refreshToken !== '') {
            try {
                $client = $this->createUnauthenticatedHttpClient();
                $client->request(
                    'POST',
                    'https://oauth2.googleapis.com/revoke',
                    ['Content-Type' => 'application/x-www-form-urlencoded'],
                    http_build_query(['token' => $refreshToken], '', '&', PHP_QUERY_RFC3986)
                );
            } catch (Throwable $exception) {
                $this->SendDebug('GoogleOAuthRevoke', $this->sanitizeError($exception->getMessage()), 0);
            }
        }

        $this->WriteAttributeString('GoogleRefreshToken', '');
        $this->WriteAttributeString('GoogleAccount', '');
        $this->WriteAttributeString('GoogleTokenClientID', '');
        $this->WriteAttributeString('GoogleOAuthState', '');
        $this->SetBuffer('GoogleAccessToken', '');
        $this->ClearCache();
        $this->SetStatus($this->ReadPropertyBoolean('Active') ? self::STATUS_CONFIGURATION_MISSING : IS_INACTIVE);
        $this->ReloadForm();

        return true;
    }

    protected function ProcessHookData(): void
    {
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                throw new GoogleOAuthException('Unsupported OAuth callback method.');
            }

            $storedState = json_decode($this->ReadAttributeString('GoogleOAuthState'), true);
            $receivedState = trim((string) ($_GET['state'] ?? ''));
            if (!is_array($storedState)
                || trim((string) ($storedState['value'] ?? '')) === ''
                || $receivedState === ''
                || !hash_equals((string) $storedState['value'], $receivedState)
                || (int) ($storedState['createdAt'] ?? 0) < time() - self::GOOGLE_OAUTH_STATE_TTL) {
                throw new GoogleOAuthException('The Google OAuth state is invalid or has expired.');
            }
            $this->WriteAttributeString('GoogleOAuthState', '');

            $oauthError = trim((string) ($_GET['error_description'] ?? $_GET['error'] ?? ''));
            if ($oauthError !== '') {
                throw new GoogleOAuthException($oauthError);
            }

            $tokens = $this->createGoogleOAuthClient()->exchangeAuthorizationCode(
                (string) ($_GET['code'] ?? '')
            );
            $this->storeGoogleTokens($tokens);
            $this->WriteAttributeString('LastError', '');
            $this->SetStatus($this->ReadPropertyBoolean('Active') ? IS_ACTIVE : IS_INACTIVE);
            $this->ReloadForm();
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            echo htmlspecialchars(
                $this->Translate('Google Calendar was connected successfully. You can close this window.'),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
        } catch (Throwable $exception) {
            $message = $this->handleProviderError($exception);
            http_response_code(400);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            echo htmlspecialchars(
                $this->Translate('Google Calendar could not be connected') . ': ' . $message,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
        }
    }

    private function getGoogleAccessToken(): string
    {
        if (!$this->isGoogleConnected()) {
            throw new GoogleOAuthException('Google Calendar is not connected yet.');
        }

        $cached = json_decode($this->GetBuffer('GoogleAccessToken'), true);
        if (is_array($cached)
            && trim((string) ($cached['token'] ?? '')) !== ''
            && (int) ($cached['expiresAt'] ?? 0) > time() + 60) {
            return (string) $cached['token'];
        }

        $tokens = $this->createGoogleOAuthClient()->refreshAccessToken(
            $this->ReadAttributeString('GoogleRefreshToken')
        );
        $this->storeGoogleTokens($tokens);
        return $tokens['accessToken'];
    }

    /**
     * @param array{accessToken: string, refreshToken: string, expiresAt: int} $tokens
     */
    private function storeGoogleTokens(array $tokens): void
    {
        if ($tokens['refreshToken'] !== '') {
            $this->WriteAttributeString('GoogleRefreshToken', $tokens['refreshToken']);
        }
        $this->WriteAttributeString(
            'GoogleTokenClientID',
            trim($this->ReadPropertyString('GoogleClientID'))
        );
        $this->SetBuffer('GoogleAccessToken', json_encode(
            ['token' => $tokens['accessToken'], 'expiresAt' => $tokens['expiresAt']],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function createGoogleOAuthClient(): GoogleOAuthClient
    {
        return new GoogleOAuthClient(
            $this->createUnauthenticatedHttpClient(),
            trim($this->ReadPropertyString('GoogleClientID')),
            $this->ReadPropertyString('GoogleClientSecret'),
            $this->googleOAuthRedirectUri()
        );
    }

    private function googleOAuthHookAddress(): string
    {
        return self::GOOGLE_OAUTH_HOOK_PREFIX . $this->InstanceID;
    }

    private function googleOAuthRedirectUri(): string
    {
        foreach (IPS_GetInstanceListByModuleID(self::CONNECT_CONTROL_MODULE_ID) as $connectId) {
            $instance = IPS_GetInstance($connectId);
            if ((int) ($instance['InstanceStatus'] ?? 0) !== IS_ACTIVE) {
                continue;
            }

            $connectUrl = trim((string) CC_GetConnectURL($connectId));
            if (filter_var($connectUrl, FILTER_VALIDATE_URL) === false
                || strtolower((string) parse_url($connectUrl, PHP_URL_SCHEME)) !== 'https') {
                continue;
            }

            return rtrim($connectUrl, '/') . '/hook/' . rawurlencode($this->googleOAuthHookAddress());
        }

        throw new GoogleOAuthException('An active Symcon Connect connection is required for Google OAuth.');
    }

    private function googleRedirectUriText(): string
    {
        try {
            return sprintf(
                $this->Translate('Authorized redirect URI: %s'),
                $this->googleOAuthRedirectUri()
            );
        } catch (Throwable $exception) {
            return $this->Translate('Authorized redirect URI is unavailable') . ': '
                . $this->translateErrorMessage($exception->getMessage());
        }
    }

    private function isGoogleConnected(): bool
    {
        $clientId = trim($this->ReadPropertyString('GoogleClientID'));

        return $clientId !== ''
            && trim($this->ReadAttributeString('GoogleRefreshToken')) !== ''
            && hash_equals($clientId, $this->ReadAttributeString('GoogleTokenClientID'));
    }

    private function googleStatusText(): string
    {
        if (trim($this->ReadPropertyString('GoogleClientID')) === ''
            || $this->ReadPropertyString('GoogleClientSecret') === '') {
            return $this->Translate('Enter your personal Google OAuth client credentials.');
        }
        if (!$this->isGoogleConnected()) {
            return $this->Translate('Google account is not connected.');
        }
        $account = trim($this->ReadAttributeString('GoogleAccount'));
        return $account !== ''
            ? sprintf($this->Translate('Connected with %s.'), $account)
            : $this->Translate('Google account is connected.');
    }
}
