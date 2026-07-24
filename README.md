# IPS_Kalender

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

Folgende Module beinhaltet das IPS_Kalender Repository:

- __Kalender Konto__ ([Dokumentation](Kalender%20Konto))  
	Verbindet Apple-iCloud-, Google-Calendar- und CalDAV-Konten, bündelt mehrere iCalendar-Abonnements in einem Konto, löst wiederkehrende Feed-Termine lokal auf und stellt die Kalender bereit.

- __Kalender Konfigurator__ ([Dokumentation](Kalender%20Konfigurator))  
	Findet Kalender eines Kontos und legt Kalenderinstanzen an.

- __Kalender__ ([Dokumentation](Kalender))  
	Repräsentiert einen einzelnen Online-Kalender.

- __Kalender Ansicht__ ([Dokumentation](Kalender%20Ansicht))  
	Führt mehrere Kalender in einer modernen Kachelansicht zusammen.
