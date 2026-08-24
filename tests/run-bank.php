<?php
/* ═══════════════════════════════════════════════════════════════
   Testy parsera wyciągu bankowego (adopcja/bank.php).
   BEZ zależności (bazy, sieci). Uruchom:  php tests/run-bank.php
   Kod wyjścia != 0, gdy którykolwiek test nie przejdzie (dla CI).

   Układ kolumn Erste Bank Polska nie jest publicznie udokumentowany,
   więc testy sprawdzają ODPORNOŚĆ na warianty (separator, kodowanie,
   kolejność i nazwy kolumn), a nie jeden zaklepany format.
  ═══════════════════════════════════════════════════════════════ */

require __DIR__ . '/../adopcja/bank.php';

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
function tmpfile_with(string $content, string $ext = 'csv'): string {
    $p = sys_get_temp_dir() . '/mada-bank-' . bin2hex(random_bytes(6)) . '.' . $ext;
    file_put_contents($p, $content);
    return $p;
}

// ── Kwoty ──────────────────────────────────────────────────────
eq(bank_parse_amount('360,00'),      36000,  'kwota: przecinek dziesiętny');
eq(bank_parse_amount('1 234,56'),    123456, 'kwota: spacja jako tysiące');
eq(bank_parse_amount("1\xC2\xA0234,56"), 123456, 'kwota: spacja nierozdzielająca');
eq(bank_parse_amount('-1.234,56'),  -123456, 'kwota: kropka tysiące, przecinek dziesiętny');
eq(bank_parse_amount('1,234.56'),    123456, 'kwota: format US');
eq(bank_parse_amount('-60.00'),       -6000, 'kwota: ujemna z kropką');
eq(bank_parse_amount('60,00 PLN'),     6000, 'kwota: z dopiskiem waluty');
eq(bank_parse_amount('1.234'),       123400, 'kwota: 1.234 to tysiące, nie 1,234 zł');
eq(bank_parse_amount('(120,00)'),    -12000, 'kwota: nawias = wydatek');
eq(bank_parse_amount(''),               null, 'kwota: pusta');
eq(bank_parse_amount('brak'),           null, 'kwota: tekst');

// ── Daty ───────────────────────────────────────────────────────
eq(bank_parse_date('2026-08-10'),  '2026-08-10', 'data: ISO');
eq(bank_parse_date('10.08.2026'),  '2026-08-10', 'data: polska z kropkami');
eq(bank_parse_date('10-08-2026'),  '2026-08-10', 'data: polska z myślnikami');
eq(bank_parse_date('2026-08-10 12:33'), '2026-08-10', 'data: z godziną');
eq(bank_parse_date('31.02.2026'),        null, 'data: odrzuca nieistniejącą');
eq(bank_parse_date(''),                  null, 'data: pusta');

// ── Numer rachunku ─────────────────────────────────────────────
eq(bank_account_key('70 1090 1056 0000 0001 5832 5871'), '70109010560000000158325871', 'rachunek: bez spacji');
eq(bank_account_key('PL49 1090 1056 0000 0001 6067 9663'), '49109010560000000160679663', 'rachunek: obcina PL');
eq(bank_account_key('brak danych'), '', 'rachunek: śmieci odrzucone');

// ── Mapowanie kolumn (różne nazwy i kolejność) ─────────────────
$map = bank_map_columns(['Data operacji', 'Data księgowania', 'Tytuł', 'Nadawca', 'Numer rachunku', 'Kwota', 'Waluta', 'Saldo']);
eq($map['date'], 0,   'kolumny: "Data operacji" wygrywa z "Data księgowania"');
eq($map['title'], 2,  'kolumny: tytuł');
eq($map['party'], 3,  'kolumny: nadawca');
eq($map['account'], 4,'kolumny: numer rachunku');
eq($map['amount'], 5, 'kolumny: kwota');

$map2 = bank_map_columns(['Kwota transakcji', 'Opis operacji', 'Data transakcji', 'Kontrahent']);
eq($map2['amount'], 0, 'kolumny: inna kolejność - kwota');
eq($map2['title'], 1,  'kolumny: inna kolejność - opis');
eq($map2['date'], 2,   'kolumny: inna kolejność - data');
eq($map2['party'], 3,  'kolumny: inna kolejność - kontrahent');

// ── Czytanie pliku: średnik + Windows-1250 + nagłówek raportu ──
$csv1250 = (string)iconv('UTF-8', 'CP1250',
    "Historia operacji;;;;\r\n" .
    "Rachunek: 70 1090 1056 0000 0001 5832 5871;;;;\r\n" .
    "Data operacji;Tytuł;Nadawca;Numer rachunku;Kwota\r\n" .
    "10.08.2026;Adopcja Serca - darowizna - Kiady 23;Łukasz Żółć;12 1090 1056 0000 0001 1111 2222;360,00\r\n" .
    "09.08.2026;Opłata za prowadzenie rachunku;Erste Bank Polska S.A.;;-15,00\r\n");
$p1 = tmpfile_with($csv1250);
[$h1, $r1] = bank_read_table($p1);
eq($h1[0] ?? '', 'Data operacji', 'plik: nagłówek raportu pominięty');
eq(count($r1), 2, 'plik: dwa wiersze operacji');
$ops1 = bank_rows_to_ops($h1, $r1);
eq(count($ops1), 2, 'plik: dwie operacje');
eq($ops1[0]['op_date'], '2026-08-10', 'plik: data pierwszej operacji');
eq($ops1[0]['amount_grosze'], 36000, 'plik: kwota pierwszej operacji');
eq($ops1[0]['party'], 'Łukasz Żółć', 'plik: polskie znaki z Windows-1250');
eq($ops1[0]['account_key'], '12109010560000000111112222', 'plik: rachunek nadawcy');
eq($ops1[1]['amount_grosze'], -1500, 'plik: opłata jako wydatek');
@unlink($p1);

// ── Ten sam plik jako UTF-8 z przecinkiem: identyczne odciski ──
$csvUtf = "\xEF\xBB\xBF" .
    "\"Data operacji\",\"Tytuł\",\"Nadawca\",\"Numer rachunku\",\"Kwota\"\n" .
    "\"2026-08-10\",\"Adopcja Serca - darowizna - Kiady 23\",\"Łukasz Żółć\",\"12 1090 1056 0000 0001 1111 2222\",\"360.00\"\n";
$p2 = tmpfile_with($csvUtf);
[$h2, $r2] = bank_read_table($p2);
$ops2 = bank_rows_to_ops($h2, $r2);
eq(count($ops2), 1, 'utf8: jedna operacja');
eq($ops2[0]['op_hash'], $ops1[0]['op_hash'], 'utf8: ten sam odcisk co z CP1250 (dedup działa mimo formatu)');
@unlink($p2);

/* ── Realny układ Erste: plik BEZ nazw kolumn ───────────────────
   Odwzorowanie eksportu z 14-08-2026 (przecinek, UTF-8, LF, metryka
   rachunku w pierwszej linii, salda liczone od najstarszej operacji).
   Nazwiska i numery kont są zmyślone - prawdziwy wyciąg nie ma prawa
   trafić do repozytorium. */
$erste = "2026-08-14,05-08-2026,'70 1090 1056 0000 0001 5832 5871,FUNDACJA MISJA MADA,PLN,\"90,00\",\"640,00\",4,\n"
       . "14-08-2026,14-08-2026,ADOPCJA SERCA - Kiady 23,JAN KOWALSKI UL. KWIATOWA 1 00-001 WARSZAWA ELIXIR 14-08-2026,12 1090 1056 0000 0001 1111 2222,\"360,00\",\"640,00\",1,\n"
       . "13-08-2026,13-08-2026,\"Adopcja serca czerwiec, lipiec\",ANNA NOWAK UL. POLNA 2 00-002 WARSZAWA ELIXIR 13-08-2026,31 1090 1072 0000 0001 0988 0871,\"120,00\",\"280,00\",2,\n"
       . "12-08-2026,12-08-2026,/OPF/X///// Wypłata z PayU(439320843 Sklep-misjamada.pl),PayU S.A. ul. Grunwaldzka 186 Poznań ELIXIR 12-08-2026,57 1240 1040 1111 0010 5698 4900,\"75,00\",\"160,00\",3,\n"
       . "11-08-2026,11-08-2026,Opłata za użytkownika Mini Firma,CENTRUM USŁUG ROZLICZENIOWYCH,48 1090 0004 0000 0011 8415 0001,\"-5,00\",\"85,00\",4,\n";
$p3 = tmpfile_with($erste);
[$h3, $r3, $meta3] = bank_read_table($p3);
$ops3 = bank_rows_to_ops($h3, $r3, $meta3);
eq(count($ops3), 4, 'erste: cztery operacje mimo braku wiersza nagłówka');
eq($meta3['currency'] ?? '', 'PLN', 'erste: waluta z metryki rachunku');
eq($meta3['account'] ?? '', '70109010560000000158325871', 'erste: numer rachunku mimo apostrofu z Excela');
eq($meta3['count'] ?? 0, 4, 'erste: zapowiedziana liczba operacji');
eq($meta3['date_from'] ?? '', '2026-08-05', 'erste: początek zakresu z metryki');
eq(bank_check_meta($meta3, $ops3), [], 'erste: liczba operacji i suma zgodne z saldami');

eq($ops3[0]['op_date'], '2026-08-14', 'erste: data operacji');
eq($ops3[0]['amount_grosze'], 36000, 'erste: kwota w cudzysłowie z przecinkiem');
eq($ops3[0]['account_key'], '12109010560000000111112222', 'erste: rachunek nadawcy');
eq($ops3[1]['title'], 'Adopcja serca czerwiec, lipiec', 'erste: przecinek wewnątrz tytułu nie rozbija wiersza');
eq($ops3[3]['amount_grosze'], -500, 'erste: opłata jako wydatek');
ok(str_contains($ops3[0]['party'], 'JAN KOWALSKI'), 'erste: nadawca z adresem w jednym polu');

// Metryka to jedyny sposób, żeby wykryć ucięty plik - bez niej brak operacji
// wygląda tak samo jak spokojny tydzień.
$ops3short = array_slice($ops3, 0, 3);
eq(count(bank_check_meta($meta3, $ops3short)), 2, 'erste: brakujący wiersz łapany na liczbie i na saldzie');

// Rachunek walutowy: waluty NIE MA przy operacji, jest tylko w metryce.
$erstGbp = "2026-08-14,10-08-2026,'34 1090 1056 0000 0001 6645 4246,Fundacja Misja Mada,GBP,\"0,00\",\"240,00\",1,\n"
         . "10-08-2026,10-08-2026,REF: PET113341222 Adopcja Serca - darowizna Michael nr 134,ADAM TESTOWY,GB82 BUKB 2096 6003 7696 74,\"240,00\",\"240,00\",1,\n";
$p4 = tmpfile_with($erstGbp);
[$h4, $r4, $meta4] = bank_read_table($p4);
$ops4 = bank_rows_to_ops($h4, $r4, $meta4);
eq(count($ops4), 1, 'erste GBP: jedna operacja');
eq($ops4[0]['currency'], 'GBP', 'erste GBP: waluta z metryki, nie domyślne PLN');
eq($ops4[0]['amount_grosze'], 24000, 'erste GBP: kwota w funtach');
eq($ops4[0]['account_key'], 'GB82BUKB20966003769674', 'erste GBP: IBAN zagraniczny jako klucz rachunku');
@unlink($p3); @unlink($p4);

/* ── Operacje bliźniacze: dwa osobne przelewy, nie duplikat ─────
   Darczyńca z dwojgiem dzieci potrafi wysłać dwa przelewy po 70 zł tego
   samego dnia, z tym samym tytułem. Wcześniej miały identyczny odcisk
   i drugi znikał jako rzekomy duplikat - czyli wpłata przepadała cicho. */
$bliz = "2026-08-14,14-08-2026,'70 1090 1056 0000 0001 5832 5871,FUNDACJA MISJA MADA,PLN,\"0,00\",\"140,00\",2,\n"
      . "14-08-2026,14-08-2026,ADOPCJA SERCA,MARIA WISNIEWSKA UL. LESNA 3 00-003 WARSZAWA ELIXIR 14-08-2026,31 1090 1072 0000 0001 0988 0871,\"70,00\",\"140,00\",1,\n"
      . "14-08-2026,14-08-2026,ADOPCJA SERCA,MARIA WISNIEWSKA UL. LESNA 3 00-003 WARSZAWA ELIXIR 14-08-2026,31 1090 1072 0000 0001 0988 0871,\"70,00\",\"70,00\",2,\n";
$p5 = tmpfile_with($bliz);
[$h5, $r5, $meta5] = bank_read_table($p5);
$ops5 = bank_rows_to_ops($h5, $r5, $meta5);
eq(count($ops5), 2, 'bliźniaki: dwa identyczne przelewy to dwie operacje');
ok($ops5[0]['op_hash'] !== $ops5[1]['op_hash'], 'bliźniaki: różne odciski mimo identycznych pól');
eq(bank_check_meta($meta5, $ops5), [], 'bliźniaki: suma zgodna z saldami');

// Ponowny import tego samego pliku musi dać te same odciski, inaczej
// dedup przestałby chronić przed zdublowaniem wpłat.
$ops5again = bank_rows_to_ops($h5, $r5, $meta5);
eq(array_column($ops5again, 'op_hash'), array_column($ops5, 'op_hash'),
   'bliźniaki: powtórny odczyt daje te same odciski');
// Pierwsze wystąpienie liczy się jak dawniej - operacje wczytane przed tą
// zmianą nie mogą wrócić do poczekalni jako „nowe".
$solo = $ops5[0]; unset($solo['seq'], $solo['op_hash']);
eq(bank_op_hash($solo), $ops5[0]['op_hash'], 'bliźniaki: odcisk pierwszego wystąpienia bez zmian');
@unlink($p5);

/* ── Metryka przeżywa dołożenie nazw kolumn ─────────────────────
   Gdyby bank dorzucił do eksportu wiersz z nazwami kolumn, parser poszedłby
   ścieżką nagłówkową, która wcześniej zwracała pustą metrykę - a razem z nią
   znikała kontrola kompletności i waluta rachunku (240 GBP jako 240 zł). */
$zNaglowkiem = "2026-08-14,10-08-2026,'34 1090 1056 0000 0001 6645 4246,Fundacja Misja Mada,GBP,\"0,00\",\"240,00\",1,\n"
             . "Data księgowania,Data operacji,Tytuł,Nadawca,Numer rachunku,Kwota,Saldo,Lp\n"
             . "10-08-2026,10-08-2026,Adopcja Serca - darowizna Michael nr 134,ADAM TESTOWY,GB82 BUKB 2096 6003 7696 74,\"240,00\",\"240,00\",1,\n";
$p6 = tmpfile_with($zNaglowkiem);
[$h6, $r6, $meta6] = bank_read_table($p6);
eq($h6[1] ?? '', 'Data operacji', 'nagłówek: wiersz nazw kolumn rozpoznany');
eq($meta6['currency'] ?? '', 'GBP', 'nagłówek: metryka rachunku nie ginie razem z nagłówkiem');
$ops6 = bank_rows_to_ops($h6, $r6, $meta6);
eq(count($ops6), 1, 'nagłówek: jedna operacja');
eq($ops6[0]['currency'], 'GBP', 'nagłówek: waluta z metryki mimo innego układu pliku');
@unlink($p6);

// Metryka podana razem z operacjami nie może przejść jako operacja
// (ma daty i kwoty, więc bez osobnego testu wyglądałaby jak wpłata 871,37).
$zMetryka = bank_rows_to_ops(BANK_ERSTE_HEADER, [
    ['2026-08-14', '05-08-2026', "'70 1090 1056 0000 0001 5832 5871", 'FUNDACJA MISJA MADA', 'PLN', '871,37', '7167,56', '47', ''],
    ['14-08-2026', '14-08-2026', 'ADOPCJA SERCA', 'JAN KOWALSKI', '12 1090 1056 0000 0001 1111 2222', '70,00', '7167,56', '1', ''],
], ['currency' => 'PLN']);
eq(count($zMetryka), 1, 'metryka: wiersz rachunku odsiany z listy operacji');

// ── Wskazówki z tytułu przelewu ────────────────────────────────
$h = bank_title_hints('Adopcja Serca - darowizna - Kiady 23');
eq($h['numbers'], [23], 'tytuł: numer dziecka');
ok(in_array('kiady', $h['words'], true), 'tytuł: imię dziecka');
ok(!in_array('adopcja', $h['words'], true), 'tytuł: słowa techniczne odfiltrowane');
$h2b = bank_title_hints('darowizna na cele statutowe');
eq($h2b['numbers'], [], 'tytuł: brak numeru');

// ── Dopasowanie operacji ───────────────────────────────────────
$ctx = [
    'children' => [
        ['id' => 1, 'number' => 23, 'name' => 'Kiady'],
        ['id' => 2, 'number' => 75, 'name' => 'Alvin'],
    ],
    'donors' => [
        ['id' => 10, 'full_name' => 'Łukasz Żółć'],
        ['id' => 11, 'full_name' => 'Ola i Tomasz Kwietniewscy'],
    ],
    'adoptions' => [
        ['id' => 100, 'donor_id' => 10, 'child_id' => 1, 'amount_grosze' => 6000,
         'start_month' => '2026-01', 'end_month' => null, 'status' => 'active', 'paid_until' => '2026-07'],
        ['id' => 101, 'donor_id' => 11, 'child_id' => 2, 'amount_grosze' => 6000,
         'start_month' => '2026-01', 'end_month' => null, 'status' => 'active', 'paid_until' => null],
    ],
    'accounts' => ['12109010560000000111112222' => 10],
];

$m1 = bank_match_op($ops1[0], $ctx);
eq($m1['kind'], 'payment',   'dopasowanie: wpłata Adopcji Serca');
eq($m1['adoption_id'], 100,  'dopasowanie: właściwa adopcja');
eq($m1['months'], 6,         'dopasowanie: 360 zł przy stawce 60 zł = 6 miesięcy');
eq($m1['period_from'], '2026-08', 'dopasowanie: start od pierwszego nieopłaconego');
eq($m1['period_to'], '2027-01',   'dopasowanie: koniec okresu');
eq($m1['confidence'], 'auto',     'dopasowanie: znany rachunek + równa kwota = pewne');

// Sam tytuł (nadawca nieznany, rachunek nieznany) - dziecko wskazuje adopcję.
$opTytul = ['op_date' => '2026-08-11', 'amount_grosze' => 12000, 'currency' => 'PLN',
            'title' => 'Adopcja Serca - darowizna - Alvin 75', 'party' => 'Jan Nowak',
            'account' => '', 'account_key' => ''];
$m2 = bank_match_op($opTytul, $ctx);
eq($m2['adoption_id'], 101, 'dopasowanie: adopcja z numeru i imienia w tytule');
eq($m2['months'], 2,        'dopasowanie: 120 zł = 2 miesiące');
eq($m2['period_from'], '2026-01', 'dopasowanie: brak wpłat -> od startu adopcji');

// Nadawca znany, tytuł bez wskazówek - jedna aktywna adopcja darczyńcy.
$opNadawca = ['op_date' => '2026-08-12', 'amount_grosze' => 6000, 'currency' => 'PLN',
              'title' => 'przelew', 'party' => 'Kwietniewscy Ola i Tomasz',
              'account' => '', 'account_key' => ''];
$m3 = bank_match_op($opNadawca, $ctx);
eq($m3['donor_id'], 11,      'dopasowanie: darczyńca po nazwie nadawcy (inna kolejność słów)');
eq($m3['adoption_id'], 101,  'dopasowanie: jedyna aktywna adopcja darczyńcy');
eq($m3['confidence'], 'suggest', 'dopasowanie: bez rachunku i bez tytułu = do potwierdzenia');

// Wpłata bez żadnego tropu - nie zgadujemy.
$opObcy = ['op_date' => '2026-08-13', 'amount_grosze' => 20000, 'currency' => 'PLN',
           'title' => 'darowizna na cele statutowe', 'party' => 'Anonim',
           'account' => '', 'account_key' => ''];
$m4 = bank_match_op($opObcy, $ctx);
eq($m4['kind'], 'flow',       'dopasowanie: brak tropu -> przepływ finansowy');
eq($m4['confidence'], 'none', 'dopasowanie: brak tropu -> bez propozycji adopcji');
eq($m4['category'], 'darowizna', 'dopasowanie: kategoria zgadnięta z tytułu');

// Wydatek zawsze do finansów, nigdy jako wpłata darczyńcy.
$m5 = bank_match_op($ops1[1], $ctx);
eq($m5['kind'], 'flow', 'dopasowanie: opłata bankowa to przepływ');
eq($m5['category'], 'koszt_administracyjny', 'dopasowanie: opłata -> koszt administracyjny');

// Kwota nie dzieląca się przez stawkę - okres zostawiamy człowiekowi.
$opDziwna = ['op_date' => '2026-08-14', 'amount_grosze' => 15000, 'currency' => 'PLN',
             'title' => 'Adopcja Serca - darowizna - Kiady 23', 'party' => 'Łukasz Żółć',
             'account' => '12 1090 1056 0000 0001 1111 2222', 'account_key' => '12109010560000000111112222'];
$m6 = bank_match_op($opDziwna, $ctx);
eq($m6['adoption_id'], 100,      'dopasowanie: adopcja mimo nietypowej kwoty');
eq($m6['months'], null,          'dopasowanie: 150 zł przy stawce 60 zł - brak podziału');
eq($m6['confidence'], 'suggest', 'dopasowanie: nietypowa kwota wymaga potwierdzenia');

/* Zbiorcza wypłata z bramki: te wpłaty panel ma już z notyfikacji PayU
   (payu/notify.php -> adopt_payment_from_charge), więc zapisanie jej jako
   darowizny policzyłoby je drugi raz. Ma iść do Finansów jako przepływ. */
ok(bank_is_gateway_settlement($ops3[2]), 'payu: rozpoznana wypłata z bramki');
$m7 = bank_match_op($ops3[2], $ctx);
eq($m7['kind'], 'flow',       'payu: nigdy jako wpłata darczyńcy');
eq($m7['confidence'], 'none', 'payu: bez propozycji adopcji');
eq($m7['category'], 'inne',   'payu: przepływ własnych pieniędzy, nie nowa darowizna');
eq($m7['donor_id'], null,     'payu: nie przypisujemy darczyńcy');

// Prowizja pobrana przez bramkę to już zwykły koszt, nie rozliczenie.
$opProwizja = ['op_date' => '2026-08-12', 'amount_grosze' => -1230, 'currency' => 'PLN',
               'title' => 'Prowizja PayU', 'party' => 'PayU S.A.', 'account' => '', 'account_key' => ''];
ok(!bank_is_gateway_settlement($opProwizja), 'payu: obciążenie to nie rozliczenie');
eq(bank_guess_category($opProwizja), 'koszt_administracyjny', 'payu: prowizja jako koszt administracyjny');

/* Kierunek zmienia znaczenie słowa „adopcja”: wpływ = darowizna na Adopcję
   Serca, wydatek = przelew do misji na Madagaskarze. */
$opWplataAdopcja = ['op_date' => '2026-08-10', 'amount_grosze' => 24000, 'currency' => 'GBP',
                    'title' => 'Adopcja Serca - darowizna Michael nr 134', 'party' => 'Adam Testowy',
                    'account' => '', 'account_key' => ''];
eq(bank_guess_category($opWplataAdopcja), 'adopcja', 'kategoria: wpłata na Adopcję Serca to wpływ, nie wypłata');
$opWyplataMisja = ['op_date' => '2026-08-10', 'amount_grosze' => -500000, 'currency' => 'PLN',
                   'title' => 'Adopcja Serca - przekazanie środków', 'party' => 'Siostry Madagaskar',
                   'account' => '', 'account_key' => ''];
eq(bank_guess_category($opWyplataMisja), 'wyplata_adopcja', 'kategoria: przelew do misji to wypłata');

/* ── Kto wpłacił: nadawca obłożony adresem ──────────────────────
   Bank wkłada w jedno pole osobę, ulicę, kod, miasto i ogon systemowy. */
eq(bank_party_person('HELENA ŻANKOWSKA UL.GAGARINA 31 M.12 00-753 WARSZAWA ELIXIR 07-08-2026'),
   'helena zankowska', 'nadawca: adres i ogon ELIXIR odcięte');
eq(bank_party_person('ŁUKASZ WILK WYSZATYCE 292 37-733 WYSZATYCE ELIXIR 14-08-2026'),
   'lukasz wilk wyszatyce', 'nadawca: wieś bez numeru zostaje, ale numer ucina resztę');
eq(bank_party_person(''), '', 'nadawca: puste pole');

/* ── Darczyńca zapisany w kartotece jako para ───────────────────
   To był powód, dla którego panel pisał „brak tropu w tytule i nadawcy"
   przy nadawcach z nazwiskiem jak wół: adopt_name_match zwraca dla par
   „fuzzy", a bank_match_op brał wyłącznie „exact". */
$ctxPara = [
    'children' => [
        ['id' => 1, 'number' => 23, 'name' => 'Kiady'],
        ['id' => 2, 'number' => 75, 'name' => 'Alvin'],
        ['id' => 3, 'number' => 90, 'name' => 'Maja'],
    ],
    'donors' => [
        ['id' => 20, 'full_name' => 'Helena i Jan Żankowscy'],
        ['id' => 21, 'full_name' => 'Krzysztof Miszkurka'],
        ['id' => 22, 'full_name' => 'Barbara Wójcik'],
    ],
    'adoptions' => [
        ['id' => 200, 'donor_id' => 20, 'child_id' => 1, 'amount_grosze' => 7000,
         'start_month' => '2026-01', 'end_month' => null, 'status' => 'active',
         'payments' => [['period_from' => '2026-01', 'period_to' => '2026-05']]],
        ['id' => 201, 'donor_id' => 21, 'child_id' => 2, 'amount_grosze' => 7000,
         'start_month' => '2026-01', 'end_month' => null, 'status' => 'active',
         'payments' => [['period_from' => '2026-01', 'period_to' => '2026-05']]],
        ['id' => 202, 'donor_id' => 22, 'child_id' => 3, 'amount_grosze' => 7000,
         'start_month' => '2026-01', 'end_month' => null, 'status' => 'active',
         'payments' => [['period_from' => '2026-01', 'period_to' => '2026-07']]],
    ],
    'accounts' => [],
];

$opPara = ['op_date' => '2026-08-07', 'amount_grosze' => 14000, 'currency' => 'PLN',
           'title' => 'WSPÓLNA DAROWIZNA OD HELENY I JANA ŻANKOWSKICH_NA CELE POŻYTKU PUBLICZNEGO'
                    . ' - DZIAŁALNOŚĆ CHARYTATYWNA - ADOPCJA SERCA',
           'party' => 'HELENA ŻANKOWSKA UL.GAGARINA 31 M.12 00-753 WARSZAWA ELIXIR 07-08-2026',
           'account' => '', 'account_key' => ''];
$cPara = bank_donor_candidates($opPara, $ctxPara['donors']);
eq(count($cPara), 1, 'para: jeden kandydat, nie tłum');
eq($cPara[0]['id'], 20, 'para: trafiony darczyńca zapisany jako dwoje ludzi');
eq($cPara[0]['level'], 'fuzzy', 'para: trafienie niepewne, bo zapis się różni');
$mPara = bank_match_op($opPara, $ctxPara);
eq($mPara['donor_id'], 20,        'para: darczyńca wskazany mimo niepewnego nazwiska');
eq($mPara['adoption_id'], 200,    'para: jedyna adopcja tego darczyńcy');
ok($mPara['confidence'] !== 'auto', 'para: niepewne nazwisko nigdy nie daje pewności');
ok(str_contains($mPara['reason'], 'sprawdź'), 'para: podpowiedź prosi o sprawdzenie');

// Nazwisko bywa TYLKO w tytule, nadawcą jest ktoś inny (np. współmałżonek
// płacący ze swojego konta).
$opTytulNazwisko = ['op_date' => '2026-08-07', 'amount_grosze' => 7000, 'currency' => 'PLN',
    'title' => 'Darowizna od Barbary Wójcik - Adopcja Serca',
    'party' => 'ANDRZEJ NOWICKI UL. LIPOWA 4 00-004 WARSZAWA ELIXIR 07-08-2026',
    'account' => '', 'account_key' => ''];
$cTyt = bank_donor_candidates($opTytulNazwisko, $ctxPara['donors']);
eq($cTyt[0]['id'] ?? 0, 22,       'tytuł: nazwisko z tytułu wskazuje darczyńcę');
eq($cTyt[0]['where'] ?? '', 'tytule', 'tytuł: wiadomo, skąd trop');

// Obcy nadawca nie może przypadkiem trafić w kogokolwiek z kartoteki.
$opObcy2 = ['op_date' => '2026-08-07', 'amount_grosze' => 5000, 'currency' => 'PLN',
            'title' => 'darowizna na cele statutowe', 'party' => 'ZENON PIETRZAK ELIXIR 07-08-2026',
            'account' => '', 'account_key' => ''];
eq(bank_donor_candidates($opObcy2, $ctxPara['donors']), [], 'obcy: brak fałszywych kandydatów');

/* ── Okres wypisany w tytule przelewu ───────────────────────────
   Najpewniejsze źródło, bo pochodzi od samego darczyńcy. */
$tm = bank_title_months('Adopcja serca czerwiec, lipiec,sierpień, wrzesień, październik,'
                      . ' listopad, grudzień 2026', '2026-08-14');
eq($tm['from'] ?? '', '2026-06', 'tytuł-okres: pierwszy wymieniony miesiąc');
eq($tm['to'] ?? '',   '2026-12', 'tytuł-okres: ostatni wymieniony miesiąc');
eq($tm['months'] ?? 0, 7,        'tytuł-okres: siedem miesięcy');
eq($tm['gap'] ?? true, false,    'tytuł-okres: ciąg bez dziur');

eq(bank_title_months('składka za VI-XII 2026', '2026-08-14')['from'] ?? '', '2026-06',
   'tytuł-okres: zapis rzymski');
eq(bank_title_months('adopcja 06-12/2026', '2026-08-14')['months'] ?? 0, 7,
   'tytuł-okres: zapis cyfrowy z zakresem');
eq(bank_title_months('wpłata za lipiec', '2026-07-20')['from'] ?? '', '2026-07',
   'tytuł-okres: pojedynczy miesiąc przy słowie okresowym');
eq(bank_title_months('Adopcja Serca', '2026-08-14'), [],
   'tytuł-okres: zwykły tytuł nie udaje okresu');
eq(bank_title_months('darowizna Maja', '2026-08-14', ['Kiady', 'Maja']), [],
   'tytuł-okres: imię dziecka wygrywa z nazwą miesiąca');
eq(bank_title_months('za styczeń', '2026-12-28')['from'] ?? '', '2027-01',
   'tytuł-okres: styczeń pisany w grudniu to następny rok');
eq(bank_title_months('styczeń i marzec 2026', '2026-03-10')['gap'] ?? false, true,
   'tytuł-okres: dziura w wyliczeniu zgłoszona');

// Tytuł Miszkurki w komplecie: 490 zł przy stawce 70 zł to dokładnie te
// siedem miesięcy, które wypisał.
$opMisz = ['op_date' => '2026-08-14', 'amount_grosze' => 49000, 'currency' => 'PLN',
    'title' => 'Adopcja serca czerwiec, lipiec,sierpień, wrzesień, październik, listopad, grudzień 2026',
    'party' => 'MISZKURKA KRZYSZTOF NA POPIELÓWKĘ 43K/1 32-087 ZIELONKI ELIXIR 13-08-2026',
    'account' => '', 'account_key' => ''];
$mMisz = bank_match_op($opMisz, $ctxPara);
eq($mMisz['donor_id'], 21,          'Miszkurka: darczyńca rozpoznany po nadawcy');
eq($mMisz['period_from'], '2026-06', 'Miszkurka: okres z tytułu, nie od bieżącego miesiąca');
eq($mMisz['period_to'], '2026-12',   'Miszkurka: koniec okresu z tytułu');
eq($mMisz['warn'], [],               'Miszkurka: kwota zgadza się z tytułem, brak ostrzeżeń');

/* ── Okres liczony od pierwszej DZIURY, nie od ostatniej wpłaty ──
   Darczyńca opłacił styczeń-maj, potem z wyprzedzeniem sam wrzesień.
   Start od max(period_to)+1 dałby październik i zaległości przepadłyby. */
$ctxDziura = $ctxPara;
$ctxDziura['adoptions'][0]['payments'] = [
    ['period_from' => '2026-01', 'period_to' => '2026-05'],
    ['period_from' => '2026-09', 'period_to' => '2026-09'],
];
$opDziura = ['op_date' => '2026-08-20', 'amount_grosze' => 21000, 'currency' => 'PLN',
             'title' => 'Adopcja Serca', 'party' => 'JAN ŻANKOWSKI ELIXIR 20-08-2026',
             'account' => '', 'account_key' => ''];
$mDziura = bank_match_op($opDziura, $ctxDziura);
eq($mDziura['period_from'], '2026-06', 'dziura: start od pierwszego niepokrytego miesiąca');
eq($mDziura['period_to'], '2026-08',   'dziura: trzy miesiące z kwoty 210 zł');
eq($mDziura['warn'], [], 'dziura: proponowany zakres nie nachodzi na opłacony wrzesień');

// Wpłata na miesiące już opłacone musi to powiedzieć wprost.
$opNaklad = ['op_date' => '2026-08-20', 'amount_grosze' => 7000, 'currency' => 'PLN',
             'title' => 'adopcja za marzec 2026', 'party' => 'BARBARA WÓJCIK ELIXIR 20-08-2026',
             'account' => '', 'account_key' => ''];
$mNaklad = bank_match_op($opNaklad, $ctxPara);
eq($mNaklad['period_from'], '2026-03', 'nakładanie: okres wzięty z tytułu');
ok(!empty($mNaklad['warn']), 'nakładanie: ostrzeżenie o opłaconym już miesiącu');
ok(str_contains(implode(' ', $mNaklad['warn']), '2026-03'), 'nakładanie: wskazany konkretny miesiąc');

// Tytuł mówi swoje, kwota swoje - obie liczby na wierzchu.
$opSprzecznosc = ['op_date' => '2026-08-20', 'amount_grosze' => 14000, 'currency' => 'PLN',
    'title' => 'adopcja czerwiec, lipiec, sierpień 2026', 'party' => 'KRZYSZTOF MISZKURKA ELIXIR 20-08-2026',
    'account' => '', 'account_key' => ''];
$mSprz = bank_match_op($opSprzecznosc, $ctxPara);
eq($mSprz['months'], 3, 'sprzeczność: liczba miesięcy z tytułu');
ok(str_contains(implode(' ', $mSprz['warn']), 'kwota starcza na 2'),
   'sprzeczność: kwota kontra tytuł pokazane pracownikowi');

// Wpłata w obcej walucie: stawka jest w złotych, więc dzielenie kwoty nie ma sensu.
$opGbp = ['op_date' => '2026-08-10', 'amount_grosze' => 24000, 'currency' => 'GBP',
          'title' => 'Adopcja Serca - darowizna Alvin 75', 'party' => 'KRZYSZTOF MISZKURKA ELIXIR 10-08-2026',
          'account' => '', 'account_key' => ''];
$mGbp = bank_match_op($opGbp, $ctxPara);
eq($mGbp['adoption_id'], 201, 'waluta: adopcja rozpoznana mimo funtów');
eq($mGbp['months'], null,     'waluta: bez automatycznego podziału na miesiące');
ok(str_contains($mGbp['reason'], 'GBP'), 'waluta: powód napisany wprost');

/* ── Jedna wpłata, dwoje dzieci ─────────────────────────────────
   Radek: „Darczyńcy, którzy mają 2 dzieci i robią 1 wpłatę - mogę wybrać
   tylko 1 dziecko i nie mogę przyporządkować wpłat dla 2 dziecka". */
$dwoje = [
    ['id' => 301, 'amount_grosze' => 7000, 'start_month' => '2026-01', 'end_month' => null,
     'payments' => [['period_from' => '2026-01', 'period_to' => '2026-07']]],
    ['id' => 302, 'amount_grosze' => 7000, 'start_month' => '2026-01', 'end_month' => null,
     'payments' => [['period_from' => '2026-01', 'period_to' => '2026-07']]],
];
$podzial = bank_split_payment(
    ['op_date' => '2026-08-07', 'amount_grosze' => 14000, 'currency' => 'PLN'], $dwoje);
eq(array_keys($podzial), [301, 302], 'podział: obie adopcje dostają swoją część');
eq($podzial[301]['amount_grosze'], 7000, 'podział: 140 zł to po 70 zł na dziecko');
eq($podzial[301]['period_from'], '2026-08', 'podział: okres od pierwszego niezapłaconego');
eq($podzial[301]['months'], 1, 'podział: jeden miesiąc dla każdego dziecka');

// Dwa miesiące dla dwojga dzieci naraz.
eq(bank_split_payment(['op_date' => '2026-08-07', 'amount_grosze' => 28000, 'currency' => 'PLN'],
                      $dwoje)[302]['period_to'] ?? '', '2026-09', 'podział: 280 zł to dwa miesiące');

// Kwota nie dzieląca się przez sumę stawek - nie zgadujemy, pola zostają puste.
eq(bank_split_payment(['op_date' => '2026-08-07', 'amount_grosze' => 15000, 'currency' => 'PLN'],
                      $dwoje), [], 'podział: nierówna kwota nie jest dzielona na siłę');
eq(bank_split_payment(['op_date' => '2026-08-07', 'amount_grosze' => 14000, 'currency' => 'GBP'],
                      $dwoje), [], 'podział: obca waluta wobec stawek w złotych');
eq(bank_split_payment(['op_date' => '2026-08-07', 'amount_grosze' => 7000, 'currency' => 'PLN'],
                      [$dwoje[0]]), [], 'podział: jedno dziecko nie wymaga dzielenia');

// Okres wypisany w tytule wygrywa z wyliczonym, gdy liczba miesięcy się zgadza.
$podzialTytul = bank_split_payment(
    ['op_date' => '2026-08-07', 'amount_grosze' => 28000, 'currency' => 'PLN'], $dwoje,
    bank_title_months('adopcja listopad, grudzień 2026', '2026-08-07'));
eq($podzialTytul[301]['period_from'] ?? '', '2026-11', 'podział: okres z tytułu ma pierwszeństwo');

/* ── Czy plik nadal jest tym, czym był ──────────────────────────
   Bank kiedyś zmieni eksport. Groźne są nie awarie, tylko importy, które
   PRZECHODZĄ z przekłamanymi liczbami - i to wyłapuje ten raport. */
$metryka = "2026-08-14,05-08-2026,'70 1090 1056 0000 0001 5832 5871,FUNDACJA MISJA MADA,PLN,\"90,00\",\"640,00\",4,\n";
$tresc = "14-08-2026,14-08-2026,ADOPCJA SERCA - Kiady 23,JAN KOWALSKI UL. KWIATOWA 1 00-001 WARSZAWA ELIXIR 14-08-2026,12 1090 1056 0000 0001 1111 2222,\"360,00\",\"640,00\",1,\n"
       . "13-08-2026,13-08-2026,ADOPCJA SERCA,ANNA NOWAK UL. POLNA 2 00-002 WARSZAWA ELIXIR 13-08-2026,31 1090 1072 0000 0001 0988 0871,\"120,00\",\"280,00\",2,\n"
       . "12-08-2026,12-08-2026,Wpłata,MARIA LIS UL. LEŚNA 3 00-003 WARSZAWA ELIXIR 12-08-2026,57 1240 1040 1111 0010 5698 4900,\"75,00\",\"160,00\",3,\n"
       . "11-08-2026,11-08-2026,Opłata za rachunek,CENTRUM USŁUG ROZLICZENIOWYCH,48 1090 0004 0000 0011 8415 0001,\"-5,00\",\"85,00\",4,\n";

$pOk = tmpfile_with($metryka . $tresc);
[$hOk, $rOk, $mOk] = bank_read_table($pOk);
$opsOk = bank_rows_to_ops($hOk, $rOk, $mOk);
eq(bank_sanity_report($mOk, $opsOk, $rOk), [], 'kontrola: poprawny plik nie generuje ostrzeżeń');
eq(bank_row_width(['a', 'b', 'c', '']), 3, 'kontrola: puste ogony kolumn nie liczą się do szerokości');
@unlink($pOk);

// Bez metryki nie mamy CZYM sprawdzić pliku - i to musi być powiedziane wprost,
// bo wcześniej cisza wyglądała identycznie jak „wszystko się zgadza".
$pBez = tmpfile_with($tresc);
[$hBez, $rBez, $mBez] = bank_read_table($pBez);
$repBez = bank_sanity_report($mBez, bank_rows_to_ops($hBez, $rBez, $mBez), $rBez);
ok(bank_sanity_has_hard($repBez), 'kontrola: brak metryki to ostrzeżenie twarde');
ok(str_contains($repBez[0]['text'], 'metryki'), 'kontrola: napisane wprost, czego brakuje');
@unlink($pBez);

// Ucięty eksport wygląda jak spokojny tydzień - łapią go liczba i salda.
$pUciety = tmpfile_with($metryka . implode("\n", array_slice(explode("\n", $tresc), 0, 3)) . "\n");
[$hU, $rU, $mU] = bank_read_table($pUciety);
$repU = bank_sanity_report($mU, bank_rows_to_ops($hU, $rU, $mU), $rU);
eq(count($repU), 2, 'kontrola: ucięty plik łapany na liczbie operacji i na saldzie');
ok(bank_sanity_has_hard($repU), 'kontrola: ucięty plik to ostrzeżenie twarde');
@unlink($pUciety);

/* Kolumna dołożona w środku wiersza (bank „ulepsza" eksport). Parser nie
   rozpoznaje takich wierszy jako operacji, czyli awaria jest GŁOŚNA - ale
   pracownik musi dostać sensowny komunikat, a nie radę „nie otwieraj w Excelu". */
$pZmiana = tmpfile_with(
    "14-08-2026,14-08-2026,PRZELEW,ADOPCJA SERCA,JAN KOWALSKI ELIXIR 14-08-2026,12 1090 1056 0000 0001 1111 2222,\"360,00\",\"640,00\",1,\n"
  . "13-08-2026,13-08-2026,PRZELEW,ADOPCJA SERCA,ANNA NOWAK ELIXIR 13-08-2026,31 1090 1072 0000 0001 0988 0871,\"120,00\",\"280,00\",2,\n");
[$hZ, $rZ, $mZ] = bank_read_table($pZmiana);
eq(bank_rows_to_ops($hZ, $rZ, $mZ), [], 'zmiana układu: parser nie udaje, że rozumie plik');
$peek = bank_peek_rows($pZmiana);
eq(count($peek), 2, 'zmiana układu: pierwsze wiersze do pokazania pracownikowi');
ok(str_contains($peek[0], '[numer rachunku]'), 'zmiana układu: numer rachunku zamazany w podglądzie');
ok(!str_contains($peek[0], '1090 1056 0000'), 'zmiana układu: numeru rachunku nie widać');
@unlink($pZmiana);

// ── Wynik ──────────────────────────────────────────────────────
echo "\nTesty parsera wyciągu bankowego: {$T['pass']} OK";
if ($T['fail'] > 0) { echo ", {$T['fail']} BŁĄD\n"; exit(1); }
echo ", 0 błędów\n";
exit(0);
