<?php
/* ═══ Wyciąg bankowy - parser i dopasowania ═══════════════════════
   CZYSTA LOGIKA: bez bazy i bez sieci, żeby dało się to testować
   (tests/run-bank.php) i żeby ekran importu tylko wyświetlał wynik.

   Źródło: eksport historii z bankowości Erste Bank Polska (dawny
   Santander; rachunki fundacji mają numer rozliczeniowy 1090).
   UKŁAD KOLUMN POTRAFI SIĘ ZMIENIĆ przy zmianie systemu - dlatego
   parser najpierw próbuje rozpoznać kolumny po nagłówkach
   (bank_map_columns) i sam wykrywa separator oraz kodowanie.

   Realny eksport (sprawdzony na wyciągu z 14-08-2026) NIE MA jednak
   wiersza z nazwami kolumn - jest przecinkowy, w UTF-8, a w pierwszej
   linii stoi metryka rachunku. Na taki plik jest druga ścieżka:
   bank_split_erste (układ pozycyjny + waluta z metryki).

   Zasada jak przy migracji z arkuszy: PARSER NIE ZGADUJE. Zwraca
   propozycję z poziomem pewności, a decyzję zatwierdza pracownik.
  ═══════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/lib.php';

/* ── Nagłówki kolumn ───────────────────────────────────────────────
   Klucz = pole wewnętrzne, wartości = fragmenty nagłówków (po
   normalizacji: małe litery, bez ogonków). Dopasowanie po zawieraniu,
   więc "Data operacji" i "Data transakcji" trafiają w to samo pole. */
const BANK_HEADER_MAP = [
    'date'     => ['data operacji', 'data transakcji', 'data waluty', 'data ksiegowania', 'data'],
    'title'    => ['tytul', 'opis operacji', 'opis transakcji', 'szczegoly', 'opis'],
    'party'    => ['nadawca', 'odbiorca', 'kontrahent', 'nazwa nadawcy', 'nazwa odbiorcy',
                   'nadawca odbiorca', 'druga strona'],
    'account'  => ['rachunek nadawcy', 'rachunek odbiorcy', 'numer rachunku', 'rachunek kontrahenta',
                   'nr rachunku', 'rachunek'],
    'amount'   => ['kwota operacji', 'kwota transakcji', 'kwota'],
    'currency' => ['waluta'],
    'balance'  => ['saldo'],
];

/* ── Układ Erste: plik bez nazw kolumn ─────────────────────────────
   Eksport z bankowości wygląda tak (CSV, przecinek, UTF-8, końce LF):

     2026-08-14,05-08-2026,'70 1090 ...,FUNDACJA MISJA MADA,PLN,"871,37","7167,56",47,
     14-08-2026,14-08-2026,ADOPCJA SERCA,JAN KOWALSKI ... ELIXIR 14-08-2026,45 1140 ...,"70,00","7167,56",1,

   Pierwsza linia to METRYKA RACHUNKU: data wydruku, początek zakresu,
   numer rachunku (apostrof to zabezpieczenie przed Excelem), nazwa
   posiadacza, waluta, saldo początkowe, saldo końcowe, liczba operacji.
   Dalej idą od razu dane - żadnych nazw kolumn, więc mapowanie po
   nagłówkach nie ma czego złapać.

   Metryka jest przy tym prezentem: liczba operacji i oba salda pozwalają
   sprawdzić, czy import objął cały plik (bank_check_meta). */
const BANK_ERSTE_HEADER = ['data ksiegowania', 'data operacji', 'tytul', 'nadawca',
                           'numer rachunku', 'kwota', 'saldo', 'lp'];

/**
 * Wiersz metryki rachunku -> opis rachunku, albo null gdy to nie metryka.
 * Rozpoznanie po dwóch rzeczach naraz: w kolumnie 3 stoi numer rachunku,
 * a w 5 sam kod waluty - w wierszu operacji jest tam tytuł i rachunek
 * kontrahenta, więc pomyłka nie grozi.
 */
function bank_erste_meta(array $r): ?array {
    if (count($r) < 8) return null;
    if (!preg_match('/^[A-Z]{3}$/', strtoupper(trim($r[4])))) return null;
    $account = bank_account_key($r[2]);
    if ($account === '') return null;
    $from = bank_parse_amount($r[5]);
    $to   = bank_parse_amount($r[6]);
    if ($from === null || $to === null || !preg_match('/^\d+$/', trim($r[7]))) return null;
    return [
        'account'      => $account,
        'holder'       => trim($r[3]),
        'currency'     => strtoupper(trim($r[4])),
        'balance_from' => $from,
        'balance_to'   => $to,
        'count'        => (int)trim($r[7]),
        'date_from'    => bank_parse_date($r[1]),
        'printed_at'   => bank_parse_date($r[0]),
    ];
}

/** Czy wiersz wygląda na operację w układzie Erste (dwie daty, kwota, saldo, lp). */
function bank_erste_is_op(array $r): bool {
    if (count($r) < 8) return false;
    return bank_parse_date($r[0]) !== null
        && bank_parse_date($r[1]) !== null
        && bank_parse_amount($r[5]) !== null
        && bank_parse_amount($r[6]) !== null
        && preg_match('/^\d+$/', trim($r[7])) === 1;
}

/**
 * Ostatnia deska ratunku, gdy w pliku nie ma nazw kolumn: bierzemy układ
 * pozycyjny Erste i podstawiamy syntetyczny nagłówek, żeby dalsza część
 * parsera działała bez zmian. Metryka rachunku wraca osobno.
 *
 * Uwaga na kolejność dat: w eksporcie księgowanie i operacja są dotąd
 * zawsze równe; gdyby się rozjechały, mapa nagłówków bierze „data operacji".
 */
function bank_split_erste(array $rows): array {
    $meta = [];
    $ops  = [];
    foreach ($rows as $r) {
        $m = bank_erste_meta($r);
        if ($m !== null) {
            if (!$meta) $meta = $m;
            else        $meta['multi'] = true;   // sklejone wyciągi z dwóch rachunków
            continue;
        }
        if (bank_erste_is_op($r)) $ops[] = $r;
    }
    return $ops ? [BANK_ERSTE_HEADER, $ops, $meta] : [[], [], []];
}

/**
 * Kontrola po parsowaniu: czy wczytaliśmy tyle, ile obiecuje metryka pliku.
 * Zwraca listę komunikatów dla pracownika - pusta znaczy „zgadza się co do
 * grosza". To jedyny moment, w którym da się wyłapać ucięty plik.
 */
function bank_check_meta(array $meta, array $ops): array {
    if (!$meta) return [];
    $money = fn(int $gr) => number_format($gr / 100, 2, ',', ' ') . ' ' . ($meta['currency'] ?? 'PLN');
    $out = [];
    if (isset($meta['count']) && $meta['count'] !== count($ops)) {
        $out[] = 'plik zapowiada ' . (int)$meta['count'] . ' operacji, wczytano ' . count($ops);
    }
    if (isset($meta['balance_from'], $meta['balance_to'])) {
        $suma    = array_sum(array_column($ops, 'amount_grosze'));
        $roznica = $meta['balance_to'] - $meta['balance_from'];
        if ($suma !== $roznica) {
            $out[] = 'suma operacji ' . $money($suma) . ' nie zgadza się ze zmianą salda ' . $money($roznica);
        }
    }
    if (!empty($meta['multi'])) {
        $out[] = 'plik zawiera więcej niż jeden rachunek - waluta może być różna dla części operacji';
    }
    return $out;
}

/** Nagłówek -> postać porównywalna (małe litery, bez ogonków i interpunkcji). */
function bank_header_key(string $h): string {
    return adopt_name_normalize($h);
}

/**
 * Mapuje nagłówki pliku na pola wewnętrzne: [pole => indeks kolumny].
 * Wzorce w BANK_HEADER_MAP są uszeregowane wg PRIORYTETU: wygrywa pierwszy,
 * który w ogóle pasuje (dlatego przy „Data operacji" i „Data księgowania"
 * bierzemy datę operacji, a nie dłuższy nagłówek). Kolumna obsadza jedno pole.
 */
function bank_map_columns(array $headers): array {
    $keys = array_map('bank_header_key', $headers);
    $map = [];
    $taken = [];
    foreach (BANK_HEADER_MAP as $field => $patterns) {
        foreach ($patterns as $p) {
            $hit = null;
            foreach ($keys as $i => $k) {
                if ($k === '' || isset($taken[$i])) continue;
                if (str_contains($k, $p)) { $hit = $i; break; }
            }
            if ($hit !== null) { $map[$field] = $hit; $taken[$hit] = true; break; }
        }
    }
    return $map;
}

/* ── Kodowanie i separator ─────────────────────────────────────── */

/* Polskie znaki CP1250 -> UTF-8. Awaryjna droga na wypadek PHP bez iconv;
   mbstring NIE MA CP1250 (na PHP 8.3 zna z tej rodziny tylko ISO-8859-2,
   które ma inny układ bajtów), więc nie da się go tu użyć zamiennie. */
const BANK_CP1250_PL = [
    "\xB9" => 'ą', "\xE6" => 'ć', "\xEA" => 'ę', "\xB3" => 'ł', "\xF1" => 'ń',
    "\xF3" => 'ó', "\x9C" => 'ś', "\x9F" => 'ź', "\xBF" => 'ż',
    "\xA5" => 'Ą', "\xC6" => 'Ć', "\xCA" => 'Ę', "\xA3" => 'Ł', "\xD1" => 'Ń',
    "\xD3" => 'Ó', "\x8C" => 'Ś', "\x8F" => 'Ź', "\xAF" => 'Ż',
    "\x84" => '„', "\x93" => '"', "\x96" => '-', "\x97" => '-', "\xA0" => ' ',
];

/** Treść pliku jako UTF-8 (bank eksportuje Windows-1250, ale bywa UTF-8). */
function bank_to_utf8(string $raw): string {
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);          // BOM
    if (mb_check_encoding($raw, 'UTF-8')) {
        // Czysty ASCII albo poprawny UTF-8 - zostawiamy bez zmian.
        return $raw;
    }
    if (function_exists('iconv')) {
        $out = @iconv('CP1250', 'UTF-8//IGNORE', $raw);
        if ($out !== false) return $out;
    }
    return strtr($raw, BANK_CP1250_PL);
}

/** Separator CSV: ten, który daje najwięcej kolumn w wierszu nagłówka. */
function bank_detect_separator(string $headerLine): string {
    $best = ';'; $bestCount = 0;
    foreach ([';', ',', "\t", '|'] as $sep) {
        $n = count(str_getcsv($headerLine, $sep, '"', '\\'));
        if ($n > $bestCount) { $bestCount = $n; $best = $sep; }
    }
    return $best;
}

/**
 * Czyta plik wyciągu do postaci [nagłówki, wiersze, metryka rachunku].
 * Obsługuje CSV/TXT (autodetekcja) i XLSX (pierwsza zakładka).
 * Pomija wiersze śmieciowe sprzed nagłówka (banki lubią nagłówek raportu),
 * a gdy nazw kolumn nie ma wcale - wchodzi układ pozycyjny Erste.
 */
function bank_read_table(string $path): array {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $rows = $ext === 'xlsx' ? bank_read_xlsx($path) : bank_read_csv($path);
    return bank_split_header($rows);
}

function bank_read_csv(string $path): array {
    $raw = (string)file_get_contents($path);
    $raw = bank_to_utf8($raw);
    $lines = preg_split("/\r\n|\n|\r/", $raw);
    $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
    if (!$lines) return [];
    // Separator liczymy na linii, która wygląda na nagłówek (najwięcej pól).
    $probe = $lines[0];
    foreach (array_slice($lines, 0, 15) as $l) {
        if (substr_count($l, ';') > substr_count($probe, ';')) $probe = $l;
    }
    $sep = bank_detect_separator($probe);
    $out = [];
    foreach ($lines as $l) {
        $out[] = array_map(fn($c) => trim((string)$c), str_getcsv($l, $sep, '"', '\\'));
    }
    return $out;
}

/** Minimalny czytnik XLSX (pierwsza zakładka) - zip + XML, bez zależności. */
function bank_read_xlsx(string $path): array {
    if (!class_exists('ZipArchive')) return [];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];

    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $sx = new SimpleXMLElement($ss);
        foreach ($sx->si as $si) {
            $txt = '';
            foreach ($si->xpath('.//*[local-name()="t"]') as $t) $txt .= (string)$t;
            $shared[] = $txt;
        }
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheet === false) return [];

    $rows = [];
    $sx = new SimpleXMLElement($sheet);
    foreach ($sx->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            preg_match('/^([A-Z]+)/', $ref, $m);
            $idx = 0;
            foreach (str_split($m[1] ?? 'A') as $ch) $idx = $idx * 26 + (ord($ch) - 64);
            $idx--;
            $v = (string)$c->v;
            if ((string)$c['t'] === 's') $v = $shared[(int)$v] ?? '';
            elseif ((string)$c['t'] === 'inlineStr') $v = (string)$c->is->t;
            $cells[$idx] = trim($v);
        }
        if (!$cells) { $rows[] = []; continue; }
        $rows[] = array_map(fn($i) => $cells[$i] ?? '', range(0, max(array_keys($cells))));
    }
    return $rows;
}

/**
 * Wydziela wiersz nagłówka: pierwszy, w którym rozpoznajemy datę i kwotę.
 * Wszystko powyżej to nagłówek raportu (nazwa rachunku, zakres dat itd.).
 * Brak takiego wiersza nie kończy sprawy - plik idzie wtedy ścieżką Erste.
 *
 * Metryka rachunku spotkana po drodze WRACA razem z nagłówkiem. Wcześniej
 * ta ścieżka zwracała pustą metrykę, więc gdyby bank dołożył do eksportu
 * nazwy kolumn - czyli formalnie go ulepszył - panel po cichu straciłby
 * kontrolę kompletności (bank_check_meta) i walutę rachunku.
 */
function bank_split_header(array $rows): array {
    $meta = [];
    foreach ($rows as $i => $r) {
        if (count($r) < 3) continue;
        $m = bank_erste_meta($r);
        if ($m !== null) {                            // metryka rachunku to nie nagłówek
            if (!$meta) $meta = $m;
            continue;
        }
        $map = bank_map_columns($r);
        if (isset($map['date'], $map['amount'])) {
            return [$r, array_values(array_slice($rows, $i + 1)), $meta];
        }
    }
    return bank_split_erste($rows);
}

/* ── Wartości ──────────────────────────────────────────────────── */

/** Data z wyciągu -> 'RRRR-MM-DD' albo null. Obsługuje też datę z godziną. */
function bank_parse_date(string $raw): ?string {
    $s = trim($raw);
    if ($s === '') return null;
    $s = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?$/', '', $s);   // ucina godzinę
    $pats = [
        '/^(\d{4})-(\d{2})-(\d{2})$/'   => [1, 2, 3],
        '/^(\d{4})\.(\d{2})\.(\d{2})$/' => [1, 2, 3],
        '/^(\d{4})\/(\d{2})\/(\d{2})$/' => [1, 2, 3],
        '/^(\d{2})\.(\d{2})\.(\d{4})$/' => [3, 2, 1],
        '/^(\d{2})-(\d{2})-(\d{4})$/'   => [3, 2, 1],
        '/^(\d{2})\/(\d{2})\/(\d{4})$/' => [3, 2, 1],
    ];
    foreach ($pats as $re => $ord) {
        if (preg_match($re, $s, $m)) {
            [$y, $mo, $d] = [$m[$ord[0]], $m[$ord[1]], $m[$ord[2]]];
            return checkdate((int)$mo, (int)$d, (int)$y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
        }
    }
    return null;
}

/**
 * Kwota z wyciągu -> grosze ZE ZNAKIEM (wydatek ujemny), albo null.
 * Radzi sobie z "1 234,56", "-1.234,56", "1,234.56" i spacją nierozdzielającą.
 */
function bank_parse_amount(string $raw): ?int {
    $s = str_replace(["\xC2\xA0", ' ', "'"], '', trim($raw));
    if ($s === '') return null;
    $neg = str_starts_with($s, '-') || str_ends_with($s, '-')
        || (str_contains($s, '(') && str_contains($s, ')'));
    $s = preg_replace('/[^0-9.,]/', '', $s);
    if ($s === '' || !preg_match('/\d/', $s)) return null;

    $lastComma = strrpos($s, ',');
    $lastDot   = strrpos($s, '.');
    if ($lastComma !== false && $lastDot !== false) {
        // Ten dalej z tyłu jest separatorem dziesiętnym, drugi - tysięcy.
        $dec = $lastComma > $lastDot ? ',' : '.';
        $thou = $dec === ',' ? '.' : ',';
        $s = str_replace($thou, '', $s);
        $s = str_replace($dec, '.', $s);
    } elseif ($lastComma !== false) {
        $s = str_replace(',', '.', $s);
    } elseif ($lastDot !== false) {
        // Jedna kropka z trzema cyframi po niej to separator tysięcy (1.234).
        if (preg_match('/^\d{1,3}\.\d{3}$/', $s)) $s = str_replace('.', '', $s);
    }
    if (substr_count($s, '.') > 1) $s = preg_replace('/\.(?=.*\.)/', '', $s);
    if (!is_numeric($s)) return null;
    $gr = (int)round(((float)$s) * 100);
    return $neg ? -abs($gr) : $gr;
}

/**
 * Numer rachunku do porównań: polski NRB bez spacji i bez prefiksu „PL",
 * a dla zagranicznych - IBAN w całości (darczyńcy z Wielkiej Brytanii
 * przysyłają przelewy z kont typu GB82 BUKB ..., które nie są NRB).
 */
function bank_account_key(string $raw): string {
    $s = strtoupper((string)preg_replace('/[^0-9A-Za-z]/', '', $raw));
    if (preg_match('/^\d{16,26}$/', $s)) return $s;                       // NRB
    if (preg_match('/^PL(\d{16,26})$/', $s, $m)) return $m[1];            // polski IBAN -> NRB
    if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{8,30}$/', $s)) return $s;     // IBAN zagraniczny
    return '';
}

/**
 * Odcisk operacji - chroni przed zdublowaniem wpłat przy ponownym
 * wgraniu tego samego pliku albo zachodzących na siebie zakresach dat.
 *
 * `seq` odróżnia operacje BLIŹNIACZE: dwa osobne przelewy po 70 zł tego
 * samego dnia, od tej samej osoby, z tym samym tytułem (darczyńca płacący
 * za dwoje dzieci dwoma przelewami) miały wcześniej identyczny odcisk
 * i drugi z nich znikał jako rzekomy duplikat. Numer wystąpienia liczy
 * bank_rows_to_ops w obrębie jednego pliku, więc ponowny import tego
 * samego zakresu dat dalej daje zero nowych operacji.
 *
 * Do odcisku wchodzi dopiero od drugiego wystąpienia - dzięki temu
 * operacje wczytane przed tą zmianą zachowują swoje odciski i nie wracają
 * do poczekalni jako „nowe".
 */
function bank_op_hash(array $op): string {
    $seq = (int)($op['seq'] ?? 0);
    return sha1(implode('|', [
        $op['op_date'] ?? '', (string)($op['amount_grosze'] ?? ''), $op['currency'] ?? '',
        adopt_name_normalize((string)($op['title'] ?? '')),
        adopt_name_normalize((string)($op['party'] ?? '')),
        $op['account_key'] ?? '',
    ]) . ($seq > 0 ? '|#' . $seq : ''));
}

/**
 * Surowe wiersze -> lista operacji [op_date, amount_grosze, currency, title,
 * party, account, account_key, op_hash, raw]. Wiersze bez daty albo bez kwoty
 * są pomijane (stopki, sumy, puste linie).
 *
 * $meta to metryka rachunku z bank_read_table. Jest tu potrzebna dla waluty:
 * eksport Erste podaje ją RAZ, w metryce, a nie przy każdej operacji - bez
 * tego wpłata 240 GBP na rachunek walutowy weszłaby jako 240 zł.
 */
function bank_rows_to_ops(array $headers, array $rows, array $meta = []): array {
    $map = bank_map_columns($headers);
    if (!isset($map['date'], $map['amount'])) return [];
    $get = function (array $r, ?int $i): string {
        return $i === null ? '' : trim((string)($r[$i] ?? ''));
    };
    $ops = [];
    $seen = [];        // odcisk bez numeru wystąpienia => ile razy już był
    foreach ($rows as $r) {
        // Metryka rachunku ma daty i kwoty, więc w pliku z nazwami kolumn
        // przeszłaby tu jako operacja - odsiewamy ją po numerze rachunku
        // i kodzie waluty naraz, tak samo jak w ścieżce pozycyjnej.
        if (bank_erste_meta($r) !== null) continue;
        $date = bank_parse_date($get($r, $map['date'] ?? null));
        $amount = bank_parse_amount($get($r, $map['amount'] ?? null));
        if ($date === null || $amount === null || $amount === 0) continue;
        $acc = $get($r, $map['account'] ?? null);
        $op = [
            'op_date'       => $date,
            'amount_grosze' => $amount,
            'currency'      => strtoupper($get($r, $map['currency'] ?? null))
                               ?: (string)($meta['currency'] ?? 'PLN'),
            'title'         => $get($r, $map['title'] ?? null),
            'party'         => $get($r, $map['party'] ?? null),
            'account'       => $acc,
            'account_key'   => bank_account_key($acc),
            'raw'           => $r,
        ];
        $base = bank_op_hash($op);
        $n = $seen[$base] ?? 0;
        $seen[$base] = $n + 1;
        $op['seq'] = $n;
        $op['op_hash'] = $n > 0 ? bank_op_hash($op) : $base;
        $ops[] = $op;
    }
    return $ops;
}

/* ── Dopasowanie do Adopcji Serca ──────────────────────────────── */

/**
 * Czy to przelew rozliczeniowy z bramki płatniczej na konto fundacji.
 *
 * Bramka wypłaca zbiorczo (u nas: „Wypłata z PayU(... Sklep-misjamada.pl)"),
 * więc jedna taka pozycja kryje wiele wpłat wielu osób - i to wpłat, które
 * panel ZNA JUŻ Z NOTYFIKACJI (payu/notify.php -> adopt_payment_from_charge).
 * Zaksięgowanie jej jako darowizny zdublowałoby te wpłaty, a rozbijanie
 * ręcznie na darczyńców zdublowałoby je drugi raz. Do Finansów, bez pytania.
 *
 * Dotyczy wpływów; obciążenie od bramki (prowizja) to zwykły koszt.
 */
function bank_is_gateway_settlement(array $op): bool {
    if (($op['amount_grosze'] ?? 0) <= 0) return false;
    $s = adopt_name_normalize(($op['party'] ?? '') . ' ' . ($op['title'] ?? ''));
    foreach (['payu', 'przelewy24', 'tpay', 'dotpay', 'stripe', 'paypal'] as $gw) {
        if (str_contains($s, $gw)) return true;
    }
    return false;
}

/* Słowa, które w tytule przelewu i w nazwie nadawcy nic nie mówią o osobie
   ani o dziecku - odsiewamy je, zanim zaczniemy szukać nazwisk i imion.
   Tytuły bywają urzędowe: „NA CELE POŻYTKU PUBLICZNEGO - DZIAŁALNOŚĆ
   CHARYTATYWNA - ADOPCJA SERCA". */
const BANK_TITLE_STOP = ['adopcja', 'adopcji', 'serca', 'serce', 'darowizna', 'darowizny',
    'dziecko', 'dzieci', 'nr', 'na', 'za', 'od', 'wplata', 'wplaty', 'przelew', 'cel', 'cele',
    'celu', 'misja', 'mada', 'fundacja', 'fundacji', 'imie', 'numer', 'pozytku', 'publicznego',
    'dzialalnosc', 'charytatywna', 'statutowy', 'statutowe', 'wspolna', 'wspolne', 'razem',
    'ref', 'tytulem', 'oplata', 'skladka', 'rata', 'miesiac', 'miesiace', 'mies', 'okres', 'rok'];

/**
 * Wyławia z tytułu przelewu wskazówkę o dziecku. Fundacja prosi o tytuł
 * „Adopcja Serca - darowizna - imię i numer dziecka" (np. „Kiady 23"),
 * ale w praktyce darczyńcy piszą różnie - stąd numer i imię osobno.
 * Zwraca ['numbers' => [23, ...], 'words' => ['kiady', ...]].
 */
function bank_title_hints(string $title): array {
    $norm = adopt_name_normalize($title);
    $numbers = [];
    if (preg_match_all('/\b(\d{1,3})\b/', $norm, $m)) {
        foreach ($m[1] as $n) {
            $n = (int)$n;
            if ($n > 0 && $n < 1000) $numbers[] = $n;
        }
    }
    $words = array_values(array_diff(explode(' ', $norm), BANK_TITLE_STOP));
    $words = array_values(array_filter($words, fn($w) => $w !== '' && !ctype_digit($w) && mb_strlen($w) >= 3));
    return ['numbers' => array_values(array_unique($numbers)), 'words' => $words];
}

/* ── Kto wpłacił: osoba wyjęta z pola nadawcy ──────────────────── */

/* Człony adresowe otwierające adres - od nich w polu nadawcy zaczyna się
   ulica, a kończy imię i nazwisko. „ELIXIR" to nazwa systemu rozliczeń,
   bank dokleja ją z datą na końcu każdego pola nadawcy. */
const BANK_ADDRESS_WORDS = ['ul', 'ulica', 'al', 'aleja', 'aleje', 'os', 'osiedle', 'osied',
                            'pl', 'plac', 'skr', 'poczt', 'elixir', 'sorbnet'];

/**
 * Pole nadawcy -> sama osoba, bez adresu i ogona systemowego.
 *
 * Bank wkłada w jedno pole wszystko naraz:
 *   „HELENA ŻANKOWSKA UL.GAGARINA 31 M.12 00-753 WARSZAWA ELIXIR 07-08-2026"
 * Porównywanie tego w całości z kartoteką działa dla trafień dokładnych, ale
 * podnosi ryzyko, że nazwa ulicy albo miasta trafi w cudze nazwisko. Ucinamy
 * na pierwszym członie adresowym albo pierwszym słowie z cyfrą i bierzemy
 * najwyżej cztery słowa - „Marta i Tomasz Kowalscy" mieści się w tym z zapasem.
 */
function bank_party_person(string $party): string {
    $norm = adopt_name_normalize($party);
    if ($norm === '') return '';
    $out = [];
    foreach (explode(' ', $norm) as $t) {
        if ($t === '') continue;
        if (in_array($t, BANK_ADDRESS_WORDS, true)) break;
        if (preg_match('/\d/', $t)) break;
        $out[] = $t;
        if (count($out) >= 4) break;
    }
    return implode(' ', $out);
}

/**
 * Darczyńcy, którzy mogą stać za tą operacją, od najpewniejszego.
 *
 * Dwa źródła, bo nazwisko bywa tylko w jednym z nich: oczyszczona nazwa
 * nadawcy oraz tytuł przelewu („WSPÓLNA DAROWIZNA OD HELENY I JANA
 * ŻANKOWSKICH..."). Poziom bierzemy z adopt_name_match():
 *
 *  - `exact` - te same tokeny albo krótszy zapis w całości zawarty w dłuższym
 *    („Krzysztof Miszkurka" w „MISZKURKA KRZYSZTOF NA POPIELÓWKĘ ...");
 *  - `fuzzy` - wspólne nazwisko albo literówka. Tu wpadają WSZYSCY darczyńcy
 *    zapisani w kartotece jako para („Helena i Jan Żankowscy" wobec nadawcy
 *    „HELENA ŻANKOWSKA"), a takich jest w kartotece sporo. Wcześniej ten
 *    wynik był liczony i wyrzucany, więc panel pisał im „brak tropu".
 *
 * Fuzzy nigdy nie zamyka sprawy samo z siebie - patrz bank_match_op().
 */
function bank_donor_candidates(array $op, array $donors, int $limit = 6): array {
    $person = bank_party_person((string)($op['party'] ?? ''));
    $hints  = bank_title_hints((string)($op['title'] ?? ''));
    $title  = implode(' ', $hints['words']);
    $out = [];
    foreach ($donors as $d) {
        $name = trim((string)($d['full_name'] ?? ''));
        if ($name === '') continue;
        $byParty = $person !== '' ? adopt_name_match($person, $name) : 'none';
        $byTitle = $title  !== '' ? adopt_name_match($title, $name)  : 'none';
        if     ($byParty === 'exact') $rank = 1;
        elseif ($byTitle === 'exact') $rank = 2;
        elseif ($byParty === 'fuzzy') $rank = 3;
        elseif ($byTitle === 'fuzzy') $rank = 4;
        else continue;
        $out[] = [
            'id'    => (int)$d['id'],
            'name'  => $name,
            'rank'  => $rank,
            'level' => $rank <= 2 ? 'exact' : 'fuzzy',
            'where' => ($rank === 1 || $rank === 3) ? 'nadawcy' : 'tytule',
        ];
    }
    usort($out, fn($a, $b) => [$a['rank'], $a['name']] <=> [$b['rank'], $b['name']]);
    return array_slice($out, 0, $limit);
}

/* ── Okres wpłaty deklarowany w tytule ─────────────────────────── */

/* Nazwy miesięcy po normalizacji (bez ogonków), mianownik i dopełniacz -
   darczyńcy piszą i „czerwiec", i „za czerwca". */
const BANK_MONTH_WORDS = [
    'styczen' => 1, 'stycznia' => 1, 'luty' => 2, 'lutego' => 2, 'marzec' => 3, 'marca' => 3,
    'kwiecien' => 4, 'kwietnia' => 4, 'maj' => 5, 'maja' => 5, 'czerwiec' => 6, 'czerwca' => 6,
    'lipiec' => 7, 'lipca' => 7, 'sierpien' => 8, 'sierpnia' => 8, 'wrzesien' => 9, 'wrzesnia' => 9,
    'pazdziernik' => 10, 'pazdziernika' => 10, 'listopad' => 11, 'listopada' => 11,
    'grudzien' => 12, 'grudnia' => 12,
];

/* Rzymskie bez „I" - samotne „i" to po polsku spójnik („czerwiec i lipiec"),
   a nie styczeń. Strata jest niewielka, pomyłka byłaby kosztowna. */
const BANK_MONTH_ROMAN = [
    'ii' => 2, 'iii' => 3, 'iv' => 4, 'v' => 5, 'vi' => 6, 'vii' => 7, 'viii' => 8,
    'ix' => 9, 'x' => 10, 'xi' => 11, 'xii' => 12,
];

/* Słowa, przy których pojedynczy miesiąc w tytule wolno wziąć na serio nawet
   bez roku („wpłata za lipiec"). */
const BANK_PERIOD_WORDS = ['za', 'okres', 'miesiac', 'miesiace', 'miesiecy', 'mies', 'skladka',
                           'rata', 'oplata', 'platnosc'];

/**
 * Okres zadeklarowany w tytule przelewu, albo [] gdy tytuł nic nie mówi.
 *
 * To NAJPEWNIEJSZE źródło okresu, bo pochodzi wprost od darczyńcy:
 * „Adopcja serca czerwiec, lipiec, sierpień, wrzesień, październik,
 * listopad, grudzień 2026" nie zostawia miejsca na domysły.
 *
 * $childNames chroni przed kolizją imienia z nazwą miesiąca: dziecko „Maja"
 * kontra dopełniacz „maja". Imię dziecka wygrywa - wpłata z imieniem w tytule
 * jest częstsza niż wpłata za sam maj.
 *
 * Zwraca ['from','to','months','list','gap'] - `gap` mówi, że wymienione
 * miesiące nie są ciągiem („styczeń i marzec"), więc zakres od-do obejmie
 * więcej, niż darczyńca napisał.
 */
function bank_title_months(string $title, string $opDate, array $childNames = []): array {
    $norm = adopt_name_normalize($title);
    if ($norm === '') return [];
    $skip = [];
    foreach ($childNames as $n) {
        $k = adopt_name_normalize((string)$n);
        if ($k !== '') $skip[$k] = true;
    }

    // Rok: bierzemy pod uwagę tylko wtedy, gdy w tytule stoi dokładnie jeden.
    preg_match_all('/\b(20\d{2})\b/', $norm, $ym);
    $years = array_values(array_unique($ym[1] ?? []));
    $year  = count($years) === 1 ? (int)$years[0] : null;

    $tokens = explode(' ', $norm);
    $hasPeriodWord = (bool)array_intersect($tokens, BANK_PERIOD_WORDS);

    $nums = [];
    foreach ($tokens as $t) {
        if (isset($skip[$t])) continue;
        if (isset(BANK_MONTH_WORDS[$t])) $nums[] = BANK_MONTH_WORDS[$t];
    }
    // Rzymskie tylko wtedy, gdy nazw miesięcy nie było - inaczej „V" z jakiegoś
    // skrótu potrafiłoby dopisać maj do wyliczonych słownie miesięcy.
    if (!$nums) {
        foreach ($tokens as $t) {
            if (isset(BANK_MONTH_ROMAN[$t])) $nums[] = BANK_MONTH_ROMAN[$t];
        }
    }
    // Zapis cyfrowy: „06-12/2026", „07/2026", „06.2026". Szukamy w oryginale,
    // bo normalizacja gubi ukośniki i kropki.
    if (!$nums && preg_match('~\b(0?[1-9]|1[0-2])\s*[-\x{2013}]\s*(0?[1-9]|1[0-2])\s*[/.\-]\s*(20\d{2})\b~u',
                             $title, $m)) {
        $a = (int)$m[1]; $b = (int)$m[2];
        if ($a <= $b) { $nums = range($a, $b); $year = (int)$m[3]; }
    }
    if (!$nums && preg_match('~\b(0?[1-9]|1[0-2])\s*[/.]\s*(20\d{2})\b~', $title, $m)) {
        $nums = [(int)$m[1]]; $year = (int)$m[2];
    }
    if (!$nums) return [];

    // Zakres słowny „czerwiec - grudzień": dwa miesiące rozdzielone myślnikiem
    // znaczą przedział, nie dwie osobne składki.
    if (count($nums) === 2 && $nums[0] < $nums[1]
        && preg_match('~[a-ząćęłńóśźż]\s*[-\x{2013}]\s*[a-ząćęłńóśźż]~ui', $title)) {
        $nums = range($nums[0], $nums[1]);
    }

    // Jeden miesiąc bez roku i bez słowa okresowego to za mało - „Maj" bywa
    // nazwiskiem, a „marca" fragmentem czegokolwiek.
    if (count($nums) === 1 && $year === null && !$hasPeriodWord) return [];

    $base = $year ?? (int)substr((string)(adopt_month_from_date($opDate) ?: date('Y-m')), 0, 4);
    $opM  = adopt_month_from_date($opDate);
    $list = [];
    foreach (array_unique($nums) as $n) {
        $y = $base;
        // Bez roku w tytule zgadujemy go z daty operacji: „za styczeń" napisane
        // w grudniu dotyczy stycznia następnego roku, „za grudzień" w styczniu
        // - grudnia poprzedniego.
        if ($year === null && $opM !== null) {
            $opNum = (int)substr($opM, 5, 2);
            if ($n - $opNum > 6) $y--;
            if ($opNum - $n > 6) $y++;
        }
        $list[] = sprintf('%04d-%02d', $y, $n);
    }
    sort($list);
    $list = array_values(array_unique($list));
    $from = $list[0];
    $to   = $list[count($list) - 1];
    return [
        'from'   => $from,
        'to'     => $to,
        'months' => count($list),
        'list'   => $list,
        'gap'    => count($list) !== adopt_month_count($from, $to),
    ];
}

/**
 * Propozycja dla jednej operacji.
 *
 * $ctx = [
 *   'children'  => [['id','number','name'], ...],
 *   'donors'    => [['id','full_name'], ...],
 *   'adoptions' => [['id','donor_id','child_id','amount_grosze','start_month',
 *                    'end_month','status','paid_until'], ...],
 *   'accounts'  => ['<klucz rachunku>' => donor_id, ...],   // zapamiętane konta
 * ]
 *
 * Zwraca ['kind' => 'payment'|'flow', 'confidence' => 'auto'|'suggest'|'none',
 *         'donor_id','adoption_id','child_id','months','period_from','period_to',
 *         'category','reason'].
 * 'auto' = zgadza się rachunek darczyńcy albo tytuł jednoznacznie wskazuje jego
 * adopcję; i tak wymaga kliknięcia „Zatwierdź" - to podpowiedź, nie zapis.
 */
function bank_match_op(array $op, array $ctx): array {
    $none = ['kind' => 'flow', 'confidence' => 'none', 'donor_id' => null, 'adoption_id' => null,
             'child_id' => null, 'months' => null, 'period_from' => null, 'period_to' => null,
             'category' => null, 'reason' => '', 'donor_candidates' => [], 'warn' => []];

    // Wydatki i zwroty nie są wpłatami darczyńców - idą do finansów.
    if ($op['amount_grosze'] < 0) {
        return array_merge($none, [
            'category' => bank_guess_category($op),
            'reason'   => 'wydatek - do rejestru przepływów',
        ]);
    }

    // Zbiorcza wypłata z bramki - patrz bank_is_gateway_settlement.
    if (bank_is_gateway_settlement($op)) {
        return array_merge($none, [
            'category' => 'inne',
            'reason'   => 'rozliczenie bramki płatniczej - te wpłaty są już w panelu, '
                        . 'tu tylko przepływ pieniędzy na konto',
        ]);
    }

    $hints = bank_title_hints($op['title'] ?? '');
    $donorId = null; $why = []; $warn = []; $soft = false;

    // 1. Zapamiętany rachunek - najpewniejszy trop.
    $accKey = $op['account_key'] ?? '';
    if ($accKey !== '' && isset($ctx['accounts'][$accKey])) {
        $donorId = (int)$ctx['accounts'][$accKey];
        $why[] = 'znany rachunek darczyńcy';
    }

    // 2. Nazwisko z nadawcy albo z tytułu vs kartoteka darczyńców.
    $cands  = bank_donor_candidates($op, $ctx['donors'] ?? []);
    $exacts = array_values(array_filter($cands, fn($c) => $c['level'] === 'exact'));
    $fuzzy  = array_values(array_filter($cands, fn($c) => $c['level'] === 'fuzzy'));
    if ($donorId === null && count($exacts) === 1) {
        $donorId = $exacts[0]['id'];
        $why[] = 'nazwisko z ' . $exacts[0]['where'] . ' zgodne z darczyńcą';
    } elseif ($donorId === null && !$exacts && count($fuzzy) === 1) {
        // Para w kartotece („Helena i Jan Żankowscy") albo literówka. Wskazujemy
        // darczyńcę, ale bez podnoszenia pewności - to zawsze idzie do sprawdzenia.
        $donorId = $fuzzy[0]['id'];
        $soft = true;
        $why[] = 'nazwisko w ' . $fuzzy[0]['where'] . ' przypomina darczyńcę '
               . $fuzzy[0]['name'] . ' - sprawdź';
    }

    // 3. Dziecko z tytułu przelewu (numer, a jeśli brak - imię).
    $childId = null; $childWhy = '';
    foreach ($ctx['children'] ?? [] as $c) {
        if (in_array((int)$c['number'], $hints['numbers'], true)) {
            $childId = (int)$c['id']; $childWhy = 'numer dziecka z tytułu';
            if (in_array(adopt_name_normalize((string)$c['name']), $hints['words'], true)) {
                $childWhy = 'numer i imię dziecka z tytułu';
                break;
            }
        }
    }
    if ($childId === null) {
        $hitsByName = [];
        foreach ($ctx['children'] ?? [] as $c) {
            if (in_array(adopt_name_normalize((string)$c['name']), $hints['words'], true)) $hitsByName[] = (int)$c['id'];
        }
        if (count($hitsByName) === 1) { $childId = $hitsByName[0]; $childWhy = 'imię dziecka z tytułu'; }
    }
    if ($childWhy !== '') $why[] = $childWhy;

    // 4. Adopcja: przecięcie darczyńcy i dziecka; każde z osobna też coś mówi.
    $active = array_values(array_filter($ctx['adoptions'] ?? [],
        fn($a) => in_array($a['status'] ?? 'active', ['pending', 'active'], true)));
    $adCands = $active;
    if ($donorId !== null) $adCands = array_values(array_filter($adCands, fn($a) => (int)$a['donor_id'] === $donorId));
    if ($childId !== null) {
        $byChild = array_values(array_filter($adCands, fn($a) => (int)($a['child_id'] ?? 0) === $childId));
        if ($byChild) $adCands = $byChild;
    }

    if (count($adCands) !== 1) {
        /* Nie umiemy wskazać jednej adopcji - decyduje człowiek. Dwa częste
           powody: nikogo nie rozpoznaliśmy albo rozpoznany darczyniec ma
           kilkoro dzieci (wtedy ekran pokazuje jego adopcje osobnymi wierszami
           i wpłatę da się rozdzielić). */
        $many = $donorId !== null && count($adCands) > 1;
        return array_merge($none, [
            'kind'       => $donorId !== null || $childId !== null ? 'payment' : 'flow',
            'confidence' => $donorId !== null || $childId !== null ? 'suggest' : 'none',
            'donor_id'   => $donorId,
            'child_id'   => $childId,
            'category'   => $donorId === null && $childId === null ? bank_guess_category($op) : null,
            'donor_candidates' => $donorId === null ? $cands : [],
            'reason'     => $why
                ? implode(', ', $why) . ($many
                    ? ' - darczyńca ma ' . count($adCands) . ' adopcje, rozdziel wpłatę'
                    : ' - wskaż adopcję')
                : 'brak tropu w tytule i nadawcy',
        ]);
    }

    $ad = $adCands[0];
    if ($donorId === null) { $donorId = (int)$ad['donor_id']; $why[] = 'darczyńca z adopcji dziecka'; }

    /* 5. Okres. Kolejność źródeł, od najpewniejszego:
       a) miesiące wymienione w tytule przelewu - deklaracja samego darczyńcy;
       b) kwota podzielona przez stawkę, licząc od pierwszego NIEPOKRYTEGO
          miesiąca. Wcześniej start liczył się od max(period_to) + 1, więc
          przeskakiwał dziury: ktoś zalegał od czerwca, wpłacał za siedem
          miesięcy, a panel proponował sierpień i czerwiec przepadał. */
    $rate   = (int)($ad['amount_grosze'] ?? 0);
    $sameCur = ($op['currency'] ?? 'PLN') === 'PLN';
    $months = null; $from = null; $to = null;

    // Ile miesięcy wynika z kwoty (tylko w złotych - stawka jest w PLN).
    $byAmount = null;
    if ($sameCur && $rate > 0 && $op['amount_grosze'] % $rate === 0) {
        $n = intdiv($op['amount_grosze'], $rate);
        if ($n >= 1 && $n <= 24) $byAmount = $n;
    }

    $fromTitle = bank_title_months((string)($op['title'] ?? ''), (string)($op['op_date'] ?? ''),
                                   array_column($ctx['children'] ?? [], 'name'));
    if ($fromTitle) {
        $from   = $fromTitle['from'];
        $to     = $fromTitle['to'];
        $months = $fromTitle['months'];
        $why[]  = 'okres wypisany w tytule przelewu';
        if (!empty($fromTitle['gap'])) {
            $warn[] = 'w tytule wymieniono ' . bank_months_label($months) . ', ale nie po kolei - '
                    . 'zakres od-do obejmie także ' . (adopt_month_count($from, $to) - $months)
                    . ' miesięcy, których darczyńca nie wymienił';
        }
        if ($byAmount !== null && $byAmount !== $months) {
            $warn[] = 'tytuł mówi o ' . $months . ' miesiącach, a kwota starcza na ' . $byAmount;
        }
    } elseif ($byAmount !== null) {
        $months = $byAmount;
        $from   = bank_first_unpaid($ad, (string)($op['op_date'] ?? ''));
        if ($from !== null) {
            $to = adopt_month_add($from, $months - 1);
            $why[] = 'kwota = ' . bank_months_label($months);
        }
    }

    if ($months === null) {
        $why[] = $sameCur
            ? 'kwota nie dzieli się przez stawkę - podaj okres ręcznie'
            : 'wpłata w ' . ($op['currency'] ?? '?') . ', a stawka w złotych - podaj okres ręcznie';
    }

    // Miesiące już opłacone, na które nachodzi propozycja.
    if ($from !== null && $to !== null && is_array($ad['payments'] ?? null)) {
        $dup = array_intersect(adopt_month_range($from, $to), adopt_coverage($ad['payments']));
        if ($dup) {
            $warn[] = count($dup) === 1
                ? 'miesiąc ' . reset($dup) . ' jest już opłacony'
                : 'miesiące ' . implode(', ', $dup) . ' są już opłacone';
        }
    }

    $exactAcc = $accKey !== '' && isset($ctx['accounts'][$accKey]);
    $strongTitle = $childWhy === 'numer i imię dziecka z tytułu';

    return [
        'kind'        => 'payment',
        // Niepewne nazwisko ($soft) i ostrzeżenia nigdy nie dają „auto".
        'confidence'  => !$soft && !$warn && ($exactAcc || $strongTitle) && $months !== null
                         ? 'auto' : 'suggest',
        'donor_id'    => $donorId,
        'adoption_id' => (int)$ad['id'],
        'child_id'    => $childId ?? (int)($ad['child_id'] ?? 0) ?: null,
        'months'      => $months,
        'period_from' => $from,
        'period_to'   => $to,
        'category'    => null,
        'donor_candidates' => [],
        'warn'        => $warn,
        'reason'      => implode(', ', $why),
    ];
}

/** Liczba miesięcy z odmienionym rzeczownikiem: 1 miesiąc, 3 miesiące, 7 miesięcy. */
function bank_months_label(int $n): string {
    $mod10 = $n % 10;
    $mod100 = $n % 100;
    if ($n === 1) return '1 miesiąc';
    if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) return "$n miesiące";
    return "$n miesięcy";
}

/**
 * Pierwszy miesiąc, za który adopcja nie jest opłacona.
 *
 * Liczy się z LUK w pokryciu (adopt_arrears), a nie z ostatniej opłaconej
 * daty - inaczej pojedyncza wpłata z wyprzedzeniem zasłaniałaby wszystkie
 * zaległości sprzed niej. Gdy kontekst nie niesie wpłat (starsze wywołania
 * i testy jednostkowe), spadamy na `paid_until` + 1.
 */
function bank_first_unpaid(array $ad, string $opDate): ?string {
    $start = (string)($ad['start_month'] ?? '');
    if (!adopt_month_valid($start)) $start = (string)(adopt_month_from_date($opDate) ?? '');
    $pays = $ad['payments'] ?? null;
    if (is_array($pays)) {
        if (adopt_month_valid($start)) {
            $miss = adopt_arrears($start, $ad['end_month'] ?? null, $pays, $opDate, true);
            if ($miss) return $miss[0];
        }
        $paid = adopt_paid_until($pays);
        if ($paid !== null) return adopt_month_add($paid, 1);
    } elseif (($ad['paid_until'] ?? null) !== null) {
        return adopt_month_add((string)$ad['paid_until'], 1);
    }
    return adopt_month_valid($start) ? $start : null;
}

/**
 * Kategoria przepływu zgadywana z kontrahenta i tytułu - podpowiedź do
 * listy rozwijanej na ekranie importu (kategorie jak w fin_flows).
 */
function bank_guess_category(array $op): string {
    // Wpływ z bramki to przesunięcie własnych pieniędzy, nie nowa darowizna
    // - kategoria „inne", żeby nie policzyć tych wpłat drugi raz w sumach.
    if (bank_is_gateway_settlement($op)) return 'inne';
    $s = adopt_name_normalize(($op['party'] ?? '') . ' ' . ($op['title'] ?? ''));

    /* Reguły osobno dla wpływów i wydatków, bo te same słowa znaczą co innego
       po każdej ze stron: „Adopcja Serca" we wpływie to darowizna darczyńcy,
       a w wydatku - pieniądze wysłane do misji. Wspólna lista wrzucała wpłatę
       240 GBP z tytułem „Adopcja Serca" do wypłat na Madagaskar. */
    $rules = ($op['amount_grosze'] ?? 0) < 0 ? [
        'wyplata_adopcja'       => ['siostry', 'sisters', 'madagaskar', 'mada mission', 'misja mada', 'adopcja'],
        'wyplata_jedzenie'      => ['jedzenie', 'food', 'posilki', 'wyzywienie'],
        'wyplata_studnia'       => ['studnia', 'well', 'woda'],
        'koszt_administracyjny' => ['oplata', 'prowizja', 'abonament', 'ksiegowosc', 'hosting', 'domena',
                                    'przelew wychodzacy oplata', 'payu'],
        'wymiana_walut'         => ['przewalutowanie', 'wymiana walut', 'kantor', 'fx'],
    ] : [
        'adopcja'               => ['adopcja', 'adoption', 'adopce'],
        'zbiorka'               => ['zbiorka', 'parafia', 'kolekta'],
        'wymiana_walut'         => ['przewalutowanie', 'wymiana walut', 'kantor'],
        'darowizna'             => ['darowizna', 'donation', 'cel statutowy'],
    ];
    foreach ($rules as $cat => $words) {
        foreach ($words as $w) if (str_contains($s, $w)) return $cat;
    }
    return ($op['amount_grosze'] ?? 0) < 0 ? 'inne' : 'darowizna';
}
