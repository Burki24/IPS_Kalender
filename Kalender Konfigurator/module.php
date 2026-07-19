<?php

declare(strict_types=1);

class KalenderKonfigurator extends IPSModuleStrict
{
    private const DATA_ID_TO_PARENT = '{4E535B1D-69C7-AC77-1372-0282B21BAEC9}';
    private const CALENDAR_MODULE_ID = '{227B63E4-4223-316B-76E9-FD3849689562}';

    private const STATUS_DISCOVERY_FAILED = 201;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterAttributeString('CachedCalendars', '[]');
        $this->RegisterAttributeString('LastError', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetStatus(IS_ACTIVE);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(
            file_get_contents(__DIR__ . '/form.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        try {
            $calendars = $this->requestCalendars();
            $this->cacheCalendars($calendars);
            $this->WriteAttributeString('LastError', '');
            $this->SetStatus(IS_ACTIVE);
        } catch (Throwable $exception) {
            $message = $this->sanitizeError($exception->getMessage());
            $this->WriteAttributeString('LastError', $message);
            $this->SetStatus(self::STATUS_DISCOVERY_FAILED);
            $calendars = $this->readCachedCalendars();
            $form['actions'][] = [
                'type'    => 'Label',
                'caption' => $this->Translate('Calendar discovery failed') . ': ' . $message
            ];
        }

        $form['elements'][0]['values'] = $this->buildConfiguratorValues($calendars);

        return json_encode(
            $form,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    public function RefreshCalendars(): string
    {
        try {
            $calendars = $this->requestCalendars();
            $this->cacheCalendars($calendars);
            $this->WriteAttributeString('LastError', '');
            $this->SetStatus(IS_ACTIVE);
            $this->UpdateFormField(
                'Calendars',
                'values',
                json_encode(
                    $this->buildConfiguratorValues($calendars),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                )
            );

            return sprintf($this->Translate('%d calendars found.'), count($calendars));
        } catch (Throwable $exception) {
            $message = $this->sanitizeError($exception->getMessage());
            $this->WriteAttributeString('LastError', $message);
            $this->SetStatus(self::STATUS_DISCOVERY_FAILED);

            return $this->Translate('Calendar discovery failed') . ': ' . $message;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requestCalendars(): array
    {
        $responseJson = $this->SendDataToParent(json_encode(
            [
                'DataID'    => self::DATA_ID_TO_PARENT,
                'Operation' => 'DiscoverCalendars',
                'RequestID' => bin2hex(random_bytes(8))
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));

        if ($responseJson === '') {
            throw new RuntimeException('The calendar account did not return a response.');
        }

        $response = json_decode($responseJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($response) || !($response['Success'] ?? false)) {
            $error = is_array($response) ? (string) ($response['Error'] ?? '') : '';
            throw new RuntimeException($error !== '' ? $error : 'The calendar account rejected the request.');
        }

        $calendars = $response['Payload'] ?? null;
        if (!is_array($calendars)) {
            throw new UnexpectedValueException('The calendar account returned invalid calendar data.');
        }

        return array_values(array_filter($calendars, 'is_array'));
    }

    /**
     * @param list<array<string, mixed>> $calendars
     */
    private function cacheCalendars(array $calendars): void
    {
        $this->WriteAttributeString(
            'CachedCalendars',
            json_encode(
                $calendars,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readCachedCalendars(): array
    {
        try {
            $calendars = json_decode($this->ReadAttributeString('CachedCalendars'), true, 512, JSON_THROW_ON_ERROR);
            return is_array($calendars) ? array_values(array_filter($calendars, 'is_array')) : [];
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * @param list<array<string, mixed>> $calendars
     * @return list<array<string, mixed>>
     */
    private function buildConfiguratorValues(array $calendars): array
    {
        $existingInstances = $this->getExistingCalendarInstances();
        $values = [];

        foreach ($calendars as $calendar) {
            $calendarId = trim((string) ($calendar['id'] ?? ''));
            if ($calendarId === '') {
                continue;
            }

            $name = trim((string) ($calendar['name'] ?? ''));
            if ($name === '') {
                $name = $calendarId;
            }

            $capabilities = is_array($calendar['capabilities'] ?? null)
                ? $calendar['capabilities']
                : [];
            $canWrite = (bool) ($capabilities['create'] ?? false)
                || (bool) ($capabilities['update'] ?? false)
                || (bool) ($capabilities['delete'] ?? false);

            $instanceId = $existingInstances[$calendarId] ?? 0;
            unset($existingInstances[$calendarId]);

            $values[] = [
                'name'       => $name,
                'color'      => (string) ($calendar['color'] ?? ''),
                'access'     => $canWrite
                    ? $this->Translate('Read and write')
                    : $this->Translate('Read only'),
                'instanceID' => $instanceId,
                'create'     => [
                    'moduleID'      => self::CALENDAR_MODULE_ID,
                    'name'          => $name,
                    'info'          => (string) ($calendar['description'] ?? ''),
                    'configuration' => [
                        'CalendarID'         => $calendarId,
                        'ProviderCalendarID' => (string) ($calendar['providerId'] ?? $calendarId),
                        'CalendarURL'        => (string) ($calendar['url'] ?? '')
                    ]
                ]
            ];
        }

        foreach ($existingInstances as $instanceId) {
            $values[] = [
                'name'       => IPS_GetName($instanceId),
                'color'      => '',
                'access'     => $this->Translate('Not found'),
                'instanceID' => $instanceId
            ];
        }

        return $values;
    }

    /**
     * @return array<string, int>
     */
    private function getExistingCalendarInstances(): array
    {
        $parentId = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        $instances = [];

        foreach (IPS_GetInstanceListByModuleID(self::CALENDAR_MODULE_ID) as $instanceId) {
            $instance = IPS_GetInstance($instanceId);
            if ((int) ($instance['ConnectionID'] ?? 0) !== $parentId) {
                continue;
            }

            $calendarId = trim((string) IPS_GetProperty($instanceId, 'CalendarID'));
            if ($calendarId !== '') {
                $instances[$calendarId] = $instanceId;
            }
        }

        return $instances;
    }

    private function sanitizeError(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');
        return $message !== '' ? $message : 'Unknown error.';
    }
}
