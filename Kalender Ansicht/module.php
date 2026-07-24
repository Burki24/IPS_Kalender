<?php

declare(strict_types=1);

class KalenderAnsicht extends IPSModuleStrict
{
    private const CALENDAR_MODULE_ID = '{227B63E4-4223-316B-76E9-FD3849689562}';
    private const INITIALIZATION_DELAY_MS = 5_000;

    private const STATUS_NO_CALENDARS = 201;
    private const STATUS_INVALID_CONFIGURATION = 202;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterPropertyString('Calendars', '[]');
        $this->RegisterPropertyInteger('DefaultView', 0);
        $this->RegisterPropertyInteger('TileWeekOrientation', 0);
        $this->RegisterPropertyInteger('PastDays', 0);
        $this->RegisterPropertyInteger('FutureDays', 31);
        $this->RegisterPropertyInteger('MaxEvents', 250);
        $this->RegisterPropertyBoolean('ShowWeekends', true);
        $this->RegisterPropertyBoolean('ShowCalendarName', true);
        $this->RegisterPropertyBoolean('ShowLocation', true);
        $this->RegisterPropertyBoolean('ShowDescription', false);
        $this->RegisterPropertyBoolean('EnableIPSView', false);
        $this->RegisterPropertyBoolean('IPSViewTransparent', true);
        $this->RegisterPropertyInteger('IPSViewTheme', 0);
        $this->RegisterPropertyInteger('IPSViewFontScale', 115);
        $this->RegisterPropertyInteger('IPSViewColorBarWidth', 7);
        $this->RegisterPropertyInteger('IPSViewWeekOrientation', 0);
        $this->RegisterAttributeBoolean('RuntimeReady', false);

        $this->SetVisualizationType(1);
        $this->RegisterTimer('InitializationTimer', 0, 'IPSKALVIEW_Initialize($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->WriteAttributeBoolean('RuntimeReady', false);
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->SetTimerInterval('InitializationTimer', 0);
        $this->MaintainVariable(
            'IPSViewCalendar',
            'IPSView calendar',
            VARIABLETYPE_STRING,
            '~HTMLBox',
            10,
            $this->ReadPropertyBoolean('EnableIPSView')
        );

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->scheduleInitialization();
    }

    public function Initialize(): bool
    {
        $this->SetTimerInterval('InitializationTimer', 0);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return false;
        }

        foreach ($this->GetMessageList() as $senderId => $messageIds) {
            foreach ($messageIds as $messageId) {
                if ((int) $senderId === 0 && (int) $messageId === IPS_KERNELSTARTED) {
                    continue;
                }
                $this->UnregisterMessage($senderId, $messageId);
            }
        }

        $this->WriteAttributeBoolean('RuntimeReady', true);
        try {
            $calendars = $this->getSelectedCalendars();
            foreach ($calendars as $calendar) {
                $instanceId = $calendar['instanceId'];
                $this->RegisterMessage($instanceId, OM_CHANGENAME);
                $eventsVariableId = $this->findChildByIdent($instanceId, 'Events');
                if ($eventsVariableId > 0) {
                    $this->RegisterMessage($eventsVariableId, VM_UPDATE);
                }
            }

            $this->SetStatus($calendars === [] ? self::STATUS_NO_CALENDARS : IS_ACTIVE);
        } catch (Throwable $exception) {
            $this->SendDebug('Configuration', $exception->getMessage(), 0);
            $this->SetStatus(self::STATUS_INVALID_CONFIGURATION);
        }

        $this->broadcastState();

        return true;
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($SenderID === 0 && $Message === IPS_KERNELSTARTED) {
            $this->scheduleInitialization();
            return;
        }
        if (!$this->isRuntimeReady()) {
            return;
        }

        $this->broadcastState();
    }

    public function GetVisualizationTile(): string
    {
        return $this->renderCalendarHtml($this->buildState(), false);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        try {
            switch ($Ident) {
                case 'Refresh':
                    $success = $this->SynchronizeCalendars();
                    $this->sendToast(
                        $success ? 'success' : 'error',
                        $success ? $this->Translate('Calendars synchronized.') : $this->Translate('Synchronization failed.')
                    );
                    break;

                case 'CreateEvent':
                    $request = $this->decodeActionValue($Value);
                    $instanceId = $this->requireWritableCalendar($request);
                    $event = $request['event'] ?? null;
                    if (!is_array($event)) {
                        throw new InvalidArgumentException('The event data is invalid.');
                    }
                    $result = json_decode(
                        IPSKAL_CreateEvent(
                            $instanceId,
                            json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                        ),
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                    if (!is_array($result) || !($result['success'] ?? false)) {
                        throw new RuntimeException((string) ($result['error'] ?? 'Event creation failed.'));
                    }
                    $this->sendToast('success', $this->Translate('Event created.'));
                    $this->broadcastState();
                    break;

                case 'UpdateEvent':
                    $request = $this->decodeActionValue($Value);
                    $instanceId = $this->requireWritableCalendar($request);
                    $event = $request['event'] ?? null;
                    if (!is_array($event)) {
                        throw new InvalidArgumentException('The event data is invalid.');
                    }
                    $result = json_decode(
                        IPSKAL_UpdateEvent(
                            $instanceId,
                            json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                        ),
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                    if (!is_array($result) || !($result['success'] ?? false)) {
                        throw new RuntimeException((string) ($result['error'] ?? 'Event update failed.'));
                    }
                    $this->sendToast('success', $this->Translate('Event updated.'));
                    $this->broadcastState();
                    break;

                case 'DeleteEvent':
                    $request = $this->decodeActionValue($Value);
                    $instanceId = $this->requireWritableCalendar($request);
                    $event = $request['event'] ?? null;
                    if (!is_array($event)) {
                        throw new InvalidArgumentException('The event data is invalid.');
                    }
                    if (!IPSKAL_DeleteEvent(
                        $instanceId,
                        json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                    )) {
                        throw new RuntimeException('Event deletion failed.');
                    }
                    $this->sendToast('success', $this->Translate('Event deleted.'));
                    $this->broadcastState();
                    break;

                default:
                    throw new InvalidArgumentException('Unsupported visualization action: ' . $Ident);
            }
        } catch (Throwable $exception) {
            $this->SendDebug('VisualizationAction', $exception->getMessage(), 0);
            $this->sendToast('error', $exception->getMessage());
        }
    }

    public function SynchronizeCalendars(): bool
    {
        $success = true;
        foreach ($this->getSelectedCalendars() as $calendar) {
            if (!IPSKAL_Synchronize($calendar['instanceId'])) {
                $success = false;
            }
        }
        $this->broadcastState();
        return $success;
    }

    public function GetAggregatedEvents(): string
    {
        return json_encode(
            $this->buildState(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    public function GetIPSViewHTML(): string
    {
        return $this->renderCalendarHtml($this->buildState(), true);
    }

    private function broadcastState(): void
    {
        if (!$this->isRuntimeReady()) {
            return;
        }

        try {
            $state = $this->buildState();
        } catch (Throwable $exception) {
            $this->SendDebug('CalendarState', $exception->getMessage(), 0);
            return;
        }

        try {
            $this->UpdateVisualizationValue($this->getFullUpdateMessage($state));
        } catch (Throwable $exception) {
            $this->SendDebug('VisualizationUpdate', $exception->getMessage(), 0);
        }

        if ($this->ReadPropertyBoolean('EnableIPSView')
            && $this->findChildByIdent($this->InstanceID, 'IPSViewCalendar') > 0) {
            try {
                $this->SetValue('IPSViewCalendar', $this->renderCalendarHtml($state, true));
            } catch (Throwable $exception) {
                $this->SendDebug('IPSViewUpdate', $exception->getMessage(), 0);
            }
        }
    }

    /**
     * @param array<string, mixed>|null $state
     */
    private function getFullUpdateMessage(?array $state = null): string
    {
        return json_encode(
            ['type' => 'state', 'payload' => $state ?? $this->buildState()],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    private function renderCalendarHtml(array $state, bool $ipsView): string
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        if ($html === false) {
            return '';
        }

        $translations = [];
        if ($ipsView) {
            $classes = ['ipsview-mode'];
            if ($this->ReadPropertyBoolean('IPSViewTransparent')) {
                $classes[] = 'ipsview-transparent';
            }
            $classes[] = match ($this->ReadPropertyInteger('IPSViewTheme')) {
                1       => 'ipsview-light',
                2       => 'ipsview-dark',
                default => 'ipsview-auto'
            };
            $fontScale = max(80, min(200, $this->ReadPropertyInteger('IPSViewFontScale')));
            $colorBarWidth = max(2, min(16, $this->ReadPropertyInteger('IPSViewColorBarWidth')));
            $html = str_replace(
                '<html lang="en">',
                '<html lang="de" class="' . implode(' ', $classes) . '">',
                $html
            );
            $html = str_replace(
                '<meta name="viewport" content="width=device-width, initial-scale=1.0">',
                '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">',
                $html
            );
            $html = str_replace(
                '<div id="calendar-app">',
                sprintf(
                    '<div id="calendar-app" style="font-size:%d%% !important; --agenda-color-bar-width:%dpx; --compact-color-bar-width:%dpx;">',
                    $fontScale,
                    $colorBarWidth,
                    $colorBarWidth
                ),
                $html
            );
            foreach ([
                'Agenda', '3 Days', 'Week', 'Month', 'Previous', 'Today', 'Next', 'Refresh',
                'No calendars selected', 'Select at least one calendar in the instance configuration.',
                'No events', 'There are no events in this period.', 'All day', 'Untitled event',
                'more', 'Create event', 'Event details', 'Calendar', 'Title', 'Start', 'End', 'Location',
                'Description', 'Cancel', 'Save', 'Delete', 'Close', 'Tomorrow', 'Yesterday',
                'Recurring occurrences are currently read-only.', 'This calendar is read-only.',
                'Editing events is only available in the Symcon tile.'
            ] as $text) {
                $translations[$text] = $this->Translate($text);
            }
        }

        $bootstrap = [
            'translations' => $translations,
            'message'      => ['type' => 'state', 'payload' => $state]
        ];

        $script = '<script>(function(bootstrap){'
            . 'window.ipsViewTranslations=bootstrap.translations||{};'
            . 'handleMessage(bootstrap.message);'
            . '})(' . json_encode(
                $bootstrap,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                    | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . ');</script>';

        return str_replace('</body>', $script . '</body>', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildState(): array
    {
        if (!$this->isRuntimeReady()) {
            return $this->emptyState();
        }

        $calendars = $this->getSelectedCalendars();
        $events = [];
        $pastDays = max(0, min(1095, $this->ReadPropertyInteger('PastDays')));
        $futureDays = max(1, min(1095, $this->ReadPropertyInteger('FutureDays')));
        $rangeStart = (new DateTimeImmutable('today'))->modify('-' . $pastDays . ' days')->getTimestamp();
        $rangeEnd = (new DateTimeImmutable('today'))->modify('+' . ($futureDays + 1) . ' days')->getTimestamp();

        foreach ($calendars as $calendar) {
            try {
                $calendarEvents = json_decode(IPSKAL_GetEvents($calendar['instanceId']), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                $this->SendDebug('CalendarData', $exception->getMessage(), 0);
                continue;
            }
            if (!is_array($calendarEvents)) {
                continue;
            }

            foreach ($calendarEvents as $event) {
                if (!is_array($event)) {
                    continue;
                }
                $startTimestamp = (int) ($event['startTimestamp'] ?? 0);
                $endTimestamp = (int) ($event['endTimestamp'] ?? $startTimestamp);
                if ($startTimestamp <= 0 || $endTimestamp < $rangeStart || $startTimestamp >= $rangeEnd) {
                    continue;
                }
                $event['calendarInstanceId'] = $calendar['instanceId'];
                $event['calendarName'] = $calendar['name'];
                $event['calendarColor'] = $calendar['color'];
                $event['canWrite'] = $calendar['canWrite'];
                $events[] = $event;
            }
        }

        usort(
            $events,
            static fn(array $left, array $right): int => ((int) $left['startTimestamp'] <=> (int) $right['startTimestamp'])
                ?: strcasecmp((string) ($left['summary'] ?? ''), (string) ($right['summary'] ?? ''))
        );
        $events = array_slice($events, 0, max(1, min(1000, $this->ReadPropertyInteger('MaxEvents'))));

        return [
            'events'      => $events,
            'calendars'   => array_values($calendars),
            'generatedAt' => time(),
            'settings'    => $this->viewSettings()
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyState(): array
    {
        return [
            'events'      => [],
            'calendars'   => [],
            'generatedAt' => time(),
            'settings'    => $this->viewSettings()
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function viewSettings(): array
    {
        return [
            'defaultView'      => match ($this->ReadPropertyInteger('DefaultView')) {
                1       => 'week',
                2       => 'month',
                3       => 'threeDays',
                default => 'agenda'
            },
            'showWeekends'     => $this->ReadPropertyBoolean('ShowWeekends'),
            'showCalendarName' => $this->ReadPropertyBoolean('ShowCalendarName'),
            'showLocation'     => $this->ReadPropertyBoolean('ShowLocation'),
            'showDescription'  => $this->ReadPropertyBoolean('ShowDescription'),
            'tileWeekOrientation' => $this->ReadPropertyInteger('TileWeekOrientation') === 1
                ? 'vertical'
                : 'horizontal',
            'ipsViewWeekOrientation' => $this->ReadPropertyInteger('IPSViewWeekOrientation') === 1
                ? 'vertical'
                : 'horizontal'
        ];
    }

    private function scheduleInitialization(): void
    {
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->SetTimerInterval('InitializationTimer', self::INITIALIZATION_DELAY_MS);
        }
    }

    private function isRuntimeReady(): bool
    {
        return IPS_GetKernelRunlevel() === KR_READY
            && $this->ReadAttributeBoolean('RuntimeReady');
    }

    /**
     * @return list<array{instanceId: int, name: string, color: string, canWrite: bool}>
     */
    private function getSelectedCalendars(): array
    {
        $configuration = json_decode($this->ReadPropertyString('Calendars'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($configuration)) {
            throw new UnexpectedValueException('The calendar selection is invalid.');
        }

        $result = [];
        $usedIds = [];
        foreach ($configuration as $row) {
            if (!is_array($row) || !($row['Enabled'] ?? true)) {
                continue;
            }
            $instanceId = (int) ($row['InstanceID'] ?? 0);
            if ($instanceId <= 0 || isset($usedIds[$instanceId]) || !IPS_InstanceExists($instanceId)) {
                continue;
            }
            $instance = IPS_GetInstance($instanceId);
            if (($instance['ModuleInfo']['ModuleID'] ?? '') !== self::CALENDAR_MODULE_ID) {
                continue;
            }
            $usedIds[$instanceId] = true;
            $calendarStatus = [];
            try {
                $decodedStatus = json_decode(IPSKAL_GetCalendarStatus($instanceId), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decodedStatus)) {
                    $calendarStatus = $decodedStatus;
                }
            } catch (Throwable $exception) {
                $this->SendDebug('CalendarStatus', $exception->getMessage(), 0);
            }

            $color = strtoupper(trim((string) ($calendarStatus['calendarColor']
                ?? IPS_GetProperty($instanceId, 'CalendarColor'))));
            if (preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) {
                $palette = ['#4F8EF7', '#4FB286', '#E09F3E', '#D65DB1', '#7B61FF', '#EF6F6C', '#2CA6A4'];
                $color = $palette[abs(crc32((string) $instanceId)) % count($palette)];
            }

            $result[] = [
                'instanceId' => $instanceId,
                'name'       => IPS_GetName($instanceId),
                'color'      => $color,
                'canWrite'   => (bool) ($calendarStatus['canWrite']
                    ?? IPS_GetProperty($instanceId, 'CanWrite'))
            ];
        }

        return $result;
    }

    private function findChildByIdent(int $parentId, string $ident): int
    {
        foreach (IPS_GetChildrenIDs($parentId) as $childId) {
            $object = IPS_GetObject($childId);
            if (($object['ObjectIdent'] ?? '') === $ident) {
                return $childId;
            }
        }
        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeActionValue(mixed $value): array
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('The visualization request is invalid.');
        }
        $request = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($request) || array_is_list($request)) {
            throw new InvalidArgumentException('The visualization request is invalid.');
        }
        return $request;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function requireWritableCalendar(array $request): int
    {
        $instanceId = (int) ($request['calendarInstanceId'] ?? 0);
        foreach ($this->getSelectedCalendars() as $calendar) {
            if ($calendar['instanceId'] === $instanceId && $calendar['canWrite']) {
                return $instanceId;
            }
        }
        throw new RuntimeException('The selected calendar is not writable.');
    }

    private function sendToast(string $level, string $message): void
    {
        $this->UpdateVisualizationValue(json_encode(
            ['type' => 'toast', 'level' => $level, 'message' => $message],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }
}
