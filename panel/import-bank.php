<?php
/* ═══ CMS - import operacji z wyciągu bankowego ═══════════════════
   Wgrany plik (CSV/XLSX z bankowości) trafia do POCZEKALNI: każda
   operacja dostaje propozycję - wpłata Adopcji Serca albo wiersz
   w Finansach - a zapisuje ją dopiero kliknięcie pracownika.
   Plik NIE JEST zapisywany na serwerze; czytamy go z katalogu
   tymczasowego i zostawiamy same operacje.

   Parser i reguły dopasowania: adopcja/bank.php (czysta logika,
   testy w tests/run-bank.php). Tutaj tylko baza i ekran. */
require_once __DIR__ . '/layout.php';
mada_require_login();
require_once __DIR__ . '/../adopcja/db.php';
require_once __DIR__ . '/../adopcja/lib.php';
require_once __DIR__ . '/../adopcja/bank.php';

const IMPORT_BANK_MAX_MB = 8;

$dbError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mada_csrf_check();
    try {
        adopt_db_ensure_schema();
        $action = $_POST['action'] ?? '';
        $user = mada_current_user();

        /* ── Wgranie pliku ──────────────────────────────────────── */
        if ($action === 'upload') {
            $f = $_FILES['plik'] ?? null;
            if (!$f || !is_uploaded_file($f['tmp_name'] ?? '')) mada_redirect('import-bank.php?msg=nofile');
            if ((int)$f['size'] > IMPORT_BANK_MAX_MB * 1024 * 1024) mada_redirect('import-bank.php?msg=big');
            $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) mada_redirect('import-bank.php?msg=type');

            // Rozszerzenie musi zostać przy pliku - po nim parser wybiera tryb.
            // Kasujemy go w finally: wyciąg nie ma prawa zostać na serwerze
            // nawet wtedy, gdy parser się wyłoży.
            $tmp = $f['tmp_name'] . '.' . $ext;
            if (!@move_uploaded_file($f['tmp_name'], $tmp)) mada_redirect('import-bank.php?msg=readerr');
            try {
                [$headers, $rows] = bank_read_table($tmp);
                $ops = $headers ? bank_rows_to_ops($headers, $rows) : [];
            } finally {
                @unlink($tmp);
            }

            if (!$ops) mada_redirect('import-bank.php?msg=empty');
            $res = bank_ops_insert_many($ops, $user);
            mada_audit('bank.import', 'bank', null,
                ['plik' => (string)$f['name'], 'operacji' => count($ops)] + $res);
            mada_redirect('import-bank.php?msg=loaded&n=' . (int)$res['added'] . '&d=' . (int)$res['dups']);
        }

        /* ── Zapis operacji jako wpłaty Adopcji Serca ───────────── */
        if ($action === 'save-payment') {
            $op = bank_op_get((int)($_POST['op_id'] ?? 0));
            $adoptionId = (int)($_POST['adoption_id'] ?? 0);
            $ad = $adoptionId > 0 ? adopt_adoption_get($adoptionId) : null;
            $from = trim((string)($_POST['period_from'] ?? ''));
            $to   = trim((string)($_POST['period_to'] ?? '')) ?: $from;
            if (!$op || $op['status'] !== 'open' || !$ad || (int)$op['amount_grosze'] <= 0
                || !adopt_month_valid($from) || !adopt_month_valid($to) || $to < $from) {
                mada_redirect('import-bank.php?msg=bad');
            }
            $pid = adopt_payment_insert([
                'adoption_id'   => $adoptionId,
                'amount_grosze' => (int)$op['amount_grosze'],
                'paid_at'       => $op['op_date'],
                'period_from'   => $from,
                'period_to'     => $to,
                'method'        => 'transfer',
                'note'          => 'wyciąg: ' . (string)$op['title'],
                'created_by'    => $user,
            ]);
            adopt_adoption_backfill_start($adoptionId);
            if (!empty($_POST['zapamietaj']) && (string)$op['account_key'] !== '') {
                bank_account_remember((string)$op['account_key'], (int)$ad['donor_id'],
                                      (string)$op['party'], $user);
            }
            bank_op_resolve((int)$op['id'], 'payment', $pid, $user);
            mada_audit('bank.payment', 'payment', $pid,
                ['operacja' => (int)$op['id'], 'adopcja' => $adoptionId, 'okres' => "$from..$to"]);
            mada_redirect('import-bank.php?msg=payok');
        }

        /* ── Zapis operacji jako przepływu finansowego ──────────── */
        if ($action === 'save-flow') {
            $op = bank_op_get((int)($_POST['op_id'] ?? 0));
            $cat = (string)($_POST['category'] ?? 'inne');
            if (!$op || $op['status'] !== 'open' || !isset(FIN_CATEGORIES[$cat])) {
                mada_redirect('import-bank.php?msg=bad');
            }
            $amount = abs((int)$op['amount_grosze']);
            $cur = (string)$op['currency'];
            $fid = fin_flow_insert([
                'flow_date' => $op['op_date'],
                'direction' => (int)$op['amount_grosze'] < 0 ? 'out' : 'in',
                'category'  => $cat,
                'amount_grosze' => $amount,
                'currency'  => $cur,
                'fx_rate'   => null,
                // Kurs uzupełnia się w Finansach - wyciąg go nie podaje.
                'amount_pln_grosze' => $cur === 'PLN' ? $amount : null,
                'method'    => 'przelew',
                'counterparty' => (string)$op['party'],
                'group_label'  => '',
                'status'    => 'wykonane',
                'note'      => (string)$op['title'],
                'created_by'=> $user,
            ]);
            bank_op_resolve((int)$op['id'], 'flow', $fid, $user);
            mada_audit('bank.flow', 'flow', $fid, ['operacja' => (int)$op['id'], 'kategoria' => $cat]);
            mada_redirect('import-bank.php?msg=flowok');
        }

        if ($action === 'skip') {
            $op = bank_op_get((int)($_POST['op_id'] ?? 0));
            if (!$op) mada_redirect('import-bank.php?msg=bad');
            bank_op_resolve((int)$op['id'], 'skipped', null, $user);
            mada_redirect('import-bank.php?msg=skipped');
        }
        if ($action === 'reopen') {
            $op = bank_op_get((int)($_POST['op_id'] ?? 0));
            // Cofamy tylko pominięte - zapisanej wpłaty nie da się „odkliknąć"
            // stąd, żeby nie zostawiać w bazie wpłaty bez śladu w poczekalni.
            if (!$op || $op['status'] !== 'skipped') mada_redirect('import-bank.php?msg=bad');
            bank_op_reopen((int)$op['id']);
            mada_redirect('import-bank.php?st=skipped&msg=reopened');
        }
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

function imp_flash() {
    $codes = [
        'loaded'   => ['ok', 'Wczytano plik.'],
        'payok'    => ['ok', 'Wpłata zapisana i dopisana do macierzy.'],
        'flowok'   => ['ok', 'Wiersz dopisany do Finansów.'],
        'skipped'  => ['ok', 'Operacja pominięta.'],
        'reopened' => ['ok', 'Operacja wróciła do poczekalni.'],
        'nofile'   => ['error', 'Nie wybrano pliku.'],
        'big'      => ['error', 'Plik jest za duży (maks. ' . IMPORT_BANK_MAX_MB . ' MB).'],
        'type'     => ['error', 'Nieobsługiwany format. Wgraj CSV, TXT albo XLSX z bankowości.'],
        'readerr'  => ['error', 'Nie udało się odczytać pliku.'],
        'empty'    => ['error', 'W pliku nie znaleziono operacji. Sprawdź, czy eksport zawiera '
                              . 'kolumny z datą i kwotą (w Erste/Santander: Historia -> CSV, separator średnik).'],
        'bad'      => ['error', 'Nieprawidłowe dane operacji.'],
    ];
    $m = $_GET['msg'] ?? '';
    if (!isset($codes[$m])) return '';
    [$t, $txt] = $codes[$m];
    if ($m === 'loaded') {
        $txt = 'Wczytano ' . (int)($_GET['n'] ?? 0) . ' nowych operacji'
             . ((int)($_GET['d'] ?? 0) > 0 ? ', pominięto ' . (int)$_GET['d'] . ' już wczytanych wcześniej' : '') . '.';
    }
    return '<div class="alert alert-' . ($t === 'ok' ? 'ok' : 'error') . '">' . mada_esc($txt) . '</div>';
}

/** Kwota w groszach -> "1 234,56 zł" (albo z kodem waluty). */
function imp_money(int $grosze, string $cur = 'PLN'): string {
    return number_format($grosze / 100, 2, ',', ' ') . ' ' . ($cur === 'PLN' ? 'zł' : $cur);
}

$status = (string)($_GET['st'] ?? 'open');
if (!in_array($status, ['open', 'payment', 'flow', 'skipped'], true)) $status = 'open';

$ops = []; $counts = ['open' => 0, 'payment' => 0, 'flow' => 0, 'skipped' => 0];
$ctx = ['children' => [], 'donors' => [], 'adoptions' => [], 'accounts' => []];
$adoptionOptions = [];
try {
    adopt_db_ensure_schema();
    $counts = bank_ops_counts();
    $ops = bank_ops_list($status);
    if ($status === 'open' && $ops) {
        $ctx = bank_match_context();
        // Lista adopcji do ręcznego wskazania: "Nazwisko - Imię dziecka (nr)".
        foreach (adopt_sort_by_surname(adopt_adoption_list_all(), 'donor_name') as $a) {
            if (!in_array($a['status'], ['pending', 'active'], true)) continue;
            $adoptionOptions[(int)$a['id']] = $a['donor_name']
                . ($a['child_name'] ? ' - ' . $a['child_name'] . ' (nr ' . (int)$a['child_number'] . ')' : ' - bez dziecka')
                . ' · ' . number_format(((int)$a['amount_grosze']) / 100, 0, ',', ' ') . ' zł/mies.';
        }
    }
} catch (Throwable $e) {
    $dbError = $dbError ?: $e->getMessage();
}

panel_header('Import z banku - Finanse');
?>
    <div class="bar">
      <h2 style="margin:0;">Operacje z wyciągu bankowego</h2>
    </div>
    <?= imp_flash() ?>

<?php if ($dbError !== ''): ?>
    <div class="alert alert-error">Błąd bazy danych: <?= mada_esc($dbError) ?></div>
<?php else: ?>

    <div class="spraw-panel" style="display:block;">
      <h3 style="margin:0 0 6px;">Wgraj wyciąg</h3>
      <p style="margin:0 0 10px;">Bankowość Erste (dawny Santander): <b>Historia</b> -> ustaw zakres dat ->
         <b>CSV</b> -> separator <b>średnik</b>. Plik nie zostaje na serwerze - czytamy z niego operacje
         i od razu kasujemy. Wgranie tego samego okresu drugi raz niczego nie zdubluje.</p>
      <form method="post" enctype="multipart/form-data" class="form" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0;">
        <?= mada_csrf_field() ?>
        <input type="hidden" name="action" value="upload">
        <label style="margin:0;">Plik z bankowości (CSV, TXT albo XLSX)
          <input type="file" name="plik" accept=".csv,.txt,.xlsx" required>
        </label>
        <button type="submit" class="btn-primary btn-sm">Wczytaj operacje</button>
      </form>
    </div>

    <!-- Filtr stanu, nie nawigacja modułu - stąd przyciski, a nie drugi pasek
         wyglądający jak pod-menu Finansów tuż nad nim. -->
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin:18px 0 12px;" role="group" aria-label="Stan operacji">
      <?php foreach ([
          'open' => 'Do decyzji', 'payment' => 'Zapisane jako wpłaty',
          'flow' => 'Zapisane w Finansach', 'skipped' => 'Pominięte',
      ] as $k => $lbl): ?>
        <a href="import-bank.php?st=<?= $k ?>" class="<?= $status === $k ? 'btn-primary' : 'btn-secondary' ?> btn-sm"
           <?= $status === $k ? 'aria-current="page"' : '' ?>><?= $lbl ?> (<?= (int)$counts[$k] ?>)</a>
      <?php endforeach; ?>
    </div>

    <?php if (!$ops): ?>
      <p class="hint"><?= $status === 'open'
          ? 'Poczekalnia jest pusta - wgraj wyciąg, żeby zobaczyć operacje do rozliczenia.'
          : 'Brak operacji w tym stanie.' ?></p>
    <?php else: ?>

      <?php foreach ($ops as $op):
          $isIn = (int)$op['amount_grosze'] > 0;
          $m = $status === 'open'
             ? bank_match_op([
                 'op_date' => $op['op_date'], 'amount_grosze' => (int)$op['amount_grosze'],
                 'currency' => $op['currency'], 'title' => (string)$op['title'],
                 'party' => (string)$op['party'], 'account' => (string)$op['account'],
                 'account_key' => (string)$op['account_key'],
               ], $ctx)
             : null;
      ?>
        <div class="events" style="border:1px solid var(--rule);border-radius:10px;padding:14px 16px;margin:0 0 12px;background:#fff;">
          <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:baseline;">
            <b style="font-size:16px;color:<?= $isIn ? 'var(--ok)' : 'var(--err)' ?>;">
              <?= $isIn ? '+' : '' ?><?= mada_esc(imp_money((int)$op['amount_grosze'], (string)$op['currency'])) ?></b>
            <span><?= mada_esc((string)$op['op_date']) ?></span>
            <span><b><?= mada_esc((string)$op['party'] ?: '(bez nadawcy)') ?></b></span>
            <span class="hint" style="flex:1;min-width:200px;"><?= mada_esc((string)$op['title']) ?></span>
            <?php if ($op['status'] !== 'open'): ?>
              <span class="badge" style="background:var(--creamDk);color:#8a7963;border-color:var(--rule);">
                <?= $op['status'] === 'payment' ? 'wpłata #' . (int)$op['target_id']
                  : ($op['status'] === 'flow' ? 'przepływ #' . (int)$op['target_id'] : 'pominięta') ?></span>
            <?php endif; ?>
          </div>

          <?php if ($status === 'open'): ?>
            <p class="hint" style="margin:6px 0 10px;">
              <?= $m['reason'] !== '' ? mada_esc('Podpowiedź: ' . $m['reason']) : '' ?>
              <?php if ((string)$op['account_key'] !== ''): ?>
                <span style="margin-left:8px;">rachunek: <?= mada_esc((string)$op['account']) ?></span>
              <?php endif; ?>
            </p>

            <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:flex-start;">
              <?php if ($isIn): ?>
              <form method="post" class="form" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin:0;flex:2;min-width:320px;">
                <?= mada_csrf_field() ?>
                <input type="hidden" name="action" value="save-payment">
                <input type="hidden" name="op_id" value="<?= (int)$op['id'] ?>">
                <label style="flex:1;min-width:260px;">Wpłata na adopcję
                  <select name="adoption_id" required>
                    <option value="">- wskaż adopcję -</option>
                    <?php foreach ($adoptionOptions as $aid => $lbl): ?>
                      <option value="<?= $aid ?>" <?= (int)($m['adoption_id'] ?? 0) === $aid ? 'selected' : '' ?>><?= mada_esc($lbl) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>Okres od<input type="text" name="period_from" placeholder="2026-08" style="width:90px;"
                       value="<?= mada_esc((string)($m['period_from'] ?? '')) ?>" required></label>
                <label>do<input type="text" name="period_to" placeholder="2027-01" style="width:90px;"
                       value="<?= mada_esc((string)($m['period_to'] ?? '')) ?>"></label>
                <?php if ((string)$op['account_key'] !== ''): ?>
                  <label style="flex-direction:row;align-items:center;gap:6px;">
                    <input type="checkbox" name="zapamietaj" value="1" checked style="width:auto;">
                    <span class="hint">zapamiętaj rachunek</span>
                  </label>
                <?php endif; ?>
                <button type="submit" class="btn-primary btn-sm">Zapisz wpłatę</button>
              </form>
              <?php endif; ?>

              <form method="post" class="form" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin:0;flex:1;min-width:260px;">
                <?= mada_csrf_field() ?>
                <input type="hidden" name="action" value="save-flow">
                <input type="hidden" name="op_id" value="<?= (int)$op['id'] ?>">
                <label style="flex:1;min-width:180px;"><?= $isIn ? 'albo do Finansów jako' : 'Do Finansów jako' ?>
                  <select name="category">
                    <?php foreach (FIN_CATEGORIES as $k => $lbl): ?>
                      <option value="<?= $k ?>" <?= ($m['category'] ?? '') === $k ? 'selected' : '' ?>><?= mada_esc($lbl) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <button type="submit" class="btn-secondary btn-sm">Zapisz przepływ</button>
              </form>

              <form method="post" style="margin:0;align-self:flex-end;">
                <?= mada_csrf_field() ?>
                <input type="hidden" name="action" value="skip">
                <input type="hidden" name="op_id" value="<?= (int)$op['id'] ?>">
                <button type="submit" class="btn-ghost btn-sm">Pomiń</button>
              </form>
            </div>
          <?php elseif ($op['status'] === 'skipped'): ?>
            <form method="post" style="margin:8px 0 0;">
              <?= mada_csrf_field() ?>
              <input type="hidden" name="action" value="reopen">
              <input type="hidden" name="op_id" value="<?= (int)$op['id'] ?>">
              <button type="submit" class="btn-ghost btn-sm">Przywróć do decyzji</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

    <?php endif; ?>
<?php endif; ?>
<?php
panel_footer();
