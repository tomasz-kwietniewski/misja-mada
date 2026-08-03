<?php
/* ═══ CMS - dodanie / edycja darczyńcy Adopcji Serca ═════════════ */
require_once __DIR__ . '/layout.php';
mada_require_login();
require_once __DIR__ . '/../adopcja/db.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$dbError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mada_csrf_check();
    try {
        adopt_db_ensure_schema();
        $d = [
            'full_name'    => trim((string)($_POST['full_name'] ?? '')),
            'email'        => trim((string)($_POST['email'] ?? '')),
            'emails_extra' => trim((string)($_POST['emails_extra'] ?? '')),
            'phone'        => trim((string)($_POST['phone'] ?? '')),
            'notes'        => trim((string)($_POST['notes'] ?? '')),
        ];
        if ($d['full_name'] === '' || ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL))) {
            mada_redirect('darczynca-edit.php?' . ($id ? "id=$id&" : '') . 'msg=invalid');
        }
        if ($id > 0) {
            adopt_donor_update($id, $d);
            mada_audit('donor.edit', 'donor', $id, $d);
            mada_redirect("darczynca.php?id=$id&msg=saved");
        }
        $newId = adopt_donor_insert($d + ['source' => 'manual']);
        mada_audit('donor.add', 'donor', $newId, $d);
        mada_redirect("darczynca.php?id=$newId&msg=saved");
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

$donor = null;
try {
    adopt_db_ensure_schema();
    if ($id > 0) $donor = adopt_donor_get($id);
} catch (Throwable $e) {
    $dbError = $dbError ?: $e->getMessage();
}

function de_flash() {
    if (($_GET['msg'] ?? '') === 'invalid') {
        return '<div class="alert alert-error">Uzupełnij imię i nazwisko; e-mail (jeśli podany) musi być poprawny.</div>';
    }
    return '';
}

panel_header(($id ? 'Edycja' : 'Nowy') . ' darczyńca');
?>
    <div class="bar">
      <h2 style="margin:0;"><?= $id ? 'Edycja darczyńcy' : 'Nowy darczyńca' ?></h2>
      <a href="<?= $id ? 'darczynca.php?id=' . $id : 'darczyncy.php' ?>" class="btn-ghost btn-sm">← Wróć</a>
    </div>
    <?= de_flash() ?>
<?php if ($dbError !== ''): ?>
    <div class="alert alert-error">Błąd bazy danych: <?= mada_esc($dbError) ?></div>
<?php elseif ($id > 0 && !$donor): ?>
    <div class="alert alert-error">Nie znaleziono darczyńcy.</div>
<?php else: ?>
    <form method="post" class="form" style="max-width:560px;">
      <?= mada_csrf_field() ?>
      <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
      <label>Imię i nazwisko *
        <input type="text" name="full_name" required value="<?= mada_esc($donor['full_name'] ?? '') ?>">
      </label>
      <label>E-mail
        <input type="email" name="email" value="<?= mada_esc($donor['email'] ?? '') ?>">
      </label>
      <label>Dodatkowe e-maile (po średniku)
        <input type="text" name="emails_extra" value="<?= mada_esc($donor['emails_extra'] ?? '') ?>">
      </label>
      <label>Telefon
        <input type="text" name="phone" value="<?= mada_esc($donor['phone'] ?? '') ?>">
      </label>
      <label>Notatki
        <textarea name="notes" rows="3"><?= mada_esc($donor['notes'] ?? '') ?></textarea>
      </label>
      <button type="submit" class="btn-primary"><?= $id ? 'Zapisz zmiany' : 'Dodaj darczyńcę' ?></button>
    </form>
<?php endif; ?>
<?php
panel_footer();
