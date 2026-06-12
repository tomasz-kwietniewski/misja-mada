<?php
/* ═══ CMS - upload PDF sprawozdania (POST) ═══════════════════════
   Bezpieczeństwo: CSRF, walidacja typu po ZAWARTOŚCI (finfo ->
   application/pdf), limit rozmiaru, losowa nazwa, zapis wyłącznie
   w uploads/sprawozdania/. Duplikat (typ+rok) nadpisuje wpis.
  ═══════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/auth.php';
mada_require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { mada_redirect('sprawozdania.php'); }
mada_csrf_check();

const MADA_SPRAW_MAX_BYTES = 20 * 1024 * 1024;   // 20 MB / plik

function spraw_back($code) { mada_redirect('sprawozdania.php?smsg=' . $code); }

$type    = (string)($_POST['type'] ?? '');
$yearRaw = $_POST['year'] ?? '';
if (!mada_valid_spraw_type($type))    spraw_back('badtype');
if (!mada_valid_spraw_year($yearRaw)) spraw_back('badyear');
$year = (int)$yearRaw;

// ── Plik ───────────────────────────────────────────────────────
if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) spraw_back('uperr');
$f = $_FILES['pdf'];
if ($f['size'] <= 0 || $f['size'] > MADA_SPRAW_MAX_BYTES) spraw_back('big');
if (!is_uploaded_file($f['tmp_name'])) spraw_back('uperr');

// ── Walidacja typu po zawartości ───────────────────────────────
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($f['tmp_name']);
if ($mime !== 'application/pdf') spraw_back('notpdf');

// ── Zapis pliku (losowa nazwa) ─────────────────────────────────
mada_spraw_ensure_dir();
$key   = bin2hex(random_bytes(8));
$fname = $type . '-' . $year . '-' . $key . '.pdf';
$dest  = MADA_SPRAW_UPLOADS . '/' . $fname;
if (!move_uploaded_file($f['tmp_name'], $dest)) spraw_back('save');
@chmod($dest, 0644);

// ── Wpis do manifestu (nadpisz duplikat typ+rok) ───────────────
$data     = mada_sprawozdania();
$list     = $data[$type] ?? [];
$newList  = [];
$replaced = null;
foreach ($list as $it) {
    if ((int)($it['year'] ?? 0) === $year) { $replaced = $it; continue; }
    $newList[] = $it;
}
$newList[] = [
    'year'  => $year,
    'file'  => MADA_SPRAW_UPLOADS_URL . '/' . $fname,
    'title' => $type === 'finansowe' ? 'Sprawozdanie finansowe' : 'Sprawozdanie z działalności',
];
$data[$type] = $newList;
mada_save_sprawozdania($data);

// Skasuj stary plik nadpisanego roku - tylko jeśli był w uploads/ (media/ nie ruszamy).
if ($replaced) mada_spraw_delete_file($replaced['file'] ?? '');

spraw_back('added');
