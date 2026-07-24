<?php

declare(strict_types=1);

use IPSKalender\CalendarHttpClient;
use IPSKalender\MicrosoftOAuthClient;
use IPSKalender\MicrosoftOAuthException;
use IPSKalender\SymconOAuthOriginPolicy;

trait KalenderKontoMicrosoftOAuthTrait
{
    /**
     * Starts Microsoft authorization through the native Symcon OAuth handler.
     *
     * @return string Authorization URL, or a localized error message when startup fails.
     */
    public function ConnectMicrosoft(): string
    {
        try {
            $this->assertMicrosoftConnectAvailable();
            if (!$this->RegisterOAuth(self::MICROSOFT_OAUTH_IDENTIFIER)) {
                throw new MicrosoftOAuthException('Microsoft OAuth could not be registered in Symcon.');
            }

            $this->SetBuffer('MicrosoftAccessToken', '');

            return $this->createMicrosoftOAuthClient()->getAuthorizationUrl((string) IPS_GetLicensee());
        } catch (Throwable $exception) {
            return $this->Translate('Microsoft authorization could not be started') . ': '
                . $this->handleProviderError($exception);
        }
    }

    /**
     * Clears locally stored Microsoft OAuth credentials and account state.
     *
     * @return bool Always true after local Microsoft authorization data has been cleared.
     */
    public function DisconnectMicrosoft(): bool
    {
        $this->WriteAttributeString('MicrosoftRefreshToken', '');
        $this->WriteAttributeString('MicrosoftAccount', '');
        $this->SetBuffer('MicrosoftAccessToken', '');
        $this->ClearCache();
        $this->SetStatus($this->ReadPropertyBoolean('Active') ? self::STATUS_CONFIGURATION_MISSING : IS_INACTIVE);
        $this->ReloadForm();

        return true;
    }

    /**
     * Receives the Microsoft authorization code forwarded by Symcon OAuth.
     */
    protected function ProcessOAuthData(): void
    {
        try {
            $oauthData = $this->readMicrosoftOAuthData();
            $error = trim((string) ($oauthData['error_description'] ?? $oauthData['error'] ?? ''));
            if ($error !== '') {
                throw new MicrosoftOAuthException($error);
            }

            $tokens = $this->createMicrosoftOAuthClient()->exchangeAuthorizationCode(
                (string) ($oauthData['code'] ?? '')
            );
            $this->storeMicrosoftTokens($tokens);
            $this->WriteAttributeString('MicrosoftAccount', '');
            $this->WriteAttributeString('LastError', '');
            $this->ClearCache();
            $this->SetStatus($this->ReadPropertyBoolean('Active') ? IS_ACTIVE : IS_INACTIVE);
            $this->ReloadForm();

            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            echo htmlspecialchars(
                $this->Translate('Microsoft 365 was connected successfully. You can close this window.'),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
        } catch (Throwable $exception) {
            $message = $this->handleProviderError($exception);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            echo htmlspecialchars(
                $this->Translate('Microsoft 365 could not be connected') . ': ' . $message,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
        }
    }

    private function getMicrosoftAccessToken(): string
    {
        if (!$this->isMicrosoftConnected()) {
            throw new MicrosoftOAuthException('Microsoft 365 is not connected yet.');
        }

        $cached = json_decode($this->GetBuffer('MicrosoftAccessToken'), true);
        if (is_array($cached)
            && trim((string) ($cached['token'] ?? '')) !== ''
            && (int) ($cached['expiresAt'] ?? 0) > time() + 60) {
            return (string) $cached['token'];
        }

        $tokens = $this->createMicrosoftOAuthClient()->refreshAccessToken(
            $this->ReadAttributeString('MicrosoftRefreshToken')
        );
        $this->storeMicrosoftTokens($tokens);

        return $tokens['accessToken'];
    }

    /**
     * @param array{accessToken: string, refreshToken: string, expiresAt: int} $tokens
     */
    private function storeMicrosoftTokens(array $tokens): void
    {
        if ($tokens['refreshToken'] !== '') {
            $this->WriteAttributeString('MicrosoftRefreshToken', $tokens['refreshToken']);
        }
        $this->SetBuffer('MicrosoftAccessToken', json_encode(
            ['token' => $tokens['accessToken'], 'expiresAt' => $tokens['expiresAt']],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function createMicrosoftOAuthClient(): MicrosoftOAuthClient
    {
        return new MicrosoftOAuthClient(
            new CalendarHttpClient(
                max(5, min(120, $this->ReadPropertyInteger('RequestTimeout'))),
                $this->ReadPropertyBoolean('VerifyTLS'),
                '',
                '',
                new SymconOAuthOriginPolicy()
            ),
            self::MICROSOFT_OAUTH_IDENTIFIER
        );
    }

    private function isMicrosoftConnected(): bool
    {
        return trim($this->ReadAttributeString('MicrosoftRefreshToken')) !== '';
    }

    private function microsoftStatusText(): string
    {
        if (!$this->isMicrosoftConnected()) {
            return $this->Translate('Microsoft account is not connected.');
        }

        $account = trim($this->ReadAttributeString('MicrosoftAccount'));
        return $account !== ''
            ? sprintf($this->Translate('Connected with %s.'), $account)
            : $this->Translate('Microsoft account is connected.');
    }

    private function assertMicrosoftConnectAvailable(): void
    {
        foreach (IPS_GetInstanceListByModuleID(self::CONNECT_CONTROL_MODULE_ID) as $connectId) {
            $instance = IPS_GetInstance($connectId);
            if ((int) ($instance['InstanceStatus'] ?? 0) === IS_ACTIVE) {
                return;
            }
        }

        throw new MicrosoftOAuthException('An active Symcon Connect connection is required for Microsoft OAuth.');
    }

    /**
     * @return array<string, string>
     */
    private function readMicrosoftOAuthData(): array
    {
        $rawInput = trim((string) file_get_contents('php://input'));
        $data = [];

        if ($rawInput !== '') {
            $decoded = json_decode($rawInput, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_scalar($value)) {
                        $data[(string) $key] = (string) $value;
                    }
                }
            } elseif (str_contains($rawInput, 'code=') || str_contains($rawInput, 'error=')) {
                $formData = [];
                parse_str($rawInput, $formData);
                foreach ($formData as $key => $value) {
                    if (is_scalar($value)) {
                        $data[(string) $key] = (string) $value;
                    }
                }
            } else {
                $data['code'] = $rawInput;
            }
        }

        foreach (['code', 'error', 'error_description'] as $key) {
            if (isset($_GET[$key]) && is_scalar($_GET[$key])) {
                $data[$key] = (string) $_GET[$key];
            } elseif (isset($_POST[$key]) && is_scalar($_POST[$key])) {
                $data[$key] = (string) $_POST[$key];
            }
        }

        return $data;
    }
}
