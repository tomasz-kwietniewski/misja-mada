<?php
/* ═══ CMS - ręczne łączenie niedopasowanych wierszy importu ═══════
   Wiersze wpłat z macierzy GR1-5, których parser nie umiał
   jednoznacznie przypisać (niejednoznaczne nazwisko albo darczyńca
   z kilkoma adopcjami). Pracownik wybiera adopcję docelową - wpłaty
   dopisują się do niej; albo pomija wiersz.
   Strona tymczasowa - do usunięcia po zakończonej migracji (etap E). */
require_once __DIR__ . '/layout.php';
mada_require_login();
require_once __DIR__ . '/../adopcja/db.php';
require_once __DIR__ . '/../adopcja/lib.php';

$dbError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mada_csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    try {
        adopt_db_ensure_schema();
        $row = adopt_pending_get($id);
        if (!$row || $row['status'] !== 'open') {
            mada_redirect('import-lacz.php?msg=gone');
        }
        if ($action === 'skip') {
            adopt_pending_resolve($id, 'skipped');
            mada_audit('import.skip', 'import_pending', $id, ['label' => $row['label']]);
            mada_redirect('import-lacz.php?msg=skipped');
        }
        if ($action === 'attach') {
            $adoptionId = (int)($_POST['adoption_id'] ?? 0);
            if ($adoptionId <= 0) mada_redirect('import-lacz.php?msg=noadopt');
            $data = json_decode($row['payload'], true) ?: [];
            $events = $data['events'] ?? [];
            if (!$events) {
                adopt_pending_resolve($id, 'resolved');
                mada_redirect('import-lacz.php?msg=empty');
            }
            $pdo = payu_db();
            $pdo->beginTransaction();
            $n = 0;
            foreach ($events as $e) {
                adopt_payment_insert([
                    'adoption_id'   => $adoptionId,
                    'amount_grosze' => (int)$e['amount_grosze'],
                    'paid_at'       => $e['period_from'] . '-01',
                    'period_from'   => $e['period_from'],
                    'period_to'     => $e['period_to'],
                    'method'        => 'transfer',
                    'note'          => 'import (ręcznie): ' . ($data['group'] ?? '?') . ' ' . ($data['name'] ?? ''),
                    'created_by'    => mada_current_user(),
                ]);
                $n++;
            }
            adopt_adoption_backfill_start($adoptionId);
            adopt_pending_resolve($id, 'resolved');
            $pdo->commit();
            mada_audit('import.attach', 'adoption', $adoptionId,
                ['pending_id' => $id, 'label' => $row['label'], 'payments' => $n]);
            mada_redirect('import-lacz.php?msg=attached');
        }
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $dbError = $e->getMessage();
    }
}

function lacz_flash() {
    $codes = [
        'attached' => ['ok',    'Wpłaty zostały dopisane do wybranej adopcji.'],
        'skipped'  => ['ok',    'Wiersz pominięty.'],
        'gone'     => ['error', 'Ta pozycja została już rozwiązana.'],
        'noadopt'  => ['error', 'Wybierz adopcję z listy.'],
        'empty'    => ['ok',    'Wiersz nie zawierał wpłat - oznaczono jako rozwiązany.'],
    ];
    $m = $_GET['msg'] ?? '';
    if (!isset($codes[$m])) return '';
    [$t, $txt] = $codes[$m];
    return '<div class="alert alert-' . ($t === 'ok' ? 'ok' : 'error') . '">' . mada_esc($txt) . '</div>';
}

$open = [];
$adoptionsAll = [];
try {
    adopt_db_ensure_schema();
    $open = adopt_pending_open();
    $adoptionsAll = adopt_adoption_list_all();
} catch (Throwable $e) {
    $dbError = $dbError ?: $e->getMessage();
}

/** Opcje selecta adopcji (etykieta: darczyńca - dziecko nr X). */
function lacz_adoption_options(array $all, string $preferName = ''): string {
    $html = '<option value="">- wybierz adopcję -</option>';
    $prefNorm = $preferName !== '' ? adopt_name_normalize($preferName) : '';
    foreach ($all as $a) {
        $label = $a['donor_name']
               . ' - ' . ($a['child_name'] !== null ? $a['child_name'] . ' (nr ' . $a['child_number'] . ')' : 'bez dziecka')
               . ' [' . ($a['start_month'] !== null ? 'od ' . adopt_month_label($a['start_month']) : 'start ?')
               . ($a['end_month'] !== null ? ' do ' . adopt_month_label($a['end_month']) : '') . ']';
        $mark = '';
        if ($prefNorm !== '' && adopt_name_match($preferName, $a['donor_name']) !== 'none') {
            $mark = ' *';   // podpowiedź: kandydat wg nazwiska
        }
        $html .= '<option value="' . (int)$a['id'] . '">' . mada_esc($label . $mark) . '</option>';
    }
    return $html;
}

panel_header('Łączenie importu - Adopcja Serca');
?>
    <div class="bar">
      <h2 style="margin:0;">Ręczne łączenie wierszy importu</h2>
      <a href="import.php" class="btn-ghost">← Import</a>
    </div>
    <?= lacz_flash() ?>

<?php if ($dbError !== ''): ?>
    <div class="alert alert-error">Błąd bazy danych: <?= mada_esc($dbError) ?></div>
<?php endif; ?>

<?php if (!$open): ?>
    <div class="alert alert-ok">Nie ma pozycji do ręcznego łączenia. Migracja domknięta 🎉</div>
<?php else: ?>
    <p class="hint">Pozycji do rozwiązania: <b><?= count($open) ?></b>.
       Gwiazdka (*) na liście adopcji oznacza kandydata pasującego nazwiskiem.
       Wpłaty z wiersza zostaną dopisane do wybranej adopcji, a nieznany start adopcji uzupełni się automatycznie.</p>

    <?php foreach ($open as $row):
        $data = json_decode($row['payload'], true) ?: [];
        $events = $data['events'] ?? [];
        $sum = array_sum(array_column($events, 'amount_grosze'));
    ?>
    <div class="spraw-panel" style="display:block;">
      <h3 style="margin:0 0 4px;"><?= mada_esc($data['name'] ?? $row['label']) ?>
          <span class="hint">(<?= mada_esc($data['group'] ?? '?') ?>, <?= mada_esc($data['file'] ?? '') ?>)</span></h3>
      <p class="hint" style="margin:0 0 8px;"><?= mada_esc($row['hint'] ?? '') ?></p>
      <p style="margin:0 0 8px;">
        <?php foreach ($events as $e): ?>
          <span class="badge"><?= mada_esc(adopt_month_label($e['period_from'])) ?><?= $e['period_to'] !== $e['period_from'] ? ' - ' . mada_esc(adopt_month_label($e['period_to'])) : '' ?>:
            <?= number_format($e['amount_grosze'] / 100, 0, ',', ' ') ?> zł</span>
        <?php endforeach; ?>
        <b style="margin-left:8px;">razem <?= number_format($sum / 100, 2, ',', ' ') ?> zł</b>
      </p>
      <?php if (!empty($data['notes'])): ?>
        <p class="hint" style="margin:0 0 8px;">Notatki z arkusza: <?= mada_esc(implode(' | ', (array)$data['notes'])) ?></p>
      <?php endif; ?>
      <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0;">
        <?= mada_csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
        <select name="adoption_id" style="max-width:460px;">
          <?= lacz_adoption_options($adoptionsAll, (string)($data['name'] ?? '')) ?>
        </select>
        <button type="submit" name="action" value="attach" class="btn-primary btn-sm">Dopisz wpłaty</button>
        <button type="submit" name="action" value="skip" class="btn-danger btn-sm"
                onclick="return confirm('Pominąć ten wiersz? Wpłaty NIE zostaną zaimportowane.');">Pomiń</button>
      </form>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php
panel_footer();
