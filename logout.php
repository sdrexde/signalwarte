<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
session_boot();
$_SESSION = [];
session_destroy();
header('Location: login.php');
