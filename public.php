<?php
// Öffentliche, schreibgeschützte Map-Daten (für geteilte Maps / NOC-Wand).
declare(strict_types=1);

require_once __DIR__ . '/lib/engine.php';

$tok = (string) ($_GET['t'] ?? '');
if ($tok === '' || strlen($tok) > 64) {
    json_err('Ungültiger Link', 404);
}
$st = db()->prepare('SELECT id, name, data, refresh_s FROM maps WHERE public_token = ?');
$st->execute([$tok]);
$m = $st->fetch() ?: json_err('Map nicht gefunden oder nicht mehr freigegeben', 404);
$data = json_decode($m['data'], true) ?: ['nodes' => [], 'edges' => []];

// Nur Status der Objekte ausliefern, die in der Map tatsächlich vorkommen.
$idx = status_index();
$filtered = ['group' => [], 'device' => [], 'sensor' => []];
foreach (array_merge($data['nodes'] ?? [], $data['edges'] ?? []) as $o) {
    $b = $o['bind'] ?? null;
    if (is_array($b) && isset($b['type'], $b['id'], $idx[$b['type']][$b['id']])) {
        $filtered[$b['type']][$b['id']] = $idx[$b['type']][$b['id']];
    }
}
if (($_GET['action'] ?? '') === 'status') {
    json_out(['ok' => true, 'index' => $filtered, 'now' => time()]);
}
json_out(['ok' => true, 'map' => ['id' => (int) $m['id'], 'name' => $m['name'], 'data' => $data, 'refresh_s' => (int) $m['refresh_s']], 'index' => $filtered]);
