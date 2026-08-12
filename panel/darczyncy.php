<?php
/* ═══ CMS - lista darczyńców Adopcji Serca (etap A: tylko odczyt) ═
   Dane z MySQL (moduł adopcja/). Wyszukiwarka po nazwisku/e-mailu,
   kolumny: dzieci, metoda, opłacone do, zaległość. */
require_once __DIR__ . '/layout.php';
mada_require_login();
require_once __DIR__ . '/../adopcja/db.php';
require_once __DIR__ . '/../adopcja/lib.php';

$q = trim((string)($_GET['q'] ?? ''));
$showArchived = ($_GET['arch'] ?? '') === '1';
$dbError = '';
$donors = [];
$archivedCnt = 0;
$adoptionsByDonor = [];
$paymentsByAdoption = [];

try {
    adopt_db_ensure_schema();
    $donors = adopt_sort_by_surname(adopt_donor_list($q));   // domyślnie po NAZWISKU
    $archivedCnt = count(array_filter($donors, fn($d) => (int)$d['is_archived'] === 1));
    /* Domyślnie lista pokazuje osoby, które aktualnie wspierają. Archiwalnych
       (po zakończeniu wszystkich adopcji) odsłania przycisk. Wyszukiwarka
       celowo przeszukuje WSZYSTKICH - szuka się konkretnej osoby, także tej,
       która przestała wpłacać. */
    if (!$showArchived && $q === '') {
        $donors = array_values(array_filter($donors, fn($d) => (int)$d['is_archived'] === 0));
    }
    $all = adopt_adoption_list_all();
    foreach ($all as $a) $adoptionsByDonor[(int)$a['donor_id']][] = $a;
    $paymentsByAdoption = adopt_payments_by_adoptions(array_column($all, 'id'));
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$today = date('Y-m-d');
$methodLabel = ['transfer' => 'przelew', 'card' => 'karta', 'cash' => 'gotówka'];

panel_header('Darczyńcy - Adopcja Serca');
?>
    <div class="bar">
      <h2 style="margin:0;">Darczyńcy Adopcji Serca</h2>
      <a href="darczynca-edit.php" class="btn-primary btn-sm">+ Nowy darczyńca</a>
    </div>

<?php if ($dbError !== ''): ?>
    <div class="alert alert-error">Baza danych jest niedostępna (sprawdź <code>payu/secret/db-config.php</code>): <?= mada_esc($dbError) ?></div>
<?php else: ?>
    <form method="get" style="margin:0 0 16px;display:flex;gap:10px;">
      <input type="search" name="q" value="<?= mada_esc($q) ?>" placeholder="Szukaj: nazwisko lub e-mail"
             style="flex:1;max-width:340px;padding:8px 12px;border:1px solid var(--rule);border-radius:9px;font:inherit;">
      <button type="submit" class="btn-secondary btn-sm">Szukaj</button>
      <?php if ($q !== ''): ?><a href="darczyncy.php" class="btn-ghost btn-sm">Wyczyść</a><?php endif; ?>
      <?php if ($q === '' && $archivedCnt > 0): ?>
        <a href="darczyncy.php<?= $showArchived ? '' : '?arch=1' ?>" class="btn-ghost btn-sm" style="margin-left:auto;">
          <?= $showArchived ? 'Ukryj archiwalnych' : 'Pokaż archiwalnych (' . $archivedCnt . ')' ?>
        </a>
      <?php endif; ?>
    </form>

    <?php if (!$donors): ?>
      <p class="hint"><?= $q !== '' ? 'Brak wyników dla „' . mada_esc($q) . '".' : 'Baza darczyńców jest pusta - zacznij od strony Import.' ?></p>
    <?php else: ?>
      <p class="hint" style="margin:0 0 12px;">Łącznie: <?= count($donors) ?><?= $q !== '' ? ' (filtr aktywny; wyszukiwarka obejmuje też archiwalnych)' : '' ?><?php
        if ($q === '' && $archivedCnt > 0 && !$showArchived): ?>, ukrytych archiwalnych: <?= $archivedCnt ?><?php endif; ?></p>
      <table class="events">
        <thead><tr>
          <th>Darczyńca</th><th>Dzieci</th><th>Metoda</th><th>Opłacone do</th><th>Zaległość</th><th>Dossier</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($donors as $d):
            $ads = $adoptionsByDonor[(int)$d['id']] ?? [];
            $active = array_filter($ads, fn($a) => in_array($a['status'], ['pending', 'active'], true));
            // agregaty pokrycia po aktywnych adopcjach darczyńcy
            $paidUntilMin = null; $arrearsTotal = 0; $noStart = false;
            foreach ($active as $a) {
                $pays = $paymentsByAdoption[(int)$a['id']] ?? [];
                $pu = adopt_paid_until($pays);
                if ($a['start_month'] === null) { $noStart = true; continue; }
                $arrearsTotal += count(adopt_arrears($a['start_month'], $a['end_month'], $pays, $today));
                if ($pu !== null && ($paidUntilMin === null || $pu < $paidUntilMin)) $paidUntilMin = $pu;
            }
            $methods = array_unique(array_map(fn($a) => $methodLabel[$a['method']] ?? $a['method'], $active));
        ?>
          <tr class="row-link" data-href="darczynca.php?id=<?= (int)$d['id'] ?>">
            <td><a href="darczynca.php?id=<?= (int)$d['id'] ?>"><?= mada_esc($d['full_name']) ?></a><?= (int)$d['is_archived'] === 1 ? ' <span class="badge" style="background:var(--creamDk);color:#8a7963;border-color:var(--rule);">archiwum</span>' : '' ?><?php
                if ((int)($d['email_shared_now'] ?? 0) === 1): ?> <span class="badge badge-arch" title="Ten adres e-mail ma w bazie więcej niż jeden darczyńca - sprawdź, czy adopcje wiszą przy właściwej osobie">wspólny e-mail</span><?php endif; ?><br>
                <span class="hint"><?= mada_esc($d['email'] ?: '-') ?><?= $d['emails_extra'] ? '; ' . mada_esc($d['emails_extra']) : '' ?><?php
                  if (($d['phone'] ?? '') !== '') echo ' · ' . mada_esc($d['phone']);
                  if (($d['city'] ?? '') !== '') echo ' · ' . mada_esc($d['city']);
                ?></span></td>
            <td><?php if ($d['children_names']): ?>
                  <?= mada_esc($d['children_names']) ?> <span class="hint">(nr <?= mada_esc($d['children_numbers']) ?>)</span>
                <?php else: ?><span class="hint">-</span><?php endif; ?></td>
            <td><?= mada_esc($methods ? implode(', ', $methods) : '-') ?></td>
            <td><?= mada_esc(adopt_month_label($paidUntilMin)) ?><?= $noStart ? ' <span class="hint">(start ?)</span>' : '' ?></td>
            <td><?php if ($arrearsTotal > 0): ?>
                  <span class="badge" style="background:#fbeeec;color:var(--err);border-color:#e6b9b1;"><?= $arrearsTotal ?> mies.</span>
                <?php elseif ($active): ?>
                  <span class="badge" style="background:#e9f5ee;color:var(--ok);border-color:#b8dcc6;">OK</span>
                <?php else: ?><span class="hint">-</span><?php endif; ?></td>
            <?php
              /* Dossier = mail z przedstawieniem dziecka. Liczymy tylko adopcje,
                 które mają już przypisane dziecko - bez dziecka nie ma czego wysyłać. */
              $withChild = (int)($d['with_child_cnt'] ?? 0);
              $dsSent    = (int)($d['dossier_sent_cnt'] ?? 0);
            ?>
            <td><?php if ($withChild === 0): ?><span class="hint">-</span>
                <?php elseif ($dsSent >= $withChild): ?>
                  <span class="badge badge-ok">wysłane<?= $withChild > 1 ? ' (' . $dsSent . '/' . $withChild . ')' : '' ?></span>
                <?php elseif ($dsSent > 0): ?>
                  <span class="badge badge-err"><?= $dsSent ?>/<?= $withChild ?></span>
                <?php else: ?>
                  <span class="badge badge-err">nie wysłano</span>
                <?php endif; ?></td>
            <td style="white-space:nowrap;">
              <a class="btn-secondary btn-sm" href="darczynca-edit.php?id=<?= (int)$d['id'] ?>">Edytuj</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
<?php endif; ?>
<?php
panel_footer();
