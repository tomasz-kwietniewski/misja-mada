<?php
/* ═══════════════════════════════════════════════════════════════
   Adopcja Serca - CZYSTA LOGIKA (bez bazy, bez sieci).
   ───────────────────────────────────────────────────────────────
   Miesiące pokrycia wpłat, zaległości, parser okresów z arkuszy
   fundacji, dopasowywanie nazwisk przy imporcie. Testy:
   php tests/run-adopcja.php
  ═══════════════════════════════════════════════════════════════ */

/* ── Miesiące 'YYYY-MM' (porównywalne leksykograficznie) ───────── */

/** Czy łańcuch to poprawny miesiąc 'YYYY-MM'. */
function adopt_month_valid(?string $ym): bool {
    if (!is_string($ym) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) return false;
    return true;
}

/** Miesiąc + n (może być ujemne). '2026-01' + 2 = '2026-03'. */
function adopt_month_add(string $ym, int $n): string {
    [$y, $m] = array_map('intval', explode('-', $ym));
    $idx = $y * 12 + ($m - 1) + $n;
    $ny = intdiv($idx, 12);
    $nm = ($idx % 12) + 1;
    if ($nm <= 0) { $nm += 12; $ny -= 1; }   // intdiv/% dla ujemnych
    return sprintf('%04d-%02d', $ny, $nm);
}

/** Lista miesięcy od..do włącznie (pusta, gdy do < od). */
function adopt_month_range(string $from, string $to): array {
    if (!adopt_month_valid($from) || !adopt_month_valid($to) || $to < $from) return [];
    $out = [];
    for ($m = $from; $m <= $to; $m = adopt_month_add($m, 1)) $out[] = $m;
    return $out;
}

/** Liczba miesięcy w zakresie włącznie (0 gdy zły zakres). */
function adopt_month_count(string $from, string $to): int {
    if (!adopt_month_valid($from) || !adopt_month_valid($to) || $to < $from) return 0;
    [$fy, $fm] = array_map('intval', explode('-', $from));
    [$ty, $tm] = array_map('intval', explode('-', $to));
    return ($ty * 12 + $tm) - ($fy * 12 + $fm) + 1;
}

/** Miesiąc z daty 'YYYY-MM-DD' (lub null). */
function adopt_month_from_date(string $date): ?string {
    if (preg_match('/^(\d{4}-\d{2})-\d{2}$/', $date, $m)) return adopt_month_valid($m[1]) ? $m[1] : null;
    return null;
}

/* ── Pokrycie wpłat ────────────────────────────────────────────── */

/**
 * Zbiór pokrytych miesięcy z listy wpłat (każda: period_from, period_to).
 * Zwraca posortowaną listę unikalnych 'YYYY-MM'.
 */
function adopt_coverage(array $payments): array {
    $set = [];
    foreach ($payments as $p) {
        $from = (string)($p['period_from'] ?? '');
        $to   = (string)($p['period_to'] ?? '');
        foreach (adopt_month_range($from, $to) as $m) $set[$m] = true;
    }
    ksort($set);
    return array_keys($set);
}

/** Ostatni opłacony miesiąc (max period_to) lub null. */
function adopt_paid_until(array $payments): ?string {
    $max = null;
    foreach ($payments as $p) {
        $to = (string)($p['period_to'] ?? '');
        if (adopt_month_valid($to) && ($max === null || $to > $max)) $max = $to;
    }
    return $max;
}

/**
 * Zaległości: miesiące NALEŻNE a niepokryte.
 * Należne = od start_month do min(end_month, POPRZEDNI miesiąc) - bieżący
 * miesiąc nie liczy się jako zaległość (2. dnia miesiąca nikt jeszcze nie
 * "zalega"; fundacja odhacza wpłaty w trakcie miesiąca). $includeCurrent=true
 * dolicza bieżący (do widoków "co jeszcze nieopłacone").
 * $today = 'YYYY-MM-DD' lub 'YYYY-MM'. Zwraca listę brakujących miesięcy.
 */
function adopt_arrears(string $startMonth, ?string $endMonth, array $payments, string $today,
                       bool $includeCurrent = false): array {
    $nowM = adopt_month_valid($today) ? $today : adopt_month_from_date($today);
    if ($nowM === null || !adopt_month_valid($startMonth)) return [];
    $dueTo = $includeCurrent ? $nowM : adopt_month_add($nowM, -1);
    if ($endMonth !== null && adopt_month_valid($endMonth) && $endMonth < $dueTo) $dueTo = $endMonth;
    $covered = array_flip(adopt_coverage($payments));
    $missing = [];
    foreach (adopt_month_range($startMonth, $dueTo) as $m) {
        if (!isset($covered[$m])) $missing[] = $m;
    }
    return $missing;
}

/* ── Parser okresów z arkuszy fundacji ─────────────────────────── */

/** 'MM.YYYY' / 'DD.MM.YYYY' / 'YYYY-MM' -> 'YYYY-MM' (lub null). */
function adopt_parse_month_token(string $tok): ?string {
    $tok = trim($tok, " .\t");
    if (preg_match('/^(\d{4})-(\d{2})$/', $tok, $m)) {
        $ym = $m[1] . '-' . $m[2];
        return adopt_month_valid($ym) ? $ym : null;
    }
    if (preg_match('/^(?:\d{1,2}\.)?(\d{1,2})\.(\d{4})$/', $tok, $m)) {
        $ym = sprintf('%04d-%02d', (int)$m[2], (int)$m[1]);
        return adopt_month_valid($ym) ? $ym : null;
    }
    return null;
}

/**
 * Parser kolumny "CZAS ADOPCJI" (formaty niespójne, z literówkami):
 *   "NIEOKREŚLONY", "OKREŚLONY (do 10.2026)", "OKREŚLONY (05.2026-04.2027)",
 *   "OKREŚLONNY (11.2025-11.2026)", "05.2026-04.2027", "do 30.06.2025",
 *   separatory "-", "–", "—".
 * Zwraca: ['duration' => 'indefinite'|'fixed'|null, 'start_month', 'end_month', 'warning'].
 * Nie zgaduje: gdy nie umie, duration=null + warning (do ręcznej decyzji).
 */
function adopt_parse_period(string $raw): array {
    $out = ['duration' => null, 'start_month' => null, 'end_month' => null, 'warning' => null];
    $s = trim($raw);
    if ($s === '') { $out['warning'] = 'pusty okres'; return $out; }

    $upper = mb_strtoupper($s, 'UTF-8');
    // NIEOKREŚLONY (dowolna liczba literówek w końcówce)
    if (str_contains($upper, 'NIEOKRE')) { $out['duration'] = 'indefinite'; return $out; }

    // Normalizacja separatorów zakresu na zwykły dywiz
    $norm = str_replace(["\xE2\x80\x93", "\xE2\x80\x94"], '-', $s);   // en/em dash -> '-'

    // Zakres "X - Y" (tokeny dat po obu stronach)
    if (preg_match('/(\d{1,2}\.\d{1,2}\.\d{4}|\d{1,2}\.\d{4})\s*-\s*(\d{1,2}\.\d{1,2}\.\d{4}|\d{1,2}\.\d{4})/u', $norm, $m)) {
        $from = adopt_parse_month_token($m[1]);
        $to   = adopt_parse_month_token($m[2]);
        if ($from !== null && $to !== null && $from <= $to) {
            $out['duration'] = 'fixed';
            $out['start_month'] = $from;
            $out['end_month'] = $to;
            return $out;
        }
    }

    // "do X" (tylko koniec)
    if (preg_match('/\bdo\s+(\d{1,2}\.\d{1,2}\.\d{4}|\d{1,2}\.\d{4})/iu', $norm, $m)) {
        $to = adopt_parse_month_token($m[1]);
        if ($to !== null) {
            $out['duration'] = 'fixed';
            $out['end_month'] = $to;
            return $out;
        }
    }

    // Samotny token daty przy słowie OKREŚLONY - za mało informacji
    $out['warning'] = 'nierozpoznany format okresu: ' . $s;
    return $out;
}

/* ── Częstotliwość / metoda z arkusza ──────────────────────────── */

function adopt_parse_frequency(string $raw): ?string {
    $u = mb_strtoupper(trim($raw), 'UTF-8');
    if ($u === '') return null;
    if (str_contains($u, 'MIESI')) return 'monthly';
    if (str_contains($u, 'KWART')) return 'quarterly';
    if (str_contains($u, 'ROCZ'))  return 'yearly';
    return null;
}

/* ── Macierz wpłat (GR1-5) -> zdarzenia wpłat ──────────────────── */

/** Wartość komórki macierzy -> grosze (int) lub null, gdy to nie kwota. */
function adopt_parse_amount_cell(string $cell): ?int {
    $t = trim(str_replace(["\xC2\xA0", ' '], '', $cell));   // nbsp/spacje w liczbach
    if ($t === '') return null;
    $t = str_replace(',', '.', $t);
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $t)) return null;
    return (int) round(((float)$t) * 100);
}

/**
 * Zwija wiersz macierzy (miesiąc 'YYYY-MM' => surowa komórka) do zdarzeń wpłat:
 * każde ciągłe pasmo kwot = jedno zdarzenie {period_from, period_to, amount_grosze}.
 * Komórki nienumeryczne trafiają do 'notes' (np. "do sierpnia 2026").
 */
function adopt_collapse_matrix_row(array $cells): array {
    ksort($cells);
    $events = [];
    $notes = [];
    $run = null;   // ['from','to','amount']
    $prev = null;
    foreach ($cells as $ym => $cell) {
        if (!adopt_month_valid((string)$ym)) continue;
        $g = adopt_parse_amount_cell((string)$cell);
        if ($g === null) {
            $txt = trim((string)$cell);
            if ($txt !== '') $notes[] = "$ym: $txt";
            // przerwa w paśmie
            if ($run !== null) { $events[] = $run; $run = null; }
            $prev = null;
            continue;
        }
        if ($run !== null && $prev !== null && adopt_month_add($prev, 1) === $ym) {
            $run['period_to'] = $ym;
            $run['amount_grosze'] += $g;
        } else {
            if ($run !== null) $events[] = $run;
            $run = ['period_from' => $ym, 'period_to' => $ym, 'amount_grosze' => $g];
        }
        $prev = $ym;
    }
    if ($run !== null) $events[] = $run;
    return ['events' => $events, 'notes' => $notes];
}

/* ── Dopasowywanie nazwisk (import plik1 <-> plik2) ────────────── */

/** Normalizacja do porównań: lowercase, bez ogonków, bez nawiasów i interpunkcji. */
function adopt_name_normalize(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = preg_replace('/\(.*?\)/u', ' ', $s);          // "(Owca)" itd.
    $map = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z'];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9 ]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** Tokeny znaczące nazwiska (bez spójników/tytułów, zdrobnienia -> forma pełna). */
function adopt_name_tokens(string $s): array {
    $stop = ['i', 'ks', 'o', 'sj', 'br', 's'];
    // częste zdrobnienia z arkuszy fundacji - normalizacja do formy pełnej
    $dimin = [
        'ola' => 'aleksandra', 'ania' => 'anna',   'kasia' => 'katarzyna',
        'zuzia' => 'zuzanna',  'piotrek' => 'piotr', 'krzysio' => 'krzysztof',
        'danusia' => 'danuta', 'gosia' => 'malgorzata', 'asia' => 'joanna',
    ];
    $toks = [];
    foreach (explode(' ', adopt_name_normalize($s)) as $t) {
        if ($t === '' || in_array($t, $stop, true)) continue;
        $toks[] = $dimin[$t] ?? $t;
    }
    return $toks;
}

/**
 * Dopasowanie dwóch zapisów osoby: 'exact' | 'fuzzy' | 'none'.
 *  - exact: te same zbiory tokenów (kolejność bez znaczenia)
 *  - fuzzy: wspólny znaczący token (>=4 znaki, np. nazwisko) lub tokeny
 *           różniące się literówką (Levenshtein <= 2)
 *  - none: nic wspólnego
 * Fuzzy ZAWSZE idzie do ręcznego potwierdzenia - funkcja tylko podpowiada.
 */
function adopt_name_match(string $a, string $b): string {
    $ta = adopt_name_tokens($a);
    $tb = adopt_name_tokens($b);
    if (!$ta || !$tb) return 'none';
    $sa = $ta; $sb = $tb;
    sort($sa); sort($sb);
    if ($sa === $sb) return 'exact';

    $common = 0; $fuzzyHit = false;
    foreach ($ta as $x) {
        foreach ($tb as $y) {
            if ($x === $y) { if (mb_strlen($x) >= 4) $common++; continue; }
            if (mb_strlen($x) >= 4 && mb_strlen($y) >= 4 && levenshtein($x, $y) <= 2) $fuzzyHit = true;
        }
    }
    // wszystkie tokeny krótszej listy zawarte w dłuższej -> traktuj jak exact
    // (np. "marta swiercz" vs "marta i tomek swiercz")
    $short = count($ta) <= count($tb) ? $ta : $tb;
    $long  = count($ta) <= count($tb) ? $tb : $ta;
    if ($common >= 1 && !array_diff($short, $long)) return 'exact';

    if ($common >= 1 || $fuzzyHit) return 'fuzzy';
    return 'none';
}

/* ── Pomocnicze dla panelu ─────────────────────────────────────── */

/** 'YYYY-MM' -> 'MM.YYYY' (format czytelny dla fundacji). */
function adopt_month_label(?string $ym): string {
    if ($ym === null || !adopt_month_valid($ym)) return '-';
    [$y, $m] = explode('-', $ym);
    return $m . '.' . $y;
}

/** E-maile z pola arkusza ("a@x; b@y, brak") -> [primary, extra|null]. */
function adopt_parse_emails(string $raw): array {
    $parts = preg_split('/[;,]/', $raw);
    $valid = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) $valid[] = $p;
    }
    if (!$valid) return [null, null];
    $primary = array_shift($valid);
    return [$primary, $valid ? implode('; ', $valid) : null];
}
