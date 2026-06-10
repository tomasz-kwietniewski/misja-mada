<?php
/* ═══════════════════════════════════════════════════════════════
   CMS - glosariusz terminów (Fundacja Misja MADA)
   ───────────────────────────────────────────────────────────────
   DeepL Free nie gwarantuje glosariusza, więc terminy chronimy
   „ręcznie": przed tłumaczeniem podmieniamy je na żetony (tokeny),
   a po tłumaczeniu wstawiamy kanoniczne tłumaczenie dla danego języka.
   Dzięki temu nazwy własne i terminy religijne są zawsze spójne.
  ═══════════════════════════════════════════════════════════════ */

/** Lista terminów: 'pl' (forma źródłowa) => kanoniczne 'en'/'fr'.
 *  Kolejność nie ma znaczenia - dopasowanie idzie od najdłuższych. */
function mada_glossary() {
    return [
        ['pl' => 'Siostry Małe Misjonarki Miłosierdzia', 'en' => 'Little Missionary Sisters of Charity', 'fr' => 'Petites Sœurs Missionnaires de la Charité'],
        ['pl' => 'Małe Misjonarki Miłosierdzia',          'en' => 'Little Missionary Sisters of Charity', 'fr' => 'Petites Sœurs Missionnaires de la Charité'],
        ['pl' => 'Siostry Orionistki',                    'en' => 'Orionine Sisters',                     'fr' => 'Sœurs Orionines'],
        ['pl' => 'Fundacja Misja MADA',                   'en' => 'Misja MADA Foundation',                'fr' => 'Fondation Misja MADA'],
        ['pl' => 'Misja MADA',                            'en' => 'Misja MADA',                           'fr' => 'Misja MADA'],
        ['pl' => 'Adopcja Serca',                         'en' => 'Heart Adoption',                       'fr' => 'Adoption du Cœur'],
        ['pl' => 'Centrum Edukacyjne',                    'en' => 'Educational Centre',                   'fr' => 'Centre éducatif'],
        ['pl' => 'Msze Święte',                           'en' => 'Holy Masses',                          'fr' => 'saintes messes'],
        ['pl' => 'Mszy Świętych',                         'en' => 'Holy Masses',                          'fr' => 'saintes messes'],
        ['pl' => 'Msza Święta',                           'en' => 'Holy Mass',                            'fr' => 'sainte messe'],
        ['pl' => 'Mszy Świętej',                          'en' => 'Holy Mass',                            'fr' => 'sainte messe'],
        ['pl' => 'Mszy Św.',                              'en' => 'Holy Mass',                            'fr' => 'sainte messe'],
        ['pl' => 'Madagaskar',                            'en' => 'Madagascar',                           'fr' => 'Madagascar'],
    ];
}

/** Token nieingerujący w tłumaczenie (DeepL przepuszcza takie ciągi). */
function mada_glossary_token($i) {
    return 'MADXGLOS' . $i . 'X';
}

/**
 * Chroni terminy: podmienia wystąpienia na tokeny.
 * Zwraca [tekstZTokenami, mapaTokenów] gdzie mapa: token => indeks terminu.
 * Dopasowanie od najdłuższych terminów, by uniknąć kolizji.
 */
function mada_glossary_protect($text) {
    $terms = mada_glossary();
    // sortuj malejąco po długości formy PL
    uasort($terms, function ($a, $b) { return mb_strlen($b['pl']) - mb_strlen($a['pl']); });

    $map = [];
    $i = 0;
    foreach ($terms as $idx => $term) {
        $pl = $term['pl'];
        // Dopasowanie tylko do CAŁYCH wyrazów (granice litery), by nie ciąć
        // wewnątrz odmienionych form (np. "Madagaskar" w "Madagaskarze").
        $pattern = '/(?<!\p{L})' . preg_quote($pl, '/') . '(?!\p{L})/u';
        $token = mada_glossary_token($i);
        $count = 0;
        $new = preg_replace($pattern, $token, $text, -1, $count);
        if ($new !== null && $count > 0) {
            $text = $new;
            $map[$token] = $idx;
            $i++;
        }
    }
    return [$text, $map];
}

/** Przywraca tokeny jako kanoniczne tłumaczenie dla języka ('en'|'fr'). */
function mada_glossary_restore($text, $map, $lang) {
    $terms = mada_glossary();
    foreach ($map as $token => $idx) {
        $repl = $terms[$idx][$lang] ?? $terms[$idx]['pl'];
        $text = str_replace($token, $repl, $text);
    }
    return $text;
}
