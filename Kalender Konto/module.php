<?php

declare(strict_types=1);

use IPSKalender\CalendarHttpClient;
use IPSKalender\CalendarProviderInterface;
use IPSKalender\CalDAVProvider;
use IPSKalender\CalDAVProviderException;
use IPSKalender\GoogleCalendarProvider;
use IPSKalender\GoogleCalendarProviderException;
use IPSKalender\OAuthBridgeClient;
use IPSKalender\OAuthBridgeException;

require_once __DIR__ . '/../libs/CalendarProviderInterface.php';
require_once __DIR__ . '/../libs/CalendarHttpClient.php';
require_once __DIR__ . '/../libs/CalDAVProvider.php';
require_once __DIR__ . '/../libs/GoogleCalendarProvider.php';
require_once __DIR__ . '/../libs/OAuthBridgeClient.php';

class KalenderKonto extends IPSModuleStrict
{
    private const DATA_ID_TO_CHILD = '{8ED646DD-88E9-ACE2-95D5-9766EED4B5B0}';
    private const APPLE_CALDAV_URL = 'https://caldav.icloud.com';
    private const GOOGLE_OAUTH_IDENTIFIER = 'ipskalender_google';

    private const PROVIDER_APPLE = 0;
    private const PROVIDER_CALDAV = 1;
    private const PROVIDER_GOOGLE = 2;
    private const PROVIDER_MICROSOFT = 3;
    private const PROVIDER_ICS = 4;

    private const STATUS_CONFIGURATION_MISSING = 201;
    private const STATUS_AUTHENTICATION_FAILED = 202;
    private const STATUS_CONNECTION_FAILED = 203;
    private const STATUS_PROVIDER_NOT_IMPLEMENTED = 204;
    private const STATUS_INVALID_RESPONSE = 205;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyInteger('Provider', self::PROVIDER_APPLE);
        $this->RegisterPropertyString('ServerURL', self::APPLE_CALDAV_URL);
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('UpdateInterval', 15);
        $this->RegisterPropertyBoolean('VerifyTLS', true);
        $this->RegisterPropertyInteger('RequestTimeout', 30);

        $this->RegisterAttributeString('CachedCalendars', '[]');
        $this->RegisterAttributeInteger('LastSynchronization', 0);
        $this->RegisterAttributeString('LastError', '');
        $this->RegisterAttributeString('GoogleRefreshToken', '');
        $this->RegisterAttributeString('GoogleAccount', '');

        $this->RegisterTimer('SynchronizationTimer', 0, 'IPSKALACC_Synchronize($_IPS[\'TARGET\']);');
        $this->RegisterOAuth(self::GOOGLE_OAUTH_IDENTIFIER);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(
            file_get_contents(__DIR__ . '/form.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $provider = $this->ReadPropertyInteger('Provider');
        $isPasswordProvider = in_array($provider, [self::PROVIDER_APPLE, self::PROVIDER_CALDAV], true);
        $isGoogle = $provider === self::PROVIDER_GOOGLE;

        foreach ($form['elements'] as &$element) {
            $name = (string) ($element['name'] ?? '');
            if ($name === 'ServerURL') {
                $element['visible'] = $isPasswordProvider;
                $element['enabled'] = $provider === self::PROVIDER_CALDAV;
                if ($provider === self::PROVIDER_APPLE) {
                    $element['value'] = self::APPLE_CALDAV_URL;
                }
            } elseif (in_array($name, ['Username', 'Password'], true)) {
                $element['visible'] = $isPasswordProvider;
            } elseif (in_array($name, ['GoogleStatus', 'GoogleConnect', 'GoogleDisconnect'], true)) {
                $element['visible'] = $isGoogle;
                if ($name === 'GoogleStatus') {
                    $element['caption'] = $this->googleStatusText();
                } elseif ($name === 'GoogleConnect') {
                    $element['visible'] = $isGoogle && !$this->isGoogleConnected();
                } elseif ($name === 'GoogleDisconnect') {
                    $element['visible'] = $isGoogle && $this->isGoogleConnected();
                }
            }
        }
        unset($element);

        return json_encode(
            $form,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    public function UpdateProviderForm(int $provider): void
    {
        $isPasswordProvider = in_array($provider, [self::PROVIDER_APPLE, self::PROVIDER_CALDAV], true);
        $isGoogle = $provider === self::PROVIDER_GOOGLE;
        $this->UpdateFormField('ServerURL', 'visible', $isPasswordProvider);
        $this->UpdateFormField('Username', 'visible', $isPasswordProvider);
        $this->UpdateFormField('Password', 'visible', $isPasswordProvider);
        $this->UpdateFormField('GoogleStatus', 'visible', $isGoogle);
        $this->UpdateFormField('GoogleConnect', 'visible', $isGoogle && !$this->isGoogleConnected());
        $this->UpdateFormField('GoogleDisconnect', 'visible', $isGoogle && $this->isGoogleConnected());

        if ($provider === self::PROVIDER_APPLE) {
            $this->UpdateFormField('ServerURL', 'value', self::APPLE_CALDAV_URL);
            $this->UpdateFormField('ServerURL', 'enabled', false);
            return;
        }

        if ($provider === self::PROVIDER_CALDAV) {
            $storedProvider = $this->ReadPropertyInteger('Provider');
            $storedServerUrl = trim($this->ReadPropertyString('ServerURL'));
            if ($storedProvider === self::PROVIDER_APPLE || $storedServerUrl === self::APPLE_CALDAV_URL) {
                $this->UpdateFormField('ServerURL', 'value', '');
            }
            $this->UpdateFormField('ServerURL', 'enabled', true);
            return;
        }

        $this->UpdateFormField('ServerURL', 'enabled', false);
    }

    public function ConnectGoogle(): string
    {
        // Re-register immediately before authentication so the callback reaches
        // the account instance whose button was pressed, even with multiple accounts.
        $this->RegisterOAuth(self::GOOGLE_OAUTH_IDENTIFIER);
        return $this->createOAuthBridgeClient()->getAuthorizationUrl((string) IPS_GetLicensee());
    }

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
        $this->SetBuffer('GoogleAccessToken', '');
        $this->ClearCache();
        $this->SetStatus($this->ReadPropertyBoolean('Active') ? self::STATUS_CONFIGURATION_MISSING : IS_INACTIVE);
        $this->ReloadForm();

        return true;
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $providerName = $this->getProviderName($this->ReadPropertyInteger('Provider'));
        $username = $this->ReadPropertyInteger('Provider') === self::PROVIDER_GOOGLE
            ? trim($this->ReadAttributeString('GoogleAccount'))
            : trim($this->ReadPropertyString('Username'));
        $this->SetSummary($username !== '' ? $providerName . ' – ' . $username : $providerName);

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('SynchronizationTimer', 0);
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        $validationError = $this->validateConfiguration();
        if ($validationError !== '') {
            $this->SetTimerInterval('SynchronizationTimer', 0);
            $this->WriteAttributeString('LastError', $validationError);
            $this->SetStatus(self::STATUS_CONFIGURATION_MISSING);
            return;
        }

        if (!$this->isProviderImplemented($this->ReadPropertyInteger('Provider'))) {
            $this->SetTimerInterval('SynchronizationTimer', 0);
            $this->WriteAttributeString('LastError', 'The selected provider is not implemented yet.');
            $this->SetStatus(self::STATUS_PROVIDER_NOT_IMPLEMENTED);
            return;
        }

        $interval = max(1, $this->ReadPropertyInteger('UpdateInterval'));
        $this->SetTimerInterval('SynchronizationTimer', $interval * 60 * 1000);
        $this->SetStatus(IS_ACTIVE);
    }

    public function ForwardData(string $JSONString): string
    {
        try {
            $request = json_decode($JSONString, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($request)) {
                throw new InvalidArgumentException('The request must be a JSON object.');
            }

            $operation = (string) ($request['Operation'] ?? '');
            $requestID = (string) ($request['RequestID'] ?? '');

            $payload = match ($operation) {
                'GetCalendars'      => json_decode($this->GetCalendars(), true, 512, JSON_THROW_ON_ERROR),
                'DiscoverCalendars' => $this->discoverCalendars(),
                'GetEvents'         => $this->getEventsForChild($request),
                'CreateEvent'       => $this->createEventForChild($request),
                'UpdateEvent'       => $this->updateEventForChild($request),
                'DeleteEvent'       => ['success' => $this->deleteEventForChild($request)],
                'Synchronize'       => ['success' => $this->Synchronize()],
                'TestConnection'    => json_decode($this->TestConnection(), true, 512, JSON_THROW_ON_ERROR),
                default             => throw new InvalidArgumentException('Unsupported operation: ' . $operation)
            };

            return $this->encodeResponse(true, $operation, $requestID, $payload);
        } catch (Throwable $exception) {
            $this->SendDebug('ForwardData', $this->sanitizeError($exception->getMessage()), 0);

            return $this->encodeResponse(
                false,
                isset($operation) ? $operation : '',
                isset($requestID) ? $requestID : '',
                null,
                $this->sanitizeError($exception->getMessage())
            );
        }
    }

    public function TestConnection(): string
    {
        $validationError = $this->validateConfiguration();
        if ($validationError !== '') {
            $this->WriteAttributeString('LastError', $validationError);
            $this->SetStatus(self::STATUS_CONFIGURATION_MISSING);

            return json_encode(
                ['success' => false, 'message' => $validationError],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }

        if (!$this->isProviderImplemented($this->ReadPropertyInteger('Provider'))) {
            $message = 'The selected provider is not implemented yet.';
            $this->WriteAttributeString('LastError', $message);
            $this->SetStatus(self::STATUS_PROVIDER_NOT_IMPLEMENTED);

            return json_encode(
                ['success' => false, 'message' => $message],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }

        try {
            $provider = $this->createProvider();
            $result = $provider->testConnection();
            $this->WriteAttributeString('LastError', '');
            $this->SetStatus($this->ReadPropertyBoolean('Active') ? IS_ACTIVE : IS_INACTIVE);

            return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $message = $this->handleProviderError($exception);

            return json_encode(
                ['success' => false, 'message' => $message],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }
    }

    public function Synchronize(): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return false;
        }

        $validationError = $this->validateConfiguration();
        if ($validationError !== '') {
            $this->WriteAttributeString('LastError', $validationError);
            $this->SetStatus(self::STATUS_CONFIGURATION_MISSING);
            return false;
        }

        if (!$this->isProviderImplemented($this->ReadPropertyInteger('Provider'))) {
            $this->WriteAttributeString('LastError', 'The selected provider is not implemented yet.');
            $this->SetStatus(self::STATUS_PROVIDER_NOT_IMPLEMENTED);
            return false;
        }

        try {
            $calendars = $this->discoverCalendars();
            $this->SetStatus(IS_ACTIVE);

            $this->SendDataToChildren(json_encode(
                [
                    'DataID'    => self::DATA_ID_TO_CHILD,
                    'Operation' => 'CalendarsUpdated',
                    'Payload'   => $calendars
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));

            $this->SendDebug('Synchronize', sprintf('%d calendars synchronized.', count($calendars)), 0);

            return true;
        } catch (Throwable $exception) {
            $this->handleProviderError($exception);
            return false;
        }
    }

    public function GetCalendars(): string
    {
        return $this->ReadAttributeString('CachedCalendars');
    }

    public function GetAccountStatus(): string
    {
        return json_encode(
            [
                'provider'            => $this->getProviderName($this->ReadPropertyInteger('Provider')),
                'connected'           => $this->ReadPropertyInteger('Provider') !== self::PROVIDER_GOOGLE
                    || $this->isGoogleConnected(),
                'account'             => $this->ReadPropertyInteger('Provider') === self::PROVIDER_GOOGLE
                    ? $this->ReadAttributeString('GoogleAccount')
                    : trim($this->ReadPropertyString('Username')),
                'lastSynchronization' => $this->ReadAttributeInteger('LastSynchronization'),
                'lastError'           => $this->ReadAttributeString('LastError')
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    public function ClearCache(): void
    {
        $this->WriteAttributeString('CachedCalendars', '[]');
        $this->WriteAttributeInteger('LastSynchronization', 0);
        $this->WriteAttributeString('LastError', '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function discoverCalendars(): array
    {
        $validationError = $this->validateConfiguration();
        if ($validationError !== '') {
            throw new InvalidArgumentException($validationError);
        }

        if (!$this->isProviderImplemented($this->ReadPropertyInteger('Provider'))) {
            throw new RuntimeException('The selected provider is not implemented yet.');
        }

        $calendars = $this->createProvider()->getCalendars();
        if ($this->ReadPropertyInteger('Provider') === self::PROVIDER_GOOGLE) {
            foreach ($calendars as $calendar) {
                if ((bool) ($calendar['primary'] ?? false)) {
                    $account = trim((string) ($calendar['providerId'] ?? ''));
                    $this->WriteAttributeString('GoogleAccount', $account);
                    if ($account !== '') {
                        $this->SetSummary($this->getProviderName(self::PROVIDER_GOOGLE) . ' – ' . $account);
                    }
                    break;
                }
            }
        }
        $this->WriteAttributeString(
            'CachedCalendars',
            json_encode(
                $calendars,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );
        $this->WriteAttributeInteger('LastSynchronization', time());
        $this->WriteAttributeString('LastError', '');

        return $calendars;
    }

    /**
     * @param array<string, mixed> $request
     * @return list<array<string, mixed>>
     */
    private function getEventsForChild(array $request): array
    {
        $calendar = $this->resolveCalendar((string) ($request['CalendarID'] ?? ''));
        $startTimestamp = (int) ($request['Start'] ?? 0);
        $endTimestamp = (int) ($request['End'] ?? 0);
        if ($startTimestamp <= 0 || $endTimestamp <= $startTimestamp) {
            throw new InvalidArgumentException('The requested event time range is invalid.');
        }
        if (($endTimestamp - $startTimestamp) > 6 * 366 * 86400) {
            throw new InvalidArgumentException('The requested event time range is too large.');
        }

        return $this->createProvider()->getEvents(
            $this->calendarReference($calendar),
            new DateTimeImmutable('@' . $startTimestamp),
            new DateTimeImmutable('@' . $endTimestamp)
        );
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function createEventForChild(array $request): array
    {
        $calendar = $this->resolveCalendar((string) ($request['CalendarID'] ?? ''));
        $event = $request['Event'] ?? null;
        if (!is_array($event)) {
            throw new InvalidArgumentException('The event data is invalid.');
        }

        return $this->createProvider()->createEvent($this->calendarReference($calendar), $event);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function updateEventForChild(array $request): array
    {
        $calendar = $this->resolveCalendar((string) ($request['CalendarID'] ?? ''));
        $event = $request['Event'] ?? null;
        if (!is_array($event)) {
            throw new InvalidArgumentException('The event data is invalid.');
        }

        return $this->createProvider()->updateEvent(
            $this->calendarReference($calendar),
            trim((string) ($request['ResourceURL'] ?? '')),
            trim((string) ($request['ETag'] ?? '')),
            trim((string) ($request['UID'] ?? '')),
            $event
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private function deleteEventForChild(array $request): bool
    {
        $calendar = $this->resolveCalendar((string) ($request['CalendarID'] ?? ''));

        return $this->createProvider()->deleteEvent(
            $this->calendarReference($calendar),
            trim((string) ($request['ResourceURL'] ?? '')),
            trim((string) ($request['ETag'] ?? '')),
            trim((string) ($request['RecurrenceID'] ?? ''))
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveCalendar(string $calendarId): array
    {
        if ($calendarId === '') {
            throw new InvalidArgumentException('The calendar ID is missing.');
        }

        $calendars = json_decode($this->ReadAttributeString('CachedCalendars'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($calendars)) {
            $calendars = [];
        }
        foreach ($calendars as $calendar) {
            if (is_array($calendar) && (string) ($calendar['id'] ?? '') === $calendarId
                && $this->calendarReference($calendar) !== '') {
                return $calendar;
            }
        }

        foreach ($this->discoverCalendars() as $calendar) {
            if ((string) ($calendar['id'] ?? '') === $calendarId
                && $this->calendarReference($calendar) !== '') {
                return $calendar;
            }
        }

        throw new RuntimeException('The selected calendar is no longer available in this account.');
    }

    private function createProvider(): CalendarProviderInterface
    {
        $provider = $this->ReadPropertyInteger('Provider');
        if (!$this->isProviderImplemented($provider)) {
            throw new RuntimeException('The selected provider is not implemented yet.');
        }

        if ($provider === self::PROVIDER_GOOGLE) {
            return new GoogleCalendarProvider(
                $this->createUnauthenticatedHttpClient(),
                $this->getGoogleAccessToken()
            );
        }

        $serverUrl = $provider === self::PROVIDER_APPLE
            ? self::APPLE_CALDAV_URL
            : trim($this->ReadPropertyString('ServerURL'));

        return new CalDAVProvider(
            new CalendarHttpClient(
                max(5, min(120, $this->ReadPropertyInteger('RequestTimeout'))),
                $this->ReadPropertyBoolean('VerifyTLS'),
                trim($this->ReadPropertyString('Username')),
                $this->ReadPropertyString('Password')
            ),
            $serverUrl
        );
    }

    private function validateConfiguration(): string
    {
        $provider = $this->ReadPropertyInteger('Provider');
        if (!in_array($provider, [
            self::PROVIDER_APPLE,
            self::PROVIDER_CALDAV,
            self::PROVIDER_GOOGLE,
            self::PROVIDER_MICROSOFT,
            self::PROVIDER_ICS
        ], true)) {
            return 'Unknown calendar provider.';
        }

        if ($provider === self::PROVIDER_APPLE) {
            if (trim($this->ReadPropertyString('Username')) === '') {
                return 'The Apple Account email address is missing.';
            }
            if ($this->ReadPropertyString('Password') === '') {
                return 'The app-specific password is missing.';
            }
        }

        if ($provider === self::PROVIDER_CALDAV && trim($this->ReadPropertyString('ServerURL')) === '') {
            return 'The CalDAV server URL is missing.';
        }

        if ($provider === self::PROVIDER_GOOGLE && !$this->isGoogleConnected()) {
            return 'Google Calendar is not connected yet.';
        }

        return '';
    }

    private function isProviderImplemented(int $provider): bool
    {
        return in_array($provider, [self::PROVIDER_APPLE, self::PROVIDER_CALDAV, self::PROVIDER_GOOGLE], true);
    }

    private function getProviderName(int $provider): string
    {
        return match ($provider) {
            self::PROVIDER_APPLE     => 'Apple iCloud',
            self::PROVIDER_CALDAV    => 'CalDAV',
            self::PROVIDER_GOOGLE    => 'Google Calendar',
            self::PROVIDER_MICROSOFT => 'Microsoft 365',
            self::PROVIDER_ICS       => 'ICS/Webcal',
            default                  => 'Unknown'
        };
    }

    private function handleProviderError(Throwable $exception): string
    {
        $message = $this->sanitizeError($exception->getMessage());
        $this->WriteAttributeString('LastError', $message);
        $this->SendDebug('ProviderError', $message, 0);

        if ($exception instanceof CalDAVProviderException) {
            if (in_array($exception->httpStatus, [401, 403], true)) {
                $this->SetStatus(self::STATUS_AUTHENTICATION_FAILED);
            } elseif (str_contains(strtolower($message), 'xml')) {
                $this->SetStatus(self::STATUS_INVALID_RESPONSE);
            } else {
                $this->SetStatus(self::STATUS_CONNECTION_FAILED);
            }
        } elseif ($exception instanceof GoogleCalendarProviderException) {
            $this->SetStatus($exception->httpStatus === 401
                ? self::STATUS_AUTHENTICATION_FAILED
                : self::STATUS_CONNECTION_FAILED);
        } elseif ($exception instanceof OAuthBridgeException) {
            $this->SetStatus(self::STATUS_AUTHENTICATION_FAILED);
        } elseif ($exception instanceof JsonException) {
            $this->SetStatus(self::STATUS_INVALID_RESPONSE);
        } else {
            $this->SetStatus(self::STATUS_CONNECTION_FAILED);
        }

        return $message;
    }

    protected function ProcessOAuthData(): void
    {
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                throw new OAuthBridgeException('Unsupported OAuth callback method.');
            }
            $oauthError = trim((string) ($_GET['error_description'] ?? $_GET['error'] ?? ''));
            if ($oauthError !== '') {
                throw new OAuthBridgeException($oauthError);
            }

            $tokens = $this->createOAuthBridgeClient()->exchangeAuthorizationCode((string) ($_GET['code'] ?? ''));
            $this->storeGoogleTokens($tokens);
            $this->WriteAttributeString('LastError', '');
            $this->SetStatus($this->ReadPropertyBoolean('Active') ? IS_ACTIVE : IS_INACTIVE);
            $this->ReloadForm();
            echo 'Google Calendar was connected successfully. You can close this window.';
        } catch (Throwable $exception) {
            $message = $this->handleProviderError($exception);
            echo 'Google Calendar could not be connected: ' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    private function getGoogleAccessToken(): string
    {
        $cached = json_decode($this->GetBuffer('GoogleAccessToken'), true);
        if (is_array($cached)
            && trim((string) ($cached['token'] ?? '')) !== ''
            && (int) ($cached['expiresAt'] ?? 0) > time() + 60) {
            return (string) $cached['token'];
        }

        $tokens = $this->createOAuthBridgeClient()->refreshAccessToken(
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
        $this->SetBuffer('GoogleAccessToken', json_encode(
            ['token' => $tokens['accessToken'], 'expiresAt' => $tokens['expiresAt']],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function createOAuthBridgeClient(): OAuthBridgeClient
    {
        return new OAuthBridgeClient($this->createUnauthenticatedHttpClient(), self::GOOGLE_OAUTH_IDENTIFIER);
    }

    private function createUnauthenticatedHttpClient(): CalendarHttpClient
    {
        return new CalendarHttpClient(
            max(5, min(120, $this->ReadPropertyInteger('RequestTimeout'))),
            $this->ReadPropertyBoolean('VerifyTLS')
        );
    }

    /**
     * @param array<string, mixed> $calendar
     */
    private function calendarReference(array $calendar): string
    {
        return trim((string) ($calendar['reference'] ?? $calendar['url'] ?? ''));
    }

    private function isGoogleConnected(): bool
    {
        return trim($this->ReadAttributeString('GoogleRefreshToken')) !== '';
    }

    private function googleStatusText(): string
    {
        if (!$this->isGoogleConnected()) {
            return $this->Translate('Google account is not connected.');
        }
        $account = trim($this->ReadAttributeString('GoogleAccount'));
        return $account !== ''
            ? sprintf($this->Translate('Connected with %s.'), $account)
            : $this->Translate('Google account is connected.');
    }

    private function sanitizeError(string $message): string
    {
        $password = $this->ReadPropertyString('Password');
        if ($password !== '') {
            $message = str_replace($password, '***', $message);
        }

        return $message;
    }

    private function encodeResponse(
        bool $success,
        string $operation,
        string $requestID,
        mixed $payload = null,
        string $error = ''
    ): string {
        return json_encode(
            [
                'Success'   => $success,
                'Operation' => $operation,
                'RequestID' => $requestID,
                'Payload'   => $payload,
                'Error'     => $error
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
