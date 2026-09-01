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
        $this->RegisterPropertyString('CodeBatteryVoltage', '');
        $this->RegisterPropertyString('CodePV', '');
        $this->RegisterPropertyString('CodeBattery', '');
        $this->RegisterPropertyString('CodeGrid', '');
        $this->RegisterPropertyString('CodeConsumption', '');
        $this->RegisterPropertyString('CodeDC', '');

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
        $this->RegisterVariableFloat('BatteryVoltage', $this->Translate('Battery voltage'), '~Volt', 15);
        $this->RegisterVariableFloat('BatteryPower', $this->Translate('Battery power'), '~Watt', 20);
        $this->RegisterVariableFloat('PVPower', $this->Translate('Solar power'), '~Watt', 30);
        $this->RegisterVariableFloat('GridPower', $this->Translate('Grid power'), '~Watt', 40);
        $this->RegisterVariableFloat('ConsumptionPower', $this->Translate('AC consumption'), '~Watt', 50);
        $this->RegisterVariableFloat('DCPower', $this->Translate('DC loads'), '~Watt', 60);
        $this->RegisterVariableInteger('LastUpdate', $this->Translate('Last update'), '~UnixTimestamp', 70);
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

        $m = $this->detectMetrics($data['records']);

        if ($m['SOC'] !== null) {
            $this->SetValue('SOC', $m['SOC']);
        }
        if ($m['BatteryVoltage'] !== null) {
            $this->SetValue('BatteryVoltage', $m['BatteryVoltage']);
        }
        $this->SetValue('BatteryPower', $m['Battery'] ?? 0.0);
        $this->SetValue('PVPower', $m['PV'] ?? 0.0);
        $this->SetValue('GridPower', $m['Grid'] ?? 0.0);
        $this->SetValue('ConsumptionPower', $m['Consumption'] ?? 0.0);
        $this->SetValue('DCPower', $m['DC'] ?? 0.0);
        $this->SetValue('LastUpdate', time());

        $snapshot = $this->buildSnapshot($m, time());
        $this->SetBuffer('Display', json_encode($snapshot));

        if ($this->GetStatus() !== 102) {
            $this->SetStatus(102);
        }

        $this->UpdateVisualizationValue(json_encode($snapshot));
    }

    /* ===================== Wert-Erkennung ===================== */

    /**
     * Ermittelt die Systemwerte aus den Diagnostics-Records.
     * Reihenfolge je Metrik: manueller Override > bekannte Codes > Beschreibungs-Heuristik.
     * Werte in Watt bzw. Prozent/Volt. Vorzeichen: Netz + = Bezug, Batterie + = Laden.
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

        // Einzelwerte je Code (für Phasen-Punkte)
        $byCode = function (string $code) use ($records) {
            foreach ($records as $r) {
                if (($r['code'] ?? '') === $code && is_numeric($r['rawValue'] ?? null)) {
                    return (float) $r['rawValue'];
                }
            }
            return null;
        };
        $phases = function (array $codes) use ($byCode) {
            $out = [];
            $any = false;
            foreach ($codes as $c) {
                $v = $byCode($c);
                if ($v !== null) {
                    $any = true;
                }
                $out[] = $v;
            }
            return $any ? $out : [];
        };

        $override = function (string $prop) use ($sumCodes) {
            $raw = trim($this->ReadPropertyString($prop));
            if ($raw === '') {
                return null;
            }
            return $sumCodes(array_map('trim', explode(',', $raw)));
        };

        // SOC (%)
        $soc = $override('CodeSOC') ?? $sumCodes(['SOC']) ?? $firstByDesc(['state of charge']);

        // Batteriespannung (V)
        $bvolt = $override('CodeBatteryVoltage') ?? $byCode('V') ?? $byCode('bv') ?? $firstByDesc(['battery voltage']);

        // PV / Solar (W) – DC- und AC-gekoppelt
        $pv = $override('CodePV') ?? $sumCodes(['Pdc', 'PVP'])
            ?? $sumByDesc(['pv - dc-coupled', 'pv - ac-coupled', 'pv power', 'solar']);

        // Batterieleistung (W); Fallback: U × I
        $battery = $override('CodeBattery') ?? $sumCodes(['bp', 'Pb']) ?? $firstByDesc(['battery power']);
        if ($battery === null) {
            $i = $byCode('I') ?? $byCode('bc') ?? $firstByDesc(['battery current']);
            if ($bvolt !== null && $i !== null) {
                $battery = $bvolt * $i;
            }
        }

        // Netzleistung (W) – Summe aller Phasen
        $grid = $override('CodeGrid') ?? $sumCodes(['g1', 'g2', 'g3']) ?? $sumByDesc(['grid l', 'grid power']);
        $gridPhases = $phases(['g1', 'g2', 'g3']);

        // AC-Verbrauch (W) – Summe aller Phasen
        $consumption = $override('CodeConsumption') ?? $sumCodes(['o1', 'o2', 'o3'])
            ?? $sumByDesc(['ac consumption', 'ac loads', 'consumption l']);
        $consPhases = $phases(['o1', 'o2', 'o3']);

        // DC-Lasten (W)
        $dc = $override('CodeDC') ?? $sumCodes(['dc_P', 'lo']) ?? $firstByDesc(['dc power', 'dc loads', 'system dc load']);

        // Verbrauch notfalls aus Energiebilanz ableiten: Last = PV + Netz − Batterieladung
        if ($consumption === null && ($pv !== null || $grid !== null || $battery !== null)) {
            $consumption = max(0.0, ($pv ?? 0.0) + ($grid ?? 0.0) - ($battery ?? 0.0));
        }

        $round = fn($v, $d = 1) => $v === null ? null : round($v, $d);

        return [
            'SOC'            => $round($soc, 0),
            'BatteryVoltage' => $round($bvolt, 2),
            'Battery'        => $round($battery, 0),
            'PV'             => $round($pv, 0),
            'Grid'           => $round($grid, 0),
            'GridPhases'     => $gridPhases,
            'Consumption'    => $round($consumption, 0),
            'ConsPhases'     => $consPhases,
            'DC'             => $round($dc, 0),
        ];
    }

    /* ===================== Snapshot für die Kachel ===================== */

    private function buildSnapshot(array $m, int $ts): array
    {
        return [
            'soc'         => $m['SOC'],
            'battVoltage' => $m['BatteryVoltage'],
            'battPower'   => $m['Battery'] ?? 0.0,
            'pv'          => $m['PV'] ?? 0.0,
            'grid'        => $m['Grid'] ?? 0.0,
            'gridPhases'  => $m['GridPhases'] ?? [],
            'consumption' => $m['Consumption'] ?? 0.0,
            'consPhases'  => $m['ConsPhases'] ?? [],
            'dc'          => $m['DC'] ?? 0.0,
            'timestamp'   => $ts,
        ];
    }

    private function currentSnapshot(): array
    {
        $buf = $this->GetBuffer('Display');
        if ($buf !== '') {
            $decoded = json_decode($buf, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        // Fallback aus den gespeicherten Variablen (z. B. vor dem ersten Abruf)
        return [
            'soc'         => $this->getFloatSafe('SOC'),
            'battVoltage' => $this->getFloatSafe('BatteryVoltage'),
            'battPower'   => $this->getFloatSafe('BatteryPower'),
            'pv'          => $this->getFloatSafe('PVPower'),
            'grid'        => $this->getFloatSafe('GridPower'),
            'gridPhases'  => [],
            'consumption' => $this->getFloatSafe('ConsumptionPower'),
            'consPhases'  => [],
            'dc'          => $this->getFloatSafe('DCPower'),
            'timestamp'   => (int) $this->getFloatSafe('LastUpdate'),
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

        $options = json_decode($this->ReadAttributeString('Installations'), true);
        if (!is_array($options)) {
            $options = [];
        }
        array_unshift($options, ['caption' => $this->Translate('Please select'), 'value' => 0]);

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
            $this->injectOptions($element, $options);
        }
        unset($element);

        return json_encode($form);
    }

    private function injectOptions(array &$element, array $options): void
    {
        if (($element['name'] ?? '') === 'IdSite') {
            $element['options'] = $options;
        }
        if (isset($element['items']) && is_array($element['items'])) {
            foreach ($element['items'] as &$child) {
                $this->injectOptions($child, $options);
            }
            unset($child);
        }
    }

    /* ===================== Formular-Aktionen ===================== */

    public function LoadInstallations(): void
    {
        if (trim($this->ReadPropertyString('AccessToken')) === '') {
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

        $display = array_merge([['caption' => $this->Translate('Please select'), 'value' => 0]], $options);
        $this->UpdateFormField('IdSite', 'options', json_encode($display));
        if (count($options) === 1) {
            $this->UpdateFormField('IdSite', 'value', $options[0]['value']);
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
        $initial = json_encode($this->currentSnapshot());
        return str_replace('/*__INITIAL_DATA__*/null', $initial, $module);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'RefreshTile':
                $this->UpdateVisualizationValue(json_encode($this->currentSnapshot()));
                return;
            case 'UpdateNow':
                $this->Update();
                return;
        }
        throw new Exception('Invalid Ident: ' . $Ident);
    }
}
