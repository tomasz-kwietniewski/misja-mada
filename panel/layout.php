<?php
/* ═══ CMS - wspólny layout dla stron za logowaniem ═══════════════ */
require_once __DIR__ . '/auth.php';

function panel_header($title) {
    $user = mada_current_user();
    ?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= mada_esc($title) ?> | Fundacja Misja MADA</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="panel.css">
</head>
<body>
  <header class="panel-top">
    <h1><a href="index.php" style="color:inherit;text-decoration:none;">Panel wydarzeń</a></h1>
    <span class="who">Zalogowano: <strong><?= mada_esc($user) ?></strong> · <a href="logout.php">Wyloguj</a></span>
  </header>
  <main class="panel-wrap">
<?php
}

function panel_footer() {
    ?>
  </main>
</body>
</html>
<?php
}

/** Komunikat flash z parametru ?msg= (bezpieczna mapa kodów). */
function panel_flash() {
    $codes = [
        'added'   => ['ok',  'Wydarzenie zostało dodane.'],
        'saved'   => ['ok',  'Zmiany zostały zapisane.'],
        'deleted' => ['ok',  'Wydarzenie zostało usunięte.'],
        'notrans' => ['ok',  'Zapisano. Uwaga: automatyczne tłumaczenie się nie powiodło - treść pozostaje po polsku.'],
        'nofound' => ['error', 'Nie znaleziono wydarzenia.'],
        'invalid' => ['error', 'Uzupełnij wymagane pola: tytuł, poprawną datę i datę opisową.'],
    ];
    $m = $_GET['msg'] ?? '';
    if (!isset($codes[$m])) return '';
    [$type, $text] = $codes[$m];
    return '<div class="alert alert-' . ($type === 'ok' ? 'ok' : 'error') . '">' . mada_esc($text) . '</div>';
}

/** Komunikat flash galerii z parametru ?gmsg=. */
function panel_gmsg() {
    $codes = [
        'added'      => ['ok',    'Zdjęcie zostało dodane.'],
        'embedok'    => ['ok',    'Film został dodany.'],
        'saved'      => ['ok',    'Opisy galerii zapisane.'],
        'reordered'  => ['ok',    'Kolejność zaktualizowana.'],
        'limit'      => ['error', 'Osiągnięto limit 20 zdjęć na wydarzenie.'],
        'uperr'      => ['error', 'Nie udało się wgrać pliku. Spróbuj ponownie.'],
        'big'        => ['error', 'Plik jest za duży (maks. 12 MB).'],
        'type'       => ['error', 'Niedozwolony typ pliku. Dozwolone: JPG, PNG, WEBP.'],
        'save'       => ['error', 'Nie udało się zapisać pliku na serwerze.'],
        'embedempty' => ['error', 'Wklej link do filmu.'],
        'embedbad'   => ['error', 'Nieobsługiwany link. Wklej adres filmu z YouTube lub Facebooka.'],
    ];
    $m = $_GET['gmsg'] ?? '';
    if (!isset($codes[$m])) return '';
    [$type, $text] = $codes[$m];
    return '<div class="alert alert-' . ($type === 'ok' ? 'ok' : 'error') . '">' . mada_esc($text) . '</div>';
}
