<?php

declare(strict_types=1);

/**
 * Minimaler MQTT-3.1.1-Client für den lokalen Victron-Broker (Cerbo GX / Venus OS).
 *
 * Arbeitet bewusst im Poll-Modus (verbinden → abonnieren → Keepalive senden →
 * kurze Zeit lesen → trennen), da IP-Symcon-Module keine dauerhafte Verbindung
 * über Timeraufrufe hinweg halten. Victron veröffentlicht auf ein Keepalive an
 * `R/<portalId>/keepalive` alle aktuellen Werte.
 *
 * Hinweis: Nur QoS 0, ausreichend für das Auslesen der System-Übersicht.
 */
class VictronMqtt
{
    private string $host;
    private int $port;
    private string $user;
    private string $password;
    private string $portalId;
    private float $window; // Lesefenster in Sekunden

    /** @var resource|null */
    private $socket = null;
    private string $rxBuffer = '';

    public function __construct(string $host, int $port, string $user = '', string $password = '', string $portalId = '', float $window = 2.5)
    {
        $this->host = $host;
        $this->port = $port > 0 ? $port : 1883;
        $this->user = $user;
        $this->password = $password;
        $this->portalId = $portalId;
        $this->window = $window;
    }

    public function getPortalId(): string
    {
        return $this->portalId;
    }

    /**
     * Verbindet, holt die System-Werte und trennt wieder.
     * @return array<string,float> normalisierte Schlüssel → Wert
     */
    public function fetchSystemValues(): array
    {
        $this->connect();
        try {
            // Alle System-Topics abonnieren (retained Werte kommen sofort)
            $this->subscribe('N/+/system/0/#');

            $raw = [];
            $deadline = microtime(true) + $this->window;

            // Phase 1: retained Werte lesen und dabei die Portal-ID lernen
            $this->collect($raw, min($deadline, microtime(true) + 1.0));

            if ($this->portalId === '' && !empty($raw)) {
                $this->portalId = $this->detectedPortalId;
            }

            // Phase 2: Keepalive senden, damit der Broker alle Werte frisch publiziert
            if ($this->portalId !== '') {
                $this->publish('R/' . $this->portalId . '/keepalive', '');
                $this->collect($raw, $deadline);
            }

            return $this->normalize($raw);
        } finally {
            $this->disconnect();
        }
    }

    /* ===================== Verbindung ===================== */

    private function connect(): void
    {
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 5.0);
        if ($sock === false) {
            throw new Exception('Verbindung fehlgeschlagen: ' . $errstr . ' (' . $errno . ')');
        }
        stream_set_timeout($sock, 0, 300000); // 0,3 s Leseblock
        $this->socket = $sock;

        $this->sendConnect();
        $type = $this->readPacket($ph, $payload, microtime(true) + 5.0);
        if ($type !== 0x20) { // CONNACK
            throw new Exception('Keine CONNACK-Antwort erhalten');
        }
        if (strlen($payload) >= 2 && ord($payload[1]) !== 0x00) {
            throw new Exception('MQTT-Login abgelehnt (Code ' . ord($payload[1]) . ')');
        }
    }

    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fwrite($this->socket, chr(0xE0) . chr(0x00)); // DISCONNECT
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /* ===================== MQTT-Pakete ===================== */

    private function sendConnect(): void
    {
        $protocol = $this->encodeString('MQTT') . chr(0x04); // Level 4 (3.1.1)
        $flags = 0x02; // Clean Session
        if ($this->user !== '') {
            $flags |= 0x80;
        }
        if ($this->password !== '') {
            $flags |= 0x40;
        }
        $keepAlive = chr(0x00) . chr(0x3C); // 60 s
        $clientId = $this->encodeString('symcon-vrm-' . substr(md5((string) mt_rand()), 0, 8));

        $payload = $clientId;
        if ($this->user !== '') {
            $payload .= $this->encodeString($this->user);
        }
        if ($this->password !== '') {
            $payload .= $this->encodeString($this->password);
        }

        $variable = $protocol . chr($flags) . $keepAlive;
        $this->writePacket(0x10, $variable . $payload);
    }

    private function subscribe(string $topic): void
    {
        $packetId = chr(0x00) . chr(0x01);
        $body = $packetId . $this->encodeString($topic) . chr(0x00); // QoS 0
        $this->writePacket(0x82, $body);
    }

    private function publish(string $topic, string $message): void
    {
        // QoS 0 → keine Packet-ID
        $this->writePacket(0x30, $this->encodeString($topic) . $message);
    }

    private function writePacket(int $header, string $body): void
    {
        $packet = chr($header) . $this->encodeLength(strlen($body)) . $body;
        if (!is_resource($this->socket) || @fwrite($this->socket, $packet) === false) {
            throw new Exception('Schreiben auf Socket fehlgeschlagen');
        }
    }

    /* ===================== Empfang ===================== */

    private string $detectedPortalId = '';

    /**
     * Liest eingehende PUBLISH-Pakete bis zum Deadline und legt Topic→Wert in $raw ab.
     */
    private function collect(array &$raw, float $deadline): void
    {
        while (microtime(true) < $deadline) {
            $type = $this->readPacket($ph, $payload, $deadline);
            if ($type === null) {
                continue; // Timeout-Tick, weiter versuchen bis Deadline
            }
            if (($type & 0xF0) === 0x30) { // PUBLISH
                $this->handlePublish($payload, $raw);
            }
        }
    }

    private function handlePublish(string $payload, array &$raw): void
    {
        if (strlen($payload) < 2) {
            return;
        }
        $len = (ord($payload[0]) << 8) | ord($payload[1]);
        $topic = substr($payload, 2, $len);
        $message = substr($payload, 2 + $len);

        if ($this->detectedPortalId === '' && preg_match('#^N/([^/]+)/#', $topic, $mm)) {
            $this->detectedPortalId = $mm[1];
        }

        if (!preg_match('#/system/0/(.+)$#', $topic, $m)) {
            return;
        }
        $value = $this->decodeValue($message);
        if ($value !== null) {
            $raw[$m[1]] = $value;
        }
    }

    private function decodeValue(string $message): ?float
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }
        $json = json_decode($message, true);
        if (is_array($json) && array_key_exists('value', $json)) {
            return is_numeric($json['value']) ? (float) $json['value'] : null;
        }
        return is_numeric($message) ? (float) $message : null;
    }

    /**
     * Liest genau ein MQTT-Paket. Gibt den Paket-Typ (erstes Byte) zurück oder null bei Timeout.
     */
    private function readPacket(&$fixedHeader, &$payload, float $deadline): ?int
    {
        $first = $this->readBytes(1, $deadline);
        if ($first === null) {
            return null;
        }
        $header = ord($first);

        // Remaining Length (variabel, bis 4 Bytes)
        $multiplier = 1;
        $length = 0;
        do {
            $b = $this->readBytes(1, $deadline);
            if ($b === null) {
                return null;
            }
            $digit = ord($b);
            $length += ($digit & 0x7F) * $multiplier;
            $multiplier *= 128;
        } while (($digit & 0x80) !== 0 && $multiplier <= 128 * 128 * 128);

        $payload = $length > 0 ? $this->readBytes($length, $deadline) : '';
        if ($payload === null) {
            return null;
        }
        $fixedHeader = $header;
        return $header;
    }

    private function readBytes(int $n, float $deadline): ?string
    {
        while (strlen($this->rxBuffer) < $n) {
            if (microtime(true) >= $deadline || !is_resource($this->socket)) {
                return null;
            }
            $chunk = @fread($this->socket, max(1, $n - strlen($this->rxBuffer)));
            if ($chunk === false) {
                return null;
            }
            if ($chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) {
                    continue; // nichts angekommen, erneut versuchen bis Deadline
                }
                return null;
            }
            $this->rxBuffer .= $chunk;
        }
        $out = substr($this->rxBuffer, 0, $n);
        $this->rxBuffer = substr($this->rxBuffer, $n);
        return $out;
    }

    /* ===================== Kodierung ===================== */

    private function encodeString(string $s): string
    {
        return chr((strlen($s) >> 8) & 0xFF) . chr(strlen($s) & 0xFF) . $s;
    }

    private function encodeLength(int $len): string
    {
        $out = '';
        do {
            $digit = $len % 128;
            $len = intdiv($len, 128);
            if ($len > 0) {
                $digit |= 0x80;
            }
            $out .= chr($digit);
        } while ($len > 0);
        return $out;
    }

    /* ===================== Normalisierung ===================== */

    /**
     * Bildet die Victron-System-Pfade auf die von der Kachel genutzten Schlüssel ab.
     * @param array<string,float> $raw  Pfad (z. B. "Dc/Battery/Soc") → Wert
     * @return array<string,float>
     */
    private function normalize(array $raw): array
    {
        $out = [];
        $set = function (string $key, string $path) use (&$out, $raw) {
            if (array_key_exists($path, $raw)) {
                $out[$key] = $raw[$path];
            }
        };

        $set('battery/soc', 'Dc/Battery/Soc');
        $set('battery/voltage', 'Dc/Battery/Voltage');
        $set('battery/power', 'Dc/Battery/Power');
        $set('pv/dc', 'Dc/Pv/Power');
        $set('dc/power', 'Dc/System/Power');

        foreach (['l1' => 'L1', 'l2' => 'L2', 'l3' => 'L3'] as $k => $L) {
            $set('grid/' . $k, 'Ac/Grid/' . $L . '/Power');
            $set('cons/' . $k, 'Ac/Consumption/' . $L . '/Power');

            // AC-gekoppeltes PV kann aus mehreren Quellen kommen → summieren
            $acPv = 0.0;
            $found = false;
            foreach (['Ac/PvOnGrid/', 'Ac/PvOnOutput/', 'Ac/PvOnGenset/'] as $prefix) {
                $path = $prefix . $L . '/Power';
                if (array_key_exists($path, $raw)) {
                    $acPv += (float) $raw[$path];
                    $found = true;
                }
            }
            if ($found) {
                $out['pv/ac_' . $k] = $acPv;
            }
        }

        return $out;
    }
}
