<?php
/* ═══ CMS - dodanie / edycja darczyńcy Adopcji Serca ═════════════ */
require_once __DIR__ . '/layout.php';
mada_require_login();
require_once __DIR__ . '/../adopcja/db.php';
require_once __DIR__ . '/../adopcja/lib.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$dbError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mada_csrf_check();
    try {
        adopt_db_ensure_schema();
        $d = [
            'full_name'    => trim((string)($_POST['full_name'] ?? '')),
            'first_name'   => trim((string)($_POST['first_name'] ?? '')),
            'last_name'    => trim((string)($_POST['last_name'] ?? '')),
            'email'        => trim((string)($_POST['email'] ?? '')),
            'emails_extra' => trim((string)($_POST['emails_extra'] ?? '')),
            'phone'        => trim((string)($_POST['phone'] ?? '')),
            'street'       => trim((string)($_POST['street'] ?? '')),
            'house_no'     => trim((string)($_POST['house_no'] ?? '')),
            // Panel jest polskojęzyczny i obsługuje polskie adresy - tu normalizacja
            // „00000" -> „00-000" jest bezpieczna (inaczej niż w formularzu EN/FR).
            'postcode'     => adopt_postcode_normalize((string)($_POST['postcode'] ?? '')),
            'city'         => trim((string)($_POST['city'] ?? '')),
            'notes'        => trim((string)($_POST['notes'] ?? '')),
        ];
        // Nazwa wyświetlana może zostać pusta, gdy podano imię i nazwisko - składamy ją.
        if ($d['full_name'] === '') {
            $d['full_name'] = trim($d['first_name'] . ' ' . $d['last_name']);
        }
        if ($d['full_name'] === '' || ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL))
            || !adopt_postcode_valid($d['postcode'])) {
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
        return '<div class="alert alert-error">Uzupełnij imię i nazwisko (albo nazwę); e-mail (jeśli podany) '
             . 'musi być poprawny, a kod pocztowy w formacie 00-000.</div>';
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
      <label>Imię i nazwisko / nazwa *
        <input type="text" name="full_name" required value="<?= mada_esc($donor['full_name'] ?? '') ?>">
        <span class="hint">Tak darczyńca jest pokazywany na listach i w mailach. Dla instytucji i małżeństw
          wpisz nazwę, jakiej używa fundacja (np. „Parafia Kłodzko", „Ola i Tomasz Kwietniewscy").</span>
      </label>
      <div class="row2">
        <label>Imię
          <input type="text" name="first_name" value="<?= mada_esc($donor['first_name'] ?? '') ?>">
        </label>
        <label>Nazwisko
          <input type="text" name="last_name" value="<?= mada_esc($donor['last_name'] ?? '') ?>">
        </label>
      </div>
      <p class="hint" style="margin:-10px 0 16px;">Uzupełniane automatycznie ze zgłoszenia przez stronę.
         Przy instytucji albo małżeństwie można zostawić puste - wystarczy nazwa wyżej.</p>

      <label>E-mail
        <input type="email" name="email" value="<?= mada_esc($donor['email'] ?? '') ?>">
      </label>
      <label>Dodatkowe e-maile (po średniku)
        <input type="text" name="emails_extra" value="<?= mada_esc($donor['emails_extra'] ?? '') ?>">
      </label>
      <label>Telefon
        <input type="text" name="phone" value="<?= mada_esc($donor['phone'] ?? '') ?>">
      </label>

      <fieldset>
        <legend>Adres korespondencyjny</legend>
        <div class="row2">
          <label>Ulica
            <input type="text" name="street" value="<?= mada_esc($donor['street'] ?? '') ?>">
          </label>
          <label>Nr domu / lokalu
            <input type="text" name="house_no" value="<?= mada_esc($donor['house_no'] ?? '') ?>">
          </label>
        </div>
        <div class="row2">
          <label>Kod pocztowy
            <input type="text" name="postcode" placeholder="00-000" value="<?= mada_esc($donor['postcode'] ?? '') ?>">
          </label>
          <label>Miejscowość
            <input type="text" name="city" value="<?= mada_esc($donor['city'] ?? '') ?>">
          </label>
        </div>
        <p class="hint" style="margin:0;">Adres jest dobrowolny - darczyńca nie musi go podawać w formularzu.</p>
      </fieldset>

      <label>Notatki
        <textarea name="notes" rows="3"><?= mada_esc($donor['notes'] ?? '') ?></textarea>
      </label>
      <button type="submit" class="btn-primary"><?= $id ? 'Zapisz zmiany' : 'Dodaj darczyńcę' ?></button>
    </form>
<?php endif; ?>
<?php
panel_footer();
