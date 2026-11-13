<?php
// Minimaler SNMP-Client (v1 / v2c) in reinem PHP – GET, GETNEXT, WALK. Keine Extension nötig.
declare(strict_types=1);

final class SnmpClient
{
    private $sock;
    private int $reqId;

    public function __construct(
        private string $host,
        private string $community = 'public',
        private int $version = 2,          // 1 oder 2 (= v2c)
        private int $port = 161,
        private float $timeout = 2.0,
        private int $retries = 1,
    ) {
        $target = str_contains($host, ':') && $host[0] !== '[' ? "[{$host}]" : $host;
        $this->sock = @stream_socket_client("udp://{$target}:{$port}", $errno, $errstr, $timeout);
        if (!$this->sock) {
            throw new RuntimeException("SNMP: Socket-Fehler: {$errstr}");
        }
        stream_set_blocking($this->sock, true);
        $this->reqId = random_int(1, 0x7fffffff);
    }

    public function __destruct()
    {
        if (is_resource($this->sock)) {
            fclose($this->sock);
        }
    }

    /** GET mehrerer OIDs → [oid => wert|null] */
    public function get(array $oids): array
    {
        $res = $this->request(0xA0, $oids);
        $out = [];
        foreach ($oids as $o) {
            $o = ltrim($o, '.');
            $out[$o] = $res[$o] ?? null;
        }
        return $out;
    }

    public function getOne(string $oid)
    {
        return $this->get([$oid])[ltrim($oid, '.')];
    }

    /** WALK unterhalb einer OID → [oid => wert] */
    public function walk(string $base, int $max = 2000): array
    {
        $base = ltrim($base, '.');
        $cur = $base;
        $out = [];
        for ($i = 0; $i < $max; $i++) {
            $r = $this->request(0xA1, [$cur]);
            if (!$r) {
                break;
            }
            $oid = array_key_first($r);
            $val = $r[$oid];
            if (!str_starts_with($oid . '.', $base . '.') || $val === null || $oid === $cur) {
                break;
            }
            $out[$oid] = $val;
            $cur = $oid;
        }
        return $out;
    }

    private function request(int $pduType, array $oids): array
    {
        $id = $this->reqId = ($this->reqId % 0x7fffffff) + 1;
        $vb = '';
        foreach ($oids as $o) {
            $vb .= self::tlv(0x30, self::encOid($o) . "\x05\x00");
        }
        $pdu = self::tlv($pduType, self::encInt($id) . self::encInt(0) . self::encInt(0) . self::tlv(0x30, $vb));
        $msg = self::tlv(0x30, self::encInt($this->version === 1 ? 0 : 1) . self::tlv(0x04, $this->community) . $pdu);

        for ($try = 0; $try <= $this->retries; $try++) {
            fwrite($this->sock, $msg);
            $deadline = microtime(true) + $this->timeout;
            while (($left = $deadline - microtime(true)) > 0) {
                $r = [$this->sock];
                $w = $e = null;
                if (!@stream_select($r, $w, $e, (int) $left, (int) (fmod($left, 1) * 1e6))) {
                    break;
                }
                $buf = @fread($this->sock, 65535);
                if ($buf === false || $buf === '') {
                    // ICMP port unreachable o. ä.
                    throw new RuntimeException('SNMP: keine Antwort (Port geschlossen?)');
                }
                $resp = $this->parseResponse($buf);
                if ($resp === null || $resp['id'] !== $id) {
                    continue; // fremde/alte Antwort
                }
                if ($resp['error'] && $pduType === 0xA0 && $resp['error'] !== 2) {
                    throw new RuntimeException('SNMP-Fehler ' . $resp['error']);
                }
                return $resp['vars'];
            }
        }
        throw new RuntimeException('SNMP: Zeitüberschreitung (Community/ACL/Firewall prüfen)');
    }

    /* ----------------------------------------------------------- BER */
    private static function len(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $b = '';
        while ($n > 0) {
            $b = chr($n & 0xff) . $b;
            $n >>= 8;
        }
        return chr(0x80 | strlen($b)) . $b;
    }

    private static function tlv(int $tag, string $v): string
    {
        return chr($tag) . self::len(strlen($v)) . $v;
    }

    private static function encInt(int $n): string
    {
        $b = '';
        do {
            $b = chr($n & 0xff) . $b;
            $n >>= 8;
        } while ($n > 0);
        if (ord($b[0]) & 0x80) {
            $b = "\x00" . $b;
        }
        return self::tlv(0x02, $b);
    }

    private static function encOid(string $oid): string
    {
        $p = array_map('intval', explode('.', ltrim($oid, '.')));
        if (count($p) < 2) {
            throw new InvalidArgumentException('Ungültige OID');
        }
        $b = chr(40 * $p[0] + $p[1]);
        for ($i = 2; $i < count($p); $i++) {
            $n = $p[$i];
            $s = chr($n & 0x7f);
            $n >>= 7;
            while ($n > 0) {
                $s = chr(0x80 | ($n & 0x7f)) . $s;
                $n >>= 7;
            }
            $b .= $s;
        }
        return self::tlv(0x06, $b);
    }

    /** liest TLV ab $pos → [tag, value, nextPos] */
    private static function read(string $d, int $pos): array
    {
        if ($pos + 2 > strlen($d)) {
            throw new RuntimeException('SNMP: Paket zu kurz');
        }
        $tag = ord($d[$pos++]);
        $l = ord($d[$pos++]);
        if ($l & 0x80) {
            $nb = $l & 0x7f;
            $l = 0;
            for ($i = 0; $i < $nb; $i++) {
                $l = ($l << 8) | ord($d[$pos++]);
            }
        }
        return [$tag, substr($d, $pos, $l), $pos + $l];
    }

    private static function decOid(string $b): string
    {
        $f = ord($b[0]);
        $p = [intdiv($f, 40), $f % 40];
        $n = 0;
        for ($i = 1; $i < strlen($b); $i++) {
            $c = ord($b[$i]);
            $n = ($n << 7) | ($c & 0x7f);
            if (!($c & 0x80)) {
                $p[] = $n;
                $n = 0;
            }
        }
        return implode('.', $p);
    }

    private static function decUint(string $b): int|float
    {
        $n = 0;
        for ($i = 0; $i < strlen($b); $i++) {
            $n = $n * 256 + ord($b[$i]);   // float bei > PHP_INT_MAX (Counter64)
        }
        return $n;
    }

    private static function decValue(int $tag, string $v)
    {
        switch ($tag) {
            case 0x02: // INTEGER (signed)
                if ($v === '') {
                    return 0;
                }
                $n = ord($v[0]) & 0x80 ? -1 : 0;
                for ($i = 0; $i < strlen($v); $i++) {
                    $n = ($n << 8) | ord($v[$i]);
                }
                return $n;
            case 0x04: return $v;                       // OCTET STRING
            case 0x06: return self::decOid($v);         // OID
            case 0x40: return strlen($v) === 4 ? inet_ntop($v) : bin2hex($v); // IpAddress
            case 0x41: case 0x42: case 0x43: case 0x46: case 0x47:
                return self::decUint($v);               // Counter32, Gauge32, TimeTicks, Counter64
            case 0x05: case 0x80: case 0x81: case 0x82: return null; // NULL, noSuchObject, noSuchInstance, endOfMib
            default: return bin2hex($v);
        }
    }

    private function parseResponse(string $d): ?array
    {
        try {
            [$t, $msg] = self::read($d, 0);
            if ($t !== 0x30) {
                return null;
            }
            [, , $p] = self::read($msg, 0);         // version
            [, , $p] = self::read($msg, $p);        // community
            [$pt, $pdu] = self::read($msg, $p);
            if ($pt !== 0xA2) {
                return null;
            }
            [, $idb, $q] = self::read($pdu, 0);
            [, $eb, $q] = self::read($pdu, $q);
            [, , $q] = self::read($pdu, $q);
            [, $vbl] = self::read($pdu, $q);
            $vars = [];
            $r = 0;
            while ($r < strlen($vbl)) {
                [, $vb, $r] = self::read($vbl, $r);
                [, $oidb, $s] = self::read($vb, 0);
                [$vt, $vv] = self::read($vb, $s);
                $vars[self::decOid($oidb)] = self::decValue($vt, $vv);
            }
            return ['id' => (int) self::decValue(0x02, $idb), 'error' => (int) self::decValue(0x02, $eb), 'vars' => $vars];
        } catch (Throwable) {
            return null;
        }
    }
}

/* Häufig genutzte OIDs */
const OID_SYS_DESCR   = '1.3.6.1.2.1.1.1.0';
const OID_SYS_UPTIME  = '1.3.6.1.2.1.1.3.0';
const OID_SYS_NAME    = '1.3.6.1.2.1.1.5.0';
const OID_IF_DESCR    = '1.3.6.1.2.1.2.2.1.2';
const OID_IF_OPER     = '1.3.6.1.2.1.2.2.1.8';
const OID_IF_IN       = '1.3.6.1.2.1.2.2.1.10';
const OID_IF_OUT      = '1.3.6.1.2.1.2.2.1.16';
const OID_IF_NAME     = '1.3.6.1.2.1.31.1.1.1.1';
const OID_IF_HC_IN    = '1.3.6.1.2.1.31.1.1.1.6';
const OID_IF_HC_OUT   = '1.3.6.1.2.1.31.1.1.1.10';
const OID_IF_HSPEED   = '1.3.6.1.2.1.31.1.1.1.15';
const OID_IF_ALIAS    = '1.3.6.1.2.1.31.1.1.1.18';
const OID_HR_CPU      = '1.3.6.1.2.1.25.3.3.1.2';
const OID_HR_STO_TYPE = '1.3.6.1.2.1.25.2.3.1.2';
const OID_HR_STO_DESC = '1.3.6.1.2.1.25.2.3.1.3';
const OID_HR_STO_UNIT = '1.3.6.1.2.1.25.2.3.1.4';
const OID_HR_STO_SIZE = '1.3.6.1.2.1.25.2.3.1.5';
const OID_HR_STO_USED = '1.3.6.1.2.1.25.2.3.1.6';
const OID_UCD_LOAD1   = '1.3.6.1.4.1.2021.10.1.3.1';
const OID_PF_STATES   = '1.3.6.1.4.1.12325.1.200.1.3.1.0';   // pfSense/FreeBSD bsnmpd (begemotPf)

/** Interfaces eines Geräts auflisten: [index => [name, descr, alias, oper, speed]] */
function snmp_interfaces(SnmpClient $c): array
{
    $descr = $c->walk(OID_IF_DESCR);
    $names = [];
    $alias = [];
    $speed = [];
    try {
        $names = $c->walk(OID_IF_NAME);
        $alias = $c->walk(OID_IF_ALIAS);
        $speed = $c->walk(OID_IF_HSPEED);
    } catch (Throwable) {
        // v1-Geräte ohne ifXTable
    }
    $oper = $c->walk(OID_IF_OPER);
    $out = [];
    foreach ($descr as $oid => $d) {
        $i = (int) substr($oid, strrpos($oid, '.') + 1);
        $out[$i] = [
            'index' => $i,
            'descr' => (string) $d,
            'name'  => (string) ($names[OID_IF_NAME . ".$i"] ?? $d),
            'alias' => (string) ($alias[OID_IF_ALIAS . ".$i"] ?? ''),
            'up'    => (int) ($oper[OID_IF_OPER . ".$i"] ?? 2) === 1,
            'speed' => (int) ($speed[OID_IF_HSPEED . ".$i"] ?? 0),
        ];
    }
    ksort($out);
    return $out;
}
