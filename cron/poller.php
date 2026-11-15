<?php
// Poller („Probe“): per Cron jede Minute starten. Läuft ~55 s und prüft alle fälligen Sensoren
// parallel (pcntl_fork-Worker). Nur CLI.
//   * * * * * www-data php /pfad/zur/app/cron/poller.php >/dev/null 2>&1
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once dirname(__DIR__) . '/lib/engine.php';

$opts = getopt('', ['once', 'verbose']);
$verbose = isset($opts['verbose']);
$runFor = isset($opts['once']) ? 0 : 55;
$maxWorkers = max(1, (int) setting('poller_workers', '8'));

$lock = fopen(DATA_DIR . '/poller.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    $verbose && print("Poller läuft bereits.\n");
    exit(0);
}

$start = time();
setting_set('poller_heartbeat', (string) $start);

do {
    $now = time();
    $pg = paused_group_ids();
    $due = [];
    foreach (db()->query(SENSOR_SELECT . ' WHERE s.paused = 0 AND d.paused = 0 AND s.next_check <= ' . $now . ' ORDER BY s.next_check')->fetchAll() as $s) {
        if (!isset($pg[(int) $s['group_id']])) {
            $due[] = $s;
        }
    }
    if ($due) {
        $verbose && printf("[%s] %d Sensor(en) fällig\n", date('H:i:s'), count($due));
        run_batch($due, $maxWorkers, $verbose);
        setting_set('poller_heartbeat', (string) time());
    }
    if ((int) setting('last_housekeeping', '0') < $now - 3600) {
        housekeeping();
        setting_set('last_housekeeping', (string) $now);
    }
    if ($runFor === 0) {
        break;
    }
    $next = (int) db()->query('SELECT MIN(next_check) FROM sensors WHERE paused = 0')->fetchColumn();
    $sleep = max(1, min(5, $next - time()));
    if (time() + $sleep >= $start + $runFor) {
        break;
    }
    sleep($sleep);
} while (time() < $start + $runFor);

flock($lock, LOCK_UN);

/**
 * Prüfungen laufen in Kindprozessen (ohne DB-Verbindung), Ergebnisse kommen als JSON
 * über Socket-Paare zurück und werden vom Elternprozess gespeichert.
 */
function run_batch(array $due, int $maxWorkers, bool $verbose): void
{
    // Push-Sensoren & Abhängigkeiten brauchen DB -> direkt im Elternprozess.
    $local = [];
    $remote = [];
    foreach ($due as $s) {
        if (in_array($s['type'], PUSH_TYPES, true) || $s['type'] === 'agent_channel' || !empty($s['depends_on'])) {
            $local[] = $s;
        } else {
            $remote[] = $s;
        }
    }
    foreach ($local as $s) {
        $s = sensor_row((int) $s['id']);
        if ($s) {
            sensor_run($s);
        }
    }
    if (!$remote) {
        return;
    }
    $canFork = function_exists('pcntl_fork') && count($remote) > 1;
    if (!$canFork) {
        foreach ($remote as $s) {
            sensor_store_result($s, sensor_check($s));
        }
        return;
    }
    $workers = min($maxWorkers, count($remote));
    $chunks = array_fill(0, $workers, []);
    foreach ($remote as $i => $s) {
        $chunks[$i % $workers][] = $s;
    }
    db_close();
    $children = [];
    foreach ($chunks as $chunk) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($pair[0]);
            foreach ($chunk as $s) {
                $r = sensor_check($s);
                fwrite($pair[1], json_encode(['id' => (int) $s['id'], 'r' => $r]) . "\n");
            }
            fclose($pair[1]);
            exit(0);
        }
        fclose($pair[1]);
        $children[] = [$pid, $pair[0]];
    }
    $results = [];
    foreach ($children as [$pid, $sock]) {
        while (($line = fgets($sock)) !== false) {
            $j = json_decode($line, true);
            if ($j) {
                $results[$j['id']] = $j['r'];
            }
        }
        fclose($sock);
        pcntl_waitpid($pid, $st);
    }
    foreach ($remote as $s) {
        $fresh = sensor_row((int) $s['id']);   // aktuellen Stand holen (könnte inzwischen pausiert sein)
        if (!$fresh || (int) $fresh['paused']) {
            continue;
        }
        $r = $results[(int) $s['id']] ?? r_fail('Prüfung abgebrochen (Worker-Fehler)');
        sensor_store_result($fresh, $r);
        $verbose && printf("  #%d %-30s %-8s %s\n", $s['id'], mb_substr($s['name'], 0, 30), $r['status'], $r['message']);
    }
}
