# Kalender Konfigurator

Der Kalender Konfigurator zeigt die Kalender eines bestimmten **Kalender
Kontos** an und erstellt daraus vollständig konfigurierte
**Kalender-Instanzen**.

## Funktionsumfang

- lädt die vom verbundenen Kalender Konto gefundenen Kalender
- zeigt Kalendername, Farbe, Zugriffsart und vorhandene Instanz an
- erstellt ausgewählte oder alle noch nicht angelegten Kalender
- übernimmt Kalender-ID, Anbieter-ID, Farbe und Schreibrechte
- übernimmt bei ICS/Webcal den für das Abonnement voreingestellten Aktualisierungsplan
- verbindet jede erzeugte Kalender-Instanz mit dem richtigen Konto
- erkennt bereits für dieses Konto angelegte Kalender-Instanzen

## Einrichtung

1. Zuerst das gewünschte **Kalender Konto** anlegen und vollständig
   konfigurieren.
2. Das Konto über **Jetzt synchronisieren** aktualisieren.
3. Den mit diesem Konto verbundenen **Kalender Konfigurator** öffnen.
4. In der Liste einen oder mehrere Kalender auswählen.
5. **Erstellen** beziehungsweise **Alle erstellen** verwenden.

> **Kalender-Instanzen sollen immer aus dieser Liste erstellt werden.**
> Manuell angelegte, kopierte oder nur über **Gateway ändern** verbundene
> Instanzen erhalten weder den richtigen Kalendernamen noch die vollständige
> Kalenderidentität. Bei einem Konto mit mehreren Kalendern ist anschließend
> keine eindeutige Zuordnung möglich.

Die erzeugten Instanzen können nach der Erstellung im Objektbaum verschoben
oder umbenannt werden. Ihre technische Zuordnung zum Konto bleibt dabei
erhalten.

## Kontobezogene Arbeitsweise

Ein Konfigurator gehört immer genau zu dem Kalender Konto, mit dem er über den
Datenfluss verbunden ist. Er zeigt deshalb bewusst nur Kalender dieses Kontos
an. Für ein weiteres Apple-, Google-, Microsoft-, CalDAV- oder
ICS/Webcal-Konto wird ein eigener kontobezogener Konfigurator verwendet.

Diese Trennung verhindert, dass Kalender verschiedener Zugangsdaten oder
Anbieter versehentlich mit dem falschen Konto verbunden werden.

## Voraussetzungen

- Symcon ab Version 8.1
- ein eingerichtetes und aktives **Kalender Konto**
- eine erfolgreiche Kontosynchronisation
