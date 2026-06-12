<?php
/* ═══ CMS - zarządzanie sprawozdaniami (PDF) ═════════════════════ */
require_once __DIR__ . '/layout.php';
mada_require_login();

function spraw_flash() {
    $codes = [
        'added'   => ['ok',    'Sprawozdanie zostało dodane.'],
        'deleted' => ['ok',    'Sprawozdanie zostało usunięte.'],
        'badtype' => ['error', 'Nieprawidłowy typ sprawozdania.'],
        'badyear' => ['error', 'Podaj poprawny rok (2000-2100).'],
        'uperr'   => ['error', 'Nie udało się wgrać pliku. Spróbuj ponownie.'],
        'big'     => ['error', 'Plik jest za duży (maks. 20 MB).'],
        'notpdf'  => ['error', 'Niedozwolony typ pliku. Wgraj plik PDF.'],
        'save'    => ['error', 'Nie udało się zapisać pliku na serwerze.'],
        'nofound' => ['error', 'Nie znaleziono sprawozdania.'],
    ];
    $m = $_GET['smsg'] ?? '';
    if (!isset($codes[$m])) return '';
    [$t, $txt] = $codes[$m];
    return '<div class="alert alert-' . ($t === 'ok' ? 'ok' : 'error') . '">' . mada_esc($txt) . '</div>';
}

$data   = mada_sprawozdania_sorted();
$labels = ['finansowe' => 'Sprawozdania finansowe', 'merytoryczne' => 'Sprawozdania merytoryczne'];

panel_header('Sprawozdania');
?>
    <div class="bar">
      <h2 style="margin:0;">Sprawozdania</h2>
      <a href="index.php" class="btn-ghost">← Wróć do listy</a>
    </div>
    <?= spraw_flash() ?>

    <p class="hint" style="margin:0 0 20px;">Dodawaj pliki PDF ze sprawozdaniami za poszczególne lata. Pojawią się na podstronie „Sprawozdania" i jako najnowszy rok na kaflach strony „O nas". Dodanie pliku dla roku, który już istnieje, zastępuje poprzedni.</p>

    <?php foreach ($labels as $type => $label): ?>
    <div class="form" style="margin-bottom:22px;">
      <h3 style="margin:0 0 12px;"><?= mada_esc($label) ?></h3>

      <?php if (empty($data[$type])): ?>
        <p class="hint" style="margin:0 0 12px;">Brak plików. Dodaj pierwszy poniżej.</p>
      <?php else: ?>
        <table class="events" style="margin-bottom:16px;">
          <thead><tr><th style="width:90px;">Rok</th><th>Plik</th><th style="width:120px;">Akcje</th></tr></thead>
          <tbody>
          <?php foreach ($data[$type] as $it): ?>
            <tr>
              <td><b><?= mada_esc($it['year'] ?? '') ?></b></td>
              <td><a href="/<?= mada_esc($it['file'] ?? '') ?>" target="_blank" rel="noopener">Pobierz PDF ↗</a></td>
              <td>
                <form method="post" action="sprawozdania-delete.php" onsubmit="return confirm('Na pewno usunąć to sprawozdanie?');" style="margin:0;">
                  <?= mada_csrf_field() ?>
                  <input type="hidden" name="type" value="<?= mada_esc($type) ?>">
                  <input type="hidden" name="year" value="<?= mada_esc($it['year'] ?? '') ?>">
                  <button type="submit" class="btn-danger btn-sm">Usuń</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <form method="post" action="sprawozdania-upload.php" enctype="multipart/form-data" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <?= mada_csrf_field() ?>
        <input type="hidden" name="type" value="<?= mada_esc($type) ?>">
        <label style="margin:0;">Rok
          <input type="number" name="year" min="2000" max="2100" required value="<?= mada_esc(date('Y')) ?>"
                 style="display:block;margin-top:6px;padding:9px 11px;border:1px solid var(--rule);border-radius:8px;width:120px;">
        </label>
        <label style="flex:1;min-width:220px;margin:0;">Plik PDF
          <input type="file" name="pdf" accept="application/pdf" required style="display:block;margin-top:6px;">
        </label>
        <button type="submit" class="btn-primary">Dodaj PDF</button>
      </form>
    </div>
    <?php endforeach; ?>
<?php
panel_footer();
