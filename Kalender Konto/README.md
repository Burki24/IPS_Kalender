# Kalender Konto

Das Modul verwaltet die Verbindung zu einem Online-Kalenderkonto und stellt die Kalender dieses Kontos den untergeordneten Modulen zur Verfügung.

## Funktionsumfang

- Apple iCloud über CalDAV und anwendungsspezifisches Passwort
- Google Calendar über OAuth 2.0 und die Google Calendar API v3
- generische CalDAV-Server
- mehrere schreibgeschützte iCalendar-Abonnements über HTTP(S)- oder Webcal-URL in einem Konto
- CalDAV-Discovery von Principal, Calendar Home Set und Kalendern
- Erkennung von Kalendername, Beschreibung, Farbe und Zugriffsrechten
- Zwischenspeicherung der gefundenen Kalender
- persistenter iCalendar-Feed-Cache mit HTTP-Validierung und Rückfallebene
- zyklische Synchronisation
- einheitlicher Datenfluss zum Kalender-Konfigurator und zu Kalenderinstanzen
- Lesen, Erstellen, Bearbeiten und Löschen von Google-Terminen entsprechend der Kalenderrechte
- vorbereitete Provider-Auswahl für Microsoft 365

Microsoft ist in diesem Entwicklungsstand noch nicht implementiert.

## Voraussetzungen

- Symcon ab Version 8.1
- PHP-Erweiterungen cURL und DOM
- Zugriff des Symcon-Servers auf den jeweiligen Kalenderdienst
- für Google Calendar eine aktive Symcon-Connect-Verbindung und ein persönlicher Google-OAuth-Webclient

Für Apple iCloud wird ein anwendungsspezifisches Passwort benötigt. Das normale Kennwort des Apple Accounts sollte nicht verwendet werden.

## Einrichtung

Unter **Instanz hinzufügen** das Modul **Kalender Konto** auswählen.

Eigenschaft | Beschreibung
--- | ---
Aktiv | Aktiviert die regelmäßige Synchronisation
Anbieter | Apple iCloud, Google Calendar, generischer CalDAV-Server oder ICS/Webcal
Server-URL | Bei Apple vorbelegt; ansonsten URL des CalDAV-Servers. Bei ICS/Webcal bleibt hier ein bereits vorhandenes einzelnes Abonnement aus Kompatibilitätsgründen erhalten
Kalendername | Optionale Bezeichnung des bisherigen einzelnen iCalendar-Abonnements
Benutzername | Benutzername beziehungsweise E-Mail-Adresse des Kontos; beim bisherigen einzelnen iCalendar-Abonnement optional
Passwort | Passwort oder anwendungsspezifisches Passwort; beim bisherigen einzelnen iCalendar-Abonnement optional
Google-OAuth-Client-ID | Client-ID des persönlichen Google-OAuth-Webclients
Google-OAuth-Clientschlüssel | Clientschlüssel des persönlichen Google-OAuth-Webclients
iCalendar-Abonnements | Liste zusätzlicher Feeds mit Aktivierung, Name, URL, optionalen Zugangsdaten, Titelübersetzung, Aktualisierungsplan und optionaler Farbe
Aktualisierungsplan | Vorgegebener Rhythmus von fünf Minuten bis jährlich oder ausschließlich manuelle Synchronisation; bei ICS/Webcal steuert er die erneute Kontosuche
Benutzerdefiniertes Intervall | Eigener Abstand in Minuten; wird nur beim Zeitplan „Benutzerdefiniertes Intervall“ angezeigt
TLS-Zertifikat prüfen | Sollte nur zu Diagnosezwecken deaktiviert werden
Zeitlimit der Anfrage | Maximale Dauer einer HTTP-Anfrage

Über **Verbindung testen** wird die Anmeldung geprüft und die Anzahl der gefundenen Kalender ausgegeben. **Jetzt synchronisieren** aktualisiert den internen Kalendercache und informiert die verbundenen Child-Instanzen.

Bestehende Instanzen behalten ihren bisherigen Minutenwert als benutzerdefiniertes Intervall. Bei ICS/Webcal sollte der Zeitplan des Kontos passend zum Zeitplan der zugehörigen Kalenderinstanz gewählt werden, da beide Instanzen den Feed für unterschiedliche Aufgaben abrufen.

### Google Calendar

Nach Auswahl von **Google Calendar** werden Server-URL, Benutzername und
Passwort ausgeblendet. Stattdessen zeigt die Instanz eine eindeutige
Weiterleitungs-URI sowie Eingabefelder für die persönliche Google-OAuth-Client-ID
und den Clientschlüssel an. Die Weiterleitungs-URI enthält die Instanz-ID und
darf deshalb nur für genau dieses Kalender Konto verwendet werden.

Einrichtung:

1. In einem eigenen Google-Cloud-Projekt die **Google Calendar API** aktivieren.
2. Den OAuth-Zustimmungsbildschirm konfigurieren und das Google-Konto während
   des Testbetriebs als Testnutzer eintragen.
3. Eine OAuth-Client-ID vom Typ **Webanwendung** erstellen.
4. Die in Symcon angezeigte URI unverändert als autorisierte
   Weiterleitungs-URI eintragen.
5. Client-ID und Clientschlüssel in Symcon eintragen und die Konfiguration
   übernehmen.
6. **Google-Konto verbinden** aufrufen und die Freigabe bei Google bestätigen.

Das Modul speichert den Refresh-Token intern und erneuert kurzlebige
Access-Tokens automatisch. **Google-Konto trennen** widerruft den Token bei
Google und entfernt die lokal gespeicherten Zugangsdaten.

Für die direkte Google-Cloud-Anbindung werden verwendet:

- Autorisierungs-Endpunkt: `https://accounts.google.com/o/oauth2/v2/auth`
- Token-Endpunkt: `https://oauth2.googleapis.com/token`
- Redirect-URL: persönliche Symcon-Connect-Adresse mit dem instanzspezifischen Pfad `/hook/ips-kalender-google-{InstanzID}`
- Scopes: `https://www.googleapis.com/auth/calendar.calendarlist.readonly` und `https://www.googleapis.com/auth/calendar.events`
- Offline-Zugriff, damit Google einen Refresh-Token ausstellt

Bei einem externen Zustimmungsbildschirm im Veröffentlichungsstatus **Test**
läuft der Refresh-Token nach sieben Tagen ab. Für den dauerhaften Betrieb muss
der Zustimmungsbildschirm deshalb auf **In Produktion** gestellt werden. Eine
gegebenenfalls notwendige Google-Verifizierung richtet sich nach Nutzerkreis
und angeforderten Berechtigungen.

Kalender mit den Google-Rollen `owner` und `writer` werden als les- und schreibbar erkannt. `reader` wird schreibgeschützt angeboten. Einträge mit ausschließlich `freeBusyReader` werden nicht angelegt, weil sie keine Termindetails liefern.

### ICS/Webcal

Nach Auswahl von **ICS/Webcal** können in der Liste
**iCalendar-Abonnements** mehrere private oder öffentliche Feeds gemeinsam
verwaltet werden. Jeder Eintrag besitzt:

- Aktivierung
- Kalendername
- HTTP(S)- oder Webcal-URL
- optionalen Benutzernamen und optionales Passwort für HTTP-Authentifizierung
- optionale, profilbasierte Titelübersetzung
- eigenen Aktualisierungsplan
- benutzerdefiniertes Intervall für den entsprechenden Zeitplantyp
- optionale Kalenderfarbe im Format `#RRGGBB`

Bleibt die Farbe leer, verwendet das Modul – sofern vorhanden – die Farbe aus
dem Feed. `webcal://` wird automatisch über HTTPS abgerufen. Ein eingetragener
Kalendername überschreibt die im Feed enthaltene Eigenschaft `X-WR-CALNAME`.

Das Profil **Öffentliche Google-Kalender - Deutsch** übersetzt ausschließlich
bekannte englische Termintitel der Google-Kalender für Mondphasen und
Kalendertage. Beispielsweise werden `Full Moon` zu `Vollmond` und
`Day 205 of 2026` zu `Tag 205 von 2026`. Eine gegebenenfalls angehängte
englische Uhrzeit wird in das deutsche 24-Stunden-Format umgewandelt. Andere
Termintitel bleiben unverändert. Bei übersetzten Terminen enthält
`originalSummary` weiterhin den Originaltitel. Der heruntergeladene Feed und
sein persistenter Cache werden nicht verändert.

Die bisherigen Felder **iCalendar-URL**, **Kalendername**, **Benutzername**,
**Passwort** und **Titelübersetzung** bleiben für bereits eingerichtete Einzel-Feed-Konten
rückwärtskompatibel. Der dort konfigurierte Feed wird zusätzlich zu den
Listeneinträgen angeboten. Ist dieselbe URL bereits in der Liste enthalten,
wird sie nicht doppelt angelegt.

Nach der Kontosynchronisation zeigt der zugehörige Konfigurator alle aktiven
Abonnements als einzelne Kalender an. Die Kalender-Instanzen müssen dort
erstellt werden. Der für einen Listeneintrag gewählte Aktualisierungsplan wird
als Anfangskonfiguration der neu erzeugten Kalender-Instanz übernommen.
Spätere Änderungen des Instanzzeitplans erfolgen in der jeweiligen
Kalender-Instanz.

iCalendar-Abonnements sind grundsätzlich schreibgeschützt. Die Feed-URL kann – etwa bei Googles „Privatadresse im iCal-Format“ – selbst ein Zugangsgeheimnis enthalten und sollte daher wie ein Passwort behandelt werden.

Terminserien aus einem Feed werden lokal für den von der Kalenderinstanz angeforderten Zeitraum aufgelöst. Unterstützt werden tägliche, wöchentliche, monatliche und jährliche `RRULE`-Serien einschließlich `INTERVAL`, `COUNT`, `UNTIL`, `BYDAY`, `BYMONTH`, `BYMONTHDAY`, `BYSETPOS` und `WKST`. `RDATE` ergänzt einzelne Vorkommen, `EXDATE` entfernt sie und über `RECURRENCE-ID` gelieferte Änderungen oder Absagen ersetzen das zugehörige Serienvorkommen. Lokale Uhrzeiten werden in der angegebenen Zeitzone erzeugt, sodass sie auch über Sommer- und Winterzeitwechsel konstant bleiben.

Geheime Feed-Adressen werden nicht in die Terminvariable oder die Konfiguration einer erzeugten Kalenderinstanz übernommen. Sie verbleiben in der zugehörigen Konto-Instanz.

#### Robuste Feed-Aktualisierung

Jedes aktive iCalendar-Abonnement besitzt einen eigenen persistenten
Feed-Cache:

- Liefert der Server `ETag` oder `Last-Modified`, sendet das Modul bei der
  nächsten Abfrage `If-None-Match` beziehungsweise `If-Modified-Since`.
- Bei `304 Not Modified` wird die bereits geprüfte lokale Feed-Version
  wiederverwendet.
- Bei einer neuen gültigen Antwort werden Downloadzeitpunkt und Zeitpunkt der
  letzten tatsächlichen Inhaltsänderung getrennt gespeichert.
- Leere Antworten, HTML-Fehlerseiten oder syntaktisch ungültige
  iCalendar-Antworten ersetzen niemals den letzten gültigen Feed.
- Bei vorübergehenden Netzwerkproblemen, HTTP `408`, `425`, `429` oder
  Serverfehlern ab `500` werden weiterhin die letzten gültigen Kalenderdaten
  geliefert und als veraltet markiert.
- Authentifizierungsfehler und dauerhafte Clientfehler wie `404` werden nicht
  durch Cache-Daten verborgen.

**Verbindung testen** prüft immer den aktuellen Serverzustand. Der Test meldet
einen Fehler, selbst wenn noch eine verwendbare ältere Feed-Version vorhanden
ist. Dadurch bleibt die Kalenderanzeige robust, ohne Konfigurations- oder
Zugriffsprobleme zu verschleiern.

**Kontostatus anzeigen** enthält je Abonnement `lastCheck`, `lastDownload`,
`lastChange`, `stale` und `lastError`. Feed-Adressen und Feed-Inhalte werden
dabei nicht ausgegeben. **Cache leeren** entfernt sowohl die gefundenen
Kalender als auch sämtliche gespeicherten Feed-Versionen.

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
string IPSKALACC_GetGoogleRedirectURI(int $InstanzID);
bool IPSKALACC_DisconnectGoogle(int $InstanzID);
```

Die Methoden mit komplexen Rückgabewerten liefern JSON. Passwörter und OAuth-Tokens werden weder in Rückgabewerte noch in Debugmeldungen geschrieben.
