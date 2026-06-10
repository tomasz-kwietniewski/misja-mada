<?php
/* ═══════════════════════════════════════════════════════════════
   Endpoint danych wydarzeń (Fundacja Misja MADA)
   ───────────────────────────────────────────────────────────────
   Czyta data/wydarzenia/*.json i emituje JS:
     • window.MADA_EVENTS = [...]            (treści PL + status z daty)
     • dosypuje pary PL→EN / PL→FR do słowników i18n (window.MADA_I18N,
       window.MADA_I18N_FR) - dla wydarzeń z wygenerowanym tłumaczeniem.
   Zastępuje statyczny assets/wydarzenia-data.js. Ładować PO plikach
   i18n-dict*.js, a PRZED i18n.js.
  ═══════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/panel/lib.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS;

$events = mada_all_events();

// Sort: nadchodzące rosnąco po dacie, potem archiwum malejąco (front i tak filtruje).
usort($events, function ($a, $b) {
    $sa = mada_event_status($a); $sb = mada_event_status($b);
    if ($sa !== $sb) return $sa === 'nadchodzace' ? -1 : 1;
    $da = $a['dateISO'] ?? ''; $db = $b['dateISO'] ?? '';
    return $sa === 'nadchodzace' ? strcmp($da, $db) : strcmp($db, $da);
});

/** Buduje pary PL→tłumaczenie dla danego języka z pola i18n wydarzenia. */
function mada_pairs($e, $lang) {
    $out = [];
    $t = $e['i18n'][$lang] ?? null;
    if (!is_array($t)) return $out;
    $add = function ($pl, $tr) use (&$out) {
        // Klucz normalizowany tak jak w i18n.js (zwijanie białych znaków),
        // inaczej akapity z wewnętrznym enterem nie trafiają w słownik.
        $pl = preg_replace('/\s+/u', ' ', trim((string)$pl));
        $tr = (string)$tr;
        if ($pl !== '' && $tr !== '' && $pl !== $tr) $out[$pl] = $tr;
    };
    foreach (['title', 'lead', 'place', 'categoryLabel', 'dateLabel', 'masze'] as $k) {
        if (!empty($e[$k]) && !empty($t[$k])) $add($e[$k], $t[$k]);
    }
    if (!empty($e['summary']['label']) && !empty($t['summaryLabel'])) {
        $add($e['summary']['label'], $t['summaryLabel']);
    }
    if (!empty($e['body']) && !empty($t['body']) && is_array($t['body'])) {
        foreach ($e['body'] as $i => $p) {
            if (isset($t['body'][$i])) $add($p, $t['body'][$i]);
        }
    }
    if (!empty($e['media']) && !empty($t['media']) && is_array($t['media'])) {
        foreach ($e['media'] as $i => $m) {
            if (isset($t['media'][$i]['alt']))     $add($m['alt'] ?? '', $t['media'][$i]['alt']);
            if (isset($t['media'][$i]['caption'])) $add($m['caption'] ?? '', $t['media'][$i]['caption']);
        }
    }
    return $out;
}

$pub = [];
$dictEn = [];
$dictFr = [];

foreach ($events as $e) {
    $o = [
        'id'            => $e['id'],
        'featured'      => !empty($e['featured']),
        'status'        => mada_event_status($e),
        'title'         => $e['title'] ?? '',
        'dateISO'       => $e['dateISO'] ?? '',
        'dateLabel'     => $e['dateLabel'] ?? '',
        'year'          => mada_event_year($e),
        'category'      => $e['category'] ?? '',
        'categoryLabel' => $e['categoryLabel'] ?? '',
        'place'         => $e['place'] ?? '',
        'lead'          => $e['lead'] ?? '',
        'body'          => array_values($e['body'] ?? []),
        'media'         => array_map(function ($m) {
            return [
                'type'    => $m['type'] ?? 'image',
                'src'     => $m['src'] ?? '',
                'url'     => $m['url'] ?? '',
                'videoId' => $m['videoId'] ?? '',
                'alt'     => $m['alt'] ?? '',
                'caption' => $m['caption'] ?? '',
            ];
        }, array_values($e['media'] ?? [])),
    ];
    if (!empty($e['masze']))   $o['masze']   = $e['masze'];
    if (!empty($e['summary'])) $o['summary'] = $e['summary'];
    $pub[] = $o;

    $dictEn += mada_pairs($e, 'en');
    $dictFr += mada_pairs($e, 'fr');
}

echo "/* generowane przez events.js.php */\n";
echo "window.MADA_EVENTS = " . json_encode($pub, $JSON) . ";\n";
echo "window.MADA_CATEGORIES = " . json_encode(mada_categories(), $JSON) . ";\n";
echo "window.MADA_I18N = Object.assign({}, window.MADA_I18N || {}, " . json_encode($dictEn, $JSON) . ");\n";
echo "window.MADA_I18N_FR = Object.assign({}, window.MADA_I18N_FR || {}, " . json_encode($dictFr, $JSON) . ");\n";
