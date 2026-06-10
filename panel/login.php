<?php
/* ═══ CMS - ekran logowania ═══════════════════════════════════════ */
require_once __DIR__ . '/auth.php';
mada_session_start();

if (mada_current_user() !== null) {
    mada_redirect('index.php');
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    mada_csrf_check();
    $lock = mada_login_locked_for();
    if ($lock > 0) {
        $error = 'Za dużo nieudanych prób. Odczekaj ' . $lock . ' s i spróbuj ponownie.';
    } elseif (mada_attempt_login($_POST['login'] ?? '', $_POST['haslo'] ?? '')) {
        mada_redirect('index.php');
    } else {
        $error = 'Nieprawidłowy login lub hasło.';
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Panel - logowanie | Fundacja Misja MADA</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="panel.css">
</head>
<body class="panel-login">
  <main class="login-card">
    <h1>Panel wydarzeń</h1>
    <p class="sub">Fundacja Misja MADA</p>

    <?php if ($error !== ''): ?>
      <div class="alert alert-error"><?= mada_esc($error) ?></div>
    <?php endif; ?>

    <form method="post" action="login.php" autocomplete="off">
      <?= mada_csrf_field() ?>
      <label>Login
        <input type="text" name="login" required autofocus autocomplete="username" value="<?= mada_esc($_POST['login'] ?? '') ?>">
      </label>
      <label>Hasło
        <input type="password" name="haslo" required autocomplete="current-password">
      </label>
      <button type="submit" class="btn-primary">Zaloguj się</button>
    </form>
  </main>
</body>
</html>
