# Victron VRM Energiemonitor für IP-Symcon

Integriert die Live-Werte deiner Victron-Anlage aus dem **VRM-Portal (Cloud-API)**
in IP-Symcon und stellt sie als Variablen sowie als **GUIv2-artige Energiefluss-Kachel**
für die Tile-Visualisierung bereit.

Angezeigt werden:

- ☀️ **Solarleistung** (PV, DC- und AC-gekoppelt)
- 🔋 **Batterie** – Ladezustand (SOC) und Lade-/Entladeleistung
- 🏭 **Netz** – Bezug und Einspeisung
- 🏠 **Verbrauch** – Hauslasten

Die Kachel ist der Victron-**GUIv2**-Ansicht nachempfunden und unterstützt
Light- und Dark-Mode.

| Light | Dark |
|-------|------|
| ![Energiefluss hell](docs/preview_light.png) | ![Energiefluss dunkel](docs/preview_dark.png) |

## Voraussetzungen

- IP-Symcon **ab Version 7.1** (HTML-SDK für die Kachel-Visualisierung)
- Eine Victron-Anlage mit GX-Gerät (Cerbo GX, Venus OS o. ä.), verbunden mit dem VRM-Portal
- Ein persönliches **VRM-Zugangstoken**

## Installation

1. In IP-Symcon den **Module Store → Modul über URL hinzufügen** öffnen (oder im
   Module Control) und dieses Repository angeben:
   `https://github.com/teesmokr/symcon-vrm-integration`
2. Anschließend eine neue Instanz vom Typ **„Victron VRM Energiemonitor"** anlegen.

## Einrichtung

### 1. VRM-Zugangstoken erstellen

Im [VRM-Portal](https://vrm.victronenergy.com) anmelden und unter
**Einstellungen → Integrationen → Zugangstoken** (Preferences → Integrations →
Access tokens) ein persönliches Token erstellen. Das Token wird nur einmal
angezeigt – kopieren und in der Instanz eintragen.

### 2. Anlage wählen

- Token im Instanz-Konfigurationsformular eintragen.
- Auf **„Anlagen abrufen"** klicken – die Anlagen des Kontos werden geladen.
- Die gewünschte Anlage im Auswahlfeld wählen und **Änderungen übernehmen**.

### 3. Aktualisierungsintervall

Standard sind 60 Sekunden. Die VRM-Cloud speichert die Werte ohnehin nur alle
paar Minuten neu, daher bringt ein sehr kurzes Intervall keine schnelleren Daten.
Für echte Echtzeit-Daten wäre eine lokale Anbindung (MQTT/Modbus TCP zum GX-Gerät)
nötig – dieses Modul nutzt bewusst die Cloud-API ohne lokalen Netzwerkzugriff.

### 4. Kachel in der Visualisierung

Die Instanz stellt automatisch eine HTML-Kachel bereit. In einer
Tile-Visualisierung einfach die Instanz als Kachel hinzufügen – der Energiefluss
wird im Victron-GUIv2-Stil dargestellt und live aktualisiert.

## Wertezuordnung (Auto-Erkennung)

Die Werte werden automatisch aus den VRM-Diagnostics erkannt (u. a. Codes wie
`SOC`, `Pdc`, `o1`…). Da die verfügbaren Codes je nach Anlage, Gerät und Phasenzahl
variieren, gibt es im Abschnitt **„Wertezuordnung (erweitert)"** eine manuelle
Übersteuerung:

- **„Verfügbare Codes auflisten"** zeigt alle von deiner Anlage gelieferten Codes
  mit Beschreibung und aktuellem Wert.
- Bleibt ein Wert `0` oder ist falsch, den/die passenden Code(s) in das jeweilige
  Feld eintragen. Mehrere Codes werden summiert (kommagetrennt), z. B. bei Netz
  oder Verbrauch pro Phase: `o1,o2,o3`.

### Vorzeichen-Konvention

- **Netz**: positiv = Bezug aus dem Netz, negativ = Einspeisung
- **Batterie**: positiv = Laden, negativ = Entladen

## Erzeugte Variablen

| Ident              | Bezeichnung      | Profil        | Einheit |
|--------------------|------------------|---------------|---------|
| `SOC`              | Batterieladung   | `~Battery.100`| %       |
| `BatteryVoltage`   | Batteriespannung | `~Volt`       | V       |
| `BatteryPower`     | Batterieleistung | `~Watt`       | W       |
| `PVPower`          | Solarleistung    | `~Watt`       | W       |
| `GridPower`        | Netzleistung     | `~Watt`       | W       |
| `ConsumptionPower` | AC-Verbrauch     | `~Watt`       | W       |
| `DCPower`          | DC-Lasten        | `~Watt`       | W       |
| `LastUpdate`       | Letzte Aktual.   | `~UnixTimestamp` | –    |

Diese Variablen lassen sich zusätzlich frei in Diagrammen, Skripten und
Ereignissen weiterverwenden.

## Funktionsreferenz

| Funktion                       | Beschreibung                                    |
|--------------------------------|-------------------------------------------------|
| `VRM_Update(int $InstanzID)`   | Ruft die aktuellen Werte sofort aus dem VRM-Portal ab. |
| `VRM_LoadInstallations(int $InstanzID)` | Lädt die Anlagen des Kontos (für das Formular). |
| `VRM_ListCodes(int $InstanzID)`| Listet alle verfügbaren Diagnostics-Codes auf.  |

## Hinweise zum Datenschutz

Das Zugangstoken wird ausschließlich im Header (`X-Authorization: Token …`) an
`https://vrmapi.victronenergy.com` gesendet und lokal in der Instanz gespeichert.
Es werden keine Daten an Dritte übertragen.

## Lizenz

Siehe [LICENSE](LICENSE).
