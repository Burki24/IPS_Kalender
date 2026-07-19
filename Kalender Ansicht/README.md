# Kalender Ansicht

Die Kalender Ansicht fasst Termine mehrerer Kalenderinstanzen in einer responsiven Kachel der Symcon-Kachelvisualisierung zusammen.

## Funktionsumfang

- Agenda-, Wochen- und Monatsansicht
- Zusammenführen beliebig vieler ausgewählter Kalender
- Farbliche Zuordnung der Termine zu ihren Kalendern
- Optionale Anzeige von Kalendername, Ort und Beschreibung
- Navigation innerhalb des angezeigten Zeitraums
- Manuelle Synchronisation aller ausgewählten Kalender
- Erstellen, Bearbeiten und Löschen von Terminen in beschreibbaren Kalendern
- Automatische Aktualisierung der Kachel nach einer Kalendersynchronisation
- Responsive Darstellung für große Kacheln und schmale Mobilansichten

Wiederkehrende, vom CalDAV-Server expandierte Einzeltermine werden derzeit nur lesend dargestellt. Dadurch wird verhindert, dass versehentlich die komplette Terminserie verändert wird.

## Voraussetzungen

- Symcon ab Version 8.1 mit Kachelvisualisierung und HTML-SDK
- Mindestens eine eingerichtete Instanz des Moduls `Kalender`

## Einrichtung

1. Eine Instanz `Kalender Ansicht` erstellen.
2. In der Liste `Kalender` die gewünschten Kalenderinstanzen auswählen und aktivieren.
3. Standardansicht, Zeitraum, maximale Terminanzahl und die gewünschten Detailfelder festlegen.
4. Die Instanz in der Kachelvisualisierung platzieren.

Über `Kalender synchronisieren` kann die Verbindung bereits in der Konfiguration geprüft werden. Neu vom Konfigurator angelegte Kalender übernehmen Farbe und Schreibberechtigung automatisch. Bei Kalenderinstanzen, die vor Einführung dieser Eigenschaften angelegt wurden, die Konfiguration über den Kalender-Konfigurator einmal neu anwenden.

## PHP-Befehlsreferenz

```php
// Alle ausgewählten Kalender synchronisieren.
$success = IPSKALVIEW_SynchronizeCalendars(12345);

// Die zusammengeführten Termine als JSON abrufen.
$events = IPSKALVIEW_GetAggregatedEvents(12345);
```
