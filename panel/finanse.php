<?php
/* ═══ CMS - finanse misyjne (rejestr przepływów) ══════════════════
   Odtwarza zakładki "Zbiórki", "Wypłaty", "Wymiana walut" z arkusza
   fundacji: jedna tabela zdarzeń z kategorią, kierunkiem i walutą.
   Zbiórka wielowalutowa = kilka wierszy z tym samym "group_label". */
require_once __DIR__ . '/layout.php';
mada_require_login();
require_once __DIR__ . '/../adopcja/db.php';

$dbError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mada_csrf_check();
    try {
        adopt_db_ensure_schema();
        $action = $_POST['action'] ?? '';
        if ($action === 'add' || $action === 'update') {
            $amount = (int)round(((float)str_replace([' ', ','], ['', '.'], (string)($_POST['kwota'] ?? '0'))) * 100);
            $date = trim((string)($_POST['flow_date'] ?? ''));
            if ($amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                mada_redirect('finanse.php?msg=invalid');
            }
            $currency = strtoupper(trim((string)($_POST['currency'] ?? 'PLN'))) ?: 'PLN';
            $fx = str_replace(',', '.', trim((string)($_POST['fx_rate'] ?? '')));
            $fxRate = ($fx !== '' && is_numeric($fx)) ? (float)$fx : null;
            $plnGrosze = $currency === 'PLN' ? $amount
                       : ($fxRate !== null ? (int)round($amount * $fxRate) : null);
            $d = [
                'flow_date' => $date,
                'direction' => ($_POST['direction'] ?? '') === 'out' ? 'out' : 'in',
                'category'  => (string)($_POST['category'] ?? 'inne'),
                'amount_grosze' => $amount,
                'currency'  => $currency,
                'fx_rate'   => $fxRate,
                'amount_pln_grosze' => $plnGrosze,
                'method'    => (string)($_POST['method'] ?? 'przelew'),
                'counterparty' => trim((string)($_POST['counterparty'] ?? '')),
                'group_label'  => trim((string)($_POST['group_label'] ?? '')),
                'status'    => (string)($_POST['status'] ?? 'wykonane'),
                'note'      => trim((string)($_POST['note'] ?? '')),
                'created_by'=> mada_current_user(),
            ];
            if ($action === 'update') {
                $fid = (int)($_POST['id'] ?? 0);
                if ($fid > 0 && fin_flow_get($fid)) {
                    fin_flow_delete($fid);           // update = wymiana wiersza (prosty model)
                    $newId = fin_flow_insert($d);
                    mada_audit('flow.edit', 'flow', $newId, ['stary' => $fid] + $d);
                    mada_redirect('finanse.php?msg=saved');
                }
                mada_redirect('finanse.php?msg=gone');
            }
            $newId = fin_flow_insert($d);
            mada_audit('flow.add', 'flow', $newId, $d);
            mada_redirect('finanse.php?msg=added');
        }
        if ($action === 'delete') {
            $fid = (int)($_POST['id'] ?? 0);
            $row = $fid > 0 ? fin_flow_get($fid) : null;
            if ($row) {
                fin_flow_delete($fid);
                mada_audit('flow.delete', 'flow', $fid, $row);
                mada_redirect('finanse.php?msg=deleted');
            }
            mada_redirect('finanse.php?msg=gone');
        }
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

function fin_flash() {
    $codes = [
        'added'   => ['ok', 'Przepływ został dodany.'],
        'saved'   => ['ok', 'Zapisano zmiany.'],
        'deleted' => ['ok', 'Wiersz został usunięty.'],
        'invalid' => ['error', 'Podaj poprawną kwotę i datę.'],
        'gone'    => ['error', 'Nie znaleziono wiersza.'],
    ];
    $m = $_GET['msg'] ?? '';
    if (!isset($codes[$m])) return '';
    [$t, $txt] = $codes[$m];
    return '<div class="alert alert-' . ($t === 'ok' ? 'ok' : 'error') . '">' . mada_esc($txt) . '</div>';
}

$year = (int)($_GET['rok'] ?? date('Y'));
$fCat = (string)($_GET['kat'] ?? '');
$fDir = (string)($_GET['kier'] ?? '');
$editRow = null;
$flows = []; $sums = [];
try {
    adopt_db_ensure_schema();
    $flows = fin_flow_list($year, $fCat, $fDir);
    $sums = fin_flow_sums($year);
    if (isset($_GET['edit'])) $editRow = fin_flow_get((int)$_GET['edit']);
} catch (Throwable $e) {
    $dbError = $dbError ?: $e->getMessage();
}

panel_header('Finanse misyjne');
?>
    <div class="bar">
      <h2 style="margin:0;">Finanse misyjne - <?= $year ?></h2>
      <a href="eksport.php" class="btn-secondary btn-sm">Eksport CSV/XLSX</a>
    </div>
    <?= fin_flash() ?>

<?php if ($dbError !== ''): ?>
    <div class="alert alert-error">Błąd bazy danych: <?= mada_esc($dbError) ?></div>
<?php else: ?>

    <form method="get" style="display:flex;gap:10px;align-items:flex-end;margin:0 0 16px;flex-wrap:wrap;">
      <label class="hint">Rok
        <select name="rok" onchange="this.form.submit()">
          <?php for ($y = (int)date('Y') + 1; $y >= 2024; $y--): ?>
            <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <label class="hint">Kategoria
        <select name="kat" onchange="this.form.submit()">
          <option value="">wszystkie</option>
          <?php foreach (FIN_CATEGORIES as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= $k === $fCat ? 'selected' : '' ?>><?= mada_esc($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="hint">Kierunek
        <select name="kier" onchange="this.form.submit()">
          <option value="">oba</option>
          <option value="in" <?= $fDir === 'in' ? 'selected' : '' ?>>wpływ</option>
          <option value="out" <?= $fDir === 'out' ? 'selected' : '' ?>>wydatek</option>
        </select>
      </label>
      <noscript><button type="submit" class="btn-secondary btn-sm">Filtruj</button></noscript>
    </form>

    <?php if ($sums): ?>
      <h3>Sumy <?= $year ?> (PLN po przeliczeniu)</h3>
      <table class="events" style="max-width:640px;">
        <thead><tr><th>Kategoria</th><th>Kierunek</th><th>Suma PLN</th></tr></thead>
        <tbody>
        <?php foreach ($sums as $s): ?>
          <tr>
            <td><?= mada_esc(FIN_CATEGORIES[$s['category']] ?? $s['category']) ?></td>
            <td><?= $s['direction'] === 'in' ? 'wpływ' : 'wydatek' ?></td>
            <td><b><?= number_format(((int)$s['pln']) / 100, 2, ',', ' ') ?> zł</b>
                <?php if ((int)$s['unconverted'] > 0): ?><span class="hint">(+ <?= (int)$s['unconverted'] ?> bez kursu)</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <details <?= $editRow ? 'open' : '' ?> style="margin:16px 0;">
      <summary class="hint" style="cursor:pointer;"><?= $editRow ? 'Edycja wiersza #' . (int)$editRow['id'] : '+ Dodaj przepływ' ?></summary>
      <form method="post" class="form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:10px;">
        <?= mada_csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'add' ?>">
        <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>
        <label>Data<input type="date" name="flow_date" required value="<?= mada_esc($editRow['flow_date'] ?? date('Y-m-d')) ?>"></label>
        <label>Kierunek
          <select name="direction">
            <option value="in" <?= ($editRow['direction'] ?? 'in') === 'in' ? 'selected' : '' ?>>wpływ</option>
            <option value="out" <?= ($editRow['direction'] ?? '') === 'out' ? 'selected' : '' ?>>wydatek</option>
          </select>
        </label>
        <label>Kategoria
          <select name="category">
            <?php foreach (FIN_CATEGORIES as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= ($editRow['category'] ?? '') === $k ? 'selected' : '' ?>><?= mada_esc($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Kwota<input type="text" name="kwota" required style="width:110px;" value="<?= $editRow ? number_format($editRow['amount_grosze'] / 100, 2, ',', '') : '' ?>"></label>
        <label>Waluta
          <select name="currency">
            <?php foreach (['PLN', 'EUR', 'GBP', 'CHF', 'USD'] as $c): ?>
              <option <?= ($editRow['currency'] ?? 'PLN') === $c ? 'selected' : '' ?>><?= $c ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Kurs (dla walut)<input type="text" name="fx_rate" style="width:90px;" value="<?= mada_esc((string)($editRow['fx_rate'] ?? '')) ?>"></label>
        <label>Forma
          <select name="method">
            <option value="przelew" <?= ($editRow['method'] ?? '') !== 'gotowka' ? 'selected' : '' ?>>przelew</option>
            <option value="gotowka" <?= ($editRow['method'] ?? '') === 'gotowka' ? 'selected' : '' ?>>gotówka</option>
          </select>
        </label>
        <label>Status
          <select name="status">
            <?php foreach (['wykonane', 'przekazane', 'zaplanowane'] as $s): ?>
              <option <?= ($editRow['status'] ?? 'wykonane') === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label style="min-width:180px;">Kontrahent / miejsce<input type="text" name="counterparty" value="<?= mada_esc($editRow['counterparty'] ?? '') ?>"></label>
        <label style="min-width:150px;">Etykieta grupy (zbiórka)<input type="text" name="group_label" value="<?= mada_esc($editRow['group_label'] ?? '') ?>"></label>
        <label style="flex:1;min-width:160px;">Notatka<input type="text" name="note" value="<?= mada_esc($editRow['note'] ?? '') ?>"></label>
        <button type="submit" class="btn-primary btn-sm"><?= $editRow ? 'Zapisz' : 'Dodaj' ?></button>
        <?php if ($editRow): ?><a href="finanse.php" class="btn-ghost btn-sm">Anuluj edycję</a><?php endif; ?>
      </form>
      <p class="hint" style="margin:8px 0 0;">Zbiórka w kilku walutach: dodaj osobny wiersz na każdą walutę z tą samą etykietą grupy (np. „Zbiórka Londyn 02.2026").</p>
    </details>

    <?php if (!$flows): ?>
      <p class="hint">Brak przepływów w wybranym filtrze.</p>
    <?php else: ?>
      <table class="events">
        <thead><tr><th>Data</th><th>Kier.</th><th>Kategoria</th><th>Kwota</th><th>PLN</th><th>Forma</th><th>Kontrahent</th><th>Grupa</th><th>Status</th><th>Notatka</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($flows as $f): ?>
          <tr>
            <td><?= mada_esc($f['flow_date']) ?></td>
            <td><?= $f['direction'] === 'in' ? '<span class="badge badge-ok">+</span>' : '<span class="badge badge-err">-</span>' ?></td>
            <td><?= mada_esc(FIN_CATEGORIES[$f['category']] ?? $f['category']) ?></td>
            <td><?= number_format($f['amount_grosze'] / 100, 2, ',', ' ') ?> <?= mada_esc($f['currency']) ?><?= $f['fx_rate'] !== null ? ' <span class="hint">x ' . mada_esc(rtrim(rtrim((string)$f['fx_rate'], '0'), '.')) . '</span>' : '' ?></td>
            <td><?= $f['amount_pln_grosze'] !== null ? number_format($f['amount_pln_grosze'] / 100, 2, ',', ' ') . ' zł' : '<span class="hint">-</span>' ?></td>
            <td><?= mada_esc($f['method']) ?></td>
            <td class="hint"><?= mada_esc($f['counterparty'] ?? '') ?></td>
            <td class="hint"><?= mada_esc($f['group_label'] ?? '') ?></td>
            <td><?= mada_esc($f['status']) ?></td>
            <td class="hint"><?= mada_esc($f['note'] ?? '') ?></td>
            <td style="white-space:nowrap;">
              <a class="btn-secondary btn-sm" href="finanse.php?edit=<?= (int)$f['id'] ?>&rok=<?= $year ?>">Edytuj</a>
              <form method="post" style="display:inline;" onsubmit="return confirm('Usunąć ten wiersz?');">
                <?= mada_csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                <button type="submit" class="btn-danger btn-sm">Usuń</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
<?php endif; ?>
<?php
panel_footer();
