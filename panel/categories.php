<?php
/* ═══ CMS - zarządzanie kategoriami wydarzeń ═════════════════════ */
require_once __DIR__ . '/layout.php';
mada_require_login();

/** Unikalny klucz (slug) kategorii spoza istniejących. */
function mada_unique_cat_key($label, $existing) {
    $base = mada_slugify($label);
    $key = $base; $i = 2;
    while (isset($existing[$key])) { $key = $base . '-' . $i; $i++; }
    return $key;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    mada_csrf_check();
    $cats = mada_categories();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $labels = $_POST['label'] ?? [];
        $new = [];
        foreach ($cats as $key => $oldLabel) {
            $l = isset($labels[$key]) ? trim((string)$labels[$key]) : '';
            $new[$key] = ($l !== '') ? $l : $oldLabel;   // pusta = zostaw starą
        }
        mada_save_categories($new);
        mada_redirect('categories.php?cmsg=saved');

    } elseif ($action === 'add') {
        $label = trim((string)($_POST['newlabel'] ?? ''));
        if ($label === '') mada_redirect('categories.php?cmsg=empty');
        $key = mada_unique_cat_key($label, $cats);
        $cats[$key] = $label;
        mada_save_categories($cats);
        mada_redirect('categories.php?cmsg=added');

    } elseif (strpos($action, 'del:') === 0) {
        $key = substr($action, 4);
        if (!isset($cats[$key])) mada_redirect('categories.php?cmsg=nofound');
        if (mada_category_in_use($key)) mada_redirect('categories.php?cmsg=inuse');
        if (count($cats) <= 1) mada_redirect('categories.php?cmsg=last');
        unset($cats[$key]);
        mada_save_categories($cats);
        mada_redirect('categories.php?cmsg=deleted');
    }
    mada_redirect('categories.php');
}

function cat_flash() {
    $codes = [
        'saved'   => ['ok',    'Nazwy kategorii zapisane.'],
        'added'   => ['ok',    'Kategoria dodana.'],
        'deleted' => ['ok',    'Kategoria usunięta.'],
        'empty'   => ['error', 'Wpisz nazwę nowej kategorii.'],
        'inuse'   => ['error', 'Nie można usunąć - kategoria jest używana przez wydarzenia. Najpierw zmień ich kategorię.'],
        'last'    => ['error', 'Musi zostać co najmniej jedna kategoria.'],
        'nofound' => ['error', 'Nie znaleziono kategorii.'],
    ];
    $m = $_GET['cmsg'] ?? '';
    if (!isset($codes[$m])) return '';
    [$t, $txt] = $codes[$m];
    return '<div class="alert alert-' . ($t === 'ok' ? 'ok' : 'error') . '">' . mada_esc($txt) . '</div>';
}

$cats = mada_categories();
panel_header('Kategorie wydarzeń');
?>
    <div class="bar">
      <h2 style="margin:0;">Kategorie wydarzeń</h2>
      <a href="index.php" class="btn-ghost">← Wróć do listy</a>
    </div>
    <?= cat_flash() ?>

    <div class="form" style="margin-bottom:22px;">
      <p class="hint" style="margin:0 0 16px;">Kategorie pojawiają się na liście wyboru przy wydarzeniu oraz w filtrze archiwum. Zmiana nazwy nie rusza już zapisanych wydarzeń. Kategorii używanej przez wydarzenia nie można usunąć.</p>
      <form method="post" action="categories.php">
        <?= mada_csrf_field() ?>
        <table class="events" style="margin-bottom:16px;">
          <thead><tr><th>Nazwa kategorii</th><th style="width:120px;">Status</th><th style="width:90px;">Akcje</th></tr></thead>
          <tbody>
          <?php foreach ($cats as $key => $label):
              $inUse = mada_category_in_use($key); ?>
            <tr>
              <td><input type="text" name="label[<?= mada_esc($key) ?>]" value="<?= mada_esc($label) ?>" style="width:100%;padding:8px 10px;border:1px solid var(--rule);border-radius:8px;"></td>
              <td><?= $inUse ? '<span class="badge badge-up">w użyciu</span>' : '<span class="badge badge-arch">wolna</span>' ?></td>
              <td>
                <button type="submit" name="action" value="del:<?= mada_esc($key) ?>" class="btn-danger btn-sm"
                  <?= $inUse ? 'disabled title="Używana - nie można usunąć"' : 'onclick="return confirm(\'Usunąć tę kategorię?\');"' ?>>Usuń</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <button type="submit" name="action" value="save" class="btn-primary">Zapisz nazwy</button>
      </form>
    </div>

    <div class="form">
      <h3 style="margin:0 0 12px;">Dodaj kategorię</h3>
      <form method="post" action="categories.php" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <?= mada_csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <label style="flex:1;min-width:220px;margin:0;">Nazwa nowej kategorii
          <input type="text" name="newlabel" placeholder="np. Festyn parafialny" style="width:100%;margin-top:6px;padding:9px 11px;border:1px solid var(--rule);border-radius:8px;">
        </label>
        <button type="submit" class="btn-secondary">Dodaj kategorię</button>
      </form>
    </div>
<?php
panel_footer();
