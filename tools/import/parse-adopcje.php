<?php
/* ═══════════════════════════════════════════════════════════════
   Adopcja Serca - PARSER migracyjny (uruchamiany LOKALNIE, nie na
   serwerze; katalog tools/ jest poza deployem).
   ───────────────────────────────────────────────────────────────
   Czyta dwa źródła fundacji:
     1. "LISTA WSZYSTKICH DARCZYŃCÓW....xlsx" (arkusz 1) - rejestr adopcji
     2. katalog z eksportami HTML zakładek "WPŁATY GR..." (macierze wpłat)
   i produkuje JEDEN plik JSON do wgrania przez panel/import.php.

   Uruchom:
     php -d extension=zip tools/import/parse-adopcje.php \
         --lista "docs/Adopcja Serca/LISTA....xlsx" \
         --platnosci "docs/Adopcja Serca/Platnosci" \
         --out import-adopcje.json

   Zasada: parser NIE ZGADUJE. Wszystko, czego nie umie jednoznacznie
   dopasować, ląduje w sekcji "pending" (ekran ręcznego łączenia).
  ═══════════════════════════════════════════════════════════════ */

if (PHP_SAPI !== 'cli') exit("Tylko CLI.\n");

require __DIR__ . '/../../adopcja/lib.php';

/* ── Argumenty ─────────────────────────────────────────────────── */
$opt = getopt('', ['lista:', 'platnosci:', 'out:']);
if (empty($opt['lista']) || empty($opt['platnosci']) || empty($opt['out'])) {
    exit("Użycie: php -d extension=zip tools/import/parse-adopcje.php --lista <xlsx> --platnosci <dir-html> --out <json>\n");
}
if (!class_exists('ZipArchive')) {
    exit("Brak rozszerzenia zip. Uruchom z flagą: php -d extension=zip ...\n");
}

/* ═══ 1. Minimalny czytnik XLSX (zip + XML, bez zależności) ═════ */

function xlsx_col_index(string $cellRef): int {
    preg_match('/^([A-Z]+)/', $cellRef, $m);
    $n = 0;
    foreach (str_split($m[1]) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

/** Zwraca wiersze arkusza 1 jako tablice indeksowane kolumnami (0-based). */
function xlsx_read_first_sheet(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) exit("Nie mogę otworzyć xlsx: $path\n");

    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $sx = new SimpleXMLElement($ss);
        foreach ($sx->si as $si) {
            if (isset($si->t)) { $shared[] = (string)$si->t; }
            else {   // rich text - sklej runy
                $txt = '';
                foreach ($si->r as $r) $txt .= (string)$r->t;
                $shared[] = $txt;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) exit("Brak xl/worksheets/sheet1.xml w $path\n");
    $zip->close();

    $sx = new SimpleXMLElement($sheetXml);
    $rows = [];
    foreach ($sx->sheetData->row as $row) {
        $r = [];
        foreach ($row->c as $c) {
            $idx = xlsx_col_index((string)$c['r']);
            $t = (string)$c['t'];
            if ($t === 's')            $val = $shared[(int)$c->v] ?? '';
            elseif ($t === 'inlineStr') $val = (string)$c->is->t;
            else                        $val = isset($c->v) ? (string)$c->v : '';
            $r[$idx] = trim($val);
        }
        $rows[(int)$row['r']] = $r;
    }
    return $rows;
}

/* ═══ 2. Plik 1: rejestr adopcji ════════════════════════════════ */

echo "Czytam listę darczyńców: {$opt['lista']}\n";
$rows = xlsx_read_first_sheet($opt['lista']);

$children  = [];   // number => ['number','name','notes']
$donors    = [];   // key (norm name) => ['key','full_name','email','emails_extra','notes']
$adoptions = [];   // ['donor_key','child_number','duration',...,'payments'=>[]]
$warnings  = [];

ksort($rows);
$header = array_shift($rows);   // wiersz 1: Lp | IMIĘ I NAZWISKO | e-mail | DZIECKO | Numer | CZAS | PŁATNOŚĆ | UWAGI

foreach ($rows as $rn => $r) {
    $name = trim($r[1] ?? '');
    if ($name === '') continue;
    $emailRaw  = trim($r[2] ?? '');
    $childName = trim($r[3] ?? '');
    $childNo   = trim($r[4] ?? '');
    $periodRaw = trim($r[5] ?? '');
    $freqRaw   = trim($r[6] ?? '');
    $uwagi     = trim($r[7] ?? '');

    // darczyńca (dedupe po znormalizowanym nazwisku)
    [$email, $extra] = adopt_parse_emails($emailRaw);
    $key = adopt_name_normalize($name);
    if (!isset($donors[$key])) {
        $donors[$key] = [
            'key' => $key, 'full_name' => trim($name),
            'email' => $email, 'emails_extra' => $extra, 'notes' => null,
        ];
    } else {
        // scal e-maile z kolejnych wierszy tej samej osoby
        $d = &$donors[$key];
        foreach (array_filter([$email, $extra]) as $e) {
            if ($e !== $d['email'] && !str_contains((string)$d['emails_extra'], $e)) {
                $d['emails_extra'] = $d['emails_extra'] ? $d['emails_extra'] . '; ' . $e : $e;
            }
        }
        unset($d);
    }

    // dziecko
    $no = null;
    if ($childNo !== '' && preg_match('/^\d+$/', $childNo)) {
        $no = (int)$childNo;
        if (!isset($children[$no])) {
            $children[$no] = ['number' => $no, 'name' => trim($childName), 'notes' => null];
        }
    } elseif ($childName !== '') {
        $warnings[] = "wiersz $rn: dziecko '$childName' bez numeru";
    }

    // okres
    $p = adopt_parse_period($periodRaw);
    if ($p['warning'] !== null && $periodRaw !== '') $warnings[] = "wiersz $rn ($name): {$p['warning']}";

    $adoptions[] = [
        'row'          => $rn,
        'donor_key'    => $key,
        'child_number' => $no,
        'duration'     => $p['duration'] ?? 'indefinite',
        'start_month'  => $p['start_month'],
        'end_month'    => $p['end_month'],
        'frequency'    => adopt_parse_frequency($freqRaw) ?? 'monthly',
        'amount_grosze'=> 7000,
        'method'       => 'transfer',
        'materials_sent' => false,
        'notes'        => $uwagi !== '' ? $uwagi : null,
        'period_raw'   => $periodRaw,
        'payments'     => [],
    ];
}
if (!$adoptions) exit("Nie znalazłem żadnych adopcji w pliku listy.\n");
echo '  adopcje: ' . count($adoptions) . ', dzieci: ' . count($children) . ', darczyńcy: ' . count($donors) . "\n";

/* ═══ 3. Macierze wpłat GR1-5 (HTML) ════════════════════════════ */

$MONTHS = [
    'styczen' => 1, 'luty' => 2, 'marzec' => 3, 'kwiecien' => 4, 'maj' => 5,
    'czerwiec' => 6, 'lipiec' => 7, 'sierpien' => 8, 'wrzesien' => 9,
    'pazdziernik' => 10, 'listopad' => 11, 'grudzien' => 12,
];

function html_table_rows(string $path): array {
    $html = file_get_contents($path);
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $rows = [];
    foreach ($dom->getElementsByTagName('tr') as $tr) {
        $cells = [];
        foreach ($tr->childNodes as $td) {
            if ($td->nodeName === 'td' || $td->nodeName === 'th') $cells[] = trim($td->textContent);
        }
        $rows[] = $cells;
    }
    return $rows;
}

/** Nazwa miesiąca (dowolna wielkość liter, z ogonkami lub bez, "KWIECIEÑ") -> 1-12 albo null. */
function month_from_label(string $s, array $MONTHS): ?int {
    $n = adopt_name_normalize($s);
    $n = str_replace('n~', 'n', $n);
    // "KWIECIEÑ" z eksportu (Ñ) -> kwiecien
    $n = strtr(mb_strtolower($s, 'UTF-8'), ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ñ'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z']);
    $n = preg_replace('/[^a-z]/', '', $n);
    return $MONTHS[$n] ?? null;
}

/** Rok startowy per plik (gdy w nagłówku brak wiersza z latami przy pierwszym miesiącu). */
$GROUP_START_YEAR = [
    'GR 1' => 2024, 'GR 2' => 2024, 'GR_3' => 2025, 'GR_4' => 2026, 'GR_5' => 2026,
];

$matrixRows = [];   // ['group','name','events'=>[],'notes'=>[],'first_month']
$grFiles = glob(rtrim($opt['platnosci'], '/\\') . '/WP*ATY*.html');
sort($grFiles);
if (!$grFiles) exit("Nie znalazłem plików WPŁATY GR*.html w {$opt['platnosci']}\n");

foreach ($grFiles as $file) {
    $base = basename($file);
    $group = 'GR?';
    if (preg_match('/GR[ _]?(\d)/u', $base, $m)) $group = 'GR' . $m[1];
    $rows = html_table_rows($file);

    // znajdź wiersz z nazwami miesięcy (ten z "Imię i nazwisko")
    $monthCols = [];    // index kolumny -> 'YYYY-MM'
    $nameCol = null; $headerIdx = null;
    foreach ($rows as $i => $cells) {
        foreach ($cells as $j => $c) {
            if (mb_stripos($c, 'nazwisko') !== false) { $nameCol = $j; $headerIdx = $i; break 2; }
        }
    }
    if ($nameCol === null) { echo "  POMIJAM $base - brak nagłówka\n"; continue; }

    // wiersz(e) nad nagłówkiem mogą nieść lata; zbierz mapę kolumna->rok
    $yearByCol = [];
    for ($i = 0; $i < $headerIdx; $i++) {
        foreach ($rows[$i] as $j => $c) {
            if (preg_match('/^(20\d\d)\b/', trim($c), $m)) $yearByCol[$j] = (int)$m[1];
        }
    }

    // domyślny rok startowy z konfiguracji per plik
    $year = null;
    foreach ($GROUP_START_YEAR as $pat => $y) {
        if (str_contains($base, $pat)) { $year = $y; break; }
    }

    $prevMonth = null;
    foreach ($rows[$headerIdx] as $j => $c) {
        if ($j <= $nameCol) continue;
        $mo = month_from_label($c, $MONTHS);
        if ($mo === null) continue;
        if (isset($yearByCol[$j])) $year = $yearByCol[$j];
        elseif ($prevMonth !== null && $mo < $prevMonth) $year++;   // przejście grudzień -> styczeń
        if ($year === null) continue;
        $monthCols[$j] = sprintf('%04d-%02d', $year, $mo);
        $prevMonth = $mo;
    }
    if (!$monthCols) { echo "  POMIJAM $base - nie rozpoznałem kolumn miesięcy\n"; continue; }

    // wiersze danych: Lp numeryczne + nazwisko
    $count = 0;
    for ($i = $headerIdx + 1; $i < count($rows); $i++) {
        $cells = $rows[$i];
        $name = trim($cells[$nameCol] ?? '');
        $lp   = trim($cells[$nameCol - 1] ?? '');
        if ($name === '' || !preg_match('/^\d+\.?$/', $lp)) continue;
        $byMonth = [];
        $extraNotes = [];
        foreach ($cells as $j => $c) {
            if (isset($monthCols[$j])) $byMonth[$monthCols[$j]] = $c;
            elseif ($j > $nameCol && trim($c) !== '') $extraNotes[] = trim($c);
        }
        $col = adopt_collapse_matrix_row($byMonth);
        if (!$col['events'] && !$extraNotes) continue;
        $matrixRows[] = [
            'group'  => $group,
            'file'   => $base,
            'lp'     => rtrim($lp, '.'),   // Lp z arkusza - odróżnia wiersze o identycznej treści
            'name'   => $name,
            'events' => $col['events'],
            'notes'  => array_merge($col['notes'], $extraNotes),
        ];
        $count++;
    }
    $span = array_values($monthCols);
    echo "  $base [$group]: $count wierszy, miesiące " . $span[0] . '..' . end($span) . "\n";
}

/* ═══ 4. Dopasowanie: wiersz macierzy -> darczyńca -> adopcja ═══ */

$pending = [];
$sumMatched = 0; $sumPending = 0;
$stats = ['exact' => 0, 'fuzzy' => 0, 'none' => 0];

// indeks adopcji per donor_key
$adoptIdx = [];
foreach ($adoptions as $i => $a) $adoptIdx[$a['donor_key']][] = $i;

foreach ($matrixRows as $mr) {
    $rowSum = array_sum(array_column($mr['events'], 'amount_grosze'));

    // dopasuj darczyńcę
    $matchKey = null; $matchKind = 'none';
    $mrKey = adopt_name_normalize($mr['name']);
    if (isset($donors[$mrKey])) { $matchKey = $mrKey; $matchKind = 'exact'; }
    else {
        $cands = [];
        foreach ($donors as $key => $d) {
            $kind = adopt_name_match($mr['name'], $d['full_name']);
            if ($kind !== 'none') $cands[$key] = $kind;
        }
        $exacts = array_keys($cands, 'exact', true);
        if (count($exacts) === 1) { $matchKey = $exacts[0]; $matchKind = 'exact'; }
        elseif ($cands) $matchKind = 'fuzzy';
        if ($matchKind === 'fuzzy') {
            // punktuj kandydatów (wspólne długie tokeny > literówki), pokaż max 5
            $scored = [];
            $mrToks = adopt_name_tokens($mr['name']);
            foreach ($cands as $key => $kind) {
                $score = $kind === 'exact' ? 100 : 0;
                foreach ($mrToks as $x) {
                    foreach (adopt_name_tokens($donors[$key]['full_name']) as $y) {
                        if ($x === $y && mb_strlen($x) >= 4) $score += 10;
                        elseif (mb_strlen($x) >= 4 && mb_strlen($y) >= 4 && levenshtein($x, $y) <= 2) $score += 3;
                    }
                }
                $scored[$key] = $score;
            }
            arsort($scored);
            $mr['candidates'] = array_map(
                fn($k) => $donors[$k]['full_name'],
                array_slice(array_keys($scored), 0, 5));
        }
    }
    $stats[$matchKind]++;

    if ($matchKind !== 'exact') {
        $mr['reason'] = $matchKind === 'fuzzy'
            ? 'niejednoznaczne nazwisko (kandydaci: ' . implode(' / ', $mr['candidates'] ?? []) . ')'
            : 'brak darczyńcy o tym nazwisku w pliku listy';
        $pending[] = ['kind' => 'payment-row', 'data' => $mr];
        $sumPending += $rowSum;
        continue;
    }

    // przypisz do adopcji darczyńcy
    $idxs = $adoptIdx[$matchKey] ?? [];
    $assigned = false;
    if (count($idxs) === 1) {
        adopt_import_attach($adoptions[$idxs[0]], $mr);
        $assigned = true;
    } elseif (count($idxs) === 2 && $rowSum > 0) {
        // 140/mies. przy dwóch adopcjach -> podział po połowie na każde dziecko
        $amounts = array_unique(array_map(
            fn($e) => (int)round($e['amount_grosze'] / adopt_month_count($e['period_from'], $e['period_to'])),
            $mr['events']));
        if ($amounts === [14000]) {
            foreach ($idxs as $ii) {
                $half = $mr;
                $half['events'] = array_map(function ($e) {
                    $e['amount_grosze'] = intdiv($e['amount_grosze'], 2);
                    return $e;
                }, $mr['events']);
                adopt_import_attach($adoptions[$ii], $half);
            }
            $assigned = true;
        }
    }
    if (!$assigned && count($idxs) > 1) {
        // kilka adopcji - spróbuj po oknie czasowym (start pasma w oknie dokładnie jednej adopcji)
        $first = $mr['events'][0]['period_from'] ?? null;
        if ($first !== null) {
            $hits = [];
            foreach ($idxs as $ii) {
                $a = $adoptions[$ii];
                $s = $a['start_month']; $e = $a['end_month'];
                $okS = $s === null || $s <= $first;
                $okE = $e === null || $e >= $first;
                if ($okS && $okE) $hits[] = $ii;
            }
            if (count($hits) === 1) { adopt_import_attach($adoptions[$hits[0]], $mr); $assigned = true; }
        }
    }
    if (!$assigned) {
        $mr['reason'] = 'darczyńca ma ' . count($idxs) . ' adopcje - nie umiem jednoznacznie przypisać wpłat';
        $mr['donor'] = $donors[$matchKey]['full_name'];
        $pending[] = ['kind' => 'payment-row', 'data' => $mr];
        $sumPending += $rowSum;
        continue;
    }
    $sumMatched += $rowSum;
}

/** Dokleja zdarzenia wpłat z wiersza macierzy do adopcji (in-place). */
function adopt_import_attach(array &$a, array $mr): void {
    foreach ($mr['events'] as $e) {
        $a['payments'][] = [
            'paid_at'      => $e['period_from'] . '-01',
            'period_from'  => $e['period_from'],
            'period_to'    => $e['period_to'],
            'amount_grosze'=> $e['amount_grosze'],
            'method'       => 'transfer',
            'note'         => 'import: ' . $mr['group'],
        ];
    }
    if ($mr['notes']) {
        $n = 'Wpłaty (' . $mr['group'] . '): ' . implode(' | ', $mr['notes']);
        $a['notes'] = $a['notes'] !== null ? $a['notes'] . ' | ' . $n : $n;
    }
    // kolumna GR1 "Info o dziecku - czy poszło (TAK/NIE)" -> flaga materiałów
    if (in_array('TAK', $mr['notes'], true)) $a['materials_sent'] = true;
    // start adopcji: gdy nieznany, przyjmij pierwszy opłacony miesiąc
    if ($a['start_month'] === null && $mr['events']) {
        $a['start_month'] = $mr['events'][0]['period_from'];
    }
}

/* start_month może zostać null (NIEOKREŚLONY bez dopasowanych wpłat) -
   uzupełni się automatycznie przy rozwiązaniu wiersza wpłat w panelu. */
$noStart = 0;
$final = [];
foreach ($adoptions as $a) {
    if ($a['start_month'] === null) $noStart++;
    unset($a['row'], $a['period_raw']);
    $final[] = $a;
}

/* ═══ 5. Sumy kontrolne + zapis ═════════════════════════════════ */

$sumAll = 0;
foreach ($final as $a) foreach ($a['payments'] as $p) $sumAll += $p['amount_grosze'];

$out = [
    'generated'  => 'parse-adopcje',
    'children'   => array_values($children),
    'donors'     => array_values($donors),
    'adoptions'  => $final,
    'pending'    => $pending,
    'warnings'   => $warnings,
    'checksums'  => [
        'children'          => count($children),
        'donors'            => count($donors),
        'adoptions'         => count($final),
        'matrix_rows'       => count($matrixRows),
        'adoptions_no_start'=> $noStart,
        'match_stats'       => $stats,
        'sum_matched_pln'   => $sumMatched / 100,
        'sum_pending_pln'   => $sumPending / 100,
        'sum_imported_pln'  => $sumAll / 100,
    ],
];

file_put_contents($opt['out'], json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "\nDopasowanie wierszy macierzy: exact {$stats['exact']}, fuzzy {$stats['fuzzy']}, none {$stats['none']}\n";
echo 'Do ręcznego łączenia (pending): ' . count($pending) . "\n";
if ($warnings) {
    echo "Ostrzeżenia parsera (" . count($warnings) . "):\n";
    foreach ($warnings as $w) echo "  - $w\n";
}
echo 'Suma zaimportowanych wpłat: ' . number_format($sumAll / 100, 2, ',', ' ') . " PLN";
echo ' (+ ' . number_format($sumPending / 100, 2, ',', ' ') . " PLN czeka w pending)\n";
echo "Zapisano: {$opt['out']}\n";
