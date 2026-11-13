<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
$user = require_login_page();
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<title><?= h(APP_NAME) ?></title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=<?= APP_VERSION ?>">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>
<script src="assets/icons.js?v=<?= APP_VERSION ?>" defer></script>
<script src="assets/mapeditor.js?v=<?= APP_VERSION ?>" defer></script>
<script src="assets/app.js?v=<?= APP_VERSION ?>" defer></script>
</head>
<body>
<header class="topbar">
  <a class="brand" href="#/"><img src="assets/favicon.svg" alt="" width="26" height="26"><span><?= h(APP_NAME) ?></span></a>
  <nav class="mainnav" aria-label="Hauptnavigation">
    <a href="#/" data-nav="home">Übersicht</a>
    <a href="#/devices" data-nav="devices">Geräte</a>
    <a href="#/alarms" data-nav="alarms">Alarme <b class="navbadge" id="alarmBadge" hidden></b></a>
    <a href="#/maps" data-nav="maps">Maps</a>
    <a href="#/log" data-nav="log">Protokoll</a>
    <a href="#/settings" data-nav="settings">Einstellungen</a>
  </nav>
  <div class="topright">
    <div class="statusbar" id="statusbar" title="Sensoren nach Status"></div>
    <input type="search" id="globalSearch" placeholder="Suchen …" aria-label="Objekte suchen">
    <div class="usermenu">
      <button class="iconbtn" id="userBtn" title="Konto"><?= h(mb_strtoupper(mb_substr($user['username'], 0, 1))) ?></button>
      <div class="dropdown" id="userMenu" hidden>
        <div class="dd-head"><b><?= h($user['username']) ?></b><small><?= $user['role'] === 'admin' ? 'Administrator' : 'Nur lesen' ?></small></div>
        <a href="#/settings/account">Passwort ändern</a>
        <a href="logout.php">Abmelden</a>
      </div>
    </div>
  </div>
</header>
<div class="pollerwarn" id="pollerWarn" hidden></div>
<main id="view" class="view"></main>
<aside class="drawer" id="drawer" hidden aria-label="Details"><div class="drawer-inner" id="drawerInner"></div></aside>
<div class="modal-back" id="modalBack" hidden><div class="modal" id="modal" role="dialog" aria-modal="true"></div></div>
<div class="toasts" id="toasts"></div>
</body>
</html>
