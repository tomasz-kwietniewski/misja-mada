<?php
/* ═══ CMS - karta darczyńcy Adopcji Serca ═════════════════════════
   Dane, adopcje z pokryciem, historia wpłat, szybka wpłata,
   akcje: zakończ adopcję / wznów po przerwie / usuń błędną wpłatę. */
require_once __DIR__ . '/layout.php';
mada_require_login();
require_once __DIR__ . '/../adopcja/db.php';
require_once __DIR__ . '/../adopcja/lib.php';
require_once __DIR__ . '/../adopcja/mail-dossier.php';

$id = (int)($_GET['id'] ?? $_POST['donor_id'] ?? 0);
$dbError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mada_csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        adopt_db_ensure_schema();

        if ($action === 'payment') {
            $adoptionId = (int)($_POST['adoption_id'] ?? 0);
            $ad = $adoptionId > 0 ? adopt_adoption_get($adoptionId) : null;
            if (!$ad || (int)$ad['donor_id'] !== $id) mada_redirect("darczynca.php?id=$id&msg=badadopt");
            $amount = (int)round(((float)str_replace(',', '.', (string)($_POST['kwota'] ?? '0'))) * 100);
            $from = trim((string)($_POST['od'] ?? ''));
            $to   = trim((string)($_POST['do'] ?? '')) ?: $from;
            $paid = trim((string)($_POST['data'] ?? '')) ?: date('Y-m-d');
            $method = in_array($_POST['metoda'] ?? '', ['transfer', 'cash', 'card'], true) ? $_POST['metoda'] : 'transfer';
            if ($amount <= 0 || !adopt_month_valid($from) || !adopt_month_valid($to) || $to < $from
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid)) {
                mada_redirect("darczynca.php?id=$id&msg=badpay");
            }
            $pid = adopt_payment_insert([
                'adoption_id' => $adoptionId, 'amount_grosze' => $amount, 'paid_at' => $paid,
                'period_from' => $from, 'period_to' => $to, 'method' => $method,
                'note' => trim((string)($_POST['notatka'] ?? '')) ?: null,
                'created_by' => mada_current_user(),
            ]);
            adopt_adoption_backfill_start($adoptionId);
            mada_audit('payment.add', 'payment', $pid,
                ['adoption' => $adoptionId, 'kwota' => $amount, 'okres' => "$from..$to"]);
            mada_redirect("darczynca.php?id=$id&msg=payok");
        }

        if ($action === 'savenotes') {
            $donor = adopt_donor_get($id);
            if (!$donor) mada_redirect('darczyncy.php');
            $notes = trim((string)($_POST['notes'] ?? ''));
            $st = payu_db()->prepare('UPDATE adopt_donors SET notes = ? WHERE id = ?');
            $st->execute([$notes !== '' ? $notes : null, $id]);
            mada_audit('donor.notes', 'donor', $id, ['notes' => $notes]);
            mada_redirect("darczynca.php?id=$id&msg=noteok");
        }

        if ($action === 'delpayment') {
            $pid = (int)($_POST['payment_id'] ?? 0);
            $p = adopt_payment_get($pid);
            if ($p) {
                $ad = adopt_adoption_get((int)$p['adoption_id']);
                if ($ad && (int)$ad['donor_id'] === $id) {
                    adopt_payment_delete($pid);
                    mada_audit('payment.delete', 'payment', $pid, $p);
                    mada_redirect("darczynca.php?id=$id&msg=paydel");
                }
            }
            mada_redirect("darczynca.php?id=$id&msg=badpay");
        }

        /* Skrót „Wyślij dossier": ten sam mail co przy przypisaniu dziecka
           (adopcja-edit.php), bez wchodzenia w edycję adopcji. Bez dopisku -
           spersonalizowaną wiadomość dodaje się w edycji adopcji. */
        if ($action === 'senddossier') {
            $adoptionId = (int)($_POST['adoption_id'] ?? 0);
            $ad = $adoptionId > 0 ? adopt_adoption_get($adoptionId) : null;
            if (!$ad || (int)$ad['donor_id'] !== $id || $ad['child_id'] === null) {
                mada_redirect("darczynca.php?id=$id&msg=badadopt");
            }
            $dn = adopt_donor_get($id);
            $ch = adopt_child_get((int)$ad['child_id']);
            if ($dn && $ch && adopt_mail_child_dossier($dn, $ch, '')) {
                adopt_adoption_mark_dossier_sent($adoptionId, mada_current_user());
                mada_audit('adoption.childmail', 'adoption', $adoptionId,
                    ['dziecko' => $ch['name'], 'email' => $dn['email'], 'skrot' => true]);
                mada_redirect("darczynca.php?id=$id&msg=mailok");
            }
            mada_redirect("darczynca.php?id=$id&msg=mailfail");
        }

        if ($action === 'end') {
            $adoptionId = (int)($_POST['adoption_id'] ?? 0);
            $ad = $adoptionId > 0 ? adopt_adoption_get($adoptionId) : null;
            if (!$ad || (int)$ad['donor_id'] !== $id) mada_redirect("darczynca.php?id=$id&msg=badadopt");
            $endM = trim((string)($_POST['koniec'] ?? '')) ?: date('Y-m');
            if (!adopt_month_valid($endM)) mada_redirect("darczynca.php?id=$id&msg=badpay");
            adopt_adoption_end($adoptionId, $endM);
            mada_audit('adoption.end', 'adoption', $adoptionId, ['end_month' => $endM]);
            mada_redirect("darczynca.php?id=$id&msg=ended");
        }

        if ($action === 'resume') {
            $adoptionId = (int)($_POST['adoption_id'] ?? 0);
            $ad = $adoptionId > 0 ? adopt_adoption_get($adoptionId) : null;
            if (!$ad || (int)$ad['donor_id'] !== $id) mada_redirect("darczynca.php?id=$id&msg=badadopt");
            $startM = trim((string)($_POST['start'] ?? '')) ?: date('Y-m');
            if (!adopt_month_valid($startM)) mada_redirect("darczynca.php?id=$id&msg=badpay");
            $newId = adopt_adoption_resume($adoptionId, $startM);
            mada_audit('adoption.resume', 'adoption', $newId, ['po' => $adoptionId, 'start_month' => $startM]);
            mada_redirect("darczynca.php?id=$id&msg=resumed");
        }
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

function dk_flash() {
    $codes = [
        'payok'    => ['ok',    'Wpłata została odnotowana.'],
        'noteok'   => ['ok',    'Notatki zostały zapisane.'],
        'mailok'   => ['ok',    'Mail z przedstawieniem dziecka (dossier) został wysłany - data wysyłki jest odnotowana przy adopcji.'],
        'mailfail' => ['error', 'Mail do darczyńcy NIE został wysłany (brak adresu albo błąd wysyłki). Zmiany w adopcji zostały zapisane.'],
        'paydel'   => ['ok',    'Wpłata została usunięta.'],
        'ended'    => ['ok',    'Adopcja została zakończona (miesiące po końcu nie liczą się jako zaległość).'],
        'resumed'  => ['ok',    'Adopcja wznowiona jako nowy okres - przerwa nie liczy się jako zaległość.'],
        'saved'    => ['ok',    'Zapisano zmiany.'],
        'badpay'   => ['error', 'Nieprawidłowe dane (kwota, miesiące YYYY-MM albo data).'],
        'badadopt' => ['error', 'Nieprawidłowa adopcja.'],
    ];
    $m = $_GET['msg'] ?? '';
    if (!isset($codes[$m])) return '';
    [$t, $txt] = $codes[$m];
    return '<div class="alert alert-' . ($t === 'ok' ? 'ok' : 'error') . '">' . mada_esc($txt) . '</div>';
}

$donor = null; $ads = []; $pays = []; $subs = []; $sharing = [];
try {
    adopt_db_ensure_schema();
    $donor = adopt_donor_get($id);
    if ($donor) {
        $sharing = adopt_donors_sharing_email($donor['email'] ?? null, $id);
        $ads = adopt_adoptions_by_donor($id);
        $pays = adopt_payments_by_adoptions(array_column($ads, 'id'));
        foreach ($ads as $a) {
            if ($a['subscription_id'] !== null) {
                $s = payu_sub_get((int)$a['subscription_id']);
                if ($s) $subs[(int)$a['subscription_id']] = $s;
            }
        }
    }
} catch (Throwable $e) {
    $dbError = $dbError ?: $e->getMessage();
}

$today = date('Y-m-d');
$methodLabel = ['transfer' => 'przelew', 'card' => 'karta', 'cash' => 'gotówka'];
$statusLabel = ['pending' => 'oczekująca', 'active' => 'aktywna', 'ended' => 'zakończona', 'cancelled' => 'anulowana'];

panel_header('Darczyńca - Adopcja Serca');
?>
    <div class="bar">
      <h2 style="margin:0;"><?= $donor ? mada_esc($donor['full_name']) : 'Darczyńca' ?></h2>
      <span>
        <a href="darczyncy.php" class="btn-ghost btn-sm">← Wróć do listy darczyńców</a>
        <?php if ($donor): ?><a href="darczynca-edit.php?id=<?= (int)$donor['id'] ?>" class="btn-secondary btn-sm">Edytuj dane</a>
        <a href="adopcja-edit.php?donor=<?= (int)$donor['id'] ?>" class="btn-primary btn-sm">+ Nowa adopcja</a><?php endif; ?>

      </span>
    </div>
    <?= dk_flash() ?>

<?php if ($dbError !== ''): ?>
    <div class="alert alert-error">Błąd bazy danych: <?= mada_esc($dbError) ?></div>
<?php elseif (!$donor): ?>
    <div class="alert alert-error">Nie znaleziono darczyńcy.</div>
<?php else: ?>
    <?php if ($sharing): ?>
      <div class="alert alert-warn">
        <b>Ten sam adres e-mail ma jeszcze <?= count($sharing) === 1 ? 'inny darczyńca' : count($sharing) . ' innych darczyńców' ?>:</b>
        <?php foreach ($sharing as $i => $s): ?><?= $i ? ', ' : ' ' ?><a href="darczynca.php?id=<?= (int)$s['id'] ?>"><?= mada_esc($s['full_name']) ?></a><?php endforeach; ?>.
        Zdarza się, że jedna osoba zgłasza kogoś ze swojej skrzynki (np. proboszcz zgłaszający mamę) -
        sprawdź, czy adopcje wiszą przy właściwej osobie. Adopcję przenosi się przez „Edytuj" przy adopcji.
      </div>
    <?php endif; ?>

    <?php
      $adres = adopt_address_compose($donor);
      $imieNazwisko = trim(((string)($donor['first_name'] ?? '')) . ' ' . ((string)($donor['last_name'] ?? '')));
    ?>
    <div class="donor-card">
      <div><span class="dc-label">E-mail</span>
        <?php if (($donor['email'] ?? '') !== ''): ?>
          <a href="mailto:<?= mada_esc($donor['email']) ?>"><?= mada_esc($donor['email']) ?></a>
        <?php else: ?><span class="hint">nie podano</span><?php endif; ?>
        <?= $donor['emails_extra'] ? '<br><span class="hint">dodatkowe: ' . mada_esc($donor['emails_extra']) . '</span>' : '' ?>
      </div>
      <div><span class="dc-label">Telefon</span>
        <?php if (($donor['phone'] ?? '') !== ''): ?>
          <a href="tel:<?= mada_esc(preg_replace('/[^\d+]/', '', (string)$donor['phone'])) ?>"><?= mada_esc($donor['phone']) ?></a>
        <?php else: ?><span class="hint">nie podano</span><?php endif; ?>
      </div>
      <div><span class="dc-label">Adres korespondencyjny</span>
        <?php if ($adres !== ''): ?>
          <?= implode('<br>', array_map('mada_esc', adopt_address_lines($donor))) ?>
        <?php else: ?><span class="hint">nie podano</span><?php endif; ?>
      </div>
      <div><span class="dc-label">Imię i nazwisko</span>
        <?= $imieNazwisko !== '' ? mada_esc($imieNazwisko) : '<span class="hint">tylko nazwa wyświetlana</span>' ?>
      </div>
      <div><span class="dc-label">Źródło</span>
        <?= mada_esc(['site' => 'zgłoszenie ze strony', 'import' => 'import z arkusza', 'manual' => 'wpis ręczny'][$donor['source']] ?? $donor['source']) ?>
        <br><span class="hint">dodany <?= mada_esc(date('d.m.Y', strtotime((string)$donor['created_at']))) ?></span>
      </div>
    </div>

    <details class="donor-notes" <?= $donor['notes'] ? 'open' : '' ?> style="margin:0 0 20px;">
      <summary style="cursor:pointer;font-weight:600;color:var(--brown);">📝 Notatki fundacji<?= $donor['notes'] ? '' : ' <span class="hint">(brak - kliknij, aby dodać)</span>' ?></summary>
      <form method="post" style="margin:8px 0 0;max-width:640px;">
        <?= mada_csrf_field() ?>
        <input type="hidden" name="action" value="savenotes">
        <input type="hidden" name="donor_id" value="<?= (int)$donor['id'] ?>">
        <textarea name="notes" rows="3" style="width:100%;padding:10px 12px;border:1px solid var(--rule);border-radius:9px;font:inherit;"
                  placeholder="Np. prosiła o kontakt telefoniczny; wpłaca z konta męża; wróci do adopcji od stycznia..."><?= mada_esc($donor['notes'] ?? '') ?></textarea>
        <button type="submit" class="btn-secondary btn-sm" style="margin-top:6px;">Zapisz notatki</button>
      </form>
    </details>

    <h3>Adopcje</h3>
    <?php if (!$ads): ?><p class="hint">Brak adopcji.</p><?php else: ?>
    <table class="events">
      <thead><tr><th>Dziecko</th><th>Okres</th><th>Częst.</th><th>Kwota</th><th>Metoda</th><th>Status</th><th>Opłacone do</th><th>Zaległość</th><th>Dossier</th><th>Akcje</th></tr></thead>
      <tbody>
      <?php foreach ($ads as $a):
          $p = $pays[(int)$a['id']] ?? [];
          $pu = adopt_paid_until($p);
          $isOpen = in_array($a['status'], ['pending', 'active'], true);
          $miss = ($isOpen && $a['start_month'] !== null)
                ? adopt_arrears($a['start_month'], $a['end_month'], $p, $today) : [];
      ?>
        <tr>
          <td><?= $a['child_name'] !== null ? mada_esc($a['child_name']) . ' <span class="hint">(nr ' . (int)$a['child_number'] . ')</span>' : '<span class="hint">bez dziecka</span>' ?></td>
          <td><?= mada_esc(adopt_month_label($a['start_month'])) ?> - <?= $a['end_month'] !== null ? mada_esc(adopt_month_label($a['end_month'])) : 'bezterm.' ?></td>
          <td><?= ['monthly' => 'mies.', 'quarterly' => 'kwart.', 'yearly' => 'roczna'][$a['frequency']] ?? '' ?></td>
          <td><?= number_format($a['amount_grosze'] / 100, 0, ',', ' ') ?> zł</td>
          <td><?= $methodLabel[$a['method']] ?? $a['method'] ?><?php if ($a['subscription_id'] !== null): ?> <span class="hint">(sub #<?= (int)$a['subscription_id'] ?>)</span><?php endif; ?></td>
          <td><?= $statusLabel[$a['status']] ?? $a['status'] ?></td>
          <td><?= mada_esc(adopt_month_label($pu)) ?></td>
          <td><?php if ($miss): ?><span class="badge badge-err"><?= count($miss) ?> mies.</span>
              <?php elseif ($isOpen && $a['start_month'] !== null): ?><span class="badge badge-ok">OK</span>
              <?php else: ?><span class="hint">-</span><?php endif; ?></td>
          <?php
            /* „Dossier" = czy do darczyńcy poszedł mail z przedstawieniem TEGO dziecka.
               Zapisywane w chwili realnej wysyłki, więc „nie wysłano" jest wiarygodne. */
            $dsAt = $a['dossier_sent_at'] ?? null;
            $dsCnt = (int)($a['dossier_sent_count'] ?? 0);
          ?>
          <td><?php if ($a['child_id'] === null): ?><span class="hint">-</span>
              <?php elseif ($dsAt !== null): ?>
                <span class="badge badge-ok">wysłane</span><br>
                <span class="hint"><?= mada_esc(date('d.m.Y', strtotime((string)$dsAt))) ?><?php
                  if ($a['dossier_sent_by']) echo ', ' . mada_esc((string)$a['dossier_sent_by']);
                  if ($dsCnt > 1) echo ' (×' . $dsCnt . ')';
                ?></span>
              <?php else: ?><span class="badge badge-err">nie wysłano</span><?php endif; ?></td>
          <td style="white-space:nowrap;">
            <a class="btn-secondary btn-sm" href="adopcja-edit.php?id=<?= (int)$a['id'] ?>">Edytuj</a>
            <?php if ($a['child_id'] !== null && ($donor['email'] ?? '') !== ''): ?>
              <form method="post" style="display:inline;"
                    onsubmit="return confirm('<?= $dsAt !== null ? 'Dossier tego dziecka zostało już wysłane. Wysłać PONOWNIE' : 'Wysłać' ?> do <?= mada_esc($donor['email']) ?> mail z przedstawieniem dziecka <?= mada_esc($a['child_name'] ?? '') ?>?\n\nOsobisty dopisek dodasz przez „Edytuj”.');">
                <?= mada_csrf_field() ?>
                <input type="hidden" name="action" value="senddossier">
                <input type="hidden" name="donor_id" value="<?= (int)$donor['id'] ?>">
                <input type="hidden" name="adoption_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="btn-secondary btn-sm"
                        title="<?= $dsAt !== null ? 'Wyślij dossier jeszcze raz' : 'Wyślij darczyńcy dossier dziecka' ?>">
                  📧 <?= $dsAt !== null ? 'Wyślij ponownie' : 'Wyślij dossier' ?>
                </button>
              </form>
            <?php endif; ?>
            <?php if ($isOpen): ?>
              <form method="post" style="display:inline;" onsubmit="return confirm('Zakończyć adopcję z końcem bieżącego miesiąca?');">
                <?= mada_csrf_field() ?>
                <input type="hidden" name="action" value="end">
                <input type="hidden" name="donor_id" value="<?= (int)$donor['id'] ?>">
                <input type="hidden" name="adoption_id" value="<?= (int)$a['id'] ?>">
                <input type="hidden" name="koniec" value="<?= date('Y-m') ?>">
                <button type="submit" class="btn-danger btn-sm">Zakończ</button>
              </form>
            <?php else: ?>
              <form method="post" style="display:inline;" onsubmit="return confirm('Wznowić adopcję od bieżącego miesiąca (nowy okres)?');">
                <?= mada_csrf_field() ?>
                <input type="hidden" name="action" value="resume">
                <input type="hidden" name="donor_id" value="<?= (int)$donor['id'] ?>">
                <input type="hidden" name="adoption_id" value="<?= (int)$a['id'] ?>">
                <input type="hidden" name="start" value="<?= date('Y-m') ?>">
                <button type="submit" class="btn-secondary btn-sm">Wznów</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if ($subs): ?>
      <p class="hint">Subskrypcje kartowe: <?php foreach ($subs as $s): ?>
        <span class="badge badge-<?= $s['status'] === 'active' ? 'ok' : 'arch' ?>">#<?= (int)$s['id'] ?> <?= mada_esc($s['status']) ?>, <?= mada_esc($s['card_mask'] ?: '') ?></span>
      <?php endforeach; ?> · <a href="subskrypcje.php">zarządzaj →</a></p>
    <?php endif; ?>

    <?php $openAds = array_values(array_filter($ads, fn($a) => in_array($a['status'], ['pending', 'active'], true))); ?>
    <?php if ($openAds): ?>
    <h3>Odnotuj wpłatę</h3>
    <form method="post" class="form" style="max-width:760px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
      <?= mada_csrf_field() ?>
      <input type="hidden" name="action" value="payment">
      <input type="hidden" name="donor_id" value="<?= (int)$donor['id'] ?>">
      <label style="flex:2;min-width:200px;">Adopcja
        <select name="adoption_id" required>
          <?php foreach ($openAds as $a): ?>
            <option value="<?= (int)$a['id'] ?>"><?= $a['child_name'] !== null ? mada_esc($a['child_name']) . ' (nr ' . (int)$a['child_number'] . ')' : 'bez dziecka' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Kwota (zł)<input type="text" name="kwota" value="70" required style="width:90px;"></label>
      <label>Miesiąc od<input type="month" name="od" value="<?= date('Y-m') ?>" required></label>
      <label>do (opcjonalnie)<input type="month" name="do"></label>
      <label>Data wpłaty<input type="date" name="data" value="<?= date('Y-m-d') ?>"></label>
      <label>Metoda
        <select name="metoda">
          <option value="transfer">przelew</option>
          <option value="cash">gotówka</option>
        </select>
      </label>
      <label style="flex:1;min-width:160px;">Notatka<input type="text" name="notatka"></label>
      <button type="submit" class="btn-primary">Zapisz wpłatę</button>
    </form>
    <p class="hint">Wpłata kwartalna/roczna: podaj zakres „od-do" (np. 01.2026 do 12.2026) i pełną kwotę - pokrycie liczy się z zakresu.</p>
    <?php endif; ?>

    <h3>Historia wpłat</h3>
    <?php
      $allPays = [];
      foreach ($ads as $a) {
          foreach (($pays[(int)$a['id']] ?? []) as $p) $allPays[] = $p + ['child' => $a['child_name'], 'child_no' => $a['child_number']];
      }
      usort($allPays, fn($x, $y) => strcmp($y['period_from'], $x['period_from']) ?: ($y['id'] <=> $x['id']));
    ?>
    <?php if (!$allPays): ?><p class="hint">Brak wpłat.</p><?php else: ?>
    <table class="events">
      <thead><tr><th>Okres</th><th>Dziecko</th><th>Kwota</th><th>Data</th><th>Metoda</th><th>Notatka</th><th>Kto</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($allPays as $p): ?>
        <tr>
          <td><?= mada_esc(adopt_month_label($p['period_from'])) ?><?= $p['period_to'] !== $p['period_from'] ? ' - ' . mada_esc(adopt_month_label($p['period_to'])) : '' ?></td>
          <td><?= $p['child'] !== null ? mada_esc($p['child']) : '-' ?></td>
          <td><?= number_format($p['amount_grosze'] / 100, 2, ',', ' ') ?> zł</td>
          <td><?= mada_esc($p['paid_at']) ?></td>
          <td><?= $methodLabel[$p['method']] ?? $p['method'] ?></td>
          <td class="hint"><?= mada_esc($p['note'] ?? '') ?></td>
          <td class="hint"><?= mada_esc($p['created_by'] ?? 'auto') ?></td>
          <td>
            <?php if ($p['charge_id'] === null): ?>
            <form method="post" style="display:inline;" onsubmit="return confirm('Usunąć tę wpłatę? Tego nie można cofnąć.');">
              <?= mada_csrf_field() ?>
              <input type="hidden" name="action" value="delpayment">
              <input type="hidden" name="donor_id" value="<?= (int)$donor['id'] ?>">
              <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
              <button type="submit" class="btn-danger btn-sm">Usuń</button>
            </form>
            <?php else: ?><span class="hint">PayU</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
<?php endif; ?>
<?php
panel_footer();
