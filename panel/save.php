<?php
/* ═══ CMS - zapis wydarzenia (POST) ══════════════════════════════ */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/translate.php';
mada_require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { mada_redirect('index.php'); }
mada_csrf_check();

$post = function ($k) { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : ''; };

$title     = $post('title');
$dateISO   = $post('dateISO');
$dateLabel = $post('dateLabel');

// ── Walidacja minimalna ────────────────────────────────────────
$validDate = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateISO, $m)
          && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
$editId = $post('id');
$backId = ($editId !== '' && mada_valid_id($editId)) ? '?id=' . urlencode($editId) : '';
if ($title === '' || !$validDate || $dateLabel === '') {
    mada_redirect('edit.php' . $backId . ($backId ? '&' : '?') . 'msg=invalid');
}

// ── Tryb: edycja czy nowe ──────────────────────────────────────
$existing = null;
if ($editId !== '' && mada_valid_id($editId)) {
    $existing = mada_read_event($editId);
}
$isNew = ($existing === null);
if (!$isNew) {
    $id = $editId;                      // edycja - id (slug) niezmienne
} else {
    $id = mada_unique_slug($title);     // nowe - slug z tytułu
    $existing = [];
}

// ── Kategoria + etykieta ───────────────────────────────────────
$cats = mada_categories();
$category = $post('category');
if (!isset($cats[$category])) $category = 'misja';
$categoryLabel = $post('categoryLabel');
if ($categoryLabel === '') $categoryLabel = $cats[$category];

// ── Treść: akapity z pustych linii ─────────────────────────────
$bodyRaw = (string)($_POST['body'] ?? '');
$body = array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/u', $bodyRaw)), function ($p) {
    return $p !== '';
}));

$featured = isset($_POST['featured']);

// ── Budowa rekordu (zachowanie media/i18n/createdBy przy edycji) ─
$data = [
    'featured'      => $featured,
    'title'         => $title,
    'dateISO'       => $dateISO,
    'dateLabel'     => $dateLabel,
    'category'      => $category,
    'categoryLabel' => $categoryLabel,
    'place'         => $post('place'),
    'lead'          => $post('lead'),
    'body'          => $body,
];
$masze = $post('masze');
if ($masze !== '') $data['masze'] = $masze;

$sumValue = $post('summaryValue');
$sumLabel = $post('summaryLabel');
if ($sumValue !== '') {
    $data['summary'] = ['label' => ($sumLabel !== '' ? $sumLabel : 'Podsumowanie'), 'value' => $sumValue];
}

// Galeria - zachowana z istniejącego rekordu (zarządzana w panelu galerii)
$data['media'] = (isset($existing['media']) && is_array($existing['media'])) ? $existing['media'] : [];

// Tłumaczenia - zachowane; (re)generacja w ETAP 4
if (isset($existing['i18n'])) $data['i18n'] = $existing['i18n'];

// Metadane
$user = mada_current_user();
$now  = date('c');
if (isset($existing['_meta'])) {
    $data['_meta'] = $existing['_meta'];
} else {
    $data['_meta'] = ['createdBy' => $user, 'createdAt' => $now];
}
$data['_meta']['updatedBy'] = $user;
$data['_meta']['updatedAt'] = $now;

// ── Zapis + reguła maks. 1 wyróżnione ──────────────────────────
if (!mada_write_event($id, $data)) {
    mada_redirect('edit.php' . $backId . ($backId ? '&' : '?') . 'msg=invalid');
}
if ($featured) {
    mada_clear_other_featured($id);
}

// Automatyczne tłumaczenie EN/FR (best-effort; brak klucza = pominięte)
$tr = mada_retranslate_and_store($id);

$msg = $isNew ? 'added' : 'saved';
if ($tr === 'fail') $msg = 'notrans';
mada_redirect('index.php?msg=' . $msg);
