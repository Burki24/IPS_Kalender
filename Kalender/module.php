<?php

declare(strict_types=1);

class Kalender extends IPSModuleStrict
{
    private const STATUS_CONFIGURATION_MISSING = 201;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('CalendarID', '');
        $this->RegisterPropertyString('ProviderCalendarID', '');
        $this->RegisterPropertyString('CalendarURL', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (trim($this->ReadPropertyString('CalendarID')) === ''
            || trim($this->ReadPropertyString('ProviderCalendarID')) === '') {
            $this->SetStatus(self::STATUS_CONFIGURATION_MISSING);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
    }
}
