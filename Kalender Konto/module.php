<?php

declare(strict_types=1);

use IPSKalender\CalendarHttpClient;
use IPSKalender\CalendarEventTranslation;
use IPSKalender\CalendarProviderInterface;
use IPSKalender\CalDAVProvider;
use IPSKalender\CalDAVProviderException;
use IPSKalender\CalDAVOriginPolicy;
use IPSKalender\GoogleCalendarProvider;
use IPSKalender\GoogleCalendarProviderException;
use IPSKalender\GoogleOAuthException;
use IPSKalender\ICalendarFeedProvider;
use IPSKalender\ICalendarFeedProviderException;
use IPSKalender\ICalendarSubscriptionProvider;
use IPSKalender\SynchronizationSchedule;

require_once __DIR__ . '/../libs/CalendarProviderInterface.php';
require_once __DIR__ . '/../libs/CalendarHttpClient.php';
require_once __DIR__ . '/../libs/CalendarEventTranslation.php';
require_once __DIR__ . '/../libs/CalDAVProvider.php';
require_once __DIR__ . '/../libs/CalDAVOriginPolicy.php';
require_once __DIR__ . '/../libs/GoogleCalendarProvider.php';
require_once __DIR__ . '/../libs/GoogleOAuthClient.php';
require_once __DIR__ . '/../libs/ICalendarFeedProvider.php';
require_once __DIR__ . '/../libs/ICalendarSubscriptionProvider.php';
require_once __DIR__ . '/../libs/SynchronizationSchedule.php';
require_once __DIR__ . '/traits/GoogleOAuthTrait.php';
require_once __DIR__ . '/traits/ICalendarAccountTrait.php';
require_once __DIR__ . '/traits/ChildGatewayTrait.php';

class KalenderKonto extends IPSModuleStrict
{
    use KalenderKontoGoogleOAuthTrait;
    use KalenderKontoICalendarAccountTrait;
    use KalenderKontoChildGatewayTrait;

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

    /**
     * Registers account properties, provider state, cache attributes, timers, and OAuth hook.
     */
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

    /**
     * Builds the provider-specific account configuration form.
     *
     * @return string JSON-encoded configuration form.
     */
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

    /**
     * Updates provider-specific form fields when the provider selection changes.
     */
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

    /**
     * Updates the custom interval field for the selected synchronization schedule.
     */
    public function UpdateScheduleForm(int $schedule): void
    {
        $this->UpdateFormField(
            'UpdateInterval',
            'visible',
            $schedule === SynchronizationSchedule::CUSTOM
        );
    }

    /**
     * Handles actions triggered from the account configuration form.
     *
     * @param string $Ident Action identifier supplied by Symcon.
     * @param mixed  $Value Action value supplied by Symcon.
     */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'FormTestConnection':
                $result = json_decode($this->TestConnection(), true);
                $this->UpdateFormField(
                    is_array($result) && ($result['success'] ?? false)
                        ? 'ConnectionSuccessPopup'
                        : 'ConnectionFailurePopup',
                    'visible',
                    true
                );
                break;

            case 'FormSynchronize':
                $this->UpdateFormField(
                    $this->Synchronize() ? 'SynchronizationSuccessPopup' : 'SynchronizationFailurePopup',
                    'visible',
                    true
                );
                break;

            case 'FormClearCache':
                $this->ClearCache();
                $this->UpdateFormField('CacheClearedPopup', 'visible', true);
                break;

            case 'FormGoogleAuthorizationFailed':
                $this->UpdateFormField('GoogleAuthorizationFailedPopup', 'visible', true);
                break;

            case 'FormGoogleRedirectUnavailable':
                $this->UpdateFormField('GoogleRedirectUnavailablePopup', 'visible', true);
                break;

            default:
                throw new InvalidArgumentException('Unsupported form action: ' . $Ident);
        }
    }

    /**
     * Applies account configuration, validates the provider, and configures synchronization.
     */
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
            $this->WriteAttributeString('LastError', $this->Translate('The selected provider is not implemented yet.'));
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

    /**
     * Runs account synchronization when the configured schedule is due.
     *
     * @return bool True when no synchronization was due or synchronization succeeded.
     */
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

    /**
     * Tests the configured provider connection without modifying calendar data.
     *
     * @return string JSON-encoded connection test result.
     */
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
            $message = $this->Translate('The selected provider is not implemented yet.');
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
            if (isset($result['message']) && is_string($result['message'])) {
                $result['message'] = $this->Translate($result['message']);
            }
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

    /**
     * Refreshes the account-level calendar discovery cache.
     *
     * @return bool True when synchronization succeeded.
     */
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
            $this->WriteAttributeString('LastError', $this->Translate('The selected provider is not implemented yet.'));
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

    /**
     * Returns the calendars currently cached for this account.
     *
     * @return string JSON-encoded calendar list.
     */
    public function GetCalendars(): string
    {
        return $this->ReadAttributeString('CachedCalendars');
    }

    /**
     * Returns provider, synchronization, and cache status for this account.
     *
     * @return string JSON-encoded account status.
     */
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

    /**
     * Clears account-level calendar and iCalendar feed caches.
     */
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

        $originPolicy = new CalDAVOriginPolicy($serverUrl);

        return new CalDAVProvider(
            new CalendarHttpClient(
                max(5, min(120, $this->ReadPropertyInteger('RequestTimeout'))),
                $this->ReadPropertyBoolean('VerifyTLS'),
                trim($this->ReadPropertyString('Username')),
                $this->ReadPropertyString('Password'),
                $originPolicy
            ),
            $serverUrl,
            $originPolicy
        );
    }

    private function validateConfiguration(): string
    {
        $provider = $this->ReadPropertyInteger('Provider');
        if (!SynchronizationSchedule::isValid($this->ReadPropertyInteger('UpdateSchedule'))) {
            return $this->Translate('The synchronization schedule is invalid.');
        }
        if (!in_array($provider, [
            self::PROVIDER_APPLE,
            self::PROVIDER_CALDAV,
            self::PROVIDER_GOOGLE,
            self::PROVIDER_MICROSOFT,
            self::PROVIDER_ICS
        ], true)) {
            return $this->Translate('Unknown calendar provider.');
        }

        if ($provider === self::PROVIDER_APPLE) {
            if (trim($this->ReadPropertyString('Username')) === '') {
                return $this->Translate('The Apple Account email address is missing.');
            }
            if ($this->ReadPropertyString('Password') === '') {
                return $this->Translate('The app-specific password is missing.');
            }
        }

        if ($provider === self::PROVIDER_CALDAV && trim($this->ReadPropertyString('ServerURL')) === '') {
            return $this->Translate('The CalDAV server URL is missing.');
        }

        if ($provider === self::PROVIDER_ICS) {
            $subscriptions = $this->iCalendarSubscriptions();
            if ($subscriptions === []) {
                return $this->Translate('At least one active iCalendar subscription is required.');
            }
            $subscriptionUrls = [];
            foreach ($subscriptions as $subscription) {
                $url = trim((string) ($subscription['url'] ?? ''));
                if (filter_var($url, FILTER_VALIDATE_URL) === false
                    || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https', 'webcal'], true)) {
                    return sprintf(
                        $this->Translate('The iCalendar URL for subscription "%s" is invalid.'),
                        trim((string) ($subscription['name'] ?? ''))
                    );
                }
                $urlKey = $this->iCalendarUrlKey($url);
                if (isset($subscriptionUrls[$urlKey])) {
                    return sprintf(
                        $this->Translate('The iCalendar URL for subscription "%s" is configured more than once.'),
                        trim((string) ($subscription['name'] ?? ''))
                    );
                }
                $subscriptionUrls[$urlKey] = true;
                $color = strtoupper(trim((string) ($subscription['color'] ?? '')));
                if ($color !== '' && preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) {
                    return sprintf(
                        $this->Translate('The color for iCalendar subscription "%s" is invalid.'),
                        trim((string) ($subscription['name'] ?? ''))
                    );
                }
                if (!SynchronizationSchedule::isValid((int) ($subscription['updateSchedule'] ?? -1))) {
                    return sprintf(
                        $this->Translate('The synchronization schedule for iCalendar subscription "%s" is invalid.'),
                        trim((string) ($subscription['name'] ?? ''))
                    );
                }
                if (!CalendarEventTranslation::isValidProfile(
                    (int) ($subscription['translationProfile'] ?? -1)
                )) {
                    return sprintf(
                        $this->Translate('The title translation profile for iCalendar subscription "%s" is invalid.'),
                        trim((string) ($subscription['name'] ?? ''))
                    );
                }
            }
        }

        if ($provider === self::PROVIDER_GOOGLE) {
            if (trim($this->ReadPropertyString('GoogleClientID')) === '') {
                return $this->Translate('The Google OAuth client ID is missing.');
            }
            if ($this->ReadPropertyString('GoogleClientSecret') === '') {
                return $this->Translate('The Google OAuth client secret is missing.');
            }
            if (!$this->isGoogleConnected()) {
                return $this->Translate('Google Calendar is not connected yet.');
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
        return $this->Translate(match ($provider) {
            self::PROVIDER_APPLE     => 'Apple iCloud',
            self::PROVIDER_CALDAV    => 'CalDAV',
            self::PROVIDER_GOOGLE    => 'Google Calendar',
            self::PROVIDER_MICROSOFT => 'Microsoft 365',
            self::PROVIDER_ICS       => 'ICS/Webcal',
            default                  => 'Unknown'
        });
    }

    private function handleProviderError(Throwable $exception): string
    {
        $rawMessage = $this->sanitizeError($exception->getMessage());

        if ($exception instanceof CalDAVProviderException) {
            if (in_array($exception->httpStatus, [401, 403], true)) {
                $this->SetStatus(self::STATUS_AUTHENTICATION_FAILED);
            } elseif (str_contains(strtolower($rawMessage), 'xml')) {
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

        $message = $exception instanceof JsonException
            ? $this->Translate('Invalid JSON data.')
            : $this->translateErrorMessage($rawMessage);
        $this->WriteAttributeString('LastError', $message);
        $this->SendDebug('ProviderError', $rawMessage, 0);

        return $message;
    }

    private function createUnauthenticatedHttpClient(): CalendarHttpClient
    {
        return new CalendarHttpClient(
            max(5, min(120, $this->ReadPropertyInteger('RequestTimeout'))),
            $this->ReadPropertyBoolean('VerifyTLS')
        );
    }

    private function translateErrorMessage(string $message): string
    {
        $message = $this->sanitizeError($message);
        if ($message === '') {
            return '';
        }

        if (preg_match('/^Unsupported operation: (.+)$/', $message, $matches) === 1) {
            return sprintf($this->Translate('Unsupported operation: %s'), $matches[1]);
        }
        if (preg_match('/^Unexpected CalDAV response during (.+): HTTP (\d+)\.$/', $message, $matches) === 1) {
            return sprintf(
                $this->Translate('Unexpected CalDAV response during %s: HTTP %d.'),
                $this->Translate($matches[1]),
                (int) $matches[2]
            );
        }

        $patterns = [
            '/^HTTP request failed \((\d+)\): (.+)$/' => ['HTTP request failed (%d): %s', [1, 2]],
            '/^Unexpected CalDAV response: HTTP (\d+)\.$/' => ['Unexpected CalDAV response: HTTP %d.', [1]],
            '/^Google Calendar request failed with HTTP (\d+)\.$/' => ['Google Calendar request failed with HTTP %d.', [1]],
            '/^The calendar feed returned HTTP status (\d+)\.$/' => ['The calendar feed returned HTTP status %d.', [1]],
            '/^The calendar contains an invalid date value: (.+)$/' => ['The calendar contains an invalid date value: %s', [1]],
            '/^The iCalendar subscription URL for "(.+)" is configured more than once\.$/' => ['The iCalendar subscription URL for "%s" is configured more than once.', [1]],
            '/^The configured color for iCalendar subscription "(.+)" is invalid\.$/' => ['The configured color for iCalendar subscription "%s" is invalid.', [1]],
            '/^The synchronization schedule for iCalendar subscription "(.+)" is invalid\.$/' => ['The synchronization schedule for iCalendar subscription "%s" is invalid.', [1]],
            '/^The title translation profile for iCalendar subscription "(.+)" is invalid\.$/' => ['The title translation profile for iCalendar subscription "%s" is invalid.', [1]],
            '/^The iCalendar URL for subscription "(.+)" is invalid\.$/' => ['The iCalendar URL for subscription "%s" is invalid.', [1]],
            '/^The iCalendar URL for subscription "(.+)" is configured more than once\.$/' => ['The iCalendar URL for subscription "%s" is configured more than once.', [1]],
            '/^The color for iCalendar subscription "(.+)" is invalid\.$/' => ['The color for iCalendar subscription "%s" is invalid.', [1]],
        ];

        foreach ($patterns as $pattern => [$template, $groups]) {
            if (preg_match($pattern, $message, $matches) !== 1) {
                continue;
            }
            $values = array_map(static fn(int $group): string => $matches[$group], $groups);
            return sprintf($this->Translate($template), ...$values);
        }

        if (str_starts_with($message, 'The calendar feed could not be refreshed: ')) {
            $detail = substr($message, strlen('The calendar feed could not be refreshed: '));
            return sprintf(
                $this->Translate('The calendar feed could not be refreshed: %s'),
                $this->translateErrorMessage($detail)
            );
        }

        return $this->Translate($message);
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

}
