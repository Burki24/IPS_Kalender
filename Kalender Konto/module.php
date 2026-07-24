<?php

declare(strict_types=1);

use IPSKalender\CalendarHttpClient;
use IPSKalender\CalendarEventTranslation;
use IPSKalender\CalendarProviderInterface;
use IPSKalender\CalDAVProvider;
use IPSKalender\CalDAVProviderException;
use IPSKalender\GoogleCalendarProvider;
use IPSKalender\GoogleCalendarProviderException;
use IPSKalender\GoogleOAuthClient;
use IPSKalender\GoogleOAuthException;
use IPSKalender\ICalendarFeedProvider;
use IPSKalender\ICalendarFeedProviderException;
use IPSKalender\ICalendarSubscriptionProvider;
use IPSKalender\SynchronizationSchedule;

require_once __DIR__ . '/../libs/CalendarProviderInterface.php';
require_once __DIR__ . '/../libs/CalendarHttpClient.php';
require_once __DIR__ . '/../libs/CalendarEventTranslation.php';
require_once __DIR__ . '/../libs/CalDAVProvider.php';
require_once __DIR__ . '/../libs/GoogleCalendarProvider.php';
require_once __DIR__ . '/../libs/GoogleOAuthClient.php';
require_once __DIR__ . '/../libs/ICalendarFeedProvider.php';
require_once __DIR__ . '/../libs/ICalendarSubscriptionProvider.php';
require_once __DIR__ . '/../libs/SynchronizationSchedule.php';

class KalenderKonto extends IPSModuleStrict
{
    private const DATA_ID_TO_CHILD = '{8ED646DD-88E9-ACE2-95D5-9766EED4B5B0}';
    private const APPLE_CALDAV_URL = 'https://caldav.icloud.com';
    private const CONNECT_CONTROL_MODULE_ID = '{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}';
    private const GOOGLE_OAUTH_HOOK_PREFIX = 'ips-kalender-google-';
    private const GOOGLE_OAUTH_STATE_TTL = 900;

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
        $this->RegisterPropertyString('GoogleClientID', '');
        $this->RegisterPropertyString('GoogleClientSecret', '');
        $this->RegisterPropertyString('CalendarName', '');
        $this->RegisterPropertyInteger('ICalendarTranslationProfile', CalendarEventTranslation::NONE);
        $this->RegisterPropertyString('ICalendarFeeds', '[]');
        $this->RegisterPropertyInteger('UpdateSchedule', SynchronizationSchedule::CUSTOM);
        $this->RegisterPropertyInteger('UpdateInterval', 15);
        $this->RegisterPropertyBoolean('VerifyTLS', true);
        $this->RegisterPropertyInteger('RequestTimeout', 30);

        $this->RegisterAttributeString('CachedCalendars', '[]');
        $this->RegisterAttributeString('ICalendarFeedCache', '{}');
        $this->RegisterAttributeInteger('LastSynchronization', 0);
        $this->RegisterAttributeString('LastError', '');
        $this->RegisterAttributeString('GoogleRefreshToken', '');
        $this->RegisterAttributeString('GoogleAccount', '');
        $this->RegisterAttributeString('GoogleTokenClientID', '');
        $this->RegisterAttributeString('GoogleOAuthState', '');

        $this->RegisterTimer('SynchronizationTimer', 0, 'IPSKALACC_ScheduledSynchronize($_IPS[\'TARGET\']);');
        $this->RegisterHook($this->googleOAuthHookAddress());
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
        $isPasswordProvider = in_array($provider, [self::PROVIDER_APPLE, self::PROVIDER_CALDAV, self::PROVIDER_ICS], true);
        $isGoogle = $provider === self::PROVIDER_GOOGLE;
        $isIcs = $provider === self::PROVIDER_ICS;

        foreach ($form['elements'] as &$element) {
            $name = (string) ($element['name'] ?? '');
            if ($name === 'ServerURL') {
                $element['visible'] = $isPasswordProvider;
                $element['enabled'] = in_array($provider, [self::PROVIDER_CALDAV, self::PROVIDER_ICS], true);
                $element['caption'] = $isIcs ? $this->Translate('iCalendar URL') : $this->Translate('Server URL');
                if ($provider === self::PROVIDER_APPLE) {
                    $element['value'] = self::APPLE_CALDAV_URL;
                }
            } elseif (in_array($name, ['Username', 'Password'], true)) {
                $element['visible'] = $isPasswordProvider;
            } elseif (in_array($name, ['CalendarName', 'ICalendarTranslationProfile'], true)) {
                $element['visible'] = $isIcs;
            } elseif (in_array($name, ['ICalendarFeeds', 'ICalendarSubscriptionsHint'], true)) {
                $element['visible'] = $isIcs;
            } elseif ($name === 'UpdateSchedule') {
                $element['caption'] = $isIcs
                    ? $this->Translate('Account discovery schedule')
                    : $this->Translate('Synchronization schedule');
            } elseif ($name === 'UpdateInterval') {
                $element['visible'] = $this->ReadPropertyInteger('UpdateSchedule') === SynchronizationSchedule::CUSTOM;
                $element['caption'] = $isIcs
                    ? $this->Translate('Account custom interval')
                    : $this->Translate('Custom interval');
            } elseif (in_array($name, [
                'GoogleOAuthHint',
                'GoogleRedirectURI',
                'GoogleShowRedirectURI',
                'GoogleClientID',
                'GoogleClientSecret',
                'GoogleStatus',
                'GoogleConnect',
                'GoogleDisconnect'
            ], true)) {
                $element['visible'] = $isGoogle;
                if ($name === 'GoogleRedirectURI') {
                    $element['caption'] = $this->googleRedirectUriText();
                } elseif ($name === 'GoogleStatus') {
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
        $isPasswordProvider = in_array($provider, [self::PROVIDER_APPLE, self::PROVIDER_CALDAV, self::PROVIDER_ICS], true);
        $isGoogle = $provider === self::PROVIDER_GOOGLE;
        $isIcs = $provider === self::PROVIDER_ICS;
        $this->UpdateFormField('ServerURL', 'visible', $isPasswordProvider);
        $this->UpdateFormField('ServerURL', 'caption', $isIcs ? $this->Translate('iCalendar URL') : $this->Translate('Server URL'));
        $this->UpdateFormField('Username', 'visible', $isPasswordProvider);
        $this->UpdateFormField('Password', 'visible', $isPasswordProvider);
        $this->UpdateFormField('CalendarName', 'visible', $isIcs);
        $this->UpdateFormField('ICalendarTranslationProfile', 'visible', $isIcs);
        $this->UpdateFormField('ICalendarFeeds', 'visible', $isIcs);
        $this->UpdateFormField('ICalendarSubscriptionsHint', 'visible', $isIcs);
        $this->UpdateFormField(
            'UpdateSchedule',
            'caption',
            $isIcs ? $this->Translate('Account discovery schedule') : $this->Translate('Synchronization schedule')
        );
        $this->UpdateFormField(
            'UpdateInterval',
            'caption',
            $isIcs ? $this->Translate('Account custom interval') : $this->Translate('Custom interval')
        );
        $this->UpdateFormField('GoogleStatus', 'visible', $isGoogle);
        $this->UpdateFormField('GoogleOAuthHint', 'visible', $isGoogle);
        $this->UpdateFormField('GoogleRedirectURI', 'visible', $isGoogle);
        $this->UpdateFormField('GoogleRedirectURI', 'caption', $this->googleRedirectUriText());
        $this->UpdateFormField('GoogleShowRedirectURI', 'visible', $isGoogle);
        $this->UpdateFormField('GoogleClientID', 'visible', $isGoogle);
        $this->UpdateFormField('GoogleClientSecret', 'visible', $isGoogle);
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

        if ($provider === self::PROVIDER_ICS) {
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

    public function UpdateScheduleForm(int $schedule): void
    {
        $this->UpdateFormField(
            'UpdateInterval',
            'visible',
            $schedule === SynchronizationSchedule::CUSTOM
        );
    }

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

    public function GetGoogleRedirectURI(): string
    {
        try {
            return $this->googleOAuthRedirectUri();
        } catch (Throwable $exception) {
            return $this->sanitizeError($exception->getMessage());
        }
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
        $this->WriteAttributeString('GoogleTokenClientID', '');
        $this->WriteAttributeString('GoogleOAuthState', '');
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
        $username = match ($this->ReadPropertyInteger('Provider')) {
            self::PROVIDER_GOOGLE => trim($this->ReadAttributeString('GoogleAccount')),
            self::PROVIDER_ICS    => $this->iCalendarSummary(),
            default               => trim($this->ReadPropertyString('Username'))
        };
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

        $this->SetTimerInterval(
            'SynchronizationTimer',
            SynchronizationSchedule::timerInterval(
                $this->ReadPropertyInteger('UpdateSchedule'),
                $this->ReadPropertyInteger('UpdateInterval')
            )
        );
        $this->SetStatus(IS_ACTIVE);
    }

    public function ScheduledSynchronize(): bool
    {
        if (!SynchronizationSchedule::isDue(
            $this->ReadPropertyInteger('UpdateSchedule'),
            $this->ReadPropertyInteger('UpdateInterval'),
            $this->ReadAttributeInteger('LastSynchronization')
        )) {
            return true;
        }

        return $this->Synchronize();
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
                    : ($this->ReadPropertyInteger('Provider') === self::PROVIDER_ICS
                        ? $this->iCalendarSummary()
                        : trim($this->ReadPropertyString('Username'))),
                'lastSynchronization' => $this->ReadAttributeInteger('LastSynchronization'),
                'lastError'           => $this->ReadAttributeString('LastError'),
                'subscriptionCache'   => $this->ReadPropertyInteger('Provider') === self::PROVIDER_ICS
                    ? $this->iCalendarCacheStatus()
                    : []
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    public function ClearCache(): void
    {
        $this->WriteAttributeString('CachedCalendars', '[]');
        $this->WriteAttributeString('ICalendarFeedCache', '{}');
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
        if ($this->ReadPropertyInteger('Provider') === self::PROVIDER_ICS) {
            $this->pruneICalendarFeedCache(array_map(
                static fn(array $calendar): string => (string) ($calendar['id'] ?? ''),
                $calendars
            ));
        }
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
        $this->WriteAttributeString(
            'LastError',
            $this->ReadPropertyInteger('Provider') === self::PROVIDER_ICS
                ? $this->iCalendarCacheWarning()
                : ''
        );

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
        $calendars = json_decode($this->ReadAttributeString('CachedCalendars'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($calendars)) {
            $calendars = [];
        }
        if ($calendarId !== '') {
            foreach ($calendars as $calendar) {
                if (is_array($calendar) && (string) ($calendar['id'] ?? '') === $calendarId
                    && $this->calendarReference($calendar) !== '') {
                    return $calendar;
                }
            }
        }
        $fallback = $this->singleCalendarFallback($calendars);
        if ($fallback !== null) {
            return $fallback;
        }

        $calendars = $this->discoverCalendars();
        if ($calendarId !== '') {
            foreach ($calendars as $calendar) {
                if ((string) ($calendar['id'] ?? '') === $calendarId
                    && $this->calendarReference($calendar) !== '') {
                    return $calendar;
                }
            }
        }
        $fallback = $this->singleCalendarFallback($calendars);
        if ($fallback !== null) {
            return $fallback;
        }

        if ($calendarId === '') {
            throw new InvalidArgumentException('The calendar ID is missing.');
        }

        throw new RuntimeException('The selected calendar is no longer available in this account.');
    }

    /**
     * A single-feed ICS/Webcal account always exposes exactly one calendar.
     * Keep an existing child usable when its gateway or the feed URL changes
     * and its URL-derived calendar ID is missing or no longer matches.
     *
     * @param array<mixed> $calendars
     * @return array<string, mixed>|null
     */
    private function singleCalendarFallback(array $calendars): ?array
    {
        if ($this->ReadPropertyInteger('Provider') !== self::PROVIDER_ICS) {
            return null;
        }

        $available = array_values(array_filter(
            $calendars,
            fn(mixed $calendar): bool => is_array($calendar) && $this->calendarReference($calendar) !== ''
        ));
        if (count($available) !== 1) {
            return null;
        }

        $this->SendDebug(
            'CalendarResolution',
            'Using the only calendar exposed by the ICS/Webcal account because the stored calendar ID is missing or no longer matches.',
            0
        );

        return $available[0];
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

        if ($provider === self::PROVIDER_ICS) {
            return new ICalendarSubscriptionProvider(
                $this->iCalendarSubscriptions(),
                function (array $subscription): ICalendarFeedProvider {
                    $subscriptionId = (string) ($subscription['id'] ?? '');
                    return new ICalendarFeedProvider(
                        new CalendarHttpClient(
                            max(5, min(120, $this->ReadPropertyInteger('RequestTimeout'))),
                            $this->ReadPropertyBoolean('VerifyTLS'),
                            (string) ($subscription['username'] ?? ''),
                            (string) ($subscription['password'] ?? '')
                        ),
                        (string) ($subscription['url'] ?? ''),
                        (string) ($subscription['name'] ?? ''),
                        $this->readICalendarFeedCache($subscriptionId),
                        function (array $cacheState) use ($subscriptionId): void {
                            $this->writeICalendarFeedCache($subscriptionId, $cacheState);
                        }
                    );
                }
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
        if (!SynchronizationSchedule::isValid($this->ReadPropertyInteger('UpdateSchedule'))) {
            return 'The synchronization schedule is invalid.';
        }
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

        if ($provider === self::PROVIDER_ICS) {
            $subscriptions = $this->iCalendarSubscriptions();
            if ($subscriptions === []) {
                return 'At least one active iCalendar subscription is required.';
            }
            $subscriptionUrls = [];
            foreach ($subscriptions as $subscription) {
                $url = trim((string) ($subscription['url'] ?? ''));
                if (filter_var($url, FILTER_VALIDATE_URL) === false
                    || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https', 'webcal'], true)) {
                    return sprintf(
                        'The iCalendar URL for subscription "%s" is invalid.',
                        trim((string) ($subscription['name'] ?? ''))
                    );
                }
                $urlKey = $this->iCalendarUrlKey($url);
                if (isset($subscriptionUrls[$urlKey])) {
                    return sprintf(
                        'The iCalendar URL for subscription "%s" is configured more than once.',
                        trim((string) ($subscription['name'] ?? ''))
                    );
                }
                $subscriptionUrls[$urlKey] = true;
                $color = strtoupper(trim((string) ($subscription['color'] ?? '')));
                if ($color !== '' && preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) {
                    return sprintf(
                        'The color for iCalendar subscription "%s" is invalid.',
                        trim((string) ($subscription['name'] ?? ''))
                    );
                }
                if (!SynchronizationSchedule::isValid((int) ($subscription['updateSchedule'] ?? -1))) {
                    return sprintf(
                        'The synchronization schedule for iCalendar subscription "%s" is invalid.',
                        trim((string) ($subscription['name'] ?? ''))
                    );
                }
                if (!CalendarEventTranslation::isValidProfile(
                    (int) ($subscription['translationProfile'] ?? -1)
                )) {
                    return sprintf(
                        'The title translation profile for iCalendar subscription "%s" is invalid.',
                        trim((string) ($subscription['name'] ?? ''))
                    );
                }
            }
        }

        if ($provider === self::PROVIDER_GOOGLE) {
            if (trim($this->ReadPropertyString('GoogleClientID')) === '') {
                return 'The Google OAuth client ID is missing.';
            }
            if ($this->ReadPropertyString('GoogleClientSecret') === '') {
                return 'The Google OAuth client secret is missing.';
            }
            if (!$this->isGoogleConnected()) {
                return 'Google Calendar is not connected yet.';
            }
        }

        return '';
    }

    private function isProviderImplemented(int $provider): bool
    {
        return in_array(
            $provider,
            [self::PROVIDER_APPLE, self::PROVIDER_CALDAV, self::PROVIDER_GOOGLE, self::PROVIDER_ICS],
            true
        );
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
        } elseif ($exception instanceof ICalendarFeedProviderException) {
            $this->SetStatus(in_array($exception->httpStatus, [401, 403], true)
                ? self::STATUS_AUTHENTICATION_FAILED
                : self::STATUS_CONNECTION_FAILED);
        } elseif ($exception instanceof GoogleOAuthException) {
            $this->SetStatus(self::STATUS_AUTHENTICATION_FAILED);
        } elseif ($exception instanceof JsonException) {
            $this->SetStatus(self::STATUS_INVALID_RESPONSE);
        } else {
            $this->SetStatus(self::STATUS_CONNECTION_FAILED);
        }

        return $message;
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
                $this->Translate('Google Calendar could not be connected') . ': ' . $this->Translate($message),
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
                . $this->Translate($this->sanitizeError($exception->getMessage()));
        }
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

    private function sanitizeError(string $message): string
    {
        $password = $this->ReadPropertyString('Password');
        if ($password !== '') {
            $message = str_replace($password, '***', $message);
        }
        foreach ([
            $this->ReadPropertyString('GoogleClientSecret'),
            $this->ReadAttributeString('GoogleRefreshToken')
        ] as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }
        if ($this->ReadPropertyInteger('Provider') === self::PROVIDER_ICS) {
            foreach ($this->iCalendarSubscriptions() as $subscription) {
                $feedUrl = trim((string) ($subscription['url'] ?? ''));
                $feedPassword = (string) ($subscription['password'] ?? '');
                if ($feedUrl !== '') {
                    $message = str_replace($feedUrl, '[iCalendar URL]', $message);
                }
                if ($feedPassword !== '') {
                    $message = str_replace($feedPassword, '***', $message);
                }
            }
        }

        return $message;
    }

    /**
     * @return list<array{
     *     url: string,
     *     name: string,
     *     username: string,
     *     password: string,
     *     color: string,
     *     translationProfile: int,
     *     updateSchedule: int,
     *     updateInterval: int
     * }>
     */
    private function iCalendarSubscriptions(): array
    {
        try {
            $configured = json_decode(
                $this->ReadPropertyString('ICalendarFeeds'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            $configured = [];
        }

        $subscriptions = [];
        $configuredUrls = [];
        if (is_array($configured)) {
            foreach ($configured as $feed) {
                if (!is_array($feed)
                    || !(bool) ($feed['Active'] ?? $feed['active'] ?? true)) {
                    continue;
                }
                $url = trim((string) ($feed['URL'] ?? $feed['url'] ?? ''));
                $subscriptions[] = [
                    'url'                => $url,
                    'name'               => trim((string) ($feed['Name'] ?? $feed['name'] ?? '')),
                    'username'           => trim((string) ($feed['Username'] ?? $feed['username'] ?? '')),
                    'password'           => (string) ($feed['Password'] ?? $feed['password'] ?? ''),
                    'color'              => trim((string) ($feed['Color'] ?? $feed['color'] ?? '')),
                    'translationProfile' => (int) (
                        $feed['TranslationProfile']
                        ?? $feed['translationProfile']
                        ?? CalendarEventTranslation::NONE
                    ),
                    'updateSchedule'     => (int) (
                        $feed['UpdateSchedule']
                        ?? $feed['updateSchedule']
                        ?? $this->ReadPropertyInteger('UpdateSchedule')
                    ),
                    'updateInterval'     => (int) (
                        $feed['UpdateInterval']
                        ?? $feed['updateInterval']
                        ?? $this->ReadPropertyInteger('UpdateInterval')
                    )
                ];
                $configuredUrls[$this->iCalendarUrlKey($url)] = true;
            }
        }

        $legacyUrl = trim($this->ReadPropertyString('ServerURL'));
        if ($legacyUrl !== ''
            && $legacyUrl !== self::APPLE_CALDAV_URL
            && !isset($configuredUrls[$this->iCalendarUrlKey($legacyUrl)])) {
            array_unshift($subscriptions, [
                'url'                => $legacyUrl,
                'name'               => trim($this->ReadPropertyString('CalendarName')),
                'username'           => trim($this->ReadPropertyString('Username')),
                'password'           => $this->ReadPropertyString('Password'),
                'color'              => '',
                'translationProfile' => $this->ReadPropertyInteger('ICalendarTranslationProfile'),
                'updateSchedule'     => $this->ReadPropertyInteger('UpdateSchedule'),
                'updateInterval'     => $this->ReadPropertyInteger('UpdateInterval')
            ]);
        }

        return $subscriptions;
    }

    private function iCalendarSummary(): string
    {
        $subscriptions = $this->iCalendarSubscriptions();
        if (count($subscriptions) > 1) {
            return sprintf($this->Translate('%d subscriptions'), count($subscriptions));
        }

        return trim((string) ($subscriptions[0]['name'] ?? ''));
    }

    private function iCalendarUrlKey(string $url): string
    {
        $url = trim($url);
        if (str_starts_with(strtolower($url), 'webcal://')) {
            $url = 'https://' . substr($url, 9);
        }

        return $url;
    }

    /**
     * @return array<string, mixed>
     */
    private function readICalendarFeedCache(string $subscriptionId): array
    {
        if ($subscriptionId === '') {
            return [];
        }

        $entries = $this->readICalendarFeedCacheEntries();
        $entry = $entries[$subscriptionId] ?? null;
        if (!is_array($entry)) {
            return [];
        }

        $encodedBody = (string) ($entry['bodyData'] ?? '');
        $body = base64_decode($encodedBody, true);
        if (!is_string($body)) {
            return [];
        }
        if (($entry['encoding'] ?? '') === 'gzip-base64') {
            if (!function_exists('gzdecode')) {
                return [];
            }
            $decoded = gzdecode($body);
            if (!is_string($decoded)) {
                return [];
            }
            $body = $decoded;
        } elseif (($entry['encoding'] ?? '') !== 'base64') {
            return [];
        }

        unset($entry['bodyData'], $entry['encoding']);
        $entry['body'] = $body;

        return $entry;
    }

    /**
     * @param array<string, mixed> $cacheState
     */
    private function writeICalendarFeedCache(string $subscriptionId, array $cacheState): void
    {
        if ($subscriptionId === '') {
            return;
        }

        $body = (string) ($cacheState['body'] ?? '');
        if ($body === '') {
            return;
        }

        $encoding = 'base64';
        $bodyData = $body;
        if (function_exists('gzencode')) {
            $compressed = gzencode($body, 6);
            if (is_string($compressed) && strlen($compressed) < strlen($body)) {
                $encoding = 'gzip-base64';
                $bodyData = $compressed;
            }
        }

        unset($cacheState['body']);
        $cacheState['encoding'] = $encoding;
        $cacheState['bodyData'] = base64_encode($bodyData);

        $entries = $this->readICalendarFeedCacheEntries();
        $entries[$subscriptionId] = $cacheState;
        $this->WriteAttributeString(
            'ICalendarFeedCache',
            json_encode(
                $entries,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );
        $this->WriteAttributeString('LastError', $this->iCalendarCacheWarning());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readICalendarFeedCacheEntries(): array
    {
        try {
            $entries = json_decode(
                $this->ReadAttributeString('ICalendarFeedCache'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            return is_array($entries) ? array_filter($entries, 'is_array') : [];
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * @param list<string> $activeIds
     */
    private function pruneICalendarFeedCache(array $activeIds): void
    {
        $activeIds = array_fill_keys(array_filter($activeIds), true);
        $entries = array_intersect_key($this->readICalendarFeedCacheEntries(), $activeIds);
        $this->WriteAttributeString(
            'ICalendarFeedCache',
            json_encode(
                $entries,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function iCalendarCacheStatus(): array
    {
        $entries = $this->readICalendarFeedCacheEntries();
        $status = [];
        foreach ($this->iCalendarSubscriptions() as $subscription) {
            $id = hash('sha256', 'ics|' . $this->iCalendarUrlKey((string) ($subscription['url'] ?? '')));
            $entry = is_array($entries[$id] ?? null) ? $entries[$id] : [];
            $status[] = [
                'id'           => $id,
                'name'         => (string) ($subscription['name'] ?? ''),
                'lastCheck'    => (int) ($entry['lastCheck'] ?? 0),
                'lastDownload' => (int) ($entry['lastDownload'] ?? 0),
                'lastChange'   => (int) ($entry['lastChange'] ?? 0),
                'stale'        => (bool) ($entry['stale'] ?? false),
                'lastError'    => $this->sanitizeError((string) ($entry['lastError'] ?? ''))
            ];
        }

        return $status;
    }

    private function iCalendarCacheWarning(): string
    {
        $staleFeeds = array_values(array_filter(
            $this->iCalendarCacheStatus(),
            static fn(array $status): bool => (bool) ($status['stale'] ?? false)
        ));
        if ($staleFeeds === []) {
            return '';
        }

        $names = array_map(
            static fn(array $status): string => trim((string) ($status['name'] ?? '')) ?: 'iCalendar',
            $staleFeeds
        );

        return sprintf(
            $this->Translate('Using the last valid cached data for: %s.'),
            implode(', ', $names)
        );
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
