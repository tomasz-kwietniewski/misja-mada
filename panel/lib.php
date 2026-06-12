<?php
/* ═══════════════════════════════════════════════════════════════
   CMS wydarzeń - wspólna biblioteka (Fundacja Misja MADA)
   ───────────────────────────────────────────────────────────────
   Ścieżki, odczyt/zapis JSON wydarzeń, slug, status-z-daty,
   walidacja i drobne helpery. Bez zależności zewnętrznych.

   Źródło prawdy: data/wydarzenia/<id>.json (prywatne, deny z weba).
   Zdjęcia:       uploads/wydarzenia/<id>/...  (publiczne).
  ═══════════════════════════════════════════════════════════════ */

define('MADA_BASE',     dirname(__DIR__));               // katalog główny strony
define('MADA_DATA',     MADA_BASE . '/data');
define('MADA_EVENTS_DIR', MADA_DATA . '/wydarzenia');
define('MADA_UPLOADS',  MADA_BASE . '/uploads/wydarzenia');
define('MADA_UPLOADS_URL', 'uploads/wydarzenia');        // ścieżka względna w treści strony

/* ── Kategorie wydarzeń (klucz => etykieta) - edytowalne w panelu ──
   Przechowywane w data/categories.json; przy braku pliku - zasiew domyślnych. */
function mada_categories_defaults() {
    return [
        'misja'    => 'Akcja misyjna',
        'kiermasz' => 'Kiermasz',
        'szkola'   => 'Spotkanie w szkole',
        'fundacja' => 'Życie fundacji',
    ];
}

function mada_categories_path() { return MADA_DATA . '/categories.json'; }

function mada_categories() {
    $p = mada_categories_path();
    if (is_readable($p)) {
        $d = json_decode(file_get_contents($p), true);
        if (is_array($d) && $d) return $d;
    }
    $def = mada_categories_defaults();
    mada_save_categories($def);   // zasiew przy pierwszym użyciu
    return $def;
}

function mada_save_categories($cats) {
    mada_ensure_dirs();
    $json = json_encode($cats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $p = mada_categories_path();
    $tmp = $p . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $p);
}

/** Ile wydarzeń należy do danej kategorii (kategoria grupuje dowolnie wiele). */
function mada_category_count($key) {
    $n = 0;
    foreach (glob(MADA_EVENTS_DIR . '/*.json') as $file) {
        $d = json_decode(file_get_contents($file), true);
        if (is_array($d) && ($d['category'] ?? '') === $key) $n++;
    }
    return $n;
}

/** Czy jakieś wydarzenie używa danej kategorii (do ochrony przed usunięciem). */
function mada_category_in_use($key) {
    return mada_category_count($key) > 0;
}

/** Polska odmiana słowa "wydarzenie" wg liczby. */
function mada_plural_events($n) {
    if ($n === 1) return 'wydarzenie';
    $m10 = $n % 10; $m100 = $n % 100;
    if ($m10 >= 2 && $m10 <= 4 && ($m100 < 10 || $m100 >= 20)) return 'wydarzenia';
    return 'wydarzeń';
}

/* ── Katalogi + ochrona /data/ (htaccess tworzony przez PHP, bo
      /data/ jest wykluczony z deployu i nie ma go w repo) ───────── */
function mada_ensure_dirs() {
    if (!is_dir(MADA_EVENTS_DIR)) @mkdir(MADA_EVENTS_DIR, 0755, true);
    if (!is_dir(MADA_UPLOADS))    @mkdir(MADA_UPLOADS, 0755, true);

    // Deny web dla całego /data/ (chroni JSON-y wydarzeń ORAZ tokeny newslettera)
    $ht = MADA_DATA . '/.htaccess';
    if (is_dir(MADA_DATA) && !file_exists($ht)) {
        @file_put_contents($ht,
            "# Katalog danych - niedostepny z weba\n" .
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n"
        );
    }
}

/* ── Slug (ASCII, bez polskich znaków) ─────────────────────────── */
function mada_slugify($title) {
    $map = [
        'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
        'Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ź'=>'z','Ż'=>'z',
    ];
    $s = strtr((string)$title, $map);
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^a-z0-9]+/u', '-', $s);  // wszystko poza [a-z0-9] -> myślnik
    $s = trim($s, '-');
    return $s !== '' ? $s : 'wydarzenie';
}

/** Slug unikalny w katalogu danych (pomija plik o id == $excludeId). */
function mada_unique_slug($title, $excludeId = null) {
    $base = mada_slugify($title);
    $slug = $base;
    $i = 2;
    while (file_exists(MADA_EVENTS_DIR . '/' . $slug . '.json') && $slug !== $excludeId) {
        $slug = $base . '-' . $i;
        $i++;
    }
    return $slug;
}

/** Walidacja bezpiecznego id (anty-traversal). */
function mada_valid_id($id) {
    return is_string($id) && preg_match('/^[a-z0-9][a-z0-9-]*$/', $id) === 1;
}

/* ── Odczyt / zapis wydarzeń ────────────────────────────────────── */
function mada_event_path($id) {
    return MADA_EVENTS_DIR . '/' . $id . '.json';
}

function mada_read_event($id) {
    if (!mada_valid_id($id)) return null;
    $p = mada_event_path($id);
    if (!is_readable($p)) return null;
    $data = json_decode(file_get_contents($p), true);
    return is_array($data) ? $data : null;
}

/** Zapis atomowy (temp + rename), JSON czytelny i z polskimi znakami. */
function mada_write_event($id, array $data) {
    if (!mada_valid_id($id)) return false;
    mada_ensure_dirs();
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $p   = mada_event_path($id);
    $tmp = $p . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $p);
}

function mada_delete_event($id) {
    if (!mada_valid_id($id)) return false;
    $p = mada_event_path($id);
    if (file_exists($p)) @unlink($p);
    // usuń katalog zdjęć wydarzenia
    $dir = MADA_UPLOADS . '/' . $id;
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') as $f) { @unlink($f); }
        @rmdir($dir);
    }
    return true;
}

/** Wszystkie wydarzenia jako tablica (z dołożonym 'id'). */
function mada_all_events() {
    mada_ensure_dirs();
    $out = [];
    foreach (glob(MADA_EVENTS_DIR . '/*.json') as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (is_array($data)) {
            $data['id'] = basename($file, '.json');
            $out[] = $data;
        }
    }
    return $out;
}

/** Zdejmuje flagę featured ze wszystkich wydarzeń poza $exceptId (maks. 1 wyróżnione). */
function mada_clear_other_featured($exceptId) {
    foreach (glob(MADA_EVENTS_DIR . '/*.json') as $file) {
        $id = basename($file, '.json');
        if ($id === $exceptId) continue;
        $data = json_decode(file_get_contents($file), true);
        if (is_array($data) && !empty($data['featured'])) {
            $data['featured'] = false;
            mada_write_event($id, $data);
        }
    }
}

/* ── Status wyliczany z daty (bez ręcznego pola) ───────────────── */
/** 'nadchodzace' gdy dateISO >= dzisiaj (do końca dnia wydarzenia), inaczej 'archiwum'. */
function mada_event_status($event) {
    $d = isset($event['dateISO']) ? trim((string)$event['dateISO']) : '';
    if ($d === '') return 'archiwum';
    return ($d >= date('Y-m-d')) ? 'nadchodzace' : 'archiwum';
}

function mada_event_year($event) {
    $d = isset($event['dateISO']) ? (string)$event['dateISO'] : '';
    return preg_match('/^(\d{4})/', $d, $m) ? $m[1] : '';
}

/* ── Helpery ────────────────────────────────────────────────────── */
function mada_esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function mada_redirect($url) {
    header('Location: ' . $url);
    exit;
}

/* ═══════════════════════════════════════════════════════════════
   Sprawozdania (PDF) - manifest + pliki
   ───────────────────────────────────────────────────────────────
   Źródło prawdy: data/sprawozdania.json (prywatne, deny z weba).
   Pliki nowe:    uploads/sprawozdania/<plik>.pdf (publiczne).
   Pliki stare 2024: referencja do media/*.pdf (z repo) - nie ruszamy.
  ═══════════════════════════════════════════════════════════════ */
define('MADA_SPRAW_FILE',        MADA_DATA . '/sprawozdania.json');
define('MADA_SPRAW_UPLOADS',     MADA_BASE . '/uploads/sprawozdania');
define('MADA_SPRAW_UPLOADS_URL', 'uploads/sprawozdania');   // ścieżka względna w treści strony

/** Dwa dozwolone typy sprawozdań. */
function mada_spraw_types() { return ['finansowe', 'merytoryczne']; }

/** Domyślna zawartość manifestu - seed wskazujący istniejące PDF-y 2024 w media/. */
function mada_sprawozdania_defaults() {
    return [
        'finansowe' => [
            ['year' => 2024, 'file' => 'media/Sprawozdanie_finansowe_2024.pdf', 'title' => 'Sprawozdanie finansowe'],
        ],
        'merytoryczne' => [
            ['year' => 2024, 'file' => 'media/Sprawozdanie_z_dzialalnosci_2024.pdf', 'title' => 'Sprawozdanie z działalności'],
        ],
    ];
}

function mada_valid_spraw_type($t) { return in_array($t, mada_spraw_types(), true); }

function mada_valid_spraw_year($y) {
    if (!ctype_digit((string)$y)) return false;
    $n = (int)$y;
    return $n >= 2000 && $n <= 2100;
}

/** Odczyt manifestu; seed domyślnych przy pierwszym użyciu. Gwarantuje oba klucze. */
function mada_sprawozdania() {
    if (is_readable(MADA_SPRAW_FILE)) {
        $d = json_decode(file_get_contents(MADA_SPRAW_FILE), true);
        if (is_array($d)) {
            foreach (mada_spraw_types() as $t) {
                if (!isset($d[$t]) || !is_array($d[$t])) $d[$t] = [];
            }
            return $d;
        }
    }
    $def = mada_sprawozdania_defaults();
    mada_save_sprawozdania($def);
    return $def;
}

/** Zapis atomowy manifestu (temp + rename), JSON czytelny i z polskimi znakami. */
function mada_save_sprawozdania($data) {
    mada_ensure_dirs();
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $tmp = MADA_SPRAW_FILE . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, MADA_SPRAW_FILE);
}

/** Kopia manifestu z każdą listą posortowaną malejąco po roku. */
function mada_sprawozdania_sorted() {
    $d = mada_sprawozdania();
    foreach (mada_spraw_types() as $t) {
        usort($d[$t], function ($a, $b) { return ((int)($b['year'] ?? 0)) <=> ((int)($a['year'] ?? 0)); });
    }
    return $d;
}

/** Katalog uploadów PDF + .htaccess blokujący wykonanie skryptów (PDF-y są publiczne). */
function mada_spraw_ensure_dir() {
    if (!is_dir(MADA_SPRAW_UPLOADS)) @mkdir(MADA_SPRAW_UPLOADS, 0755, true);
    $ht = MADA_SPRAW_UPLOADS . '/.htaccess';
    if (is_dir(MADA_SPRAW_UPLOADS) && !file_exists($ht)) {
        // Bez php_flag (psułoby się pod PHP-FPM). Po prostu deny dla plików-skryptów.
        @file_put_contents($ht,
            "# PDF sprawozdan - katalog statyczny; blokada plikow-skryptow\n" .
            "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|php8|phps|pl|py|cgi|asp|sh)$\">\n" .
            "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n" .
            "  <IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n  </IfModule>\n" .
            "</FilesMatch>\n"
        );
    }
}

/** Kasuje plik PDF TYLKO jeśli leży w uploads/sprawozdania/ (plików z media/ nie ruszamy). */
function mada_spraw_delete_file($relPath) {
    $relPath = (string)$relPath;
    $prefix = MADA_SPRAW_UPLOADS_URL . '/';
    if (strpos($relPath, $prefix) !== 0) return false;
    $name = basename($relPath);
    if ($name === '' || strpos($name, '..') !== false) return false;
    $abs = MADA_SPRAW_UPLOADS . '/' . $name;
    if (is_file($abs)) @unlink($abs);
    return true;
}
