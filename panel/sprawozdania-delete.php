<?php
/* ═══ CMS - usunięcie sprawozdania (POST) ════════════════════════
   Usuwa wpis z manifestu i kasuje plik TYLKO jeśli leży w
   uploads/sprawozdania/ (pliki z media/ z repo zostają nietknięte).
  ═══════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/auth.php';
mada_require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { mada_redirect('sprawozdania.php'); }
mada_csrf_check();

$type = (string)($_POST['type'] ?? '');
$year = (int)($_POST['year'] ?? 0);
if (!mada_valid_spraw_type($type)) mada_redirect('sprawozdania.php?smsg=badtype');

$data = mada_sprawozdania();
$list = $data[$type] ?? [];
$kept = [];
$removed = null;
foreach ($list as $it) {
    if ((int)($it['year'] ?? 0) === $year) { $removed = $it; continue; }
    $kept[] = $it;
}
if ($removed === null) mada_redirect('sprawozdania.php?smsg=nofound');

$data[$type] = $kept;
mada_save_sprawozdania($data);
mada_spraw_delete_file($removed['file'] ?? '');   // no-op dla plików w media/

mada_redirect('sprawozdania.php?smsg=deleted');
