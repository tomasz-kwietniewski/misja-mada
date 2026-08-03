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
        /* Dodawanie i edycja to TEN SAM formularz (komplet pól z dossier) -
           przy dodawaniu pracownik zwykle ma już PDF z danymi dziecka. */
        if ($action === 'add' || $action === 'edit') {
            $isAdd = $action === 'add';
            $cid = (int)($_POST['child_id'] ?? 0);
            $back = $isAdd ? 'dzieci.php?dodaj=1' : 'dzieci.php?edit=' . $cid;
            $d = [
                'number' => (int)($_POST['number'] ?? 0),
                'name' => trim((string)($_POST['name'] ?? '')),
                'status' => (string)($_POST['status'] ?? 'active'),
                'notes' => trim((string)($_POST['notes'] ?? '')),
                'dossier_name' => trim((string)($_POST['dossier_name'] ?? '')),
                'birth_date' => trim((string)($_POST['birth_date'] ?? '')),
                'father' => trim((string)($_POST['father'] ?? '')),
                'mother' => trim((string)($_POST['mother'] ?? '')),
                'siblings' => trim((string)($_POST['siblings'] ?? '')),
                'description' => trim((string)($_POST['description'] ?? '')),
            ];
            if ((!$isAdd && $cid <= 0) || $d['number'] <= 0 || $d['name'] === ''
                || ($d['birth_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['birth_date']))) {
                mada_redirect($back . '&msg=invalid');
            }
            if ($isAdd) {
                if (adopt_child_by_number($d['number']) !== null) mada_redirect($back . '&msg=taken');
                $cid = adopt_child_upsert($d['number'], $d['name'], $d['notes'] ?: null);
            }
            // Zdjęcie do dossier: uploads/dzieci/, losowa nazwa (nie do zgadnięcia
            // z zewnątrz - katalog jest publiczny jak inne uploads/).
            if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
                if ((int)$_FILES['photo']['size'] > 6 * 1024 * 1024) mada_redirect($back . '&msg=photobig');
                $info = @getimagesize($_FILES['photo']['tmp_name']);
                $extMap = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
                if ($info === false || !isset($extMap[$info[2]])) mada_redirect($back . '&msg=phototype');
                $dir = __DIR__ . '/../uploads/dzieci';
                if (!is_dir($dir) && !@mkdir($dir, 0755, true)) mada_redirect($back . '&msg=photoerr');
                $fname = 'dziecko-' . $cid . '-' . bin2hex(random_bytes(8)) . '.' . $extMap[$info[2]];
                if (!@move_uploaded_file($_FILES['photo']['tmp_name'], $dir . '/' . $fname)) {
                    mada_redirect($back . '&msg=photoerr');
                }
                $old = adopt_child_get($cid);
                if ($old && !empty($old['photo'])) @unlink($dir . '/' . basename($old['photo']));
                $d['photo'] = $fname;
            }
            if (!adopt_child_update($cid, $d)) {
                mada_redirect($back . '&msg=taken');
            }
            mada_audit($isAdd ? 'child.add' : 'child.edit', 'child', $cid, array_diff_key($d, ['description' => 1]));
            mada_redirect('dzieci.php?msg=' . ($isAdd ? 'added' : 'saved'));
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
        'invalid' => ['error', 'Podaj numer (liczba > 0) i imię; data urodzenia w formacie RRRR-MM-DD.'],
        'taken'   => ['error', 'Ten numer jest już zajęty.'],
        'photobig'  => ['error', 'Zdjęcie jest za duże (maks. 6 MB).'],
        'phototype' => ['error', 'Niedozwolony typ zdjęcia. Dozwolone: JPG, PNG, WEBP.'],
        'photoerr'  => ['error', 'Nie udało się zapisać zdjęcia na serwerze.'],
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
      <form method="post" enctype="multipart/form-data" class="form" style="margin:0;">
        <?= mada_csrf_field() ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="child_id" value="<?= (int)$editChild['id'] ?>">
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
          <label>Numer *<input type="number" name="number" min="1" required value="<?= (int)$editChild['number'] ?>" style="width:90px;"></label>
          <label>Imię (krótkie) *<input type="text" name="name" required value="<?= mada_esc($editChild['name']) ?>"></label>
          <label>Status
            <select name="status">
              <option value="active" <?= $editChild['status'] === 'active' ? 'selected' : '' ?>>aktywne</option>
              <option value="inactive" <?= $editChild['status'] === 'inactive' ? 'selected' : '' ?>>nieaktywne</option>
            </select>
          </label>
          <label style="flex:1;min-width:180px;">Uwagi (robocze, nie idą do darczyńcy)<input type="text" name="notes" value="<?= mada_esc($editChild['notes'] ?? '') ?>"></label>
        </div>

        <h4 style="margin:16px 0 8px;color:var(--brown);">Dossier dziecka (treść maila do darczyńcy)</h4>
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
          <label style="flex:2;min-width:240px;">Pełne imię i nazwisko<input type="text" name="dossier_name"
                 placeholder="np. Avotriniaina Alvin RAKOTOZANANY" value="<?= mada_esc($editChild['dossier_name'] ?? '') ?>"></label>
          <label>Data urodzenia<input type="date" name="birth_date" value="<?= mada_esc($editChild['birth_date'] ?? '') ?>"></label>
          <label>Dzieci w rodzinie<input type="number" name="siblings" min="1" style="width:90px;" value="<?= mada_esc((string)($editChild['siblings'] ?? '')) ?>"></label>
        </div>
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
          <label style="flex:1;min-width:200px;">Ojciec<input type="text" name="father" value="<?= mada_esc($editChild['father'] ?? '') ?>"></label>
          <label style="flex:1;min-width:200px;">Matka<input type="text" name="mother" value="<?= mada_esc($editChild['mother'] ?? '') ?>"></label>
        </div>
        <label>Opis sytuacji dziecka
          <textarea name="description" rows="5" placeholder="Rodzina, w której wychowuje się..."><?= mada_esc($editChild['description'] ?? '') ?></textarea>
        </label>
        <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
          <?php if (!empty($editChild['photo'])): ?>
            <img src="../uploads/dzieci/<?= mada_esc($editChild['photo']) ?>" alt="" style="height:90px;border-radius:9px;border:1px solid var(--rule);">
          <?php endif; ?>
          <label style="margin:0;">Zdjęcie (JPG/PNG/WEBP, maks. 6 MB)<?= !empty($editChild['photo']) ? ' - wgranie nowego podmienia obecne' : '' ?>
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
          </label>
        </div>
        <div style="margin-top:10px;">
          <button type="submit" class="btn-primary btn-sm">Zapisz zmiany</button>
          <a href="dzieci.php" class="btn-ghost btn-sm">Anuluj</a>
        </div>
      </form>
      <p class="hint" style="margin:8px 0 0;">Zmiana numeru jest bezpieczna - adopcje i wpłaty są powiązane z dzieckiem, nie z numerem. Status „nieaktywne" = dziecko poza programem. Dossier trafia do maila „przedstawienie dziecka" wysyłanego przy przypisaniu darczyńcy.</p>
    </div>
    <?php elseif ($showAdd): ?>
    <div class="spraw-panel" style="display:block;" id="formularz">
      <h3 style="margin:0 0 10px;">Nowe dziecko</h3>
      <form method="post" enctype="multipart/form-data" class="form" style="margin:0;">
        <?= mada_csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
          <label>Numer *<input type="number" name="number" min="1" required style="width:90px;"
                 value="<?= $children ? max(array_column($children, 'number')) + 1 : 1 ?>"></label>
          <label>Imię (krótkie) *<input type="text" name="name" required autofocus></label>
          <label style="flex:1;min-width:180px;">Uwagi (robocze, nie idą do darczyńcy)<input type="text" name="notes"></label>
        </div>

        <h4 style="margin:16px 0 8px;color:var(--brown);">Dossier dziecka (treść maila do darczyńcy - można uzupełnić później)</h4>
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
          <label style="flex:2;min-width:240px;">Pełne imię i nazwisko<input type="text" name="dossier_name"
                 placeholder="np. Avotriniaina Alvin RAKOTOZANANY"></label>
          <label>Data urodzenia<input type="date" name="birth_date"></label>
          <label>Dzieci w rodzinie<input type="number" name="siblings" min="1" style="width:90px;"></label>
        </div>
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
          <label style="flex:1;min-width:200px;">Ojciec<input type="text" name="father"></label>
          <label style="flex:1;min-width:200px;">Matka<input type="text" name="mother"></label>
        </div>
        <label>Opis sytuacji dziecka
          <textarea name="description" rows="5" placeholder="Rodzina, w której wychowuje się..."></textarea>
        </label>
        <label>Zdjęcie (JPG/PNG/WEBP, maks. 6 MB)
          <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
        </label>
        <div style="margin-top:10px;">
          <button type="submit" class="btn-primary btn-sm">Dodaj dziecko</button>
          <a href="dzieci.php" class="btn-ghost btn-sm">Anuluj</a>
        </div>
      </form>
      <p class="hint" style="margin:8px 0 0;">Numer podpowiedziany jako kolejny wolny - można zmienić. Wystarczą numer i imię; dossier można dopisać później przez „Edytuj".</p>
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
        <tr class="row-link" data-href="dzieci.php?edit=<?= (int)$c['id'] ?>#formularz">
          <td><b><?= (int)$c['number'] ?></b></td>
          <td><a href="dzieci.php?edit=<?= (int)$c['id'] ?>#formularz"><?= mada_esc($c['name']) ?></a><?= !empty($c['description']) || !empty($c['photo']) ? ' <span title="dossier uzupełnione">📋</span>' : '' ?></td>
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
