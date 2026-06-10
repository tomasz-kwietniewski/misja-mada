<?php
/* ═══ CMS - operacje na galerii wydarzenia (POST) ════════════════
   op = embed | save | up | down | del
   Embed: tylko YouTube i Facebook (allowlista domen, parsowanie ID).
  ═══════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/auth.php';
mada_require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { mada_redirect('index.php'); }
mada_csrf_check();

$id = $_POST['id'] ?? '';
$event = mada_valid_id($id) ? mada_read_event($id) : null;
if ($event === null) { mada_redirect('index.php?msg=nofound'); }

function media_back($id, $code) {
    mada_redirect('edit.php?id=' . urlencode($id) . '&gmsg=' . $code . '#galeria');
}

/** Wyciąga 11-znakowy identyfikator filmu YouTube z różnych form URL. */
function parse_youtube($url) {
    $url = trim($url);
    if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/|v/))([A-Za-z0-9_-]{11})~', $url, $m)) {
        return $m[1];
    }
    return null;
}

/** Waliduje URL filmu Facebooka (po domenie). Zwraca oczyszczony URL albo null. */
function parse_facebook($url) {
    $url = trim($url);
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
    $host = preg_replace('/^www\./', '', $host);
    $allowed = ['facebook.com', 'fb.watch', 'm.facebook.com', 'web.facebook.com'];
    if (in_array($host, $allowed, true) && preg_match('~^https?://~', $url)) {
        return $url;
    }
    return null;
}

$op = $_POST['op'] ?? '';
$media = (isset($event['media']) && is_array($event['media'])) ? $event['media'] : [];

/* ── Dodanie embedu (osobny formularz) ─────────────────────────── */
if ($op === 'embed') {
    $url = trim((string)($_POST['url'] ?? ''));
    if ($url === '') { media_back($id, 'embedempty'); }

    $yt = parse_youtube($url);
    if ($yt !== null) {
        $media[] = ['type' => 'youtube', 'videoId' => $yt,
                    'url' => 'https://www.youtube.com/watch?v=' . $yt,
                    'alt' => '', 'caption' => '', 'key' => bin2hex(random_bytes(6))];
        $event['media'] = $media;
        mada_write_event($id, $event);
        media_back($id, 'embedok');
    }
    $fb = parse_facebook($url);
    if ($fb !== null) {
        $media[] = ['type' => 'facebook', 'url' => $fb,
                    'alt' => '', 'caption' => '', 'key' => bin2hex(random_bytes(6))];
        $event['media'] = $media;
        mada_write_event($id, $event);
        media_back($id, 'embedok');
    }
    media_back($id, 'embedbad');
}

/* ── save / up / down / del - praca na formularzu galerii ──────── */
// Akcja z pojedynczego przycisku: "save" albo "up_<i>" / "down_<i>" / "del_<i>"
$action = (string)($_POST['action'] ?? '');
$op  = 'save';
$idx = -1;
if (preg_match('/^(up|down|del)_(\d+)$/', $action, $mm)) {
    $op  = $mm[1];
    $idx = (int)$mm[2];
} elseif ($action !== 'save') {
    media_back($id, 'saved');  // nieznana akcja - traktuj jak brak zmian
}

// Mapa key => item z istniejących
$byKey = [];
foreach ($media as $it) {
    if (isset($it['key'])) $byKey[$it['key']] = $it;
}

// Odbuduj w kolejności przesłanych mkey[], nakładając alt/caption
$mkeys   = $_POST['mkey']    ?? [];
$alts    = $_POST['alt']     ?? [];
$caps    = $_POST['caption'] ?? [];
$newMedia = [];
if (is_array($mkeys)) {
    foreach ($mkeys as $i => $k) {
        if (!isset($byKey[$k])) continue;
        $item = $byKey[$k];
        $item['alt']     = isset($alts[$i]) ? trim((string)$alts[$i]) : ($item['alt'] ?? '');
        $item['caption'] = isset($caps[$i]) ? trim((string)$caps[$i]) : ($item['caption'] ?? '');
        $newMedia[] = $item;
        unset($byKey[$k]);
    }
}
// Dorzuć ewentualne pozostałe (nie powinno się zdarzyć - ochrona przed utratą)
foreach ($byKey as $it) { $newMedia[] = $it; }

if ($op === 'up' && $idx > 0 && $idx < count($newMedia)) {
    $tmp = $newMedia[$idx - 1];
    $newMedia[$idx - 1] = $newMedia[$idx];
    $newMedia[$idx] = $tmp;
} elseif ($op === 'down' && $idx >= 0 && $idx < count($newMedia) - 1) {
    $tmp = $newMedia[$idx + 1];
    $newMedia[$idx + 1] = $newMedia[$idx];
    $newMedia[$idx] = $tmp;
} elseif ($op === 'del' && $idx >= 0 && $idx < count($newMedia)) {
    $removed = array_splice($newMedia, $idx, 1);
    // skasuj plik, jeśli to zdjęcie
    if (!empty($removed[0]) && ($removed[0]['type'] ?? '') === 'image' && !empty($removed[0]['src'])) {
        $fpath = MADA_BASE . '/' . $removed[0]['src'];
        if (is_file($fpath) && strpos(realpath($fpath), realpath(MADA_UPLOADS)) === 0) {
            @unlink($fpath);
        }
    }
}

$event['media'] = $newMedia;
mada_write_event($id, $event);
media_back($id, $op === 'save' ? 'saved' : 'reordered');
