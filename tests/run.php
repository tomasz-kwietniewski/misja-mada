<?php
/* ═══════════════════════════════════════════════════════════════
   Minimalny runner testów logiki CMS - BEZ zależności zewnętrznych.
   ───────────────────────────────────────────────────────────────
   Uruchom:  php tests/run.php
   Kod wyjścia != 0, gdy którykolwiek test nie przejdzie (dla CI).

   Testuje czyste funkcje z panel/lib.php. Aby NIE dotykać realnych
   danych, podstawiamy stałe ścieżek na katalog tymczasowy PRZED
   require - lib.php użyje define() które dla już zdefiniowanej
   stałej tylko ostrzega (oryginał/sandbox zostaje).
  ═══════════════════════════════════════════════════════════════ */

// ── Izolacja: stałe na katalog tymczasowy ──────────────────────
$SANDBOX = sys_get_temp_dir() . '/mada_test_' . getmypid();
@mkdir($SANDBOX . '/data', 0777, true);
define('MADA_BASE',              $SANDBOX);
define('MADA_DATA',             $SANDBOX . '/data');
define('MADA_EVENTS_DIR',       $SANDBOX . '/data/wydarzenia');
define('MADA_UPLOADS',          $SANDBOX . '/uploads/wydarzenia');
define('MADA_UPLOADS_URL',      'uploads/wydarzenia');
define('MADA_SPRAW_FILE',       $SANDBOX . '/data/sprawozdania.json');
define('MADA_SPRAW_UPLOADS',    $SANDBOX . '/uploads/sprawozdania');
define('MADA_SPRAW_UPLOADS_URL','uploads/sprawozdania');

// Stałe już zdefiniowane -> define() w lib.php tylko OSTRZEGA; tłumimy to.
$er = error_reporting(E_ALL & ~E_WARNING);
require __DIR__ . '/../panel/lib.php';
error_reporting($er);

// ── Mini-framework asercji ─────────────────────────────────────
$T = ['pass' => 0, 'fail' => 0];
function ok($cond, $msg) {
    global $T;
    if ($cond) { $T['pass']++; }
    else { $T['fail']++; fwrite(STDERR, "  ✗ $msg\n"); }
}
function eq($actual, $expected, $msg) {
    ok($actual === $expected,
       $msg . "  (oczekiwano: " . var_export($expected, true) . ", było: " . var_export($actual, true) . ")");
}

// ── slug (polskie znaki -> ASCII) ──────────────────────────────
eq(mada_slugify('Kiermasz Wielkanocny'), 'kiermasz-wielkanocny', 'slugify: spacje -> myślniki');
eq(mada_slugify('Zażółć gęślą jaźń'),    'zazolc-gesla-jazn',    'slugify: polskie znaki -> ASCII');
eq(mada_slugify('   '),                  'wydarzenie',           'slugify: puste -> fallback');
eq(mada_slugify('A...B/C'),              'a-b-c',                'slugify: znaki specjalne -> pojedynczy myślnik');

// ── valid_id (anty-traversal) ──────────────────────────────────
ok(mada_valid_id('kiermasz-2024'),  'valid_id: poprawny slug');
ok(!mada_valid_id('../etc'),        'valid_id: odrzuca traversal');
ok(!mada_valid_id('Wielka'),        'valid_id: odrzuca wielkie litery');
ok(!mada_valid_id('-zaczyna'),      'valid_id: odrzuca myślnik na początku');

// ── status wyliczany z daty ────────────────────────────────────
eq(mada_event_status(['dateISO' => '2000-01-01']), 'archiwum',     'status: przeszłość -> archiwum');
eq(mada_event_status(['dateISO' => '2999-12-31']), 'nadchodzace',  'status: przyszłość -> nadchodzące');
eq(mada_event_status(['dateISO' => '']),           'archiwum',     'status: brak daty -> archiwum');
eq(mada_event_status([]),                          'archiwum',     'status: brak pola -> archiwum');

// ── rok z daty ─────────────────────────────────────────────────
eq(mada_event_year(['dateISO' => '2024-05-10']), '2024', 'year: wyciąga rok');
eq(mada_event_year(['dateISO' => '']),           '',     'year: brak daty -> pusty');

// ── polska liczba mnoga ────────────────────────────────────────
eq(mada_plural_events(1),  'wydarzenie',  'plural: 1');
eq(mada_plural_events(3),  'wydarzenia',  'plural: 3');
eq(mada_plural_events(5),  'wydarzeń',    'plural: 5');
eq(mada_plural_events(22), 'wydarzenia',  'plural: 22');

// ── sprawozdania: walidacja typu i roku ────────────────────────
ok(mada_valid_spraw_type('finansowe'),    'spraw type: finansowe ok');
ok(mada_valid_spraw_type('merytoryczne'), 'spraw type: merytoryczne ok');
ok(!mada_valid_spraw_type('inne'),        'spraw type: odrzuca obce');
ok(mada_valid_spraw_year('2024'),         'spraw year: 2024 ok');
ok(!mada_valid_spraw_year('1999'),        'spraw year: 1999 poza zakresem');
ok(!mada_valid_spraw_year('abc'),         'spraw year: nie-liczba odrzucona');
ok(!mada_valid_spraw_year('20245'),       'spraw year: 20245 poza zakresem');

// ── sprawozdania: domyślny seed (struktura) ────────────────────
$def = mada_sprawozdania_defaults();
eq($def['finansowe'][0]['year'], 2024, 'seed: finansowe 2024');
eq($def['merytoryczne'][0]['file'], 'media/Sprawozdanie_z_dzialalnosci_2024.pdf', 'seed: merytoryczne wskazuje media/');

// ── sprawozdania: round-trip + sort (w sandboxie) ──────────────
$data = mada_sprawozdania();   // plik nie istnieje -> seed
eq($data['finansowe'][0]['year'], 2024, 'manifest: seed przy pierwszym odczycie');
$data['finansowe'][] = ['year' => 2026, 'file' => 'uploads/sprawozdania/x.pdf', 'title' => 'Sprawozdanie finansowe'];
$data['finansowe'][] = ['year' => 2025, 'file' => 'uploads/sprawozdania/y.pdf', 'title' => 'Sprawozdanie finansowe'];
ok(mada_save_sprawozdania($data), 'manifest: zapis OK');
$sorted = mada_sprawozdania_sorted();
eq(array_map(fn($i) => $i['year'], $sorted['finansowe']), [2026, 2025, 2024], 'manifest: sort malejąco po roku');

// ── sprawozdania: ochrona usuwania plików ──────────────────────
ok(mada_spraw_delete_file('media/Sprawozdanie_finansowe_2024.pdf') === false, 'delete_file: NIE rusza plików z media/');
ok(mada_spraw_delete_file('') === false,                                       'delete_file: pusta ścieżka -> false');
ok(mada_spraw_delete_file('uploads/sprawozdania/realny.pdf') === true,         'delete_file: akceptuje ścieżkę z uploads/');

// ── PayU: payload anulowania adopcji (czysta funkcja) ──────────
require __DIR__ . '/../payu/sheet.php';
$subAd = ['id' => 42, 'goal' => 'adopcja', 'goal_label' => 'Adopcja Serca',
          'first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => 'j@k.pl',
          'children' => 2, 'amount_grosze' => 14000, 'currency' => 'PLN'];
$pl = mada_adopcja_cancel_payload($subAd);
ok($pl !== null,                       'cancel-payload: adopcja -> payload (nie null)');
eq($pl['type'],  'adopcja-cancel',     'cancel-payload: type');
eq($pl['subId'], '42',                 'cancel-payload: subId jako string (klucz mapowania)');
eq($pl['amount'],'140.00',             'cancel-payload: grosze -> zlote');
eq($pl['goalLabel'], 'Adopcja Serca',  'cancel-payload: goalLabel przeniesione');
ok(mada_adopcja_cancel_payload(['goal' => 'statutowe', 'id' => 7]) === null,
   'cancel-payload: cel != adopcja -> null (brak wiersza w zakladce Adopcja)');
ok(mada_adopcja_cancel_payload(['id' => 7]) === null,
   'cancel-payload: brak celu -> null');

// ── PayU: relay poczty bez konfiguracji -> false (wymusza fallback na mail()) ──
require __DIR__ . '/../payu/mail.php';
ok(mada_mail_relay('a@b.pl', 'Temat', 'tresc') === false,
   'mail-relay: brak MADA_SHEET_URL/SECRET -> false (fallback na mail())');
ok(mada_mail_relay('', 'Temat', 'tresc') === false,
   'mail-relay: pusty adresat -> false');

// ── PayU: wspólny formatter kwot "ludzkich" (grosze -> PLN z przecinkiem) ──
eq(mada_amount_pln(7000),  '70',     'amount-pln: pelne zlote bez groszy');
eq(mada_amount_pln(12550), '125,50', 'amount-pln: grosze z przecinkiem');
eq(mada_amount_pln(100),   '1',      'amount-pln: 1 zl');
eq(mada_amount_pln(99),    '0,99',   'amount-pln: ponizej zlotowki');

// ── Sprzątanie sandboxa (best-effort) ──────────────────────────
foreach (glob($SANDBOX . '/data/*') as $f) { @unlink($f); }
@unlink($SANDBOX . '/data/.htaccess');
@rmdir($SANDBOX . '/data/wydarzenia');
@rmdir($SANDBOX . '/data');
@rmdir($SANDBOX . '/uploads/wydarzenia');
@rmdir($SANDBOX . '/uploads/sprawozdania');
@rmdir($SANDBOX . '/uploads');
@rmdir($SANDBOX);

// ── Wynik ──────────────────────────────────────────────────────
echo "\nTesty logiki CMS: {$T['pass']} OK";
if ($T['fail'] > 0) { echo ", {$T['fail']} BŁĄD\n"; exit(1); }
echo ", 0 błędów\n";
exit(0);
