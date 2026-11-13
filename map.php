<?php
// Öffentliche Live-Ansicht einer Map (ohne Login) – z. B. für eine NOC-Wand / TV.
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
$t = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($_GET['t'] ?? ''));
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Map · <?= h(APP_NAME) ?></title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=<?= APP_VERSION ?>">
<script src="assets/icons.js?v=<?= APP_VERSION ?>" defer></script>
<script src="assets/mapeditor.js?v=<?= APP_VERSION ?>" defer></script>
</head>
<body>
<div class="public-map" id="pm" data-t="<?= h($t) ?>"></div>
<script>
document.addEventListener('DOMContentLoaded', async () => {
  const box = document.getElementById('pm');
  const t = box.dataset.t;
  const get = (a) => fetch('public.php?t=' + encodeURIComponent(t) + (a ? '&action=' + a : '')).then((r) => r.json());
  try {
    const j = await get('');
    if (!j.ok) throw new Error(j.error);
    document.title = j.map.name + ' · Live-Map';
    new MapEditor(box, {
      data: j.map.data, name: j.map.name, readonly: true, refresh: j.map.refresh_s, status: j.index,
      fetchStatus: () => get('status').then((r) => (r.ok ? r.index : null)),
    });
  } catch (e) {
    box.innerHTML = '<p class="public-err">' + (e.message || 'Fehler').replace(/[<>&]/g, '') + '</p>';
  }
});
</script>
</body>
</html>
