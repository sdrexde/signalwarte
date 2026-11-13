<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/authpage.php';

session_boot();
if (needs_setup()) {
    header('Location: setup.php');
    exit;
}
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $err = 'Sitzung abgelaufen – bitte erneut versuchen.';
    } elseif (login_attempt(trim((string) ($_POST['user'] ?? '')), (string) ($_POST['pass'] ?? ''))) {
        header('Location: ./');
        exit;
    } else {
        $err = 'Benutzername oder Passwort falsch.';
    }
}
ob_start(); ?>
<form method="post" class="auth-form">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <?php if ($err): ?><div class="alert"><?= h($err) ?></div><?php endif; ?>
  <label>Benutzername<input name="user" autocomplete="username" required autofocus></label>
  <label>Passwort<input name="pass" type="password" autocomplete="current-password" required></label>
  <button class="btn primary">Anmelden</button>
</form>
<?php auth_page('Anmelden', ob_get_clean());
