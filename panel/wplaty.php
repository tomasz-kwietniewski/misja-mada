<?php
/* ═══ CMS - macierz wpłat Adopcji Serca ═══════════════════════════
   Widok znany fundacji z arkuszy "WPŁATY GR x": wiersze = adopcje,
   kolumny = miesiące. Zielona komórka = opłacony, czerwona = zaległy
   (klik dopisuje wpłatę w kwocie adopcji), szara = poza okresem.
   Nad macierzą formularz wpłaty zbiorczej (kwartalne/roczne). */
require_once __DIR__ . '/layout.php';
mada_require_login();
require_once __DIR__ . '/../adopcja/db.php';
require_once __DIR__ . '/../adopcja/lib.php';

$dbError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mada_csrf_check();
    $back = 'wplaty.php?f=' . urlencode((string)($_POST['f'] ?? '')) . '&od=' . urlencode((string)($_POST['odw'] ?? ''));
    try {
        adopt_db_ensure_schema();
        $action = $_POST['action'] ?? '';
        if ($action === 'quick') {
            $adoptionId = (int)($_POST['adoption_id'] ?? 0);
            $month = (string)($_POST['month'] ?? '');
            $ad = $adoptionId > 0 ? adopt_adoption_get($adoptionId) : null;
            if (!$ad || !adopt_month_valid($month)) mada_redirect($back . '&msg=bad');
            $pid = adopt_payment_insert([
                'adoption_id' => $adoptionId,
                'amount_grosze' => (int)$ad['amount_grosze'],
                'paid_at' => date('Y-m-d'),
                'period_from' => $month, 'period_to' => $month,
                'method' => $ad['method'] === 'cash' ? 'cash' : 'transfer',
                'note' => null,
                'created_by' => mada_current_user(),
            ]);
            adopt_adoption_backfill_start($adoptionId);
            mada_audit('payment.add', 'payment', $pid, ['adoption' => $adoptionId, 'okres' => $month, 'quick' => true]);
            mada_redirect($back . '&msg=payok');
        }
        if ($action === 'bulk') {
            $adoptionId = (int)($_POST['adoption_id'] ?? 0);
            $ad = $adoptionId > 0 ? adopt_adoption_get($adoptionId) : null;
            $amount = (int)round(((float)str_replace(',', '.', (string)($_POST['kwota'] ?? '0'))) * 100);
            $from = trim((string)($_POST['od'] ?? ''));
            $to   = trim((string)($_POST['do'] ?? '')) ?: $from;
            $paid = trim((string)($_POST['data'] ?? '')) ?: date('Y-m-d');
            if (!$ad || $amount <= 0 || !adopt_month_valid($from) || !adopt_month_valid($to) || $to < $from
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid)) {
                mada_redirect($back . '&msg=bad');
            }
            $pid = adopt_payment_insert([
                'adoption_id' => $adoptionId, 'amount_grosze' => $amount, 'paid_at' => $paid,
                'period_from' => $from, 'period_to' => $to,
                'method' => in_array($_POST['metoda'] ?? '', ['transfer', 'cash'], true) ? $_POST['metoda'] : 'transfer',
                'note' => trim((string)($_POST['notatka'] ?? '')) ?: null,
                'created_by' => mada_current_user(),
            ]);
            adopt_adoption_backfill_start($adoptionId);
            mada_audit('payment.add', 'payment', $pid, ['adoption' => $adoptionId, 'okres' => "$from..$to"]);
            mada_redirect($back . '&msg=payok');
        }
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

function wp_flash() {
    $codes = [
        'payok' => ['ok', 'Wpłata została odnotowana.'],
        'bad'   => ['error', 'Nieprawidłowe dane wpłaty.'],
    ];
    $m = $_GET['msg'] ?? '';
    if (!isset($codes[$m])) return '';
    [$t, $txt] = $codes[$m];
    return '<div class="alert alert-' . ($t === 'ok' ? 'ok' : 'error') . '">' . mada_esc($txt) . '</div>';
}

/* ── Okno miesięcy i filtr ─────────────────────────────────────── */
$filter = (string)($_GET['f'] ?? 'all');
$winFrom = (string)($_GET['od'] ?? '');
if (!adopt_month_valid($winFrom)) $winFrom = adopt_month_add(date('Y-m'), -11);
$winTo = adopt_month_add($winFrom, 14);   // 15 kolumn miesięcy
$months = adopt_month_range($winFrom, $winTo);
$nowM = date('Y-m');
$today = date('Y-m-d');

$rows = [];
$adsAll = [];
try {
    adopt_db_ensure_schema();
    $adsAll = array_values(array_filter(adopt_adoption_list_all(),
        fn($a) => in_array($a['status'], ['pending', 'active'], true)));
    $pays = adopt_payments_by_adoptions(array_column($adsAll, 'id'));
    foreach ($adsAll as $a) {
        $p = $pays[(int)$a['id']] ?? [];
        $covered = array_flip(adopt_coverage($p));
        $miss = $a['start_month'] !== null ? adopt_arrears($a['start_month'], $a['end_month'], $p, $today) : [];
        if ($filter === 'zalegli' && !$miss) continue;
        $rows[] = $a + ['covered' => $covered, 'missing_cnt' => count($miss)];
    }
} catch (Throwable $e) {
    $dbError = $dbError ?: $e->getMessage();
}

panel_header('Macierz wpłat - Adopcja Serca');
?>
    <div class="bar">
      <h2 style="margin:0;">Macierz wpłat</h2>
      <span>
        <a href="adopcje.php" class="btn-ghost btn-sm">← Przegląd</a>
        <a href="darczyncy.php" class="btn-ghost btn-sm">Darczyńcy</a>
      </span>
    </div>
    <?= wp_flash() ?>
<?php if ($dbError !== ''): ?>
    <div class="alert alert-error">Błąd bazy danych: <?= mada_esc($dbError) ?></div>
<?php else: ?>

    <form method="get" style="display:flex;gap:10px;align-items:flex-end;margin:0 0 14px;flex-wrap:wrap;">
      <label class="hint">Pokaż
        <select name="f" onchange="this.form.submit()">
          <option value="all" <?= $filter !== 'zalegli' ? 'selected' : '' ?>>wszystkich</option>
          <option value="zalegli" <?= $filter === 'zalegli' ? 'selected' : '' ?>>tylko zalegających</option>
        </select>
      </label>
      <label class="hint">Okno od miesiąca
        <input type="month" name="od" value="<?= mada_esc($winFrom) ?>" onchange="this.form.submit()">
      </label>
      <noscript><button type="submit" class="btn-secondary btn-sm">Pokaż</button></noscript>
    </form>

    <details style="margin:0 0 16px;">
      <summary class="hint" style="cursor:pointer;">Wpłata zbiorcza (kwartalna / roczna / dowolny zakres)</summary>
      <form method="post" class="form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:10px;">
        <?= mada_csrf_field() ?>
        <input type="hidden" name="action" value="bulk">
        <input type="hidden" name="f" value="<?= mada_esc($filter) ?>">
        <input type="hidden" name="odw" value="<?= mada_esc($winFrom) ?>">
        <label style="flex:2;min-width:260px;">Adopcja
          <select name="adoption_id" required>
            <?php foreach ($adsAll as $a): ?>
              <option value="<?= (int)$a['id'] ?>"><?= mada_esc($a['donor_name']) ?> - <?= $a['child_name'] !== null ? mada_esc($a['child_name']) . ' (nr ' . (int)$a['child_number'] . ')' : 'bez dziecka' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Kwota (zł)<input type="text" name="kwota" value="210" style="width:90px;"></label>
        <label>Od<input type="month" name="od" value="<?= $nowM ?>" required></label>
        <label>Do<input type="month" name="do"></label>
        <label>Data<input type="date" name="data" value="<?= date('Y-m-d') ?>"></label>
        <label>Metoda
          <select name="metoda"><option value="transfer">przelew</option><option value="cash">gotówka</option></select>
        </label>
        <label style="flex:1;min-width:140px;">Notatka<input type="text" name="notatka"></label>
        <button type="submit" class="btn-primary btn-sm">Zapisz</button>
      </form>
    </details>

    <?php if (!$rows): ?>
      <p class="hint"><?= $filter === 'zalegli' ? 'Nikt nie zalega 🎉' : 'Brak aktywnych adopcji.' ?></p>
    <?php else: ?>
    <p class="hint" style="margin:0 0 10px;">Wierszy: <?= count($rows) ?>. Klik w czerwoną komórkę odnotowuje wpłatę
       w kwocie adopcji za ten miesiąc (metoda wg adopcji). Zakresy i inne kwoty - formularz zbiorczy powyżej.</p>
    <div class="matrix-scroll">
      <table class="matrix">
        <thead><tr>
          <th style="text-align:left;">Darczyńca / dziecko</th>
          <?php foreach ($months as $m): ?><th <?= $m === $nowM ? 'style="background:var(--gold);color:#fff;"' : '' ?>><?= mada_esc(adopt_month_label($m)) ?></th><?php endforeach; ?>
          <th>Zaległe</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="m-name">
              <a href="darczynca.php?id=<?= (int)$r['donor_id'] ?>"><?= mada_esc($r['donor_name']) ?></a>
              <span class="hint"><?= $r['child_name'] !== null ? '- ' . mada_esc($r['child_name']) . ' (' . (int)$r['child_number'] . ')' : '' ?></span>
            </td>
            <?php foreach ($months as $m):
                $inWindow = ($r['start_month'] === null || $m >= $r['start_month'])
                         && ($r['end_month'] === null || $m <= $r['end_month']);
                if (isset($r['covered'][$m])): ?>
                  <td class="m-paid">✓</td>
                <?php elseif (!$inWindow || $r['start_month'] === null): ?>
                  <td class="m-off"></td>
                <?php elseif ($m > $nowM): ?>
                  <td class="m-future"></td>
                <?php else: ?>
                  <td class="m-due">
                    <form method="post" style="margin:0;">
                      <?= mada_csrf_field() ?>
                      <input type="hidden" name="action" value="quick">
                      <input type="hidden" name="f" value="<?= mada_esc($filter) ?>">
                      <input type="hidden" name="odw" value="<?= mada_esc($winFrom) ?>">
                      <input type="hidden" name="adoption_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="month" value="<?= mada_esc($m) ?>">
                      <button type="submit" title="Odnotuj <?= number_format($r['amount_grosze'] / 100, 0, ',', ' ') ?> zł za <?= mada_esc(adopt_month_label($m)) ?>">+<?= number_format($r['amount_grosze'] / 100, 0, ',', ' ') ?></button>
                    </form>
                  </td>
                <?php endif;
            endforeach; ?>
            <td><?php if ($r['missing_cnt'] > 0): ?><span class="badge badge-err"><?= (int)$r['missing_cnt'] ?></span><?php else: ?><span class="badge badge-ok">OK</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
<?php endif; ?>
<?php
panel_footer();
