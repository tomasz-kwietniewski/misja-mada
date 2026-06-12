<?php
/* ═══════════════════════════════════════════════════════════════
   CMS - glosariusz terminów (Fundacja Misja MADA)
   ───────────────────────────────────────────────────────────────
   DeepL Free nie gwarantuje glosariusza, więc terminy chronimy
   „ręcznie": przed tłumaczeniem podmieniamy je na żetony (tokeny),
   a po tłumaczeniu wstawiamy kanoniczne tłumaczenie dla danego języka.
   Dzięki temu nazwy własne i terminy religijne są zawsze spójne.
  ═══════════════════════════════════════════════════════════════ */

/** Lista terminów: 'pl' (reprezentatywna forma, do sortowania) => kanoniczne 'en'/'fr'.
 *  Opcjonalne 'rx' = wzorzec regex (bez ograniczników) łapiący ODMIENIONE formy
 *  polskie (np. „Adopcji Serca", „Centrum Edukacyjnym"). Dopasowanie od najdłuższych.
 *
 *  ŹRÓDŁO PRAWDY dla nazw własnych. Formy FR są zatwierdzone przez Panią Prezes
 *  (autorytet językowy francuskiego). Przy zmianie nazwy aktualizuj TU oraz w
 *  słownikach statycznych assets/i18n-dict.js (EN) i assets/i18n-dict-fr.js (FR),
 *  żeby tłumaczenia wydarzeń i stron były spójne. Kanoniczne FR:
 *    Adopcja Serca   → „Adoption de Cœur"     (nie „du Cœur")
 *    Centrum Eduk.   → „Centre d'Éducation"   (nie „Centre Éducatif") */
function mada_glossary() {
    return [
        ['pl' => 'Siostry Małe Misjonarki Miłosierdzia', 'rx' => 'Si(?:ostry|óstr) Mał(?:e|ych) Misjonark(?:i|ek) Miłosierdzia', 'en' => 'Little Missionary Sisters of Charity', 'fr' => 'Petites Sœurs Missionnaires de la Charité'],
        ['pl' => 'Małe Misjonarki Miłosierdzia',          'rx' => 'Mał(?:e|ych) Misjonark(?:i|ek) Miłosierdzia',                  'en' => 'Little Missionary Sisters of Charity', 'fr' => 'Petites Sœurs Missionnaires de la Charité'],
        ['pl' => 'Siostry Orionistki',                    'rx' => 'Si(?:ostry|óstr) Orionist(?:ki|ek)',                            'en' => 'Orionine Sisters',                     'fr' => 'Sœurs Orionines'],
        ['pl' => 'Fundacja Misja MADA',                   'rx' => 'Fundacj\p{L}+ Misja MADA',                                      'en' => 'Misja MADA Foundation',                'fr' => 'Fondation Misja MADA'],
        ['pl' => 'Misja MADA',                                                                                                     'en' => 'Misja MADA',                           'fr' => 'Misja MADA'],
        ['pl' => 'Adopcja Serca',                         'rx' => 'Adopcj\p{L}+ Serca',                                            'en' => 'Heart Adoption',                       'fr' => 'Adoption de Cœur'],
        ['pl' => 'Centrum Edukacyjne',                    'rx' => 'Centrum Edukacyjn\p{L}+',                                       'en' => 'Educational Centre',                   'fr' => "Centre d'Éducation"],
        ['pl' => 'Msze Święte',                           'rx' => 'Msz(?:e|y) Święt(?:e|ych)',                                     'en' => 'Holy Masses',                          'fr' => 'saintes messes'],
        ['pl' => 'Msza Święta',                           'rx' => 'Msz(?:a|y|ę|ą) Święt(?:a|ej|ą|ej)|Mszy Św\.',                   'en' => 'Holy Mass',                            'fr' => 'sainte messe'],
        ['pl' => 'Madagaskar',                            'rx' => 'Madagaskar\p{L}*',                                              'en' => 'Madagascar',                           'fr' => 'Madagascar'],
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
        // Rdzeń: wzorzec 'rx' (łapie odmianę) albo dosłowna forma 'pl'.
        $core = isset($term['rx']) ? '(?:' . $term['rx'] . ')' : preg_quote($term['pl'], '/');
        // Granice litery, by nie ciąć wewnątrz innego wyrazu.
        $pattern = '/(?<!\p{L})' . $core . '(?!\p{L})/u';
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
