<?php
/* ═══ CMS - dodanie / edycja adopcji (przypisanie darczyńca-dziecko) ═
   Wybór dziecka z wolnych, okres, częstotliwość, kwota, metoda,
   powiązanie subskrypcji PayU (z backfillem historycznych rat). */
require_once __DIR__ . '/layout.php';
mada_require_login();
require_once __DIR__ . '/../adopcja/db.php';
require_once __DIR__ . '/../adopcja/lib.php';
require_once __DIR__ . '/../adopcja/mail-dossier.php';


$id      = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$donorId = (int)($_GET['donor'] ?? $_POST['donor_id'] ?? 0);
$dbError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mada_csrf_check();
    try {
        adopt_db_ensure_schema();
        $donorId = (int)($_POST['donor_id'] ?? 0);
        $childId = (int)($_POST['child_id'] ?? 0) ?: null;
        $subId   = (int)($_POST['subscription_id'] ?? 0) ?: null;
        $duration = ($_POST['duration'] ?? '') === 'fixed' ? 'fixed' : 'indefinite';
        $startM  = trim((string)($_POST['start_month'] ?? '')) ?: null;
        $endM    = $duration === 'fixed' ? (trim((string)($_POST['end_month'] ?? '')) ?: null) : null;
        $freq    = in_array($_POST['frequency'] ?? '', ['monthly', 'quarterly', 'yearly'], true) ? $_POST['frequency'] : 'monthly';
        $amount  = (int)round(((float)str_replace(',', '.', (string)($_POST['kwota'] ?? '70'))) * 100);
        $method  = in_array($_POST['method'] ?? '', ['transfer', 'card', 'cash'], true) ? $_POST['method'] : 'transfer';

        $okMonths = ($startM === null || adopt_month_valid($startM))
                 && ($endM === null || adopt_month_valid($endM))
                 && ($startM === null || $endM === null || $startM <= $endM);
        if ($donorId <= 0 || $amount <= 0 || !$okMonths || ($duration === 'fixed' && $endM === null)) {
            mada_redirect('adopcja-edit.php?' . ($id ? "id=$id" : "donor=$donorId") . '&msg=invalid');
        }

        $d = [
            'donor_id' => $donorId, 'child_id' => $childId, 'subscription_id' => $subId,
            'duration' => $duration, 'start_month' => $startM, 'end_month' => $endM,
            'frequency' => $freq, 'amount_grosze' => $amount, 'method' => $method,
            'notes' => trim((string)($_POST['notes'] ?? '')),
        ];
        if ($id > 0) {
            $before = adopt_adoption_get($id);
            if (!$before) mada_redirect('darczyncy.php');
            adopt_adoption_update($id, $d);
            // Zgłoszenie ze strony (pending) dostało dziecko -> adopcja staje się aktywna.
            if ($before['status'] === 'pending' && $childId !== null) {
                payu_db()->prepare("UPDATE adopt_adoptions SET status = 'active' WHERE id = ?")->execute([$id]);
            }
            mada_audit('adoption.edit', 'adoption', $id, $d);
            $justLinked = $subId !== null && (int)($before['subscription_id'] ?? 0) !== $subId;
        } else {
            $d['status'] = 'active';
            $id = adopt_adoption_insert($d);
            mada_audit('adoption.add', 'adoption', $id, $d);
            $justLinked = $subId !== null;
        }
        // Powiązano subskrypcję PayU -> nadrobienie wpłat z historii rat (idempotentne).
        if ($justLinked) {
            $n = adopt_backfill_subscription($subId);
            if ($n > 0) mada_audit('adoption.backfill', 'adoption', $id, ['sub' => $subId, 'wplat' => $n]);
        }
        // Na życzenie pracownika: mail do darczyńcy z przedstawieniem dziecka.
        if (!empty($_POST['notify_child']) && $childId !== null) {
            $dn = adopt_donor_get($donorId);
            $ch = adopt_child_get($childId);
            if ($dn && $ch && adopt_mail_child_dossier($dn, $ch, trim((string)($_POST['personal_note'] ?? '')))) {
                adopt_adoption_mark_dossier_sent($id, mada_current_user());
                mada_audit('adoption.childmail', 'adoption', $id, ['dziecko' => $ch['name'], 'email' => $dn['email']]);
                mada_redirect("darczynca.php?id=$donorId&msg=mailok");
            }
            mada_redirect("darczynca.php?id=$donorId&msg=mailfail");
        }
        mada_redirect("darczynca.php?id=$donorId&msg=saved");
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

$adoption = null; $donor = null; $children = []; $subCands = [];
try {
    adopt_db_ensure_schema();
    if ($id > 0) {
        $adoption = adopt_adoption_get($id);
        if ($adoption) $donorId = (int)$adoption['donor_id'];
    }
    $donor = $donorId > 0 ? adopt_donor_get($donorId) : null;
    $children = adopt_child_list();
    $subCands = adopt_subscription_candidates($adoption['subscription_id'] ?? null);
} catch (Throwable $e) {
    $dbError = $dbError ?: $e->getMessage();
}

function ae_flash() {
    if (($_GET['msg'] ?? '') === 'invalid') {
        return '<div class="alert alert-error">Sprawdź pola: kwota > 0, miesiące w formacie RRRR-MM, okres OKREŚLONY wymaga miesiąca końca.</div>';
    }
    return '';
}

panel_header(($id ? 'Edycja' : 'Nowa') . ' adopcja');
?>
    <div class="bar">
      <h2 style="margin:0;"><?= $id ? 'Edycja adopcji' : 'Nowa adopcja' ?><?= $donor ? ' - ' . mada_esc($donor['full_name']) : '' ?></h2>
      <a href="<?= $donor ? 'darczynca.php?id=' . (int)$donor['id'] : 'darczyncy.php' ?>" class="btn-ghost btn-sm">← Wróć</a>
    </div>
    <?= ae_flash() ?>
<?php if ($dbError !== ''): ?>
    <div class="alert alert-error">Błąd bazy danych: <?= mada_esc($dbError) ?></div>
<?php elseif (!$donor): ?>
    <div class="alert alert-error">Najpierw wybierz darczyńcę (wejdź przez jego kartę).</div>
<?php else: ?>
    <form method="post" class="form" style="max-width:620px;">
      <?= mada_csrf_field() ?>
      <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
      <input type="hidden" name="donor_id" value="<?= (int)$donor['id'] ?>">

      <label>Dziecko
        <select name="child_id">
          <option value="">- jeszcze bez dziecka -</option>
          <?php foreach ($children as $c):
              // nieaktywne dzieci nie sa proponowane (chyba ze to obecne dziecko tej adopcji)
              if ($c['status'] !== 'active' && (int)($adoption['child_id'] ?? 0) !== (int)$c['id']) continue;
              $taken = $c['donors'] !== null && (int)($adoption['child_id'] ?? 0) !== (int)$c['id']; ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)($adoption['child_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
              nr <?= (int)$c['number'] ?> - <?= mada_esc($c['name']) ?><?= $taken ? ' (ma darczyńcę: ' . mada_esc($c['donors']) . ')' : ' (wolne)' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>Czas adopcji
        <select name="duration" id="ae-duration">
          <option value="indefinite" <?= ($adoption['duration'] ?? '') !== 'fixed' ? 'selected' : '' ?>>NIEOKREŚLONY</option>
          <option value="fixed" <?= ($adoption['duration'] ?? '') === 'fixed' ? 'selected' : '' ?>>OKREŚLONY (od-do)</option>
        </select>
      </label>
      <div style="display:flex;gap:12px;">
        <label>Start (miesiąc)<input type="month" name="start_month" value="<?= mada_esc($adoption['start_month'] ?? '') ?>"></label>
        <label>Koniec (dla OKREŚLONEGO)<input type="month" name="end_month" value="<?= mada_esc($adoption['end_month'] ?? '') ?>"></label>
      </div>

      <div style="display:flex;gap:12px;">
        <label>Częstotliwość
          <select name="frequency">
            <option value="monthly" <?= ($adoption['frequency'] ?? 'monthly') === 'monthly' ? 'selected' : '' ?>>miesięcznie</option>
            <option value="quarterly" <?= ($adoption['frequency'] ?? '') === 'quarterly' ? 'selected' : '' ?>>kwartalnie</option>
            <option value="yearly" <?= ($adoption['frequency'] ?? '') === 'yearly' ? 'selected' : '' ?>>rocznie</option>
          </select>
        </label>
        <label>Kwota / miesiąc (zł)
          <input type="text" name="kwota" value="<?= number_format(($adoption['amount_grosze'] ?? 7000) / 100, 0, ',', '') ?>" style="width:110px;">
        </label>
        <label>Metoda
          <select name="method">
            <option value="transfer" <?= ($adoption['method'] ?? 'transfer') === 'transfer' ? 'selected' : '' ?>>przelew</option>
            <option value="card" <?= ($adoption['method'] ?? '') === 'card' ? 'selected' : '' ?>>karta (PayU)</option>
            <option value="cash" <?= ($adoption['method'] ?? '') === 'cash' ? 'selected' : '' ?>>gotówka</option>
          </select>
        </label>
      </div>

      <label>Subskrypcja PayU (dla metody karta)
        <select name="subscription_id">
          <option value="">- brak powiązania -</option>
          <?php foreach ($subCands as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= (int)($adoption['subscription_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
              #<?= (int)$s['id'] ?> <?= mada_esc($s['first_name'] . ' ' . $s['last_name'] . ' <' . $s['email'] . '>') ?>,
              <?= number_format($s['amount_grosze'] / 100, 0, ',', ' ') ?> zł/mies., <?= mada_esc($s['status']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <p class="hint" style="margin:-6px 0 14px;">Po powiązaniu subskrypcji historyczne opłacone raty PayU dopiszą się automatycznie jako wpłaty.</p>

      <?php if (($donor['email'] ?? '') !== ''): ?>
      <div style="background:#fff;border:1px solid var(--rule);border-radius:12px;padding:14px 18px;margin:0 0 14px;">
        <label style="display:flex;align-items:center;gap:8px;margin:0 0 8px;">
          <input type="checkbox" name="notify_child" value="1" style="width:auto;">
          📧 Po zapisaniu wyślij darczyńcy mail-dossier z przedstawieniem dziecka
        </label>
        <label style="margin:0;">Osobista wiadomość w mailu (opcjonalnie)
          <textarea name="personal_note" rows="3"
            placeholder="Np. Alvin uwielbia rysować i właśnie zaczął naukę w Centrum Edukacyjnym prowadzonym przez Siostry..."></textarea>
        </label>
        <p class="hint" style="margin:6px 0 0;">Mail pójdzie na <?= mada_esc($donor['email']) ?> w szablonie fundacji: zdjęcie, imię i nazwisko,
           data urodzenia, rodzice, opis sytuacji (dane z <a href="dzieci.php">Podopieczni -> Edytuj</a>) + Twój dopisek.
           Data wysyłki zostaje odnotowana przy adopcji (kolumna „Dossier" na karcie darczyńcy).
           Bez zaznaczenia nic się nie wysyła.</p>
      </div>
      <?php endif; ?>

      <label>Notatki
        <textarea name="notes" rows="3"><?= mada_esc($adoption['notes'] ?? '') ?></textarea>
      </label>

      <button type="submit" class="btn-primary"><?= $id ? 'Zapisz zmiany' : 'Utwórz adopcję' ?></button>
    </form>
<?php endif; ?>
<?php
panel_footer();
