<?php

declare(strict_types=1);

use IPSKalender\SynchronizationSchedule;

require_once __DIR__ . '/../libs/SynchronizationSchedule.php';

class Kalender extends IPSModuleStrict
{
    private const DATA_ID_TO_PARENT = '{4E535B1D-69C7-AC77-1372-0282B21BAEC9}';
    private const INITIALIZATION_DELAY_MS = 3_000;

    private const STATUS_CONFIGURATION_MISSING = 201;
    private const STATUS_SYNCHRONIZATION_FAILED = 202;
    private const STATUS_INVALID_RESPONSE = 203;
    private const STATUS_WRITE_CONFLICT = 204;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('CalendarID', '');
        $this->RegisterPropertyString('ProviderCalendarID', '');
        $this->RegisterPropertyString('CalendarURL', '');
        $this->RegisterPropertyString('CalendarColor', '');
        $this->RegisterPropertyBoolean('CanWrite', false);
        $this->RegisterPropertyInteger('UpdateSchedule', SynchronizationSchedule::CUSTOM);
        $this->RegisterPropertyInteger('UpdateInterval', 15);
        $this->RegisterPropertyInteger('PastDays', 30);
        $this->RegisterPropertyInteger('FutureDays', 365);

        $this->RegisterAttributeString('CachedEvents', '[]');
        $this->RegisterAttributeInteger('LastSynchronization', 0);
        $this->RegisterAttributeString('LastError', '');
        $this->RegisterAttributeBoolean('CalendarMetadataAvailable', false);
        $this->RegisterAttributeString('DetectedCalendarColor', '');
        $this->RegisterAttributeBoolean('DetectedCanWrite', false);
        $this->RegisterAttributeBoolean('RuntimeReady', false);

        $this->RegisterVariableInteger('EventCount', 'Event count', '', 10);
        $this->RegisterVariableInteger('LastSynchronization', 'Last synchronization', '~UnixTimestamp', 20);
        $this->RegisterVariableString('Events', 'Events', '', 30);

        $this->RegisterTimer('InitializationTimer', 0, 'IPSKAL_Initialize($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SynchronizationTimer', 0, 'IPSKAL_ScheduledSynchronize($_IPS[\'TARGET\']);');
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(
            file_get_contents(__DIR__ . '/form.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $customSchedule = $this->ReadPropertyInteger('UpdateSchedule') === SynchronizationSchedule::CUSTOM;
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'UpdateInterval') {
                $element['visible'] = $customSchedule;
                break;
            }
        }
        unset($element);

        return json_encode(
            $form,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    public function UpdateScheduleForm(int $schedule): void
    {
        $this->UpdateFormField(
            'UpdateInterval',
            'visible',
            $schedule === SynchronizationSchedule::CUSTOM
        );
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->WriteAttributeBoolean('RuntimeReady', false);
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->SetTimerInterval('InitializationTimer', 0);
        $this->SetTimerInterval('SynchronizationTimer', 0);

        $validationError = $this->validateConfiguration();
        if ($validationError !== '') {
            $this->WriteAttributeString('LastError', $validationError);
            $this->SetStatus(self::STATUS_CONFIGURATION_MISSING);
            return;
        }

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->scheduleInitialization();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($SenderID === 0 && $Message === IPS_KERNELSTARTED) {
            $this->scheduleInitialization();
        }
    }

    public function Initialize(): bool
    {
        $this->SetTimerInterval('InitializationTimer', 0);
        if (IPS_GetKernelRunlevel() !== KR_READY
            || !$this->ReadPropertyBoolean('Active')
            || $this->validateConfiguration() !== '') {
            return false;
        }

        $this->WriteAttributeBoolean('RuntimeReady', true);
        $this->SetTimerInterval(
            'SynchronizationTimer',
            SynchronizationSchedule::timerInterval(
                $this->ReadPropertyInteger('UpdateSchedule'),
                $this->ReadPropertyInteger('UpdateInterval')
            )
        );
        $this->refreshCalendarMetadataSafely();
        $this->SetStatus(IS_ACTIVE);

        return true;
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

    public function ReceiveData(string $JSONString): string
    {
        try {
            $message = json_decode($JSONString, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($message) && ($message['Operation'] ?? '') === 'CalendarsUpdated'
                && is_array($message['Payload'] ?? null)) {
                $this->applyCalendarMetadata($message['Payload']);
            }
        } catch (Throwable $exception) {
            $this->SendDebug('CalendarMetadata', $exception->getMessage(), 0);
        }

        return '';
    }

    public function Synchronize(): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return false;
        }

        try {
            $this->refreshCalendarMetadataSafely();
            $events = $this->requestEvents();
            $this->storeEvents($events);
            $this->WriteAttributeString('LastError', '');
            $this->SetStatus(IS_ACTIVE);
            $this->SendDebug('Synchronize', sprintf('%d events synchronized.', count($events)), 0);
            return true;
        } catch (Throwable $exception) {
            $this->handleError($exception);
            return false;
        }
    }

    public function GetEvents(): string
    {
        return $this->ReadAttributeString('CachedEvents');
    }

    public function CreateEvent(string $EventJSON): string
    {
        try {
            $event = $this->decodeObject($EventJSON, 'event');
            $created = $this->sendRequest('CreateEvent', ['Event' => $event]);
            $this->refreshAfterWrite();

            return $this->encodeResult(true, $created);
        } catch (Throwable $exception) {
            return $this->encodeResult(false, null, $this->handleError($exception));
        }
    }

    public function UpdateEvent(string $EventJSON): string
    {
        try {
            $event = $this->decodeObject($EventJSON, 'event');
            $changes = $event['changes'] ?? $event;
            if (!is_array($changes)) {
                throw new InvalidArgumentException('The event changes are invalid.');
            }
            foreach (['uid', 'resourceUrl', 'etag', 'recurrenceId', 'changes'] as $metadataKey) {
                unset($changes[$metadataKey]);
            }
            if ($changes === []) {
                throw new InvalidArgumentException('No event changes were supplied.');
            }

            $updated = $this->sendRequest(
                'UpdateEvent',
                [
                    'UID'         => trim((string) ($event['uid'] ?? '')),
                    'ResourceURL' => trim((string) ($event['resourceUrl'] ?? '')),
                    'ETag'        => trim((string) ($event['etag'] ?? '')),
                    'Event'       => $changes
                ]
            );
            $this->refreshAfterWrite();

            return $this->encodeResult(true, $updated);
        } catch (Throwable $exception) {
            return $this->encodeResult(false, null, $this->handleError($exception));
        }
    }

    public function DeleteEvent(string $EventJSON): bool
    {
        try {
            $event = $this->decodeObject($EventJSON, 'event');
            $result = $this->sendRequest(
                'DeleteEvent',
                [
                    'ResourceURL' => trim((string) ($event['resourceUrl'] ?? '')),
                    'ETag'        => trim((string) ($event['etag'] ?? '')),
                    'RecurrenceID' => trim((string) ($event['recurrenceId'] ?? ''))
                ]
            );
            if (!(bool) ($result['success'] ?? false)) {
                throw new RuntimeException('The calendar account did not confirm the deletion.');
            }
            $this->refreshAfterWrite();
            return true;
        } catch (Throwable $exception) {
            $this->handleError($exception);
            return false;
        }
    }

    public function ClearCache(): void
    {
        $this->storeEvents([]);
        $this->WriteAttributeInteger('LastSynchronization', 0);
        $this->SetValue('LastSynchronization', 0);
        $this->WriteAttributeString('LastError', '');
    }

    public function GetCalendarStatus(): string
    {
        if ($this->isRuntimeReady()) {
            $this->refreshCalendarMetadataSafely();
        }
        $metadataAvailable = $this->ReadAttributeBoolean('CalendarMetadataAvailable');
        $detectedColor = $this->ReadAttributeString('DetectedCalendarColor');

        return json_encode(
            [
                'calendarId'          => $this->ReadPropertyString('CalendarID'),
                'calendarColor'       => $metadataAvailable && $detectedColor !== ''
                    ? $detectedColor
                    : $this->ReadPropertyString('CalendarColor'),
                'canWrite'            => $metadataAvailable
                    ? $this->ReadAttributeBoolean('DetectedCanWrite')
                    : $this->ReadPropertyBoolean('CanWrite'),
                'eventCount'          => count($this->readEvents()),
                'lastSynchronization' => $this->ReadAttributeInteger('LastSynchronization'),
                'lastError'           => $this->ReadAttributeString('LastError')
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function refreshCalendarMetadataSafely(): void
    {
        try {
            $this->applyCalendarMetadata($this->sendRequest('GetCalendars'));
        } catch (Throwable $exception) {
            $this->SendDebug('CalendarMetadata', $exception->getMessage(), 0);
        }
    }

    /**
     * @param list<array<string, mixed>> $calendars
     */
    private function applyCalendarMetadata(array $calendars): void
    {
        $calendarId = $this->ReadPropertyString('CalendarID');
        $providerCalendarId = $this->ReadPropertyString('ProviderCalendarID');
        $calendarUrl = $this->ReadPropertyString('CalendarURL');
        $availableCalendars = [];

        foreach ($calendars as $calendar) {
            if (!is_array($calendar)) {
                continue;
            }
            $availableCalendars[] = $calendar;
            $matches = ($calendarId !== '' && (string) ($calendar['id'] ?? '') === $calendarId)
                || ($providerCalendarId !== '' && (string) ($calendar['providerId'] ?? '') === $providerCalendarId)
                || ($calendarUrl !== '' && (string) ($calendar['url'] ?? '') === $calendarUrl);
            if (!$matches) {
                continue;
            }

            $this->storeCalendarMetadata($calendar);
            return;
        }

        if (count($availableCalendars) === 1) {
            $this->storeCalendarMetadata($availableCalendars[0]);
            return;
        }

        if ($availableCalendars !== []) {
            $this->WriteAttributeBoolean('CalendarMetadataAvailable', false);
        }
    }

    /**
     * @param array<string, mixed> $calendar
     */
    private function storeCalendarMetadata(array $calendar): void
    {
        $capabilities = is_array($calendar['capabilities'] ?? null) ? $calendar['capabilities'] : [];
        $canWrite = (bool) ($capabilities['create'] ?? false)
            || (bool) ($capabilities['update'] ?? false)
            || (bool) ($capabilities['delete'] ?? false);
        $this->WriteAttributeString('DetectedCalendarColor', trim((string) ($calendar['color'] ?? '')));
        $this->WriteAttributeBoolean('DetectedCanWrite', $canWrite);
        $this->WriteAttributeBoolean('CalendarMetadataAvailable', true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requestEvents(): array
    {
        $pastDays = max(0, min(1095, $this->ReadPropertyInteger('PastDays')));
        $futureDays = max(1, min(1095, $this->ReadPropertyInteger('FutureDays')));
        $today = new DateTimeImmutable('today');
        $start = $today->modify('-' . $pastDays . ' days');
        $end = $today->modify('+' . ($futureDays + 1) . ' days');
        $payload = $this->sendRequest(
            'GetEvents',
            ['Start' => $start->getTimestamp(), 'End' => $end->getTimestamp()]
        );

        return array_values(array_filter($payload, 'is_array'));
    }

    /**
     * @param array<string, mixed> $additionalData
     * @return array<mixed>
     */
    private function sendRequest(string $operation, array $additionalData = []): array
    {
        if (!$this->isRuntimeReady()) {
            throw new RuntimeException('The calendar instance is still initializing.');
        }
        if (!$this->HasActiveParent()) {
            throw new RuntimeException('No active calendar account is connected.');
        }

        $request = array_merge(
            [
                'DataID'     => self::DATA_ID_TO_PARENT,
                'Operation'  => $operation,
                'RequestID'  => bin2hex(random_bytes(8)),
                'CalendarID' => $this->ReadPropertyString('CalendarID')
            ],
            $additionalData
        );
        $responseJson = $this->SendDataToParent(json_encode(
            $request,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
        if ($responseJson === '') {
            throw new RuntimeException('The calendar account did not return a response.');
        }

        $response = json_decode($responseJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($response) || !($response['Success'] ?? false)) {
            $error = is_array($response) ? trim((string) ($response['Error'] ?? '')) : '';
            throw new RuntimeException($error !== '' ? $error : 'The calendar account rejected the request.');
        }
        $payload = $response['Payload'] ?? null;
        if (!is_array($payload)) {
            throw new UnexpectedValueException('The calendar account returned invalid data.');
        }

        return $payload;
    }

    private function scheduleInitialization(): void
    {
        if (IPS_GetKernelRunlevel() === KR_READY && $this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('InitializationTimer', self::INITIALIZATION_DELAY_MS);
        }
    }

    private function isRuntimeReady(): bool
    {
        return IPS_GetKernelRunlevel() === KR_READY
            && $this->ReadAttributeBoolean('RuntimeReady');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function storeEvents(array $events): void
    {
        $encoded = json_encode(
            $events,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $timestamp = time();
        $this->WriteAttributeString('CachedEvents', $encoded);
        $this->WriteAttributeInteger('LastSynchronization', $timestamp);
        $this->SetValue('Events', $encoded);
        $this->SetValue('EventCount', count($events));
        $this->SetValue('LastSynchronization', $timestamp);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readEvents(): array
    {
        try {
            $events = json_decode($this->ReadAttributeString('CachedEvents'), true, 512, JSON_THROW_ON_ERROR);
            return is_array($events) ? array_values(array_filter($events, 'is_array')) : [];
        } catch (JsonException) {
            return [];
        }
    }

    private function refreshAfterWrite(): void
    {
        $events = $this->requestEvents();
        $this->storeEvents($events);
        $this->WriteAttributeString('LastError', '');
        $this->SetStatus($this->ReadPropertyBoolean('Active') ? IS_ACTIVE : IS_INACTIVE);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $json, string $description): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('The ' . $description . ' must be a JSON object.');
        }
        return $data;
    }

    private function validateConfiguration(): string
    {
        if (!SynchronizationSchedule::isValid($this->ReadPropertyInteger('UpdateSchedule'))) {
            return 'The synchronization schedule is invalid.';
        }
        if (trim($this->ReadPropertyString('CalendarID')) === ''
            && trim($this->ReadPropertyString('ProviderCalendarID')) === ''
            && !$this->HasActiveParent()) {
            return 'The calendar ID is missing.';
        }
        return '';
    }

    private function handleError(Throwable $exception): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? '');
        if ($message === '') {
            $message = 'Unknown calendar error.';
        }
        $this->WriteAttributeString('LastError', $message);
        $this->SendDebug('CalendarError', $message, 0);

        if (str_contains(strtolower($message), 'changed by another client')) {
            $this->SetStatus(self::STATUS_WRITE_CONFLICT);
        } elseif ($exception instanceof JsonException || str_contains(strtolower($message), 'invalid data')) {
            $this->SetStatus(self::STATUS_INVALID_RESPONSE);
        } else {
            $this->SetStatus(self::STATUS_SYNCHRONIZATION_FAILED);
        }

        return $message;
    }

    private function encodeResult(bool $success, mixed $event = null, string $error = ''): string
    {
        return json_encode(
            ['success' => $success, 'event' => $event, 'error' => $error],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
