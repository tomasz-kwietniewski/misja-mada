<?php
/* ═══ CMS - lista wydarzeń (stub ETAP 1; pełna wersja w ETAP 2) ═══ */
require_once __DIR__ . '/auth.php';
mada_require_login();
$user = mada_current_user();
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Panel wydarzeń | Fundacja Misja MADA</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="panel.css">
</head>
<body>
  <header class="panel-top">
    <h1>Panel wydarzeń</h1>
    <span class="who">Zalogowano: <strong><?= mada_esc($user) ?></strong> · <a href="logout.php">Wyloguj</a></span>
  </header>
  <main class="panel-wrap">
    <p>Lista wydarzeń pojawi się tutaj (ETAP 2).</p>
  </main>
</body>
</html>
