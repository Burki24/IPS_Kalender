# Kalender

Das Modul repräsentiert einen einzelnen Online-Kalender. Es wird normalerweise über den **Kalender Konfigurator** erstellt und mit dem zugehörigen **Kalender Konto** verbunden.

## Funktionsumfang

- Abruf von CalDAV-Terminen über einen konfigurierbaren Zeitraum
- Auflösen wiederkehrender Termine für die lokale Anzeige
- lokaler JSON-Cache und zyklische Synchronisation
- Erstellen neuer Termine
- Ändern einzelner, nicht wiederkehrender Termine
- Löschen einzelner, nicht wiederkehrender Termine
- ETag-basierter Schutz vor dem Überschreiben zwischenzeitlicher Änderungen
- Statusvariablen für Terminanzahl, Zeitpunkt der letzten Synchronisation und Termindaten

Das Ändern oder einzelne Löschen von Vorkommen einer Terminserie ist noch nicht freigegeben. Dadurch verhindert das Modul, dass eine komplette Serie versehentlich überschrieben oder gelöscht wird.

## Voraussetzungen

- Symcon ab Version 8.1
- eine verbundene Instanz **Kalender Konto**
- eine über den Konfigurator zugewiesene Kalender-ID

## Konfiguration

Eigenschaft | Beschreibung
--- | ---
Aktiv | Aktiviert die regelmäßige Synchronisation
Aktualisierungsintervall | Abstand der Terminabfragen in Minuten
Vergangene Termine laden | Anzahl der Tage vor dem aktuellen Datum
Zukünftige Termine laden | Anzahl der Tage nach dem aktuellen Datum
Kalenderidentität | Vom Konfigurator gesetzte, schreibgeschützte Anbieterinformationen

## Statusvariablen

Variable | Typ | Beschreibung
--- | --- | ---
Anzahl Termine | Integer | Anzahl der aktuell zwischengespeicherten Termine
Letzte Synchronisation | Integer | Unix-Zeitpunkt der letzten erfolgreichen Abfrage
Termine | String | JSON-kodierte Liste der Termine

Ein Termin enthält unter anderem `id`, `uid`, `resourceUrl`, `etag`, `summary`, `description`, `location`, `start`, `end`, `startTimestamp`, `endTimestamp`, `allDay`, `status`, `recurrenceRule` und `recurrenceId`.

## PHP-Befehlsreferenz

```php
bool IPSKAL_Synchronize(int $InstanzID);
string IPSKAL_GetEvents(int $InstanzID);
string IPSKAL_CreateEvent(int $InstanzID, string $EventJSON);
string IPSKAL_UpdateEvent(int $InstanzID, string $EventJSON);
bool IPSKAL_DeleteEvent(int $InstanzID, string $EventJSON);
string IPSKAL_GetCalendarStatus(int $InstanzID);
void IPSKAL_ClearCache(int $InstanzID);
```

### Termin erstellen

```php
$result = IPSKAL_CreateEvent(12345, json_encode([
    'summary'     => 'Besprechung',
    'description' => 'Projektstatus abstimmen',
    'location'    => 'Büro',
    'start'       => '2026-07-20T10:00:00+02:00',
    'end'         => '2026-07-20T11:00:00+02:00'
]));
```

Bei ganztägigen Terminen werden `start` und `end` als Datum angegeben. Das Ende ist entsprechend iCalendar exklusiv:

```php
$result = IPSKAL_CreateEvent(12345, json_encode([
    'summary' => 'Urlaub',
    'start'   => '2026-08-03',
    'end'     => '2026-08-08',
    'allDay'  => true
]));
```

### Termin ändern

`uid`, `resourceUrl` und `etag` stammen aus `IPSKAL_GetEvents`. Unter `changes` werden nur die zu ändernden Felder übergeben:

```php
$result = IPSKAL_UpdateEvent(12345, json_encode([
    'uid'         => 'event-uid@example',
    'resourceUrl' => 'https://server.example/calendar/event.ics',
    'etag'        => '"123456"',
    'changes'     => [
        'summary' => 'Geänderte Besprechung',
        'location' => 'Konferenzraum'
    ]
]));
```

### Termin löschen

```php
$success = IPSKAL_DeleteEvent(12345, json_encode([
    'resourceUrl' => 'https://server.example/calendar/event.ics',
    'etag'        => '"123456"'
]));
```

Nach jeder erfolgreichen Schreiboperation wird der lokale Termincache erneut vom Server geladen.
