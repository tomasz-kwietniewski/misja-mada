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

/* ── Kategorie wydarzeń (klucz => etykieta domyślna) ───────────── */
function mada_categories() {
    return [
        'misja'    => 'Akcja misyjna',
        'kiermasz' => 'Kiermasz',
        'szkola'   => 'Spotkanie w szkole',
        'fundacja' => 'Życie fundacji',
    ];
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
