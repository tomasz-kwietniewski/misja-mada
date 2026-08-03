<?php
/* ═══ CMS - dashboard Adopcji Serca ═══════════════════════════════
   Kafle liczbowe + lista zalegających + adopcje wygasające wkrótce. */
require_once __DIR__ . '/layout.php';
mada_require_login();
require_once __DIR__ . '/../adopcja/db.php';
require_once __DIR__ . '/../adopcja/lib.php';

$dbError = '';
$tiles = null;
$arrearsList = [];
$expiring = [];
$noStart = [];

try {
    adopt_db_ensure_schema();
    $all = adopt_adoption_list_all();
    $active = array_values(array_filter($all, fn($a) => in_array($a['status'], ['pending', 'active'], true)));
    $pays = adopt_payments_by_adoptions(array_column($active, 'id'));
    $today = date('Y-m-d');
    $limitM = adopt_month_add(date('Y-m'), 2);

    foreach ($active as $a) {
        $p = $pays[(int)$a['id']] ?? [];
        if ($a['start_month'] === null) { $noStart[] = $a; continue; }
        $miss = adopt_arrears($a['start_month'], $a['end_month'], $p, $today);
        if ($miss) {
            $arrearsList[] = $a + ['missing' => $miss, 'paid_until' => adopt_paid_until($p)];
        }
        if ($a['duration'] === 'fixed' && $a['end_month'] !== null
            && $a['end_month'] >= date('Y-m') && $a['end_month'] <= $limitM) {
            $expiring[] = $a;
        }
    }
    usort($arrearsList, fn($x, $y) => count($y['missing']) <=> count($x['missing']));
    usort($expiring, fn($x, $y) => strcmp($x['end_month'], $y['end_month']));

    $counts = adopt_counts();
    $childrenFree = (int)payu_db()->query(
        "SELECT COUNT(*) FROM adopt_children c
          WHERE c.status = 'active' AND NOT EXISTS
            (SELECT 1 FROM adopt_adoptions a WHERE a.child_id = c.id AND a.status IN ('pending','active'))"
    )->fetchColumn();
    $tiles = [
        'active'    => count($active),
        'arrears'   => count($arrearsList),
        'expiring'  => count($expiring),
        'free'      => $childrenFree,
        'pending'   => $counts['pending'],
    ];
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

panel_header('Adopcja Serca - przegląd');
?>
    <div class="bar">
      <h2 style="margin:0;">Adopcja Serca - przegląd</h2>
      <span>
        <a href="wplaty.php" class="btn-primary btn-sm">Macierz wpłat</a>
        <a href="darczyncy.php" class="btn-secondary btn-sm">Darczyńcy</a>
        <a href="dzieci.php" class="btn-secondary btn-sm">Podopieczni</a>
        <a href="zgloszenia.php" class="btn-secondary btn-sm">Zgłoszenia</a>
        <a href="finanse.php" class="btn-secondary btn-sm">Finanse</a>
        <a href="eksport.php" class="btn-secondary btn-sm">Eksport</a>
      </span>
    </div>

<?php if ($dbError !== ''): ?>
    <div class="alert alert-error">Baza danych jest niedostępna: <?= mada_esc($dbError) ?></div>
<?php else: ?>
    <div class="adopt-tiles">
      <div class="adopt-tile"><b><?= $tiles['active'] ?></b><span>aktywne adopcje</span></div>
      <div class="adopt-tile <?= $tiles['arrears'] > 0 ? 'tile-warn' : '' ?>"><b><?= $tiles['arrears'] ?></b><span>z zaległościami</span></div>
      <div class="adopt-tile"><b><?= $tiles['expiring'] ?></b><span>wygasa do <?= mada_esc(adopt_month_label(adopt_month_add(date('Y-m'), 2))) ?></span></div>
      <div class="adopt-tile"><b><?= $tiles['free'] ?></b><span>dzieci bez darczyńcy</span></div>
      <?php if ($tiles['pending'] > 0): ?>
        <a class="adopt-tile tile-warn" href="import-lacz.php" style="text-decoration:none;"><b><?= $tiles['pending'] ?></b><span>import do łączenia</span></a>
      <?php endif; ?>
    </div>

    <h3>Zalegają (<?= count($arrearsList) ?>)</h3>
    <?php if (!$arrearsList): ?>
      <p class="hint">Nikt nie zalega 🎉</p>
    <?php else: ?>
      <table class="events">
        <thead><tr><th>Darczyńca</th><th>Dziecko</th><th>Opłacone do</th><th>Brakujące miesiące</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($arrearsList as $a): ?>
          <tr>
            <td><a href="darczynca.php?id=<?= (int)$a['donor_id'] ?>"><?= mada_esc($a['donor_name']) ?></a></td>
            <td><?= $a['child_name'] !== null ? mada_esc($a['child_name']) . ' <span class="hint">(nr ' . (int)$a['child_number'] . ')</span>' : '<span class="hint">-</span>' ?></td>
            <td><?= mada_esc(adopt_month_label($a['paid_until'])) ?></td>
            <td><span class="badge badge-err"><?= count($a['missing']) ?> mies.</span>
                <span class="hint"><?= mada_esc(implode(', ', array_map('adopt_month_label', array_slice($a['missing'], 0, 6)))) ?><?= count($a['missing']) > 6 ? '...' : '' ?></span></td>
            <td><a class="btn-secondary btn-sm" href="wplaty.php?f=zalegli">wpłaty →</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <h3>Wygasają w ciągu 2 miesięcy (<?= count($expiring) ?>)</h3>
    <?php if (!$expiring): ?>
      <p class="hint">Nic nie wygasa w najbliższym czasie.</p>
    <?php else: ?>
      <table class="events">
        <thead><tr><th>Darczyńca</th><th>Dziecko</th><th>Koniec</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($expiring as $a): ?>
          <tr>
            <td><a href="darczynca.php?id=<?= (int)$a['donor_id'] ?>"><?= mada_esc($a['donor_name']) ?></a></td>
            <td><?= $a['child_name'] !== null ? mada_esc($a['child_name']) . ' <span class="hint">(nr ' . (int)$a['child_number'] . ')</span>' : '-' ?></td>
            <td><b><?= mada_esc(adopt_month_label($a['end_month'])) ?></b></td>
            <td><a class="btn-secondary btn-sm" href="adopcja-edit.php?id=<?= (int)$a['id'] ?>">edytuj →</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if ($noStart): ?>
      <h3>Bez ustalonego startu (<?= count($noStart) ?>)</h3>
      <p class="hint">Te adopcje nie mają miesiąca startu (import bez wpłat) - zaległości nie są dla nich liczone. Uzupełnij start w edycji albo rozwiąż wiersze wpłat na <a href="import-lacz.php">ekranie łączenia</a>.</p>
      <ul>
      <?php foreach ($noStart as $a): ?>
        <li><a href="adopcja-edit.php?id=<?= (int)$a['id'] ?>"><?= mada_esc($a['donor_name']) ?> - <?= $a['child_name'] !== null ? mada_esc($a['child_name']) : 'bez dziecka' ?></a></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
<?php endif; ?>
<?php
panel_footer();
