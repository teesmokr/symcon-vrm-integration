<?php

declare(strict_types=1);

/**
 * Victron VRM Energiemonitor
 *
 * Holt die aktuellen Systemwerte (PV, Batterie, Netz, Verbrauch) über die
 * Victron VRM Cloud-API (Personal Access Token) und stellt sie als Symcon-
 * Variablen sowie als GUIv2-artige HTML-Kachel für die Tile-Visualisierung
 * bereit.
 */
class VictronVRMEnergyMonitor extends IPSModule
{
    private const API_BASE = 'https://vrmapi.victronenergy.com';

    public function Create()
    {
        parent::Create();

        // Zugang
        $this->RegisterPropertyString('AccessToken', '');
        $this->RegisterPropertyInteger('IdSite', 0);
        $this->RegisterPropertyInteger('UpdateInterval', 60);

        // Manuelle Code-Overrides (leer = Auto-Erkennung). Mehrere Codes per Komma summieren.
        $this->RegisterPropertyString('CodeSOC', '');
        $this->RegisterPropertyString('CodePV', '');
        $this->RegisterPropertyString('CodeBattery', '');
        $this->RegisterPropertyString('CodeGrid', '');
        $this->RegisterPropertyString('CodeConsumption', '');

        // Cache der abgerufenen Anlagen für das Formular
        $this->RegisterAttributeString('Installations', '[]');
        $this->RegisterAttributeInteger('IdUser', 0);

        $this->RegisterTimer('Update', 0, 'VRM_Update($_IPS[\'TARGET\']);');

        // HTML-SDK Tile aktivieren (ab Symcon 7.1)
        $this->SetVisualizationType(1);

        $this->registerVariables();
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->registerVariables();

        $token = trim($this->ReadPropertyString('AccessToken'));
        $site  = $this->ReadPropertyInteger('IdSite');

        if ($token === '' || $site <= 0) {
            $this->SetStatus(104); // inaktiv – Konfiguration unvollständig
            $this->SetTimerInterval('Update', 0);
            return;
        }

        $this->SetStatus(102); // aktiv

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        if ($interval < 15) {
            $interval = 15; // VRM-Cloud aktualisiert nur alle paar Minuten – häufiger ist sinnlos
        }
        $this->SetTimerInterval('Update', $interval * 1000);
    }

    private function registerVariables(): void
    {
        $this->RegisterVariableFloat('SOC', $this->Translate('Battery charge'), '~Battery.100', 10);
        $this->RegisterVariableFloat('PVPower', $this->Translate('Solar power'), '~Watt', 20);
        $this->RegisterVariableFloat('BatteryPower', $this->Translate('Battery power'), '~Watt', 30);
        $this->RegisterVariableFloat('GridPower', $this->Translate('Grid power'), '~Watt', 40);
        $this->RegisterVariableFloat('ConsumptionPower', $this->Translate('Consumption'), '~Watt', 50);
        $this->RegisterVariableInteger('LastUpdate', $this->Translate('Last update'), '~UnixTimestamp', 60);
    }

    /* ===================== Timer / Datenabruf ===================== */

    public function Update(): void
    {
        $site = $this->ReadPropertyInteger('IdSite');
        if ($site <= 0 || trim($this->ReadPropertyString('AccessToken')) === '') {
            return;
        }

        [$httpCode, $data, $err] = $this->apiRequest('/v2/installations/' . $site . '/diagnostics?count=1000');
        if ($httpCode !== 200 || !is_array($data) || !isset($data['records'])) {
            $this->SendDebug('Update', 'Fehler beim Abruf: HTTP ' . $httpCode . ' ' . $err, 0);
            $this->SetStatus(201); // Kommunikationsfehler
            return;
        }

        $records = $data['records'];
        $metrics = $this->detectMetrics($records);

        if ($metrics['SOC'] !== null) {
            $this->SetValue('SOC', $metrics['SOC']);
        }
        $this->SetValue('PVPower', $metrics['PV'] ?? 0.0);
        $this->SetValue('BatteryPower', $metrics['Battery'] ?? 0.0);
        $this->SetValue('GridPower', $metrics['Grid'] ?? 0.0);
        $this->SetValue('ConsumptionPower', $metrics['Consumption'] ?? 0.0);
        $this->SetValue('LastUpdate', time());

        if ($this->GetStatus() !== 102) {
            $this->SetStatus(102);
        }

        $this->pushToTile();
    }

    /* ===================== Wert-Erkennung ===================== */

    /**
     * Ermittelt die Systemwerte aus den Diagnostics-Records.
     * Reihenfolge je Metrik: manueller Override > bekannte Codes > Beschreibungs-Heuristik.
     * Rückgabe in Watt bzw. Prozent, Vorzeichen: Netz + = Bezug, Batterie + = Laden.
     */
    private function detectMetrics(array $records): array
    {
        $sumCodes = function (array $codes) use ($records) {
            $total = 0.0;
            $found = false;
            foreach ($records as $r) {
                if (isset($r['code']) && in_array($r['code'], $codes, true) && is_numeric($r['rawValue'] ?? null)) {
                    $total += (float) $r['rawValue'];
                    $found = true;
                }
            }
            return $found ? $total : null;
        };

        $firstByDesc = function (array $needles) use ($records) {
            foreach ($records as $r) {
                $desc = strtolower((string) ($r['description'] ?? ''));
                foreach ($needles as $n) {
                    if ($desc !== '' && strpos($desc, $n) !== false && is_numeric($r['rawValue'] ?? null)) {
                        return (float) $r['rawValue'];
                    }
                }
            }
            return null;
        };

        $sumByDesc = function (array $needles) use ($records) {
            $total = 0.0;
            $found = false;
            foreach ($records as $r) {
                $desc = strtolower((string) ($r['description'] ?? ''));
                foreach ($needles as $n) {
                    if ($desc !== '' && strpos($desc, $n) !== false && is_numeric($r['rawValue'] ?? null)) {
                        $total += (float) $r['rawValue'];
                        $found = true;
                        break;
                    }
                }
            }
            return $found ? $total : null;
        };

        $override = function (string $prop) use ($sumCodes) {
            $raw = trim($this->ReadPropertyString($prop));
            if ($raw === '') {
                return null;
            }
            $codes = array_map('trim', explode(',', $raw));
            return $sumCodes($codes);
        };

        // SOC (%)
        $soc = $override('CodeSOC');
        if ($soc === null) {
            $soc = $sumCodes(['SOC']);
        }
        if ($soc === null) {
            $soc = $firstByDesc(['state of charge']);
        }

        // PV / Solar (W) – DC-gekoppelt und AC-gekoppelt summieren
        $pv = $override('CodePV');
        if ($pv === null) {
            $pv = $sumCodes(['Pdc', 'PVP']);
        }
        if ($pv === null) {
            $pv = $sumByDesc(['pv - dc-coupled', 'pv - ac-coupled', 'pv power', 'solar']);
        }

        // Batterieleistung (W)
        $battery = $override('CodeBattery');
        if ($battery === null) {
            $battery = $sumCodes(['bp', 'Pb']);
        }
        if ($battery === null) {
            $battery = $firstByDesc(['battery power']);
        }
        if ($battery === null) {
            // Fallback: aus Spannung × Strom berechnen (Vorzeichen des Stroms = Laderichtung)
            $v = $sumCodes(['V', 'bv']) ?? $firstByDesc(['battery voltage']);
            $i = $sumCodes(['I', 'bc']) ?? $firstByDesc(['battery current']);
            if ($v !== null && $i !== null) {
                $battery = $v * $i;
            }
        }

        // Netzleistung (W) – Summe aller Phasen
        $grid = $override('CodeGrid');
        if ($grid === null) {
            $grid = $sumCodes(['g1', 'g2', 'g3']);
        }
        if ($grid === null) {
            $grid = $sumByDesc(['grid l', 'grid power']);
        }

        // Verbrauch / AC-Lasten (W) – Summe aller Phasen
        $consumption = $override('CodeConsumption');
        if ($consumption === null) {
            $consumption = $sumCodes(['o1', 'o2', 'o3']);
        }
        if ($consumption === null) {
            $consumption = $sumByDesc(['ac consumption', 'ac loads', 'consumption l']);
        }

        // Verbrauch notfalls aus Energiebilanz ableiten: Last = PV + Netz + Batterieentladung
        if ($consumption === null && ($pv !== null || $grid !== null || $battery !== null)) {
            $consumption = (($pv ?? 0.0) + ($grid ?? 0.0) - ($battery ?? 0.0));
            if ($consumption < 0) {
                $consumption = 0.0;
            }
        }

        return [
            'SOC'         => $soc === null ? null : round($soc, 1),
            'PV'          => $pv === null ? null : round($pv, 1),
            'Battery'     => $battery === null ? null : round($battery, 1),
            'Grid'        => $grid === null ? null : round($grid, 1),
            'Consumption' => $consumption === null ? null : round($consumption, 1),
        ];
    }

    /* ===================== VRM API ===================== */

    private function apiRequest(string $path): array
    {
        $token = trim($this->ReadPropertyString('AccessToken'));
        $ch = curl_init(self::API_BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'X-Authorization: Token ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $decoded = is_string($body) ? json_decode($body, true) : null;
        return [$httpCode, $decoded, $err];
    }

    private function resolveUserId(): int
    {
        $cached = $this->ReadAttributeInteger('IdUser');
        if ($cached > 0) {
            return $cached;
        }
        [$httpCode, $data] = $this->apiRequest('/v2/users/me');
        if ($httpCode === 200 && isset($data['user']['id'])) {
            $id = (int) $data['user']['id'];
            $this->WriteAttributeInteger('IdUser', $id);
            return $id;
        }
        return 0;
    }

    /* ===================== Formular ===================== */

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Zwischengespeicherte Anlagen ins Auswahlfeld einsetzen
        $options = json_decode($this->ReadAttributeString('Installations'), true);
        if (!is_array($options)) {
            $options = [];
        }
        array_unshift($options, ['caption' => $this->Translate('Please select'), 'value' => 0]);

        // Sicherstellen, dass der gespeicherte Wert eine Option besitzt
        $current = $this->ReadPropertyInteger('IdSite');
        if ($current > 0) {
            $exists = false;
            foreach ($options as $o) {
                if ((int) $o['value'] === $current) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $options[] = ['caption' => 'Site #' . $current, 'value' => $current];
            }
        }

        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'IdSite') {
                $element['options'] = $options;
            }
        }
        unset($element);

        return json_encode($form);
    }

    /* ===================== Formular-Aktionen ===================== */

    public function LoadInstallations(): void
    {
        if (trim($this->ReadPropertyString('AccessToken')) === '') {
            $this->UpdateFormField('IdSite', 'caption', $this->Translate('Please enter an access token first'));
            echo $this->Translate('Please enter an access token first');
            return;
        }

        $userId = $this->resolveUserId();
        if ($userId <= 0) {
            echo $this->Translate('Could not authenticate. Please check the access token.');
            return;
        }

        [$httpCode, $data] = $this->apiRequest('/v2/users/' . $userId . '/installations?extended=1');
        if ($httpCode !== 200 || !isset($data['records'])) {
            echo $this->Translate('Could not load installations.') . ' (HTTP ' . $httpCode . ')';
            return;
        }

        $options = [];
        foreach ($data['records'] as $rec) {
            if (!isset($rec['idSite'])) {
                continue;
            }
            $options[] = [
                'caption' => ($rec['name'] ?? ('Site ' . $rec['idSite'])) . ' (#' . $rec['idSite'] . ')',
                'value'   => (int) $rec['idSite'],
            ];
        }

        $this->WriteAttributeString('Installations', json_encode($options));

        if (count($options) === 0) {
            echo $this->Translate('No installations found for this account.');
            return;
        }

        array_unshift($options, ['caption' => $this->Translate('Please select'), 'value' => 0]);
        $this->UpdateFormField('IdSite', 'options', json_encode($options));
        // Bei nur einer Anlage direkt vorbelegen
        if (count($options) === 2) {
            $this->UpdateFormField('IdSite', 'value', $options[1]['value']);
        }
    }

    /**
     * Listet alle verfügbaren Diagnostics-Codes zur Konfiguration der Overrides.
     */
    public function ListCodes(): void
    {
        $site = $this->ReadPropertyInteger('IdSite');
        if ($site <= 0) {
            echo $this->Translate('Please select an installation first.');
            return;
        }

        [$httpCode, $data] = $this->apiRequest('/v2/installations/' . $site . '/diagnostics?count=1000');
        if ($httpCode !== 200 || !isset($data['records'])) {
            echo $this->Translate('Could not load diagnostics.') . ' (HTTP ' . $httpCode . ')';
            return;
        }

        $lines = [];
        $lines[] = str_pad('code', 12) . str_pad('inst', 6) . str_pad('value', 16) . 'description';
        $lines[] = str_repeat('-', 70);
        foreach ($data['records'] as $r) {
            $lines[] = str_pad((string) ($r['code'] ?? ''), 12)
                . str_pad((string) ($r['instance'] ?? ''), 6)
                . str_pad((string) ($r['formattedValue'] ?? ($r['rawValue'] ?? '')), 16)
                . (string) ($r['description'] ?? '');
        }
        $text = implode("\n", $lines);
        $this->SendDebug('ListCodes', "\n" . $text, 0);
        echo $text;
    }

    /* ===================== Visualisierung (HTML-SDK) ===================== */

    public function GetVisualizationTile()
    {
        $module = file_get_contents(__DIR__ . '/module.html');
        // Startwerte einbetten, damit die Kachel sofort etwas anzeigt
        $initial = json_encode($this->currentValues());
        $module = str_replace('/*__INITIAL_DATA__*/null', $initial, $module);
        return $module;
    }

    private function currentValues(): array
    {
        return [
            'soc'         => $this->getFloatSafe('SOC'),
            'pv'          => $this->getFloatSafe('PVPower'),
            'battery'     => $this->getFloatSafe('BatteryPower'),
            'grid'        => $this->getFloatSafe('GridPower'),
            'consumption' => $this->getFloatSafe('ConsumptionPower'),
            'timestamp'   => (int) @$this->GetValue('LastUpdate'),
        ];
    }

    private function getFloatSafe(string $ident): float
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id === false || $id === 0) {
            return 0.0;
        }
        return (float) GetValue($id);
    }

    private function pushToTile(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->currentValues()));
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'RefreshTile':
                // Anfrage aus der Kachel: aktuelle Werte erneut senden
                $this->pushToTile();
                return;
            case 'UpdateNow':
                $this->Update();
                return;
        }
        throw new Exception('Invalid Ident: ' . $Ident);
    }
}
