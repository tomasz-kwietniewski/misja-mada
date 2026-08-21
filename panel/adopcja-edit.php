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
/* Do tego ekranu wchodzi się z DWÓCH stron: z karty darczyńcy („+ Nowa adopcja",
   „Edytuj") i z karty dziecka („Zmień darczyńcę", „+ Przypisz darczyńcę").
   `child` podpowiada dziecko przy nowej adopcji, `back=dziecko` odsyła po zapisie
   tam, skąd pracownik przyszedł - inaczej naprawa z poziomu dziecka wyrzucała go
   na kartę obcego darczyńcy. */
$childId = (int)($_GET['child'] ?? $_POST['back_child'] ?? 0);
$back    = (($_GET['back'] ?? $_POST['back'] ?? '') === 'dziecko') ? 'dziecko' : '';
$dbError = '';

/** Powrót po zapisie: na kartę dziecka albo (domyślnie) na kartę darczyńcy. */
function ae_done(string $back, int $donorId, ?int $childId, string $msg): void {
    if ($back === 'dziecko' && $childId !== null && $childId > 0) {
        // Karta dziecka ma własne komunikaty - „saved" znaczy tam co innego.
        mada_redirect('dzieci.php?edit=' . $childId . '&msg=' . ($msg === 'saved' ? 'adoptok' : $msg) . '#formularz');
    }
    mada_redirect("darczynca.php?id=$donorId&msg=$msg");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mada_csrf_check();
    try {
        adopt_db_ensure_schema();
        /* Usunięcie adopcji - furtka na pomyłki (to samo dziecko wpisane dwa razy,
           adopcja założona nie temu darczyńcy). Wpłaty blokują operację. */
        if (($_POST['action'] ?? '') === 'delete') {
            $ad = $id > 0 ? adopt_adoption_get($id) : null;
            if (!$ad) mada_redirect('darczyncy.php');
            $ofDonor = (int)$ad['donor_id'];
            $ofChild = $ad['child_id'] !== null ? (int)$ad['child_id'] : null;
            if (adopt_adoption_delete_if_unpaid($id)) {
                mada_audit('adoption.delete', 'adoption', $id, [
                    'darczynca' => $ad['donor_name'], 'dziecko' => $ad['child_name'],
                    'okres' => ($ad['start_month'] ?? '?') . ' do ' . ($ad['end_month'] ?? 'bezterm.'),
                    'status' => $ad['status'],
                ]);
                ae_done($back, $ofDonor, $ofChild, 'adoptdel');
            }
            ae_done($back, $ofDonor, $ofChild, 'adopthaspay');
        }
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
            $q = $id ? "id=$id" : "donor=$donorId&child=$childId";
            mada_redirect('adopcja-edit.php?' . $q . ($back ? '&back=dziecko' : '') . '&msg=invalid');
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
            // Przeniesienie do innego darczyńcy zostawia wyraźny ślad w audycie -
            // to operacja na cudzej historii wpłat, więc musi być odtwarzalna.
            if ((int)$before['donor_id'] !== $donorId) {
                mada_audit('adoption.movedonor', 'adoption', $id,
                    ['z' => (int)$before['donor_id'], 'do' => $donorId, 'dziecko' => $before['child_name']]);
            }
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
                ae_done($back, $donorId, $childId, 'mailok');
            }
            ae_done($back, $donorId, $childId, 'mailfail');
        }
        ae_done($back, $donorId, $childId, 'saved');
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

$adoption = null; $donor = null; $children = []; $subCands = []; $donorOpts = [];
$payCnt = 0; $backChild = null; $openByOthers = [];
try {
    adopt_db_ensure_schema();
    if ($id > 0) {
        $adoption = adopt_adoption_get($id);
        if ($adoption) {
            $donorId = (int)$adoption['donor_id'];
            $payCnt = count(adopt_payments_by_adoption($id));
        }
    }
    $donor = $donorId > 0 ? adopt_donor_get($donorId) : null;
    $children = adopt_child_list();
    $subCands = adopt_subscription_candidates($adoption['subscription_id'] ?? null);
    $donorOpts = adopt_donor_options();
    $openByOthers = adopt_children_open_donors($id > 0 ? $id : null);
    // Dziecko, na którego kartę wracamy - podane w adresie albo wzięte z adopcji.
    if ($childId <= 0 && $adoption && $adoption['child_id'] !== null) $childId = (int)$adoption['child_id'];
    $backChild = $childId > 0 ? adopt_child_get($childId) : null;
} catch (Throwable $e) {
    $dbError = $dbError ?: $e->getMessage();
}

function ae_flash() {
    if (($_GET['msg'] ?? '') === 'invalid') {
        return '<div class="alert alert-error">Sprawdź pola: darczyńca musi być wybrany, kwota > 0, miesiące w formacie RRRR-MM, okres OKREŚLONY wymaga miesiąca końca.</div>';
    }
    return '';
}

/* Adres powrotny („← Wróć" i po zapisie): karta dziecka, jeśli stamtąd przyszliśmy. */
$backUrl   = $back === 'dziecko' && $childId > 0
           ? 'dzieci.php?edit=' . $childId . '#formularz'
           : ($donor ? 'darczynca.php?id=' . (int)$donor['id'] : 'darczyncy.php');
$backLabel = $back === 'dziecko' && $backChild
           ? '← Wróć do dziecka: nr ' . (int)$backChild['number'] . ' - ' . $backChild['name']
           : '← Wróć';

panel_header(($id ? 'Edycja' : 'Nowa') . ' adopcja');
?>
    <div class="bar">
      <h2 style="margin:0;"><?= $id ? 'Edycja adopcji' : 'Nowa adopcja' ?><?= $donor ? ' - ' . mada_esc($donor['full_name']) : '' ?></h2>
      <a href="<?= mada_esc($backUrl) ?>" class="btn-ghost btn-sm"><?= mada_esc($backLabel) ?></a>
    </div>
    <?= ae_flash() ?>
<?php if ($dbError !== ''): ?>
    <div class="alert alert-error">Błąd bazy danych: <?= mada_esc($dbError) ?></div>
<?php elseif (!$donor && $childId <= 0): ?>
    <div class="alert alert-error">Najpierw wybierz darczyńcę (wejdź przez jego kartę) albo dziecko (karta podopiecznego).</div>
<?php else: ?>
    <form method="post" class="form" style="max-width:620px;"
          onsubmit="return ae_confirm_taken(this);">
      <?= mada_csrf_field() ?>
      <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
      <?php if ($back === 'dziecko'): ?>
        <input type="hidden" name="back" value="dziecko">
        <input type="hidden" name="back_child" value="<?= $childId ?>">
      <?php endif; ?>

      <label>Darczyńca
        <select name="donor_id" required>
          <?php if (!$donor): ?><option value="">- wybierz darczyńcę -</option><?php endif; ?>
          <?php foreach ($donorOpts as $o): ?>
            <option value="<?= (int)$o['id'] ?>" <?= $donor && (int)$o['id'] === (int)$donor['id'] ? 'selected' : '' ?>>
              <?php /* Nawiasy okrągłe, nie ostre: „<mail@…>" przeglądarka zjada jako tag. */ ?>
              <?= mada_esc($o['full_name']) ?><?= $o['email'] ? ' (' . mada_esc($o['email']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Zmiana tutaj <b>przenosi całą adopcję razem z jej wpłatami</b> do innego
          darczyńcy. Tak naprawia się dwie sytuacje: zgłoszenie, które wpadło pod cudzy wpis przez
          wspólny adres e-mail, oraz scalenie dwóch wpisów tej samej osoby (przenieś adopcje na jeden,
          drugi zostanie pusty i da się go usunąć z jego karty). Przeniesienie zapisuje się w audycie.</span>
      </label>

      <?php
        $curChild = (int)($adoption['child_id'] ?? 0);
        // Nowa adopcja z karty dziecka wchodzi tu z gotowym dzieckiem w adresie.
        $selChild = $curChild > 0 ? $curChild : $childId;
      ?>
      <label>Dziecko
        <select name="child_id" id="ae-child">
          <option value="">- jeszcze bez dziecka -</option>
          <?php foreach ($children as $c):
              // nieaktywne dzieci nie sa proponowane (chyba ze to obecne dziecko tej adopcji)
              if ($c['status'] !== 'active' && $curChild !== (int)$c['id'] && $selChild !== (int)$c['id']) continue;
              /* „Zajęte" liczone z pominięciem TEJ adopcji - inaczej dziecko z drugim,
                 równoległym wpisem pokazywało się jako wolne. */
              $taken = $openByOthers[(int)$c['id']] ?? null; ?>
            <option value="<?= (int)$c['id'] ?>" <?= $selChild === (int)$c['id'] ? 'selected' : '' ?>
                    <?= $taken !== null ? 'data-taken="' . mada_esc($taken) . '"' : '' ?>>
              nr <?= (int)$c['number'] ?> - <?= mada_esc($c['name']) ?><?= $taken !== null ? ' (ma darczyńcę: ' . mada_esc($taken) . ')' : ' (wolne)' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php /* Ostrzeżenie o dziecku, które ma już opiekuna. Sama adnotacja w opcji
               selecta okazała się za cicha: tak powstał DUBEL tej samej dziewczynki
               u tej samej darczyni (dwie adopcje, jedna zakończona ręcznie). */ ?>
      <div id="ae-taken" class="alert alert-error" style="display:none;margin:-8px 0 14px;"></div>

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

    <?php if ($id && $adoption): ?>
    <?php
      /* Usuwanie adopcji to jedyna nieodwracalna operacja na tym ekranie, więc jest
         zwinięta i wymaga przepisania numeru. Służy WYŁĄCZNIE do pomyłek: dwa wpisy
         tego samego dziecka u tego samego darczyńcy, adopcja założona nie tej osobie.
         Rezygnacja darczyńcy to „Zakończ" na jego karcie - okres i historia zostają. */
    ?>
    <details class="danger-zone" style="max-width:620px;margin:18px 0 0;">
      <summary>Usuń tę adopcję z bazy</summary>
      <?php if ($payCnt > 0): ?>
        <p class="hint" style="margin:10px 0 0;">
          <b>Tej adopcji nie można usunąć.</b> Wisi przy niej <?= $payCnt ?>
          <?= $payCnt === 1 ? 'wpłata' : 'wpłat(y)' ?> - skasowanie zabrałoby historię
          i rozjechało sprawozdania. Jeśli darczyńca kończy wsparcie, użyj
          <b>„Zakończ"</b> na jego karcie; jeśli wpis trafił do złej osoby, przenieś go
          selectem <b>„Darczyńca"</b> wyżej.
        </p>
      <?php else: ?>
        <p style="margin:10px 0 12px;color:var(--err);font-weight:600;">Uwaga: tej operacji NIE DA SIĘ COFNĄĆ.</p>
        <p class="hint" style="margin:0 0 12px;">
          Usuwaj tylko wpisy powstałe <b>przez pomyłkę</b> - np. to samo dziecko dopisane
          drugi raz temu samemu darczyńcy. Przy tej adopcji nie ma żadnej wpłaty, więc nic
          z historii wpłat nie przepadnie. Zakończenie wsparcia robi się przyciskiem
          <b>„Zakończ"</b> (zostawia okres i historię), a nie kasowaniem.
        </p>
        <form method="post" style="margin:0;"
              onsubmit="var v=this.potwierdz.value.trim();
                        if (v !== '<?= $id ?>') { alert('Aby usunąć, wpisz numer adopcji: <?= $id ?>'); return false; }
                        return confirm('OSTATNIE OSTRZEŻENIE\n\nUsunąć adopcję #<?= $id ?> (<?= mada_esc($adoption['donor_name']) ?> - <?= mada_esc($adoption['child_name'] ?? 'bez dziecka') ?>)?\n\nTej operacji nie da się cofnąć.');">
          <?= mada_csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $id ?>">
          <?php if ($back === 'dziecko'): ?>
            <input type="hidden" name="back" value="dziecko">
            <input type="hidden" name="back_child" value="<?= $childId ?>">
          <?php endif; ?>
          <label style="max-width:320px;margin:0 0 10px;">Aby potwierdzić, przepisz numer adopcji (<b><?= $id ?></b>)
            <input type="text" name="potwierdz" autocomplete="off" inputmode="numeric" placeholder="numer adopcji">
          </label>
          <button type="submit" class="btn-danger btn-sm">Usuń adopcję #<?= $id ?> na zawsze</button>
        </form>
      <?php endif; ?>
    </details>
    <?php endif; ?>

    <script>
    /* Dziecko z opiekunem wybrane po raz drugi = najczęstsza pomyłka na tym ekranie.
       Adnotacja w opcji selecta okazała się za cicha, więc jest jeszcze czerwona
       ramka pod polem i pytanie przy zapisie. */
    (function () {
      var sel = document.getElementById('ae-child');
      var box = document.getElementById('ae-taken');
      if (!sel || !box) return;
      function takenBy() {
        var o = sel.options[sel.selectedIndex];
        return o ? o.getAttribute('data-taken') : null;
      }
      function refresh() {
        var t = takenBy();
        box.style.display = t ? 'block' : 'none';
        if (t) box.textContent = 'Uwaga: to dziecko ma już opiekuna (' + t + '). '
          + 'Jeśli chcesz je tylko przepiąć, zmień darczyńcę przy TAMTEJ adopcji zamiast zakładać drugą - '
          + 'inaczej to samo dziecko będzie liczone dwa razy.';
      }
      sel.addEventListener('change', refresh);
      refresh();
      window.ae_confirm_taken = function () {
        var t = takenBy();
        return !t || confirm('To dziecko ma już opiekuna: ' + t
          + '.\n\nZapisać mimo to? Powstanie DRUGA adopcja tego samego dziecka.');
      };
    })();
    if (!window.ae_confirm_taken) window.ae_confirm_taken = function () { return true; };
    </script>
<?php endif; ?>
<?php
panel_footer();
