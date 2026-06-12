<?php
/* ═══════════════════════════════════════════════════════════════
   Endpoint danych sprawozdań (Fundacja Misja MADA)
   ───────────────────────────────────────────────────────────────
   Czyta data/sprawozdania.json i emituje JS:
     window.MADA_SPRAWOZDANIA = { finansowe:[...], merytoryczne:[...] }
   Listy posortowane malejąco po roku. Ładować PRZED
   assets/sprawozdania-render.js (oba PRZED i18n.js).
  ═══════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/panel/lib.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS;

$data = mada_sprawozdania_sorted();

$out = [];
foreach (mada_spraw_types() as $t) {
    $out[$t] = array_map(function ($it) {
        return [
            'year'  => (int)($it['year'] ?? 0),
            'file'  => (string)($it['file'] ?? ''),
            'title' => (string)($it['title'] ?? ''),
        ];
    }, $data[$t]);
}

echo "/* generowane przez sprawozdania.js.php */\n";
echo "window.MADA_SPRAWOZDANIA = " . json_encode($out, $JSON) . ";\n";
