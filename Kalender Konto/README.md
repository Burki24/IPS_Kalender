# Kalender Konto

Das Modul verwaltet die Verbindung zu einem Online-Kalenderkonto und stellt die Kalender dieses Kontos den untergeordneten Modulen zur Verfügung.

## Funktionsumfang

- Apple iCloud über CalDAV und anwendungsspezifisches Passwort
- Google Calendar über OAuth 2.0 und die Google Calendar API v3
- generische CalDAV-Server
- CalDAV-Discovery von Principal, Calendar Home Set und Kalendern
- Erkennung von Kalendername, Beschreibung, Farbe und Zugriffsrechten
- Zwischenspeicherung der gefundenen Kalender
- zyklische Synchronisation
- einheitlicher Datenfluss zum Kalender-Konfigurator und zu Kalenderinstanzen
- Lesen, Erstellen, Bearbeiten und Löschen von Google-Terminen entsprechend der Kalenderrechte
- vorbereitete Provider-Auswahl für Microsoft 365 und ICS/Webcal

Microsoft und ICS/Webcal sind in diesem Entwicklungsstand noch nicht implementiert.

## Voraussetzungen

- Symcon ab Version 8.1
- PHP-Erweiterungen cURL und DOM
- Zugriff des Symcon-Servers auf den jeweiligen Kalenderdienst
- für Google Calendar eine aktive Symcon-Connect-Verbindung und ein freigeschalteter OAuth-Handler

Für Apple iCloud wird ein anwendungsspezifisches Passwort benötigt. Das normale Kennwort des Apple Accounts sollte nicht verwendet werden.

## Einrichtung

Unter **Instanz hinzufügen** das Modul **Kalender Konto** auswählen.

Eigenschaft | Beschreibung
--- | ---
Aktiv | Aktiviert die regelmäßige Synchronisation
Anbieter | Apple iCloud, Google Calendar oder generischer CalDAV-Server
Server-URL | Bei Apple optional; ansonsten URL des CalDAV-Servers
Benutzername | Benutzername beziehungsweise E-Mail-Adresse des Kontos
Passwort | Passwort oder anwendungsspezifisches Passwort
Aktualisierungsintervall | Abstand der Kalenderabfragen in Minuten
TLS-Zertifikat prüfen | Sollte nur zu Diagnosezwecken deaktiviert werden
Zeitlimit der Anfrage | Maximale Dauer einer HTTP-Anfrage

Über **Verbindung testen** wird die Anmeldung geprüft und die Anzahl der gefundenen Kalender ausgegeben. **Jetzt synchronisieren** aktualisiert den internen Kalendercache und informiert die verbundenen Child-Instanzen.

### Google Calendar

Nach Auswahl von **Google Calendar** werden Server-URL, Benutzername und Passwort ausgeblendet. Über **Google-Konto verbinden** wird die Anmeldung im externen Browser geöffnet. Das Modul speichert den Refresh-Token intern und erneuert kurzlebige Access-Tokens automatisch. **Google-Konto trennen** widerruft den Token bei Google und entfernt die lokal gespeicherten Zugangsdaten.

Der OAuth-Handler verwendet den Bezeichner `ipskalender_google`. Vor dem ersten produktiven Login muss dieser Bezeichner für den OAuth-Client der Library bei der Symcon-Connect-OAuth-Brücke registriert sein. Für die Google-Cloud-Konfiguration werden verwendet:

- Autorisierungs-Endpunkt: `https://accounts.google.com/o/oauth2/v2/auth`
- Token-Endpunkt: `https://oauth2.googleapis.com/token`
- Redirect-URL: `https://oauth.ipmagic.de/forward/ipskalender_google`
- Scopes: `https://www.googleapis.com/auth/calendar.calendarlist.readonly` und `https://www.googleapis.com/auth/calendar.events`
- Offline-Zugriff, damit Google einen Refresh-Token ausstellt

Die Google-Anwendung sollte für den Produktivbetrieb veröffentlicht und gegebenenfalls von Google verifiziert werden. Im Google-Testmodus können Refresh-Tokens nach kurzer Zeit ungültig werden.

Kalender mit den Google-Rollen `owner` und `writer` werden als les- und schreibbar erkannt. `reader` wird schreibgeschützt angeboten. Einträge mit ausschließlich `freeBusyReader` werden nicht angelegt, weil sie keine Termindetails liefern.

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
string IPSKALACC_ConnectGoogle(int $InstanzID);
bool IPSKALACC_DisconnectGoogle(int $InstanzID);
```

Die Methoden mit komplexen Rückgabewerten liefern JSON. Passwörter und OAuth-Tokens werden weder in Rückgabewerte noch in Debugmeldungen geschrieben.
