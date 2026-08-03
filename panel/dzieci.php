<?php
/* ═══ CMS - podopieczni Adopcji Serca ═════════════════════════════
   Numer dziecka to klucz, którym posługuje się fundacja.
   Dodawanie dzieci + przełącznik "materiały wysłane" na adopcjach. */
require_once __DIR__ . '/layout.php';
mada_require_login();
require_once __DIR__ . '/../adopcja/db.php';

$dbError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mada_csrf_check();
    try {
        adopt_db_ensure_schema();
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            $no = (int)($_POST['number'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($no <= 0 || $name === '') mada_redirect('dzieci.php?msg=invalid');
            if (adopt_child_by_number($no) !== null) mada_redirect('dzieci.php?msg=taken');
            $cid = adopt_child_upsert($no, $name, trim((string)($_POST['notes'] ?? '')) ?: null);
            mada_audit('child.add', 'child', $cid, ['number' => $no, 'name' => $name]);
            mada_redirect('dzieci.php?msg=added');
        }
        if ($action === 'edit') {
            $cid = (int)($_POST['child_id'] ?? 0);
            $no = (int)($_POST['number'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
            $notes = trim((string)($_POST['notes'] ?? '')) ?: null;
            if ($cid <= 0 || $no <= 0 || $name === '') mada_redirect('dzieci.php?edit=' . $cid . '&msg=invalid');
            if (!adopt_child_update($cid, $no, $name, $status, $notes)) {
                mada_redirect('dzieci.php?edit=' . $cid . '&msg=taken');
            }
            mada_audit('child.edit', 'child', $cid,
                ['number' => $no, 'name' => $name, 'status' => $status, 'notes' => $notes]);
            mada_redirect('dzieci.php?msg=saved');
        }
        if ($action === 'materials') {
            // przełącz flagę na wszystkich otwartych adopcjach tego dziecka
            $cid = (int)($_POST['child_id'] ?? 0);
            $val = ($_POST['value'] ?? '') === '1' ? 1 : 0;
            $st = payu_db()->prepare(
                "UPDATE adopt_adoptions SET materials_sent = ? WHERE child_id = ? AND status IN ('pending','active')"
            );
            $st->execute([$val, $cid]);
            mada_audit('child.materials', 'child', $cid, ['sent' => $val]);
            mada_redirect('dzieci.php?msg=saved');
        }
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

function dz_flash() {
    $codes = [
        'added'   => ['ok', 'Dziecko zostało dodane.'],
        'saved'   => ['ok', 'Zapisano.'],
        'invalid' => ['error', 'Podaj numer (liczba > 0) i imię.'],
        'taken'   => ['error', 'Ten numer jest już zajęty.'],
    ];
    $m = $_GET['msg'] ?? '';
    if (!isset($codes[$m])) return '';
    [$t, $txt] = $codes[$m];
    return '<div class="alert alert-' . ($t === 'ok' ? 'ok' : 'error') . '">' . mada_esc($txt) . '</div>';
}

$children = [];
$editChild = null;
$showAdd = isset($_GET['dodaj']);
try {
    adopt_db_ensure_schema();
    $children = adopt_child_list();
    if (isset($_GET['edit'])) $editChild = adopt_child_get((int)$_GET['edit']);
} catch (Throwable $e) {
    $dbError = $dbError ?: $e->getMessage();
}

panel_header('Podopieczni - Adopcja Serca');
?>
    <div class="bar">
      <h2 style="margin:0;">Podopieczni (dzieci)</h2>
      <a href="dzieci.php?dodaj=1#formularz" class="btn-primary btn-sm">+ Dodaj dziecko</a>
    </div>
    <?= dz_flash() ?>

    <?php if ($editChild !== null): ?>
    <div class="spraw-panel" style="display:block;" id="formularz">
      <h3 style="margin:0 0 10px;">Edycja: nr <?= (int)$editChild['number'] ?> - <?= mada_esc($editChild['name']) ?></h3>
      <form method="post" class="form" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0;">
        <?= mada_csrf_field() ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="child_id" value="<?= (int)$editChild['id'] ?>">
        <label>Numer *<input type="number" name="number" min="1" required value="<?= (int)$editChild['number'] ?>" style="width:90px;"></label>
        <label>Imię *<input type="text" name="name" required value="<?= mada_esc($editChild['name']) ?>"></label>
        <label>Status
          <select name="status">
            <option value="active" <?= $editChild['status'] === 'active' ? 'selected' : '' ?>>aktywne</option>
            <option value="inactive" <?= $editChild['status'] === 'inactive' ? 'selected' : '' ?>>nieaktywne</option>
          </select>
        </label>
        <label style="flex:1;min-width:180px;">Uwagi<input type="text" name="notes" value="<?= mada_esc($editChild['notes'] ?? '') ?>"></label>
        <button type="submit" class="btn-primary btn-sm">Zapisz zmiany</button>
        <a href="dzieci.php" class="btn-ghost btn-sm">Anuluj</a>
      </form>
      <p class="hint" style="margin:8px 0 0;">Zmiana numeru jest bezpieczna - adopcje i wpłaty są powiązane z dzieckiem, nie z numerem. Status „nieaktywne" = dziecko poza programem (nie pojawia się jako wolne przy nowych adopcjach).</p>
    </div>
    <?php elseif ($showAdd): ?>
    <div class="spraw-panel" style="display:block;" id="formularz">
      <h3 style="margin:0 0 10px;">Nowe dziecko</h3>
      <form method="post" class="form" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0;">
        <?= mada_csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <label>Numer *<input type="number" name="number" min="1" required style="width:90px;"
               value="<?= $children ? max(array_column($children, 'number')) + 1 : 1 ?>"></label>
        <label>Imię *<input type="text" name="name" required autofocus></label>
        <label style="flex:1;min-width:180px;">Uwagi<input type="text" name="notes"></label>
        <button type="submit" class="btn-primary btn-sm">Dodaj</button>
        <a href="dzieci.php" class="btn-ghost btn-sm">Anuluj</a>
      </form>
      <p class="hint" style="margin:8px 0 0;">Numer podpowiedziany jako kolejny wolny - można zmienić.</p>
    </div>
    <?php endif; ?>

<?php if ($dbError !== ''): ?>
    <div class="alert alert-error">Baza danych jest niedostępna (sprawdź <code>payu/secret/db-config.php</code>): <?= mada_esc($dbError) ?></div>
<?php elseif (!$children): ?>
    <p class="hint">Baza podopiecznych jest pusta - zacznij od strony <a href="import.php">Import</a>.</p>
<?php else: ?>
    <?php
      $withDonor = count(array_filter($children, fn($c) => $c['donors'] !== null));
    ?>
    <p class="hint" style="margin:0 0 12px;">Łącznie: <?= count($children) ?>,
       z darczyńcą: <?= $withDonor ?>, bez darczyńcy: <?= count($children) - $withDonor ?>.</p>
    <table class="events">
      <thead><tr>
        <th>Nr</th><th>Imię</th><th>Status</th><th>Darczyńca</th><th>Materiały wysłane</th><th>Uwagi</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($children as $c): ?>
        <tr>
          <td><b><?= (int)$c['number'] ?></b></td>
          <td><?= mada_esc($c['name']) ?></td>
          <td><?= $c['status'] === 'active' ? 'aktywne' : '<span class="hint">nieaktywne</span>' ?></td>
          <td><?php if ($c['donors'] !== null): ?><?= mada_esc($c['donors']) ?>
              <?php else: ?><span class="badge" style="background:#fbeeec;color:var(--err);border-color:#e6b9b1;">brak</span><?php endif; ?></td>
          <td>
            <?php $sent = ((int)($c['materials_sent'] ?? 0)) === 1; ?>
            <?php if ($c['donors'] !== null): ?>
            <form method="post" style="margin:0;display:inline;">
              <?= mada_csrf_field() ?>
              <input type="hidden" name="action" value="materials">
              <input type="hidden" name="child_id" value="<?= (int)$c['id'] ?>">
              <input type="hidden" name="value" value="<?= $sent ? 0 : 1 ?>">
              <button type="submit" class="btn-sm <?= $sent ? 'btn-secondary' : 'btn-danger' ?>" title="Kliknij, aby przełączyć">
                <?= $sent ? 'TAK' : 'nie' ?>
              </button>
            </form>
            <?php else: ?><span class="hint">-</span><?php endif; ?>
          </td>
          <td class="hint"><?= mada_esc($c['notes'] ?? '') ?></td>
          <td><a class="btn-secondary btn-sm" href="dzieci.php?edit=<?= (int)$c['id'] ?>#formularz">Edytuj</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
<?php endif; ?>
<?php
panel_footer();
