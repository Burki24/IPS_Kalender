# Kalender Konto

Das Modul verwaltet die Verbindung zu einem Online-Kalenderkonto und stellt die Kalender dieses Kontos den untergeordneten Modulen zur Verfügung.

## Funktionsumfang

- Apple iCloud über CalDAV und anwendungsspezifisches Passwort
- generische CalDAV-Server
- CalDAV-Discovery von Principal, Calendar Home Set und Kalendern
- Erkennung von Kalendername, Beschreibung, Farbe und Zugriffsrechten
- Zwischenspeicherung der gefundenen Kalender
- zyklische Synchronisation
- einheitlicher Datenfluss zum Kalender-Konfigurator und zu Kalenderinstanzen
- vorbereitete Provider-Auswahl für Google Calendar, Microsoft 365 und ICS/Webcal

Google, Microsoft und ICS/Webcal sind in diesem Entwicklungsstand noch nicht implementiert.

## Voraussetzungen

- Symcon ab Version 8.1
- PHP-Erweiterungen cURL und DOM
- Zugriff des Symcon-Servers auf den jeweiligen Kalenderdienst

Für Apple iCloud wird ein anwendungsspezifisches Passwort benötigt. Das normale Kennwort des Apple Accounts sollte nicht verwendet werden.

## Einrichtung

Unter **Instanz hinzufügen** das Modul **Kalender Konto** auswählen.

Eigenschaft | Beschreibung
--- | ---
Aktiv | Aktiviert die regelmäßige Synchronisation
Anbieter | Apple iCloud oder generischer CalDAV-Server
Server-URL | Bei Apple optional; ansonsten URL des CalDAV-Servers
Benutzername | Benutzername beziehungsweise E-Mail-Adresse des Kontos
Passwort | Passwort oder anwendungsspezifisches Passwort
Aktualisierungsintervall | Abstand der Kalenderabfragen in Minuten
TLS-Zertifikat prüfen | Sollte nur zu Diagnosezwecken deaktiviert werden
Zeitlimit der Anfrage | Maximale Dauer einer HTTP-Anfrage

Über **Verbindung testen** wird die Anmeldung geprüft und die Anzahl der gefundenen Kalender ausgegeben. **Jetzt synchronisieren** aktualisiert den internen Kalendercache und informiert die verbundenen Child-Instanzen.

## Datenfluss

Unterstützte Anforderungen von Child-Modulen:

- `GetCalendars`
- `DiscoverCalendars`
- `GetEvents`
- `CreateEvent`
- `UpdateEvent`
- `DeleteEvent`
- `Synchronize`
- `TestConnection`

Nach einer erfolgreichen Synchronisation sendet das Konto `CalendarsUpdated` an seine Children.

Beispiel einer Anforderung:

```json
{
    "DataID": "{4E535B1D-69C7-AC77-1372-0282B21BAEC9}",
    "Operation": "GetCalendars",
    "RequestID": "example-1"
}
```

## PHP-Befehlsreferenz

```php
string IPSKALACC_TestConnection(int $InstanzID);
bool IPSKALACC_Synchronize(int $InstanzID);
string IPSKALACC_GetCalendars(int $InstanzID);
string IPSKALACC_GetAccountStatus(int $InstanzID);
void IPSKALACC_ClearCache(int $InstanzID);
```

Die Methoden mit komplexen Rückgabewerten liefern JSON. Passwörter werden weder in Rückgabewerte noch in Debugmeldungen geschrieben.
