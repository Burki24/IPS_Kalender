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
    KalenderKontoMicrosoftOAuthTrait::class,
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
    'ConnectMicrosoft',
    'DisconnectMicrosoft',
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


assertAccountStructure(
    $reflection->hasMethod('ProcessOAuthData') && $reflection->getMethod('ProcessOAuthData')->isProtected(),
    'The native Microsoft OAuth handler must remain protected.'
);

$accountSource = file_get_contents(__DIR__ . '/../Kalender Konto/module.php');
assertAccountStructure(
    is_string($accountSource)
        && str_contains($accountSource, 'RegisterOAuth(self::MICROSOFT_OAUTH_IDENTIFIER)')
        && !str_contains($accountSource, "RegisterPropertyString('MicrosoftClientID'")
        && !str_contains($accountSource, "RegisterPropertyString('MicrosoftClientSecret'"),
    'Microsoft OAuth must use the native shared Symcon handler without per-user client credentials.'
);
fwrite(STDOUT, "KalenderKonto structure tests passed.\n");
