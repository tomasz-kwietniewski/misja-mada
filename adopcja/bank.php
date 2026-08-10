<?php
/* ═══ Wyciąg bankowy - parser i dopasowania ═══════════════════════
   CZYSTA LOGIKA: bez bazy i bez sieci, żeby dało się to testować
   (tests/run-bank.php) i żeby ekran importu tylko wyświetlał wynik.

   Źródło: eksport historii z bankowości Erste Bank Polska (dawny
   Santander; rachunki fundacji mają numer rozliczeniowy 1090).
   Bank daje CSV ze średnikiem w Windows-1250, ale UKŁAD KOLUMN
   POTRAFI SIĘ ZMIENIĆ przy zmianie systemu - dlatego parser nie
   zakłada kolejności, tylko rozpoznaje kolumny po nagłówkach
   (bank_map_columns) i sam wykrywa separator oraz kodowanie.

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
 * Czyta plik wyciągu do postaci [nagłówki, wiersze].
 * Obsługuje CSV/TXT (autodetekcja) i XLSX (pierwsza zakładka).
 * Pomija wiersze śmieciowe sprzed nagłówka (banki lubią nagłówek raportu).
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
 */
function bank_split_header(array $rows): array {
    foreach ($rows as $i => $r) {
        if (count($r) < 3) continue;
        $map = bank_map_columns($r);
        if (isset($map['date'], $map['amount'])) {
            return [$r, array_values(array_slice($rows, $i + 1))];
        }
    }
    return [[], []];
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

/** Numer rachunku do porównań: same cyfry (bez spacji i prefiksu kraju). */
function bank_account_key(string $raw): string {
    $s = preg_replace('/[^0-9A-Za-z]/', '', $raw);
    $s = preg_replace('/^PL/i', '', (string)$s);
    return preg_match('/^\d{16,26}$/', (string)$s) ? $s : '';
}

/**
 * Odcisk operacji - chroni przed zdublowaniem wpłat przy ponownym
 * wgraniu tego samego pliku albo zachodzących na siebie zakresach dat.
 */
function bank_op_hash(array $op): string {
    return sha1(implode('|', [
        $op['op_date'] ?? '', (string)($op['amount_grosze'] ?? ''), $op['currency'] ?? '',
        adopt_name_normalize((string)($op['title'] ?? '')),
        adopt_name_normalize((string)($op['party'] ?? '')),
        $op['account_key'] ?? '',
    ]));
}

/**
 * Surowe wiersze -> lista operacji [op_date, amount_grosze, currency, title,
 * party, account, account_key, op_hash, raw]. Wiersze bez daty albo bez kwoty
 * są pomijane (stopki, sumy, puste linie).
 */
function bank_rows_to_ops(array $headers, array $rows): array {
    $map = bank_map_columns($headers);
    if (!isset($map['date'], $map['amount'])) return [];
    $get = function (array $r, ?int $i): string {
        return $i === null ? '' : trim((string)($r[$i] ?? ''));
    };
    $ops = [];
    foreach ($rows as $r) {
        $date = bank_parse_date($get($r, $map['date'] ?? null));
        $amount = bank_parse_amount($get($r, $map['amount'] ?? null));
        if ($date === null || $amount === null || $amount === 0) continue;
        $acc = $get($r, $map['account'] ?? null);
        $op = [
            'op_date'       => $date,
            'amount_grosze' => $amount,
            'currency'      => strtoupper($get($r, $map['currency'] ?? null)) ?: 'PLN',
            'title'         => $get($r, $map['title'] ?? null),
            'party'         => $get($r, $map['party'] ?? null),
            'account'       => $acc,
            'account_key'   => bank_account_key($acc),
            'raw'           => $r,
        ];
        $op['op_hash'] = bank_op_hash($op);
        $ops[] = $op;
    }
    return $ops;
}

/* ── Dopasowanie do Adopcji Serca ──────────────────────────────── */

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
    $stop = ['adopcja', 'serca', 'darowizna', 'dziecko', 'dzieci', 'nr', 'na', 'za', 'wplata',
             'przelew', 'cel', 'misja', 'mada', 'fundacja', 'imie', 'numer'];
    $words = array_values(array_diff(explode(' ', $norm), $stop));
    $words = array_values(array_filter($words, fn($w) => $w !== '' && !ctype_digit($w) && mb_strlen($w) >= 3));
    return ['numbers' => array_values(array_unique($numbers)), 'words' => $words];
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
             'category' => null, 'reason' => ''];

    // Wydatki i zwroty nie są wpłatami darczyńców - idą do finansów.
    if ($op['amount_grosze'] < 0) {
        return array_merge($none, [
            'category' => bank_guess_category($op),
            'reason'   => 'wydatek - do rejestru przepływów',
        ]);
    }

    $hints = bank_title_hints($op['title'] ?? '');
    $donorId = null; $why = [];

    // 1. Zapamiętany rachunek - najpewniejszy trop.
    $accKey = $op['account_key'] ?? '';
    if ($accKey !== '' && isset($ctx['accounts'][$accKey])) {
        $donorId = (int)$ctx['accounts'][$accKey];
        $why[] = 'znany rachunek darczyńcy';
    }

    // 2. Nazwa nadawcy vs baza darczyńców.
    $byName = [];
    foreach ($ctx['donors'] ?? [] as $d) {
        $m = adopt_name_match((string)($op['party'] ?? ''), (string)$d['full_name']);
        if ($m !== 'none') $byName[$m][] = (int)$d['id'];
    }
    if ($donorId === null && count($byName['exact'] ?? []) === 1) {
        $donorId = $byName['exact'][0];
        $why[] = 'nazwa nadawcy zgodna z darczyńcą';
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
    $cands = $active;
    if ($donorId !== null) $cands = array_values(array_filter($cands, fn($a) => (int)$a['donor_id'] === $donorId));
    if ($childId !== null) {
        $byChild = array_values(array_filter($cands, fn($a) => (int)($a['child_id'] ?? 0) === $childId));
        if ($byChild) $cands = $byChild;
    }

    if (count($cands) !== 1) {
        // Nie umiemy wskazać jednej adopcji - niech zdecyduje człowiek.
        return array_merge($none, [
            'kind'       => $donorId !== null || $childId !== null ? 'payment' : 'flow',
            'confidence' => $donorId !== null || $childId !== null ? 'suggest' : 'none',
            'donor_id'   => $donorId,
            'child_id'   => $childId,
            'category'   => $donorId === null && $childId === null ? bank_guess_category($op) : null,
            'reason'     => $why ? implode(', ', $why) . ' - wskaż adopcję' : 'brak tropu w tytule i nadawcy',
        ]);
    }

    $ad = $cands[0];
    if ($donorId === null) { $donorId = (int)$ad['donor_id']; $why[] = 'darczyńca z adopcji dziecka'; }

    // 5. Okres: kwota podzielona przez stawkę adopcji = liczba miesięcy,
    //    licząc od pierwszego nieopłaconego (jak przy wpłacie zbiorczej).
    $rate = (int)($ad['amount_grosze'] ?? 0);
    $months = null; $from = null; $to = null;
    if ($rate > 0 && $op['amount_grosze'] % $rate === 0) {
        $n = intdiv($op['amount_grosze'], $rate);
        if ($n >= 1 && $n <= 24) {
            $months = $n;
            $paidUntil = $ad['paid_until'] ?? null;
            $from = $paidUntil !== null
                ? adopt_month_add($paidUntil, 1)
                : ($ad['start_month'] ?: adopt_month_from_date($op['op_date']));
            if ($from !== null) {
                $to = adopt_month_add($from, $n - 1);
                $why[] = $n === 1 ? 'kwota = 1 miesiąc' : "kwota = $n miesięcy";
            }
        }
    }
    if ($months === null) $why[] = 'kwota nie dzieli się przez stawkę - podaj okres ręcznie';

    $exactAcc = $accKey !== '' && isset($ctx['accounts'][$accKey]);
    $strongTitle = $childWhy === 'numer i imię dziecka z tytułu';

    return [
        'kind'        => 'payment',
        'confidence'  => ($exactAcc || $strongTitle) && $months !== null ? 'auto' : 'suggest',
        'donor_id'    => $donorId,
        'adoption_id' => (int)$ad['id'],
        'child_id'    => $childId ?? (int)($ad['child_id'] ?? 0) ?: null,
        'months'      => $months,
        'period_from' => $from,
        'period_to'   => $to,
        'category'    => null,
        'reason'      => implode(', ', $why),
    ];
}

/**
 * Kategoria przepływu zgadywana z kontrahenta i tytułu - podpowiedź do
 * listy rozwijanej na ekranie importu (kategorie jak w fin_flows).
 */
function bank_guess_category(array $op): string {
    $s = adopt_name_normalize(($op['party'] ?? '') . ' ' . ($op['title'] ?? ''));
    $rules = [
        'wyplata_adopcja'       => ['siostry', 'sisters', 'madagaskar', 'mada mission', 'misja mada', 'adopcja'],
        'wyplata_jedzenie'      => ['jedzenie', 'food', 'posilki', 'wyzywienie'],
        'wyplata_studnia'       => ['studnia', 'well', 'woda'],
        'koszt_administracyjny' => ['oplata', 'prowizja', 'abonament', 'ksiegowosc', 'hosting', 'domena',
                                    'przelew wychodzacy oplata', 'payu'],
        'wymiana_walut'         => ['przewalutowanie', 'wymiana walut', 'kantor', 'fx'],
        'zbiorka'               => ['zbiorka', 'parafia', 'kolekta'],
        'darowizna'             => ['darowizna', 'donation', 'cel statutowy'],
    ];
    foreach ($rules as $cat => $words) {
        foreach ($words as $w) if (str_contains($s, $w)) return $cat;
    }
    return ($op['amount_grosze'] ?? 0) < 0 ? 'inne' : 'darowizna';
}
