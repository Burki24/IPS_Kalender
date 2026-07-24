# OpenCalendar

## Wichtiger Hinweis zur Einrichtung

**Kalender-Instanzen sollen ausschließlich über den zum Konto gehörenden
Kalender Konfigurator erstellt werden.**

Der Konfigurator übernimmt automatisch den Kalendernamen, die interne
Kalender-ID, die Anbieter-ID, die Farbe, die Schreibrechte und die Verbindung
zum richtigen Kalender Konto. Werden Kalender-Instanzen stattdessen manuell
angelegt, kopiert oder nur über **Gateway ändern** verbunden, fehlen diese
Informationen. Solche Instanzen heißen zunächst lediglich „Kalender“ und
können insbesondere bei Konten mit mehreren Kalendern nicht eindeutig
zugeordnet werden.

Empfohlene Reihenfolge:

1. **Kalender Konto** anlegen und konfigurieren.
2. Das Konto synchronisieren.
3. Den zugehörigen **Kalender Konfigurator** öffnen.
4. Die gewünschten Kalender in der gefundenen Liste auswählen und dort
   erstellen lassen.
5. Erst danach die erzeugten Kalender-Instanzen bei Bedarf im Objektbaum
   verschieben oder umbenennen.

## Persönliches Google OAuth einrichten

Die Google-Anbindung verwendet bewusst **keinen zentralen OAuth-Dienst des
Modulautors**. Jeder Anwender hinterlegt seinen eigenen Google-OAuth-Client im
jeweiligen **Kalender Konto**. Zugangsdaten und Aktualisierungstoken bleiben
dadurch in der eigenen Symcon-Installation.

Voraussetzungen sind eine aktive Symcon-Connect-Verbindung und ein eigenes
Projekt in der [Google Cloud Console](https://console.cloud.google.com/).

1. Im Google-Cloud-Projekt die **Google Calendar API** aktivieren.
2. Den OAuth-Zustimmungsbildschirm konfigurieren. Während des Testbetriebs das
   gewünschte Google-Konto als Testnutzer eintragen.
3. In Symcon ein **Kalender Konto** öffnen, als Anbieter
   **Google Calendar** wählen und die angezeigte autorisierte
   Weiterleitungs-URI kopieren.
4. In Google unter **APIs und Dienste → Anmeldedaten** eine OAuth-Client-ID vom
   Anwendungstyp **Webanwendung** erstellen.
5. Die von Symcon angezeigte URI unverändert als **Autorisierte
   Weiterleitungs-URI** eintragen. Schema, Host, Pfad und Groß-/Kleinschreibung
   müssen exakt übereinstimmen.
6. Client-ID und Clientschlüssel in das Kalender Konto eintragen und die
   Änderungen übernehmen.
7. **Google-Konto verbinden** wählen, die Google-Freigabe bestätigen und danach
   das Konto synchronisieren.
8. Die gefundenen Kalender anschließend über den zugehörigen
   **Kalender Konfigurator** anlegen.

Bei einem externen OAuth-Zustimmungsbildschirm im Veröffentlichungsstatus
**Test** laufen Aktualisierungstoken nach sieben Tagen ab. Für einen dauerhaft
laufenden Kalender muss der Zustimmungsbildschirm deshalb später auf
**In Produktion** gestellt werden. Für den persönlichen Einsatz kann Google
dabei weiterhin einen Hinweis auf eine nicht verifizierte App anzeigen.

Der OAuth-Client fordert nur die Berechtigungen zum Auflisten der Kalender
sowie zum Lesen und Verwalten von Terminen an. Das Aktualisierungstoken wird
lokal als internes Instanzattribut gespeichert; der Clientschlüssel liegt in
der Symcon-Instanzkonfiguration. Konfigurationsdateien und Backups mit diesen
Daten dürfen daher nicht veröffentlicht werden.

Google dokumentiert den verwendeten
[OAuth-Ablauf für Webserver-Anwendungen](https://developers.google.com/identity/protocols/oauth2/web-server)
und die Einrichtung der
[OAuth-Zugangsdaten](https://developers.google.com/workspace/guides/create-credentials#oauth-client-id)
sowie das
[Ablaufverhalten von Aktualisierungstoken](https://developers.google.com/identity/protocols/oauth2#expiration).

## Microsoft 365 / Outlook.com verbinden

Die Microsoft-Anbindung ist für Anwender bewusst ohne eigene App-Registrierung
aufgebaut. Voraussetzung ist lediglich eine aktive **Symcon-Connect-Verbindung**.
Im **Kalender Konto** wird als Anbieter **Microsoft 365** gewählt und anschließend
**Microsoft-Konto verbinden** aufgerufen. Die Anmeldung und Zustimmung erfolgen
direkt bei Microsoft; Client-ID und Clientschlüssel werden dem Anwender nicht
angezeigt und müssen nicht in Symcon hinterlegt werden.

OpenCalendar fordert ausschließlich delegierten Kalenderzugriff an. Unterstützt
werden Microsoft-365-Geschäfts-/Schulkonten sowie persönliche Microsoft-Konten
wie Outlook.com. Das Modul kann die eigenen Kalender auflisten und – entsprechend
den von Microsoft gemeldeten Kalenderrechten – Termine lesen, erstellen, ändern
und löschen. Mail, Kontakte, OneDrive und Teams-APIs werden nicht angefordert.

Der benutzerspezifische Refresh-Token wird als internes Attribut der
Kalender-Konto-Instanz gespeichert; kurzlebige Access-Tokens werden nur im
Instanzpuffer gehalten.

### Einmalige Freischaltung für Modulautoren

Für die Veröffentlichung muss der gemeinsame OAuth-Client einmalig außerhalb
des Repositorys eingerichtet werden. Diese Einrichtung ist **nicht** von jedem
Anwender durchzuführen:

1. In Microsoft Entra eine Web-App registrieren, die Konten aus beliebigen
   Organisationsverzeichnissen sowie persönliche Microsoft-Konten akzeptiert.
2. Als Redirect-URI
   `https://oauth.ipmagic.de/forward/opencalendar_microsoft` hinterlegen.
3. Delegiert `Calendars.ReadWrite` sowie den OAuth-Scope `offline_access`
   freigeben.
4. Einen Client-Schlüssel erzeugen und den OAuth-Client unter dem Identifier
   `opencalendar_microsoft` beim Symcon-OAuth-Dienst registrieren lassen. Dabei
   Client-ID, Client-Schlüssel, Microsoft-Autorisierungs-/Token-Endpunkte und
   die benötigten Scopes an Symcon übermitteln.

Client-Schlüssel oder andere zentrale App-Zugangsdaten gehören **nicht** in das
Repository. Erst nach dieser einmaligen serverseitigen Registrierung kann der
Microsoft-Login produktiv durchlaufen.

Folgende Module beinhaltet das Repository:

- __Kalender Konto__ ([Dokumentation](Kalender%20Konto))  
	Verbindet Apple-iCloud-, Google-Calendar-, Microsoft-365-/Outlook.com- und CalDAV-Konten, bündelt mehrere iCalendar-Abonnements in einem Konto, hält eine geprüfte Feed-Rückfallebene vor, löst wiederkehrende Feed-Termine lokal auf und stellt die Kalender bereit.

- __Kalender Konfigurator__ ([Dokumentation](Kalender%20Konfigurator))  
	Findet Kalender eines Kontos und legt Kalenderinstanzen an.

- __Kalender__ ([Dokumentation](Kalender))  
	Repräsentiert einen einzelnen Online-Kalender.

- __Kalender Ansicht__ ([Dokumentation](Kalender%20Ansicht))  
	Führt mehrere Kalender in einer modernen Kachelansicht zusammen.
