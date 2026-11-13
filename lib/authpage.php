<?php
declare(strict_types=1);

function auth_page(string $title, string $inner): void
{
    ?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> · <?= h(APP_NAME) ?></title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=<?= APP_VERSION ?>">
</head><body class="auth-body">
<div class="auth-card">
  <div class="auth-brand"><img src="assets/favicon.svg" alt="" width="34" height="34"><div><b><?= h(APP_NAME) ?></b><small>Netzwerk-Monitoring</small></div></div>
  <h1><?= h($title) ?></h1>
  <?= $inner ?>
</div>
</body></html><?php
}
