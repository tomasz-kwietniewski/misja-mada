<?php
/* ═══════════════════════════════════════════════════════════════
   Testy CZYSTEJ LOGIKI modułu Adopcja Serca (adopcja/lib.php).
   BEZ zależności (bazy, sieci). Uruchom:  php tests/run-adopcja.php
   Kod wyjścia != 0, gdy którykolwiek test nie przejdzie (dla CI).
   Przypadki wzięte z REALNYCH danych z arkuszy fundacji.
  ═══════════════════════════════════════════════════════════════ */

require __DIR__ . '/../adopcja/lib.php';

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

// ── miesiące ───────────────────────────────────────────────────
ok(adopt_month_valid('2026-01'),  'month_valid: 2026-01');
ok(!adopt_month_valid('2026-13'), 'month_valid: odrzuca miesiąc 13');
ok(!adopt_month_valid('2026-1'),  'month_valid: odrzuca bez zera wiodącego');
ok(!adopt_month_valid(null),      'month_valid: null');
eq(adopt_month_add('2026-01', 2),   '2026-03', 'month_add: +2');
eq(adopt_month_add('2026-11', 3),   '2027-02', 'month_add: przez granicę roku');
eq(adopt_month_add('2026-01', -1),  '2025-12', 'month_add: -1 przez granicę roku');
eq(adopt_month_add('2026-07', 11),  '2027-06', 'month_add: roczna wpłata +11');
eq(adopt_month_range('2026-01', '2026-03'), ['2026-01', '2026-02', '2026-03'], 'month_range: 3 miesiące');
eq(adopt_month_range('2026-03', '2026-01'), [], 'month_range: do < od -> pusty');
eq(adopt_month_count('2026-01', '2026-12'), 12, 'month_count: pełny rok');
eq(adopt_month_from_date('2026-08-02'), '2026-08', 'month_from_date');

// ── pokrycie i zaległości ──────────────────────────────────────
$pays = [
    ['period_from' => '2024-07', 'period_to' => '2024-12'],
    ['period_from' => '2025-02', 'period_to' => '2025-03'],
];
eq(adopt_coverage($pays), ['2024-07','2024-08','2024-09','2024-10','2024-11','2024-12','2025-02','2025-03'],
   'coverage: dwa pasma z dziurą w styczniu');
eq(adopt_paid_until($pays), '2025-03', 'paid_until: max period_to');
eq(adopt_paid_until([]), null, 'paid_until: brak wpłat -> null');
// zaległości: adopcja od 07.2024 bez końca, dziś kwiecień 2025 -> brakuje TYLKO 2025-01
// (bieżący miesiąc 2025-04 nie liczy się jako zaległość)
eq(adopt_arrears('2024-07', null, $pays, '2025-04-15'), ['2025-01'],
   'arrears: dziura w środku, bieżący miesiąc NIE liczony');
eq(adopt_arrears('2024-07', null, $pays, '2025-04-15', true), ['2025-01', '2025-04'],
   'arrears: includeCurrent dolicza bieżący');
// adopcja OKREŚLONA do 2024-12 - po końcu nic nie jest należne
eq(adopt_arrears('2024-07', '2024-12', $pays, '2025-04-15'), [],
   'arrears: fixed zakończona i opłacona -> brak zaległości');
// wszystko opłacone w przód (roczna) -> brak zaległości
$roczna = [['period_from' => '2026-01', 'period_to' => '2026-12']];
eq(adopt_arrears('2026-01', null, $roczna, '2026-08-02'), [], 'arrears: opłacone w przód');
// nic nie zapłacono (czerwiec i lipiec zaległe; sierpień = bieżący, nie liczony)
eq(adopt_arrears('2026-06', null, [], '2026-08'), ['2026-06', '2026-07'],
   'arrears: 2 zaległe miesiące bez bieżącego');
// adopcja wystartowała w bieżącym miesiącu -> jeszcze nic nie zalega
eq(adopt_arrears('2026-08', null, [], '2026-08'), [], 'arrears: start w bieżącym miesiącu -> pusto');

// ── parser okresów (realne wpisy z arkusza) ────────────────────
$p = adopt_parse_period('NIEOKREŚLONY ');
eq($p['duration'], 'indefinite', 'period: NIEOKREŚLONY');
$p = adopt_parse_period('OKREŚLONY (do 10.2026)');
eq([$p['duration'], $p['start_month'], $p['end_month']], ['fixed', null, '2026-10'], 'period: do MM.YYYY');
$p = adopt_parse_period('OKREŚLONY (05.2026-04.2027)');
eq([$p['duration'], $p['start_month'], $p['end_month']], ['fixed', '2026-05', '2027-04'], 'period: zakres');
$p = adopt_parse_period('OKREŚLONNY (11.2025-11.2026)');   // literówka z arkusza
eq([$p['duration'], $p['start_month'], $p['end_month']], ['fixed', '2025-11', '2026-11'], 'period: literówka OKREŚLONNY');
$p = adopt_parse_period('05.2026-04.2027');                // goły zakres (wiersz Parafia Kłodzko)
eq([$p['duration'], $p['start_month'], $p['end_month']], ['fixed', '2026-05', '2027-04'], 'period: goły zakres');
$p = adopt_parse_period('OKREŚLONY (do 30.06.2025) ');     // pełna data dzienna
eq([$p['duration'], $p['end_month']], ['fixed', '2025-06'], 'period: do DD.MM.YYYY');
$p = adopt_parse_period("OKREŚLONY (07.2026 \u{2013} 07.2027)");  // en dash z arkusza
eq([$p['duration'], $p['start_month'], $p['end_month']], ['fixed', '2026-07', '2027-07'], 'period: en dash w zakresie');
$p = adopt_parse_period('do 08.2026');
eq([$p['duration'], $p['end_month']], ['fixed', '2026-08'], 'period: samo "do"');
$p = adopt_parse_period('');
ok($p['duration'] === null && $p['warning'] !== null, 'period: pusty -> warning, bez zgadywania');
$p = adopt_parse_period('OKREŚLONY (jakoś tak)');
ok($p['duration'] === null && $p['warning'] !== null, 'period: nierozpoznany -> warning');

// ── częstotliwość ──────────────────────────────────────────────
eq(adopt_parse_frequency('MIESIĘCZNIE'), 'monthly',   'freq: miesięcznie');
eq(adopt_parse_frequency('KWARTALNIE'),  'quarterly', 'freq: kwartalnie');
eq(adopt_parse_frequency('ROCZNIE'),     'yearly',    'freq: rocznie');
eq(adopt_parse_frequency('co łaska'),    null,        'freq: nieznana -> null');

// ── komórki macierzy ───────────────────────────────────────────
eq(adopt_parse_amount_cell('70'),    7000,  'cell: 70 -> 7000 gr');
eq(adopt_parse_amount_cell('140'),   14000, 'cell: 140');
eq(adopt_parse_amount_cell('62,50'), 6250,  'cell: przecinek dziesiętny');
eq(adopt_parse_amount_cell(''),      null,  'cell: pusta -> null');
eq(adopt_parse_amount_cell('do sierpnia 2026'), null, 'cell: tekst -> null (notatka)');

// ── zwijanie wiersza macierzy do zdarzeń ───────────────────────
$row = [
    '2025-06' => '70', '2025-07' => '70', '2025-08' => '70',
    '2025-09' => '',                       // dziura
    '2025-10' => '70', '2025-11' => '70',
    '2025-12' => 'do grudnia opłacone',    // notatka
    '2026-01' => '140',
];
$c = adopt_collapse_matrix_row($row);
eq(count($c['events']), 3, 'collapse: 3 pasma');
eq($c['events'][0], ['period_from' => '2025-06', 'period_to' => '2025-08', 'amount_grosze' => 21000],
   'collapse: pasmo 1 zsumowane');
eq($c['events'][1], ['period_from' => '2025-10', 'period_to' => '2025-11', 'amount_grosze' => 14000],
   'collapse: pasmo 2 po dziurze');
eq($c['events'][2], ['period_from' => '2026-01', 'period_to' => '2026-01', 'amount_grosze' => 14000],
   'collapse: pojedynczy miesiąc 140');
eq($c['notes'], ['2025-12: do grudnia opłacone'], 'collapse: notatka z komórki tekstowej');
eq(adopt_collapse_matrix_row([]), ['events' => [], 'notes' => []], 'collapse: pusty wiersz');

// ── dopasowywanie nazwisk (realne pary z plików fundacji) ──────
eq(adopt_name_match('Kasia Szafran', 'Kasia Szafran'), 'exact', 'name: identyczne');
eq(adopt_name_match('Renata Staszewska ', 'Renata Staszewska (Owca)'), 'exact', 'name: nawias ignorowany');
eq(adopt_name_match('Marta i Tomek Świercz', 'Marta Świercz'), 'exact', 'name: podzbiór tokenów (pary)');
eq(adopt_name_match('Ola Marchlewska', 'Aleksandra Marchlewska'), 'exact', 'name: zdrobnienie Ola=Aleksandra -> exact (mapa zdrobnień)');
eq(adopt_name_match('Kamila Magdziak', 'Kamila Przybysz'), 'fuzzy', 'name: zmiana nazwiska -> fuzzy');
eq(adopt_name_match('Agnieszka Krzeminska', 'Agnieszka Topolska'), 'fuzzy', 'name: wspólne tylko imię -> fuzzy');
eq(adopt_name_match('Jan Bożewicz', 'Beata Kudła'), 'none', 'name: różne osoby');
eq(adopt_name_match('Ks. Artur Aleksiejuk', 'Artur Aleksiejuk'), 'exact', 'name: tytuł Ks. ignorowany');
eq(adopt_name_match('Piotrek Dądela', 'Piotr Dądela'), 'exact', 'name: zdrobnienie Piotrek=Piotr -> exact');
eq(adopt_name_match('Ania Zielińska', 'Anna Zielińska'), 'exact', 'name: zdrobnienie Ania=Anna -> exact');
eq(adopt_name_match('Zuzia Wiewiorowska', 'Zuzanna Wiewiorowska'), 'exact', 'name: zdrobnienie Zuzia=Zuzanna -> exact');
eq(adopt_name_match('Hanna Miszkurka', 'Anna Miszkurka'), 'fuzzy', 'name: Hanna vs Anna to RÓŻNE imiona -> fuzzy');

// ── sortowanie po nazwisku ─────────────────────────────────────
eq(adopt_surname_key('Ola i Tomasz Kwietniewscy'), 'kwietniewscy aleksandra tomasz',
   'surname_key: para -> nazwisko na początku (Ola znormalizowana do Aleksandra)');
eq(adopt_surname_key('Ks. Artur Aleksiejuk'), 'aleksiejuk artur', 'surname_key: tytuł Ks. pominięty');
eq(adopt_surname_key('Parafia Kłodzko'), 'klodzko parafia', 'surname_key: instytucja - ostatni człon');
$posort = adopt_sort_by_surname([
    ['full_name' => 'Renata Ginak'],
    ['full_name' => 'Agata Bal'],
    ['full_name' => 'Marta i Tomek Świercz'],
    ['full_name' => 'Adam Paprocki'],
]);
eq(array_column($posort, 'full_name'),
   ['Agata Bal', 'Renata Ginak', 'Adam Paprocki', 'Marta i Tomek Świercz'],
   'sort_by_surname: Bal < Ginak < Paprocki < Świercz (nie po imionach)');

// ── e-maile z pola arkusza ─────────────────────────────────────
eq(adopt_parse_emails('a@b.pl'), ['a@b.pl', null], 'emails: pojedynczy');
eq(adopt_parse_emails('krzysiekmiszkurka@gmail.com; katarzyna.zak00@gmail.com'),
   ['krzysiekmiszkurka@gmail.com', 'katarzyna.zak00@gmail.com'], 'emails: dwa po średniku');
eq(adopt_parse_emails('przemkulik@wp.pl, mkocik@wp.pl'),
   ['przemkulik@wp.pl', 'mkocik@wp.pl'], 'emails: dwa po przecinku');
eq(adopt_parse_emails('brak'), [null, null], 'emails: "brak" -> null');
eq(adopt_parse_emails(' gagucha@op.pl'), ['gagucha@op.pl', null], 'emails: spacja wiodąca');

// ── etykieta miesiąca ──────────────────────────────────────────
eq(adopt_month_label('2026-08'), '08.2026', 'label: MM.YYYY');
eq(adopt_month_label(null), '-', 'label: null');

// ── writer XLSX (tylko gdy jest rozszerzenie zip) ──────────────
require __DIR__ . '/../adopcja/xlsx.php';
eq(adopt_xlsx_col_letter(0), 'A',  'xlsx: kolumna 0 -> A');
eq(adopt_xlsx_col_letter(25), 'Z', 'xlsx: kolumna 25 -> Z');
eq(adopt_xlsx_col_letter(26), 'AA', 'xlsx: kolumna 26 -> AA');
eq(adopt_xlsx_sheet_name('Wpłaty [macierz]: a/b'), 'Wpłaty -macierz-- a-b', 'xlsx: nazwa arkusza bez znaków zakazanych');
ok(mb_strlen(adopt_xlsx_sheet_name(str_repeat('x', 50))) === 31, 'xlsx: nazwa arkusza przycięta do 31');
if (class_exists('ZipArchive')) {
    $bin = adopt_xlsx_build([
        ['name' => 'Test', 'rows' => [['Nagłówek', 'Kwota'], ['Ala & <b>', 70.5]]],
        ['name' => 'Drugi', 'rows' => [['x']]],
    ]);
    $tmpx = sys_get_temp_dir() . '/mada_xlsx_test_' . getmypid() . '.xlsx';
    file_put_contents($tmpx, $bin);
    $z = new ZipArchive();
    ok($z->open($tmpx) === true, 'xlsx: plik otwiera się jako zip');
    $wbx = (string)$z->getFromName('xl/workbook.xml');
    ok(str_contains($wbx, 'name="Test"') && str_contains($wbx, 'name="Drugi"'), 'xlsx: workbook z dwoma arkuszami');
    $s1 = (string)$z->getFromName('xl/worksheets/sheet1.xml');
    ok(str_contains($s1, 'Ala &amp; &lt;b&gt;'), 'xlsx: escapowanie XML w komórce');
    ok(str_contains($s1, '<v>70.5</v>'), 'xlsx: liczba jako liczba');
    ok((string)$z->getFromName('[Content_Types].xml') !== '', 'xlsx: Content_Types obecny');
    $z->close();
    @unlink($tmpx);
} else {
    fwrite(STDERR, "  (i) brak rozszerzenia zip - testy budowy XLSX pominięte\n");
}

/* ── Adres korespondencyjny (pola rozbite, wszystkie dobrowolne) ──
   Fundacja drukuje z tego koperty, więc puste człony mają znikać, a nie
   zostawiać osierocone przecinki i podwójne spacje. */
eq(adopt_postcode_normalize('00001'),   '00-001', 'postcode: 5 cyfr -> 00-001');
eq(adopt_postcode_normalize('87 100'),  '87-100', 'postcode: spacja -> dywiz');
eq(adopt_postcode_normalize('87-100'),  '87-100', 'postcode: już poprawny');
eq(adopt_postcode_normalize('SW1A 1AA'), 'SW1A 1AA', 'postcode: obcy format zostaje bez zmian');
ok(adopt_postcode_valid(''),        'postcode_valid: pusty jest OK (pole dobrowolne)');
ok(adopt_postcode_valid('87-100'),  'postcode_valid: 87-100');
ok(!adopt_postcode_valid('8710'),   'postcode_valid: odrzuca 4 cyfry');

$adr = ['street' => 'Szosa Chełmińska', 'house_no' => '271A', 'postcode' => '87-100', 'city' => 'Toruń'];
eq(adopt_address_lines($adr), ['Szosa Chełmińska 271A', '87-100 Toruń'], 'address_lines: dwie linie koperty');
eq(adopt_address_compose($adr), 'Szosa Chełmińska 271A, 87-100 Toruń', 'address_compose: jedna linia');
eq(adopt_address_compose(['city' => 'Toruń']), 'Toruń', 'address_compose: sama miejscowość');
eq(adopt_address_compose(['street' => 'Kwiatowa']), 'Kwiatowa', 'address_compose: sama ulica bez numeru');
eq(adopt_address_compose([]), '', 'address_compose: brak danych -> pusty łańcuch');
eq(adopt_address_compose(['street' => null, 'house_no' => null, 'postcode' => null, 'city' => null]), '',
   'address_compose: same NULL-e -> pusty łańcuch');

/* ── Kwota wg częstotliwości ──────────────────────────────────────
   Realny błąd (2026-08-11): darczyńca zaznaczył wpłatę roczną 840 zł,
   a mail powitalny podał szablon na 70 zł miesięcznie. */
eq(adopt_amount_for_frequency(1, 'monthly'),   70,   'kwota: 1 dziecko miesięcznie');
eq(adopt_amount_for_frequency(1, 'quarterly'), 210,  'kwota: 1 dziecko kwartalnie');
eq(adopt_amount_for_frequency(1, 'yearly'),    840,  'kwota: 1 dziecko rocznie (błąd z 2026-08-11)');
eq(adopt_amount_for_frequency(2, 'yearly'),    1680, 'kwota: 2 dzieci rocznie');
eq(adopt_amount_for_frequency(3, 'quarterly'), 630,  'kwota: 3 dzieci kwartalnie');
eq(adopt_amount_for_frequency(0, 'monthly'),   70,   'kwota: zero dzieci liczone jak jedno');
eq(adopt_amount_for_frequency(1, 'bzdura'),    70,   'kwota: nieznana częstotliwość -> miesięcznie');
eq(adopt_frequency_label('yearly'), 'rocznie', 'etykieta okresu: rocznie');

/* ── Tytuł przelewu (jeden wzór w całej komunikacji) ───────────── */
eq(adopt_transfer_title('Elżbieta Odachowska'),
   'Adopcja Serca - darowizna - Elżbieta Odachowska',
   'tytuł: bez przypisanego dziecka - sam darczyńca');
eq(adopt_transfer_title('Elżbieta Odachowska', [['name' => 'Kiady', 'number' => 23]]),
   'Adopcja Serca - darowizna - Elżbieta Odachowska - Kiady 23',
   'tytuł: darczyńca + dziecko');
eq(adopt_transfer_title('Parafia Kłodzko', [['name' => 'Kiady', 'number' => 23], ['name' => 'Soa', 'number' => 41]]),
   'Adopcja Serca - darowizna - Parafia Kłodzko - Kiady 23, Soa 41',
   'tytuł: kilkoro dzieci po przecinku');
eq(adopt_transfer_title('Jan Kowalski', [['name' => '', 'number' => 5]]),
   'Adopcja Serca - darowizna - Jan Kowalski',
   'tytuł: dziecko bez imienia pomijane');

/* ── Ten sam e-mail, inny darczyńca (przypadek Parafii Kłodzko) ─── */
ok(!adopt_same_donor('Parafia Kłodzko', 'Elżbieta Odachowska'),
   'same_donor: proboszcz zgłasza mamę -> OSOBNY darczyńca');
ok(adopt_same_donor('Parafia Kłodzko', 'Parafia Kłodzko'),
   'same_donor: kolejne zgłoszenie tej samej parafii -> ten sam rekord');
ok(adopt_same_donor('Anna Topolska', 'Anna Krzemińska'),
   'same_donor: zmiana nazwiska po ślubie -> ten sam rekord');
ok(adopt_same_donor('Jan Kowalski', 'J. Kowalski'),
   'same_donor: skrócone imię -> ten sam rekord');
ok(adopt_same_donor('Jan Kowalski', ''),
   'same_donor: brak nazwy w zgłoszeniu -> zachowanie jak dotąd (dopina)');
ok(!adopt_same_donor('Ewa i Michał Tobiasz', 'Zbigniew Leksiński'),
   'same_donor: zupełnie inne osoby -> osobne rekordy');

// ── Wynik ──────────────────────────────────────────────────────
echo "\nTesty modułu Adopcja Serca: {$T['pass']} OK";
if ($T['fail'] > 0) { echo ", {$T['fail']} BŁĄD\n"; exit(1); }
echo ", 0 błędów\n";
exit(0);
