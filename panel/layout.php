<?php
/* ═══ CMS - wspólny layout dla stron za logowaniem ═══════════════ */
require_once __DIR__ . '/auth.php';

/**
 * Moduły panelu do górnej nawigacji: etykieta, strona startowa modułu
 * i lista plików, przy których zakładka jest podświetlona jako aktywna.
 */
function panel_nav_modules(): array {
    return [
        'wydarzenia'   => ['Wydarzenia', 'index.php',
            ['index.php', 'edit.php', 'categories.php', 'translate.php', 'glossary.php', 'media.php']],
        'sprawozdania' => ['Sprawozdania', 'sprawozdania.php',
            ['sprawozdania.php']],
        'subskrypcje'  => ['Subskrypcje', 'subskrypcje.php',
            ['subskrypcje.php']],
        'adopcja'      => ['Adopcja Serca', 'adopcje.php',
            ['adopcje.php', 'darczyncy.php', 'darczynca.php', 'darczynca-edit.php', 'dzieci.php',
             'adopcja-edit.php', 'wplaty.php', 'zgloszenia.php', 'eksport.php']],
        // Import z wyciągu należy do Finansów: źródłem jest konto fundacji,
        // a operacje rozchodzą się stąd i do przepływów, i do wpłat Adopcji.
        'finanse'      => ['Finanse', 'finanse.php',
            ['finanse.php', 'import-bank.php']],
    ];
}

/**
 * Pod-menu modułu Adopcja Serca: TRWAŁE, identyczne na każdej stronie modułu
 * (feedback Tomasza 2026-08-03: linki w paskach stron zmieniały się i znikały -
 * nawigacja ma być stała, w treści zostają tylko przyciski akcji).
 * Etykieta => [strona docelowa, pliki przy których pozycja jest aktywna].
 */
function panel_subnav_adopcja(): array {
    return [
        'Przegląd'    => ['adopcje.php',    ['adopcje.php']],
        'Darczyńcy'   => ['darczyncy.php',  ['darczyncy.php', 'darczynca.php', 'darczynca-edit.php', 'adopcja-edit.php']],
        'Podopieczni' => ['dzieci.php',     ['dzieci.php']],
        'Wpłaty'      => ['wplaty.php',     ['wplaty.php']],
        'Zgłoszenia'  => ['zgloszenia.php', ['zgloszenia.php']],
        'Eksport'     => ['eksport.php',    ['eksport.php']],
    ];
}

/**
 * Pod-menu modułu Finanse - te same zasady co przy Adopcji: nawigacja jest
 * TRWAŁA i identyczna na każdej stronie modułu.
 */
function panel_subnav_finanse(): array {
    return [
        'Przepływy'     => ['finanse.php',     ['finanse.php']],
        'Import z banku'=> ['import-bank.php', ['import-bank.php']],
    ];
}

function panel_header($title) {
    $user = mada_current_user();
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $inAdopcja = in_array($script, panel_nav_modules()['adopcja'][2], true);
    $inFinanse = in_array($script, panel_nav_modules()['finanse'][2], true);
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
    <h1><a href="index.php" style="color:inherit;text-decoration:none;">Fundacja Misja MADA
      <span class="panel-sub">Panel administracyjny</span></a></h1>
    <span class="who">Zalogowano: <strong><?= mada_esc($user) ?></strong> · <a href="logout.php">Wyloguj</a></span>
  </header>
  <nav class="panel-nav" aria-label="Moduły panelu">
    <?php foreach (panel_nav_modules() as $m): [$label, $href, $files] = $m; ?>
      <a href="<?= mada_esc($href) ?>"<?= in_array($script, $files, true) ? ' class="active" aria-current="page"' : '' ?>><?= mada_esc($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($inAdopcja || $inFinanse): ?>
  <nav class="panel-subnav" aria-label="<?= $inAdopcja ? 'Adopcja Serca - sekcje' : 'Finanse - sekcje' ?>">
    <?php foreach ($inAdopcja ? panel_subnav_adopcja() : panel_subnav_finanse() as $label => $s): [$href, $files] = $s; ?>
      <a href="<?= mada_esc($href) ?>"<?= in_array($script, $files, true) ? ' class="active" aria-current="page"' : '' ?>><?= mada_esc($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <main class="panel-wrap">
<?php
}

function panel_footer() {
    ?>
  </main>
  <script>
  /* Klikalne wiersze tabel: <tr class="row-link" data-href="..."> otwiera
     wskazany adres po kliknięciu w dowolne miejsce wiersza. Linki, przyciski
     i pola formularzy w wierszu działają normalnie. Bez JS zostaje zwykły
     link w treści wiersza - dlatego każdy taki wiersz MUSI go zawierać. */
  document.querySelectorAll('tr.row-link[data-href]').forEach(function (tr) {
    tr.addEventListener('click', function (e) {
      if (e.target.closest('a, button, form, input, select, textarea, label')) return;
      window.location.href = tr.dataset.href;
    });
  });
  </script>
</body>
</html>
<?php
}

/** Komunikat flash z parametru ?msg= (bezpieczna mapa kodów). */
function panel_flash() {
    $codes = [
        'added'   => ['ok',  'Wydarzenie zostało dodane.'],
        'created' => ['ok',  'Wydarzenie utworzone. Dodaj teraz zdjęcia i filmy w sekcji „Galeria" poniżej.'],
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
