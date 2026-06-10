<?php
/* ═══ CMS - usunięcie wydarzenia (POST) ══════════════════════════ */
require_once __DIR__ . '/auth.php';
mada_require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { mada_redirect('index.php'); }
mada_csrf_check();

$id = $_POST['id'] ?? '';
if (mada_valid_id($id) && mada_read_event($id) !== null) {
    mada_delete_event($id);   // usuwa JSON + katalog zdjęć uploads/wydarzenia/<id>/
    mada_redirect('index.php?msg=deleted');
}
mada_redirect('index.php?msg=nofound');
