<?php
/* ═══ CMS - upload zdjęcia do galerii wydarzenia (POST) ══════════
   Bezpieczeństwo: allowlista typów (finfo), limit rozmiaru i liczby,
   RE-ENKODOWANIE przez GD (usuwa ewentualny złośliwy ładunek),
   losowa nazwa pliku, zapis tylko w uploads/wydarzenia/<id>/.
  ═══════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/auth.php';
mada_require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { mada_redirect('index.php'); }
mada_csrf_check();

const MADA_MAX_IMAGES   = 20;            // limit zdjęć hostowanych na wydarzenie
const MADA_MAX_BYTES    = 12 * 1024 * 1024; // 12 MB / plik
const MADA_MAX_DIM      = 2000;          // px - dłuższy bok po zmniejszeniu

$id = $_POST['id'] ?? '';
$event = mada_valid_id($id) ? mada_read_event($id) : null;
if ($event === null) { mada_redirect('index.php?msg=nofound'); }

function up_back($id, $code) {
    mada_redirect('edit.php?id=' . urlencode($id) . '&gmsg=' . $code . '#galeria');
}

$media = (isset($event['media']) && is_array($event['media'])) ? $event['media'] : [];
$imgCount = 0;
foreach ($media as $it) { if (($it['type'] ?? '') === 'image') $imgCount++; }
if ($imgCount >= MADA_MAX_IMAGES) { up_back($id, 'limit'); }

// ── Plik ───────────────────────────────────────────────────────
if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) { up_back($id, 'uperr'); }
$f = $_FILES['photo'];
if ($f['size'] <= 0 || $f['size'] > MADA_MAX_BYTES) { up_back($id, 'big'); }
if (!is_uploaded_file($f['tmp_name'])) { up_back($id, 'uperr'); }

// ── Walidacja typu po ZAWARTOŚCI (nie po rozszerzeniu) ─────────
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($f['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowed[$mime])) { up_back($id, 'type'); }

// ── Wczytanie do GD i re-enkodowanie ───────────────────────────
$raw = file_get_contents($f['tmp_name']);
$img = @imagecreatefromstring($raw);
if ($img === false) { up_back($id, 'type'); }

$w = imagesx($img);
$h = imagesy($img);
// Zmniejszenie, jeśli dłuższy bok > MADA_MAX_DIM
$scale = max($w, $h) > MADA_MAX_DIM ? (MADA_MAX_DIM / max($w, $h)) : 1.0;
if ($scale < 1.0) {
    $nw = (int)round($w * $scale);
    $nh = (int)round($h * $scale);
    $dst = imagecreatetruecolor($nw, $nh);
    if ($mime === 'image/png' || $mime === 'image/webp') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);
    $img = $dst;
}

// ── Zapis ──────────────────────────────────────────────────────
$dir = MADA_UPLOADS . '/' . $id;
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
$ext = $allowed[$mime];
$key = bin2hex(random_bytes(6));
$fname = $key . '.' . $ext;
$path = $dir . '/' . $fname;

$ok = false;
if ($mime === 'image/jpeg')      $ok = imagejpeg($img, $path, 85);
elseif ($mime === 'image/png')   $ok = imagepng($img, $path, 6);
elseif ($mime === 'image/webp')  $ok = imagewebp($img, $path, 85);
imagedestroy($img);
if (!$ok) { up_back($id, 'save'); }

// ── Dopisanie do media[] ───────────────────────────────────────
$media[] = [
    'type'    => 'image',
    'src'     => MADA_UPLOADS_URL . '/' . $id . '/' . $fname,
    'alt'     => '',
    'caption' => '',
    'key'     => $key,
];
$event['media'] = $media;
mada_write_event($id, $event);

up_back($id, 'added');
