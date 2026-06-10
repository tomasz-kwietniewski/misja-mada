<?php
/* ═══════════════════════════════════════════════════════════════
   CMS - tłumaczenie wydarzeń przez DeepL API Free (+ glosariusz)
   ───────────────────────────────────────────────────────────────
   Klucz w panel/secret/deepl-config.php:  define('DEEPL_KEY', '...');
   Brak klucza => tłumaczenie pomijane (zapis działa, fallback PL).
  ═══════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/glossary.php';

const DEEPL_ENDPOINT = 'https://api-free.deepl.com/v2/translate';
const DEEPL_TARGETS  = ['en' => 'EN-GB', 'fr' => 'FR'];

function mada_deepl_key() {
    $f = __DIR__ . '/secret/deepl-config.php';
    if (is_readable($f)) { require_once $f; }
    return defined('DEEPL_KEY') ? trim(DEEPL_KEY) : '';
}

/**
 * Tłumaczy tablicę tekstów na język docelowy (kod DeepL, np. 'EN-GB','FR').
 * Zwraca tablicę przetłumaczonych tekstów (równoległą do wejścia).
 * Rzuca Exception przy błędzie/braku klucza.
 */
function mada_deepl_translate(array $texts, $targetCode) {
    if (!$texts) return [];
    $key = mada_deepl_key();
    if ($key === '') throw new Exception('Brak klucza DeepL.');

    $parts = ['source_lang=PL', 'target_lang=' . rawurlencode($targetCode)];
    foreach ($texts as $t) { $parts[] = 'text=' . rawurlencode($t); }
    $body = implode('&', $parts);

    $ch = curl_init(DEEPL_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => [
            'Authorization: DeepL-Auth-Key ' . $key,
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false)  throw new Exception('DeepL - błąd połączenia: ' . $err);
    if ($code !== 200)   throw new Exception('DeepL - HTTP ' . $code . ': ' . substr($res, 0, 200));

    $data = json_decode($res, true);
    if (!isset($data['translations']) || !is_array($data['translations'])) {
        throw new Exception('DeepL - nieoczekiwana odpowiedź.');
    }
    return array_map(function ($t) { return $t['text'] ?? ''; }, $data['translations']);
}

/** Zbiera tłumaczalne pola wydarzenia jako listę [ścieżka, tekstPL]. */
function mada_collect_translatable(array $event) {
    $list = [];
    $simple = ['title', 'lead', 'place', 'categoryLabel', 'dateLabel', 'masze'];
    foreach ($simple as $k) {
        if (!empty($event[$k]) && is_string($event[$k])) $list[] = [$k, $event[$k]];
    }
    if (!empty($event['summary']['label'])) $list[] = ['summaryLabel', $event['summary']['label']];
    if (!empty($event['body']) && is_array($event['body'])) {
        foreach ($event['body'] as $i => $p) {
            if (is_string($p) && $p !== '') $list[] = ['body.' . $i, $p];
        }
    }
    if (!empty($event['media']) && is_array($event['media'])) {
        foreach ($event['media'] as $i => $m) {
            if (!empty($m['alt']))     $list[] = ['media.' . $i . '.alt', $m['alt']];
            if (!empty($m['caption'])) $list[] = ['media.' . $i . '.caption', $m['caption']];
        }
    }
    return $list;
}

/** Wstawia wartość pod ścieżką typu "body.2" / "media.1.alt" do struktury. */
function mada_assign_path(array &$target, $path, $value) {
    $parts = explode('.', $path);
    if (count($parts) === 1) {
        $target[$parts[0]] = $value;
    } elseif ($parts[0] === 'body') {
        $target['body'][(int)$parts[1]] = $value;
    } elseif ($parts[0] === 'media') {
        $target['media'][(int)$parts[1]][$parts[2]] = $value;
    }
}

/* ── Pamięć tłumaczeń (cache) - oszczędza limit znaków DeepL ─────
   Plik data/translation-cache.json: { "EN-GB": {plText: tr}, "FR": {...} }.
   Identyczne/niezmienione frazy nie są wysyłane ponownie do DeepL. */
function mada_cache_path() { return MADA_DATA . '/translation-cache.json'; }

function mada_cache_load() {
    $p = mada_cache_path();
    if (!is_readable($p)) return [];
    $d = json_decode(file_get_contents($p), true);
    return is_array($d) ? $d : [];
}

function mada_cache_save($cache) {
    mada_ensure_dirs();
    $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return;
    $tmp = mada_cache_path() . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) !== false) @rename($tmp, mada_cache_path());
}

/**
 * Buduje tłumaczenia EN/FR dla wydarzenia (z pamięcią tłumaczeń).
 * $translator: opcjonalna atrapa fn(array $texts, string $targetCode): array (do testów).
 * Zwraca ['en'=>..., 'fr'=>...] albo null gdy tłumaczenie się nie powiodło / brak klucza.
 * Do DeepL trafiają TYLKO frazy spoza cache (zmienione/nowe) - oszczędność limitu.
 */
function mada_translate_event(array $event, ?callable $translator = null) {
    if ($translator === null) {
        if (mada_deepl_key() === '') return null;           // brak klucza - pomijamy
        $translator = 'mada_deepl_translate';
    }

    $list = mada_collect_translatable($event);
    if (!$list) return null;

    $cache = mada_cache_load();
    $cacheDirty = false;
    $i18n = [];
    try {
        foreach (DEEPL_TARGETS as $lang => $code) {
            $langCache = isset($cache[$code]) && is_array($cache[$code]) ? $cache[$code] : [];

            // tylko frazy spoza cache wysyłamy do DeepL (chronione glosariuszem)
            $missIdx = [];
            $missProtected = [];
            $missMaps = [];
            foreach ($list as $idx => [$path, $text]) {
                if (array_key_exists($text, $langCache)) continue;   // trafienie - 0 znaków
                [$pt, $map] = mada_glossary_protect($text);
                $missIdx[]            = $idx;
                $missProtected[]      = $pt;
                $missMaps[$idx]       = $map;
            }
            if ($missProtected) {
                $translated = $translator($missProtected, $code);
                if (count($translated) !== count($missProtected)) {
                    throw new Exception('DeepL - niezgodna liczba tłumaczeń.');
                }
                foreach ($missIdx as $j => $idx) {
                    $text = $list[$idx][1];
                    $langCache[$text] = mada_glossary_restore($translated[$j], $missMaps[$idx], $lang);
                    $cacheDirty = true;
                }
            }

            // złóż wynik z cache
            $out = [];
            foreach ($list as $idx => [$path, $text]) {
                mada_assign_path($out, $path, isset($langCache[$text]) ? $langCache[$text] : $text);
            }
            if (isset($out['body'])) { ksort($out['body']); $out['body'] = array_values($out['body']); }
            $cache[$code] = $langCache;
            $i18n[$lang]  = $out;
        }
    } catch (Exception $e) {
        error_log('[CMS translate] ' . $e->getMessage());
        return null;
    }
    if ($cacheDirty) mada_cache_save($cache);
    return $i18n;
}

/**
 * Tłumaczy wydarzenie i zapisuje i18n do jego pliku.
 * Zwraca: 'ok' (przetłumaczono), 'nokey' (brak klucza - pominięto),
 *         'fail' (klucz jest, ale tłumaczenie się nie powiodło).
 */
function mada_retranslate_and_store($id) {
    $event = mada_read_event($id);
    if ($event === null) return 'fail';
    $hasKey = mada_deepl_key() !== '';
    $i18n = mada_translate_event($event);
    if ($i18n === null) {
        return $hasKey ? 'fail' : 'nokey';
    }
    $event['i18n'] = $i18n;
    mada_write_event($id, $event);
    return 'ok';
}
