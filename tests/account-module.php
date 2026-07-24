<?php

declare(strict_types=1);

if (!class_exists('IPSModuleStrict')) {
    class IPSModuleStrict
    {
    }
}

require_once __DIR__ . '/../Kalender Konto/module.php';

function assertAccountStructure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$reflection = new ReflectionClass(KalenderKonto::class);
$traits = class_uses(KalenderKonto::class);
foreach ([
    KalenderKontoGoogleOAuthTrait::class,
    KalenderKontoICalendarAccountTrait::class,
    KalenderKontoChildGatewayTrait::class
] as $trait) {
    assertAccountStructure(
        isset($traits[$trait]),
        sprintf('KalenderKonto must use %s.', $trait)
    );
}

foreach ([
    'Create',
    'GetConfigurationForm',
    'UpdateProviderForm',
    'UpdateScheduleForm',
    'RequestAction',
    'ConnectGoogle',
    'GetGoogleRedirectURI',
    'DisconnectGoogle',
    'ApplyChanges',
    'ScheduledSynchronize',
    'ForwardData',
    'TestConnection',
    'Synchronize',
    'GetCalendars',
    'GetAccountStatus',
    'ClearCache'
] as $method) {
    assertAccountStructure(
        $reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic(),
        sprintf('Public account API method %s is missing.', $method)
    );
}

assertAccountStructure(
    $reflection->hasMethod('ProcessHookData') && $reflection->getMethod('ProcessHookData')->isProtected(),
    'The Google OAuth hook handler must remain protected.'
);

fwrite(STDOUT, "KalenderKonto structure tests passed.\n");
