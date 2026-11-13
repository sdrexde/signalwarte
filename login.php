<?php
// Anmeldung in zwei Schritten: Passwort → (falls aktiv) 2FA-Code / Wiederherstellungscode.
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/authpage.php';

session_boot();
if (needs_setup()) {
    header('Location: setup.php');
    exit;
}
if (current_user()) {
    header('Location: ./');
    exit;
}

$err = '';
$step = 'password';
$pending = null;
if (!empty($_SESSION['pending_uid']) && time() - (int) ($_SESSION['pending_at'] ?? 0) < 300) {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$_SESSION['pending_uid']]);
    $pending = $st->fetch() ?: null;
    $step = $pending ? 'code' : 'password';
}
if (isset($_GET['abbrechen'])) {
    unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $pending ? $pending['username'] : trim((string) ($_POST['user'] ?? ''));
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $err = 'Sitzung abgelaufen – bitte erneut versuchen.';
    } elseif (($wait = login_blocked_for($user)) > 0) {
        $err = 'Zu viele Fehlversuche. Bitte in ' . ceil($wait / 60) . ' Minute(n) erneut versuchen.';
    } elseif ($step === 'code') {
        if (verify_second_factor($pending, (string) ($_POST['code'] ?? ''))) {
            complete_login((int) $pending['id'], $pending['username']);
            header('Location: ./');
            exit;
        }
        login_log($user, false);
        $err = 'Code ungültig. Bitte den aktuellen Code aus der Authenticator-App eingeben.';
    } else {
        $u = check_password($user, (string) ($_POST['pass'] ?? ''));
        if (!$u) {
            login_log($user, false);
            $err = 'Benutzername oder Passwort falsch.';
        } elseif ((int) $u['totp_enabled']) {
            session_regenerate_id(true);
            $_SESSION['pending_uid'] = (int) $u['id'];
            $_SESSION['pending_at'] = time();
            header('Location: login.php');
            exit;
        } else {
            complete_login((int) $u['id'], $u['username']);
            header('Location: ./');
            exit;
        }
    }
}

ob_start();
if ($step === 'code'): ?>
<form method="post" class="auth-form" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <?php if ($err): ?><div class="alert"><?= h($err) ?></div><?php endif; ?>
  <p class="muted">Angemeldet als <b><?= h($pending['username']) ?></b>. Gib den 6-stelligen Code aus deiner Authenticator-App ein.</p>
  <label>Code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}|[a-zA-Z0-9]{5}-[a-zA-Z0-9]{5}" maxlength="11" required autofocus placeholder="123456"></label>
  <button class="btn primary">Bestätigen</button>
  <p class="muted small">Kein Zugriff auf die App? Einen der Wiederherstellungscodes (Format <code>abcde-fghij</code>) eingeben.<br><a href="login.php?abbrechen=1">Abbrechen</a></p>
</form>
<?php else: ?>
<form method="post" class="auth-form">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <?php if ($err): ?><div class="alert"><?= h($err) ?></div><?php endif; ?>
  <label>Benutzername<input name="user" autocomplete="username" required autofocus value="<?= h($_POST['user'] ?? '') ?>"></label>
  <label>Passwort<input name="pass" type="password" autocomplete="current-password" required></label>
  <button class="btn primary">Anmelden</button>
</form>
<?php endif;
auth_page($step === 'code' ? 'Zwei-Faktor-Anmeldung' : 'Anmelden', ob_get_clean());
