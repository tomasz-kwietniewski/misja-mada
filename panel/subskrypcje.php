<?php
/* ═══ CMS - widok subskrypcji (płatności cykliczne PayU) ═════════ */
require_once __DIR__ . '/layout.php';
mada_require_login();

require_once __DIR__ . '/../payu/db.php';
require_once __DIR__ . '/../payu/recurring-lib.php';
require_once __DIR__ . '/../payu/mail.php';

// ── Anulowanie subskrypcji przez pracownika ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mada_csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'cancel' && $id > 0) {
        try {
            if (payu_sub_cancel($id)) {
                $sub = payu_sub_get($id);
                if ($sub) { mada_mail_cancelled($sub); mada_mail_foundation($sub, 'anulowana'); }
                mada_redirect('subskrypcje.php?msg=cancelled');
            }
            mada_redirect('subskrypcje.php?msg=already');
        } catch (Throwable $e) {
            mada_redirect('subskrypcje.php?msg=dberr');
        }
    }
}

function sub_flash() {
    $codes = [
        'cancelled' => ['ok',    'Subskrypcja została anulowana (darczyńca powiadomiony mailem).'],
        'already'   => ['error', 'Subskrypcja była już zakończona.'],
        'dberr'     => ['error', 'Błąd bazy danych. Spróbuj ponownie.'],
    ];
    $m = $_GET['msg'] ?? '';
    if (!isset($codes[$m])) return '';
    [$t, $txt] = $codes[$m];
    return '<div class="alert alert-' . ($t === 'ok' ? 'ok' : 'error') . '">' . mada_esc($txt) . '</div>';
}

$statusLabel = ['pending_first' => 'oczekująca', 'active' => 'aktywna', 'paused' => 'wstrzymana', 'cancelled' => 'anulowana'];

$dbError = '';
$subs = [];
try {
    payu_db_ensure_schema();
    $subs = payu_sub_list();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

function sub_amount($g) {
    $g = (int) $g;
    return $g % 100 === 0 ? (string) intdiv($g, 100) : number_format($g / 100, 2, ',', '');
}

panel_header('Subskrypcje');
?>
    <div class="bar">
      <h2 style="margin:0;">Subskrypcje (płatności cykliczne)</h2>
      <a href="index.php" class="btn-ghost">← Wróć do listy</a>
    </div>
    <?= sub_flash() ?>

<?php if ($dbError !== ''): ?>
    <div class="alert alert-error">Baza subskrypcji jest niedostępna (sprawdź <code>payu/secret/db-config.php</code>).</div>
<?php elseif (empty($subs)): ?>
    <p class="hint">Brak subskrypcji. Pojawią się tu po pierwszej comiesięcznej darowiznie.</p>
<?php else: ?>
    <p class="hint" style="margin:0 0 16px;">Łącznie: <?= count($subs) ?>. Token karty nie jest pokazywany (tylko zamaskowany numer).</p>
    <table class="events">
      <thead><tr>
        <th>#</th><th>Darczyńca</th><th>Cel</th><th>Kwota</th><th>Status</th>
        <th>Następne</th><th>Opł.</th><th>Karta</th><th>Akcje</th>
      </tr></thead>
      <tbody>
      <?php foreach ($subs as $s): ?>
        <tr>
          <td><?= (int) $s['id'] ?></td>
          <td><?= mada_esc($s['first_name'] . ' ' . $s['last_name']) ?><br><span class="hint"><?= mada_esc($s['email']) ?></span></td>
          <td><?= mada_esc($s['goal_label']) ?><?= !empty($s['children']) ? ' <span class="hint">(' . (int)$s['children'] . ' dz.)</span>' : '' ?></td>
          <td><?= mada_esc(sub_amount($s['amount_grosze'])) ?> <?= mada_esc($s['currency']) ?></td>
          <td><?= mada_esc($statusLabel[$s['status']] ?? $s['status']) ?></td>
          <td><?= $s['status'] === 'active' ? mada_esc($s['next_charge_at']) : '-' ?></td>
          <td><?= (int) $s['months_paid'] ?></td>
          <td><span class="hint"><?= mada_esc($s['card_mask'] ?: '-') ?></span></td>
          <td>
            <?php if (in_array($s['status'], ['active', 'paused', 'pending_first'], true)): ?>
              <form method="post" onsubmit="return confirm('Anulować subskrypcję darczyńcy <?= mada_esc($s['first_name'].' '.$s['last_name']) ?>?');" style="margin:0;">
                <?= mada_csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button type="submit" class="btn-danger btn-sm">Anuluj</button>
              </form>
            <?php else: ?>-<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
<?php endif; ?>
<?php
panel_footer();
