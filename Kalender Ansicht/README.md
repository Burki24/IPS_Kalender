# Kalender Ansicht

Die Kalender Ansicht fasst Termine mehrerer Kalenderinstanzen in einer responsiven Kachel der Symcon-Kachelvisualisierung oder in einer HTMLBox für IPSView zusammen.

## Funktionsumfang

- Agenda-, 3-Tage-, Wochen- und Monatsansicht
- unabhängig wählbare horizontale oder vertikale Wochenansicht für Tile und IPSView
- Zusammenführen beliebig vieler ausgewählter Kalender
- Farbliche Zuordnung der Termine zu ihren Kalendern
- Optionale Anzeige von Kalendername, Ort und Beschreibung
- Navigation innerhalb des angezeigten Zeitraums
- Manuelle Synchronisation aller ausgewählten Kalender
- Erstellen, Bearbeiten und Löschen von Terminen in beschreibbaren Kalendern
- Automatische Aktualisierung der Kachel nach einer Kalendersynchronisation
- Responsive Darstellung für große Kacheln und schmale Mobilansichten
- Optionale IPSView-Ausgabe über eine automatisch aktualisierte `~HTMLBox`-Variable

Wiederkehrende, vom CalDAV-Server expandierte Einzeltermine werden derzeit nur lesend dargestellt. Dadurch wird verhindert, dass versehentlich die komplette Terminserie verändert wird.

## Voraussetzungen

- Symcon ab Version 8.1 mit Kachelvisualisierung und HTML-SDK
- Mindestens eine eingerichtete Instanz des Moduls `Kalender`
- für die alternative Darstellung IPSView mit einem HTML-Box-Steuerelement und Browser-Renderer

## Einrichtung

1. Eine Instanz `Kalender Ansicht` erstellen.
2. In der Liste `Kalender` die gewünschten Kalenderinstanzen auswählen und aktivieren.
3. Standardansicht, Tile-Wochenausrichtung, Zeitraum, maximale Terminanzahl und die gewünschten Detailfelder festlegen.
4. Die Instanz in der Kachelvisualisierung platzieren.

### IPSView

1. In der Instanz **Kalender Ansicht** die Option **IPSView-HTMLBox bereitstellen** aktivieren und die Änderungen übernehmen.
2. Mit **Transparenter IPSView-Hintergrund** festlegen, ob die Oberfläche der umgebenden View sichtbar bleiben soll.
3. Unter **IPSView-Kontrast** die Darstellung an den Hintergrund anpassen. **Heller Hintergrund** erzeugt dunkle Schrift, **Dunkler Hintergrund** helle Schrift. **Automatisch** übernimmt das Farbschema des Endgeräts.
4. Mit **IPSView-Wochenausrichtung** festlegen, ob die Wochentage nebeneinander oder als vertikale Tageszeilen dargestellt werden.
5. Mit **IPSView-Schriftgröße** die gesamte Darstellung zwischen 80 und 200 Prozent skalieren. Der Standardwert 115 Prozent verbessert die Lesbarkeit auf Touchdisplays.
6. Über **IPSView-Farbbalkenbreite** die Kalenderkennzeichnung zwischen 2 und 16 Pixeln einstellen. Der Standardwert beträgt 7 Pixel.
7. Unterhalb der Instanz wird die String-Variable **IPSView-Kalender** mit dem Profil `~HTMLBox` angelegt.
8. Im IPSView Designer ein Steuerelement vom Typ **HTML-Box** einfügen und diese Variable als ID auswählen.
9. Als HTML Renderer **Browser des Clients** oder **Automatisch** verwenden. Der native einfache HTML Renderer reicht nicht aus, weil Ansichtswechsel und Navigation JavaScript verwenden.

Agenda, 3-Tage-, Wochen- und Monatsansicht sowie die Navigation funktionieren direkt innerhalb der IPSView-HTMLBox. Die Variable wird bei Änderungen oder Synchronisationen der ausgewählten Kalender automatisch neu erzeugt. Das Öffnen von Termindetails ist lesend möglich. Erstellen, Bearbeiten, Löschen und die manuelle Synchronisationsschaltfläche bleiben der Symcon-Kachel vorbehalten, da die IPSView-HTMLBox keine Symcon-HTML-SDK-Aktionsbrücke bereitstellt.

Die HTMLBox kann den Hintergrund der umgebenden IPSView nicht auslesen, da sie in einem eigenen Browserbereich ausgeführt wird. Deshalb ist der Kontrast unabhängig von der Transparenz einstellbar. Bei älteren IPSView-Clients oder nativen Renderern kann der Browserbereich trotz transparentem HTML einen eigenen Hintergrund zeichnen; in diesem Fall den aktuellen Browser-Renderer des Clients verwenden.

Die IPSView-Option **Seite skalieren** wird laut Hersteller nur von den mobilen Clients unterstützt und hat unter Windows keine Wirkung. Schriftgröße und Farbbalkenbreite werden deshalb vom Modul direkt am Kalenderinhalt gesetzt. Für die zuverlässige Verarbeitung von CSS und JavaScript muss in IPSView **Browser des Clients** statt **HTML Renderer** ausgewählt sein.

Über `Kalender synchronisieren` kann die Verbindung bereits in der Konfiguration geprüft werden. Neu vom Konfigurator angelegte Kalender übernehmen Farbe und Schreibberechtigung automatisch. Bei Kalenderinstanzen, die vor Einführung dieser Eigenschaften angelegt wurden, die Konfiguration über den Kalender-Konfigurator einmal neu anwenden.

## PHP-Befehlsreferenz

```php
// Alle ausgewählten Kalender synchronisieren.
$success = IPSKALVIEW_SynchronizeCalendars(12345);

// Die zusammengeführten Termine als JSON abrufen.
$events = IPSKALVIEW_GetAggregatedEvents(12345);

// Den aktuellen eigenständigen HTML-Inhalt für IPSView abrufen.
$html = IPSKALVIEW_GetIPSViewHTML(12345);
```
