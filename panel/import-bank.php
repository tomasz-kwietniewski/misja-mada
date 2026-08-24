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
            $peek = [];
            try {
                [$headers, $rows, $meta] = bank_read_table($tmp);
                $ops = $headers ? bank_rows_to_ops($headers, $rows, $meta) : [];
                // Plik ma treść, ale nie rozumiemy układu - to najczęstszy objaw
                // zmiany formatu po stronie banku. Pokazujemy surowe wiersze
                // zamiast obwiniać pracownika o zły eksport.
                if (!$ops) $peek = bank_peek_rows($tmp);
            } finally {
                @unlink($tmp);
            }

            if (!$ops) {
                $_SESSION['bank_import_peek'] = $peek;
                mada_audit('bank.import', 'bank', null,
                    ['plik' => (string)$f['name'], 'operacji' => 0, 'uklad' => 'nierozpoznany']);
                mada_redirect('import-bank.php?msg=' . ($peek ? 'layout' : 'empty'));
            }

            /* Kontrola formatu i kompletności. Metryka pliku mówi, ile operacji
               ma być i o ile zmieniło się saldo; jej BRAK jest tak samo ważną
               informacją, bo znaczy, że nie mamy czym sprawdzić danych. Wynik
               zostaje przy partii, a nie w sesji - ma być widoczny za każdym
               razem, gdy ktoś patrzy na te operacje. */
            $warnings = bank_sanity_report($meta, $ops, $rows);
            // Najczęstsza szerokość wiersza - zapisujemy ją, żeby dało się później
            // odczytać z historii, kiedy układ pliku się zmienił.
            $widths = array_count_values(array_filter(array_map('bank_row_width', $rows)));
            arsort($widths);
            $batchId = bank_batch_insert([
                'file_name'     => (string)$f['name'],
                'layout'        => $headers === BANK_ERSTE_HEADER ? 'erste' : 'naglowek',
                'columns_count' => $widths ? (int)array_key_first($widths) : 0,
                'currency'      => (string)($meta['currency'] ?? ''),
                'account'       => (string)($meta['account'] ?? ''),
                'holder'        => (string)($meta['holder'] ?? ''),
                'date_from'     => $meta['date_from'] ?? null,
                'ops_declared'  => $meta['count'] ?? null,
                'ops_read'      => count($ops),
                'sum_grosze'    => array_sum(array_column($ops, 'amount_grosze')),
                'balance_from'  => $meta['balance_from'] ?? null,
                'balance_to'    => $meta['balance_to'] ?? null,
                'warnings'      => $warnings,
                'imported_by'   => $user,
            ]);
            $res = bank_ops_insert_many($ops, $user, $batchId);
            bank_batch_mark_added($batchId, (int)$res['added']);
            mada_audit('bank.import', 'bank', $batchId, [
                'plik' => (string)$f['name'], 'operacji' => count($ops),
                'uklad' => $headers === BANK_ERSTE_HEADER ? 'erste' : 'naglowek',
                'ostrzezenia' => count($warnings),
            ] + $res);
            mada_redirect('import-bank.php?msg=loaded&n=' . (int)$res['added'] . '&d=' . (int)$res['dups']);
        }

        /* ── Zapis operacji jako wpłaty Adopcji Serca ─────────────
           Jedna operacja może dać KILKA wpłat: darczyńca z dwojgiem dzieci
           robi zwykle jeden przelew na oba. Gdy rozdysponowana suma nie
           pokrywa całej kwoty, operacja zostaje w poczekalni z resztą. */
        if ($action === 'save-payment') {
            $op = bank_op_get((int)($_POST['op_id'] ?? 0));
            if (!$op || $op['status'] !== 'open' || (int)$op['amount_grosze'] <= 0) {
                mada_redirect('import-bank.php?msg=bad');
            }
            $left = abs((int)$op['amount_grosze']) - (int)($op['allocated_grosze'] ?? 0);
            $items = []; $seen = [];
            foreach (array_keys((array)($_POST['use'] ?? [])) as $i) {
                $aid = (int)(($_POST['adoption_id'][$i] ?? 0));
                if ($aid <= 0) continue;
                if (isset($seen[$aid])) mada_redirect('import-bank.php?msg=dubel');
                $ad = adopt_adoption_get($aid);
                if (!$ad) mada_redirect('import-bank.php?msg=bad');
                $from = trim((string)($_POST['period_from'][$i] ?? ''));
                $to   = trim((string)($_POST['period_to'][$i] ?? '')) ?: $from;
                if (!adopt_month_valid($from) || !adopt_month_valid($to) || $to < $from) {
                    mada_redirect('import-bank.php?msg=okres');
                }
                $amount = bank_parse_amount((string)($_POST['amount'][$i] ?? ''));
                if ($amount === null || $amount <= 0) mada_redirect('import-bank.php?msg=kwota');
                $seen[$aid] = true;
                $items[] = ['adoption_id' => $aid, 'donor_id' => (int)$ad['donor_id'],
                            'amount' => $amount, 'from' => $from, 'to' => $to];
            }
            if (!$items) mada_redirect('import-bank.php?msg=nosel');
            if (array_sum(array_column($items, 'amount')) > $left) {
                mada_redirect('import-bank.php?msg=kwota');
            }

            $pdo = payu_db();
            $pdo->beginTransaction();
            try {
                $first = null; $suma = 0;
                foreach ($items as $it) {
                    $pid = adopt_payment_insert([
                        'adoption_id'   => $it['adoption_id'],
                        'amount_grosze' => $it['amount'],
                        'currency'      => (string)$op['currency'],
                        'paid_at'       => $op['op_date'],
                        'period_from'   => $it['from'],
                        'period_to'     => $it['to'],
                        'method'        => 'transfer',
                        'note'          => 'wyciąg: ' . (string)$op['title'],
                        'created_by'    => $user,
                    ]);
                    adopt_adoption_backfill_start($it['adoption_id']);
                    $first ??= $pid;
                    $suma += $it['amount'];
                    mada_audit('bank.payment', 'payment', $pid, [
                        'operacja' => (int)$op['id'], 'adopcja' => $it['adoption_id'],
                        'okres' => $it['from'] . '..' . $it['to'],
                        'kwota' => number_format($it['amount'] / 100, 2, '.', ''),
                    ]);
                }
                bank_op_allocate((int)$op['id'], $suma, count($items), $first, $user);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            if (!empty($_POST['zapamietaj']) && (string)$op['account_key'] !== '') {
                bank_account_remember((string)$op['account_key'], $items[0]['donor_id'],
                                      (string)$op['party'], $user);
            }
            $rest = $left - array_sum(array_column($items, 'amount'));
            mada_redirect('import-bank.php?msg=payok&n=' . count($items) . '&rest=' . max(0, $rest));
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
        /* ── Cofnięcie całego importu ────────────────────────────
           Ratunek na wypadek, gdy plik okazał się nie tym, czym miał być
           (zmieniony format, zły zakres dat, pomyłkowy rachunek). Usuwa
           operacje, przy których niczego nie zapisano; wpłaty, przepływy
           i operacje rozliczone częściowo zostają - patrz bank_batch_undo. */
        if ($action === 'undo-batch') {
            $bid = (int)($_POST['batch_id'] ?? 0);
            $b = $bid > 0 ? bank_batch_get($bid) : null;
            if (!$b || $b['status'] !== 'open') mada_redirect('import-bank.php?msg=bad');
            $usuniete = bank_batch_undo($bid, $user);
            mada_audit('bank.undo', 'bank', $bid,
                ['plik' => (string)($b['file_name'] ?? ''), 'usunieto' => $usuniete]);
            mada_redirect('import-bank.php?msg=undone&n=' . $usuniete);
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
        'empty'    => ['error', 'W pliku nie znaleziono operacji. Wgraj plik prosto z bankowości '
                              . '(w Erste: Historia -> zakres dat -> pobierz CSV), bez otwierania '
                              . 'i zapisywania go po drodze w Excelu.'],
        'layout'   => ['error', ''],   // treść składana w imp_layout_flash()
        'undone'   => ['ok', ''],      // j.w.
        'bad'      => ['error', 'Nieprawidłowe dane operacji.'],
        'nosel'    => ['error', 'Nie zaznaczono żadnej adopcji do rozliczenia.'],
        'okres'    => ['error', 'Okres musi mieć postać RRRR-MM, a miesiąc „do" nie może być '
                              . 'wcześniejszy niż „od".'],
        'kwota'    => ['error', 'Kwoty są nieprawidłowe albo ich suma przekracza to, '
                              . 'co zostało do rozliczenia w tej operacji.'],
        'dubel'    => ['error', 'Ta sama adopcja wskazana dwa razy w jednej operacji.'],
    ];
    $m = $_GET['msg'] ?? '';
    if (!isset($codes[$m])) return '';
    [$t, $txt] = $codes[$m];
    if ($m === 'loaded') {
        $txt = 'Wczytano ' . (int)($_GET['n'] ?? 0) . ' nowych operacji'
             . ((int)($_GET['d'] ?? 0) > 0 ? ', pominięto ' . (int)$_GET['d'] . ' już wczytanych wcześniej' : '') . '.';
    }
    if ($m === 'layout')  return imp_layout_flash();
    if ($m === 'undone') {
        $txt = 'Import cofnięty - z poczekalni usunięto ' . (int)($_GET['n'] ?? 0)
             . ' operacji, przy których niczego nie zapisano. Wpłaty, przepływy i operacje '
             . 'rozliczone częściowo zostały nietknięte, a poprawiony plik można wgrać od nowa.';
    }
    if ($m === 'payok') {
        $n = max(1, (int)($_GET['n'] ?? 1));
        $rest = (int)($_GET['rest'] ?? 0);
        $txt = ($n === 1 ? 'Wpłata zapisana i dopisana do macierzy.'
                         : 'Zapisano ' . $n . ($n <= 4 ? ' wpłaty' : ' wpłat') . ' i dopisano do macierzy.')
             . ($rest > 0
                ? ' Operacja została w poczekalni - do rozliczenia zostało '
                  . imp_money($rest) . '.'
                : '');
    }
    return '<div class="alert alert-' . ($t === 'ok' ? 'ok' : 'error') . '">' . mada_esc($txt) . '</div>';
}

/**
 * Komunikat na wypadek, gdy plik ma treść, ale układu kolumn nie rozumiemy.
 *
 * Dotąd każdy taki przypadek kończył się radą „wgraj plik prosto z bankowości,
 * bez otwierania w Excelu" - czyli obwinialiśmy pracownika o błąd, którego nie
 * popełnił, a najbardziej prawdopodobną przyczyną jest zmiana formatu po
 * stronie banku. Pokazujemy więc pierwsze wiersze pliku: zrzut tego ekranu
 * wystarczy, żeby zdiagnozować sprawę zdalnie.
 */
function imp_layout_flash(): string {
    $peek = $_SESSION['bank_import_peek'] ?? [];
    unset($_SESSION['bank_import_peek']);
    $h = '<div class="alert alert-error"><strong>Nie rozpoznaję układu tego pliku.</strong><br>'
       . 'Plik ma treść, ale kolumny nie wyglądają tak jak dotychczas. Najczęściej znaczy to, '
       . 'że bank zmienił format eksportu - wtedy ponawianie wgrywania nic nie da. '
       . 'Pokaż ten ekran Tomkowi, wystarczy zrzut.';
    if ($peek) {
        $h .= '<br><span class="hint">Pierwsze wiersze pliku (numery rachunków zamazane):</span>'
            . '<pre style="white-space:pre-wrap;word-break:break-all;font-size:12px;'
            . 'background:var(--creamDk);padding:8px;border-radius:6px;margin:6px 0 0;">'
            . mada_esc(implode("
", $peek)) . '</pre>';
    }
    return $h . '</div>';
}

/**
 * Raport wgranego pliku: który rachunek, jaka waluta, czy liczba operacji
 * i suma zgadzają się z saldami, czy układ kolumn wygląda jak dotychczas.
 *
 * Wisi nad poczekalnią tak długo, jak długo partia ma nierozliczone operacje.
 * Wcześniej ten sam komunikat szedł do sesji i pokazywał się dokładnie RAZ,
 * czyli znikał przy pierwszym przeładowaniu strony - a więc wtedy, gdy był
 * najbardziej potrzebny. Przy ostrzeżeniach twardych („nie mam jak sprawdzić
 * kompletności") raport jest czerwony i ma przy sobie cofnięcie importu.
 */
function imp_batch_report(array $b): string {
    $warn = json_decode((string)($b['warnings'] ?? ''), true) ?: [];
    $hard = bank_sanity_has_hard($warn);
    $cur  = (string)($b['currency'] ?? '') ?: 'PLN';

    $opis = 'Plik <b>' . mada_esc((string)($b['file_name'] ?? '(bez nazwy)')) . '</b>'
          . ($b['holder'] ? ', rachunek ' . mada_esc((string)$b['holder']) : '')
          . ' (' . mada_esc($cur) . ')'
          . ($b['date_from'] ? ', wyciąg od ' . mada_esc((string)$b['date_from']) : '')
          . ': '
          . ($b['ops_declared'] !== null
              ? 'plik zapowiada ' . (int)$b['ops_declared'] . ' operacji, rozpoznano ' . (int)$b['ops_read']
              : 'rozpoznano ' . (int)$b['ops_read'] . ' operacji')
          . ', nowych ' . (int)$b['ops_added']
          . ', do rozliczenia zostało ' . (int)$b['open_ops'] . '.';

    $h = '<div class="alert alert-' . ($hard ? 'error' : 'ok') . '">';
    if ($hard) $h .= '<strong>Ten plik wymaga sprawdzenia, zanim zatwierdzisz wpłaty.</strong><br>';
    $h .= $opis;
    if ($warn) {
        $h .= '<ul style="margin:6px 0 0;padding-left:18px;">';
        foreach ($warn as $w) {
            $h .= '<li' . (($w['level'] ?? '') === 'hard' ? '' : ' class="hint"') . '>'
                . mada_esc((string)($w['text'] ?? '')) . '</li>';
        }
        $h .= '</ul>';
    } else {
        $h .= ' Suma operacji zgadza się ze zmianą salda, układ pliku bez zmian.';
    }
    $pytanie = 'Cofnąć cały ten import? Z poczekalni znikną operacje, przy których niczego '
             . 'jeszcze nie zapisano. Wpłaty, przepływy i operacje rozliczone częściowo zostaną.';
    $h .= '<form method="post" style="margin:8px 0 0;" onsubmit="return confirm('
        . mada_esc(json_encode($pytanie, JSON_UNESCAPED_UNICODE)) . ');">'
        . mada_csrf_field()
        . '<input type="hidden" name="action" value="undo-batch">'
        . '<input type="hidden" name="batch_id" value="' . (int)$b['id'] . '">'
        . '<button type="submit" class="btn-ghost btn-sm">Cofnij cały import</button></form>';
    return $h . '</div>';
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
$adoptionsByDonor = [];
$childNames = [];
$batches = [];
try {
    adopt_db_ensure_schema();
    $counts = bank_ops_counts();
    $ops = bank_ops_list($status);
    // Raporty wgranych plików, które mają jeszcze coś do rozliczenia.
    if ($status === 'open') $batches = bank_batches_open();
    if ($status === 'open' && $ops) {
        $ctx = bank_match_context();
        // Lista adopcji do ręcznego wskazania: "Nazwisko - Imię dziecka (nr)".
        foreach (adopt_sort_by_surname(adopt_adoption_list_all(), 'donor_name') as $a) {
            if (!in_array($a['status'], ['pending', 'active'], true)) continue;
            $adoptionOptions[(int)$a['id']] = $a['donor_name']
                . ($a['child_name'] ? ' - ' . $a['child_name'] . ' (nr ' . (int)$a['child_number'] . ')' : ' - bez dziecka')
                . ' · ' . number_format(((int)$a['amount_grosze']) / 100, 0, ',', ' ') . ' zł/mies.';
        }
        // Adopcje po darczyńcy - z nich powstają gotowe wiersze formularza,
        // gdy jedna wpłata ma pokryć kilkoro dzieci tej samej osoby.
        foreach ($ctx['adoptions'] as $a) $adoptionsByDonor[(int)$a['donor_id']][] = $a;
        $childNames = array_column($ctx['children'], 'name');
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
         pobierz <b>CSV</b> i wgraj tutaj bez otwierania go w Excelu. Każdy rachunek pobiera się osobno
         (walutowy też - walutę bierzemy z pliku). Plik nie zostaje na serwerze - czytamy z niego operacje
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

    <?php foreach ($batches as $b): ?>
      <?= imp_batch_report($b) ?>
    <?php endforeach; ?>

    <?php if (!$ops): ?>
      <p class="hint"><?= $status === 'open'
          ? 'Poczekalnia jest pusta - wgraj wyciąg, żeby zobaczyć operacje do rozliczenia.'
          : 'Brak operacji w tym stanie.' ?></p>
    <?php else: ?>

      <?php foreach ($ops as $op):
          $isIn = (int)$op['amount_grosze'] > 0;
          // Ile z tej operacji zostało jeszcze do rozdysponowania.
          $left = abs((int)$op['amount_grosze']) - (int)($op['allocated_grosze'] ?? 0);
          $part = (int)($op['allocated_grosze'] ?? 0) > 0;

          /* Podpowiedź liczymy od kwoty POZOSTAŁEJ, nie od pełnej: przy operacji
             rozliczonej w części „kwota = 2 miesiące" dotyczyłaby pieniędzy,
             których już nie ma do rozdysponowania. */
          $opArr = [
              'op_date' => $op['op_date'],
              'amount_grosze' => (int)$op['amount_grosze'] > 0 ? $left : (int)$op['amount_grosze'],
              'currency' => $op['currency'], 'title' => (string)$op['title'],
              'party' => (string)$op['party'], 'account' => (string)$op['account'],
              'account_key' => (string)$op['account_key'],
          ];
          $m = $status === 'open' ? bank_match_op($opArr, $ctx) : null;

          /* Wiersze formularza: adopcje rozpoznanego darczyńcy (gotowe do
             zaznaczenia) plus dwa puste z pełną listą. Przy dwojgu dzieci
             panel proponuje podział kwoty - patrz bank_split_payment. */
          $rowsFor = []; $split = [];
          if ($status === 'open' && $isIn) {
              $did = (int)($m['donor_id'] ?? 0);
              if ($did > 0) $rowsFor = $adoptionsByDonor[$did] ?? [];
              if (!$rowsFor && (int)($m['adoption_id'] ?? 0) > 0) {
                  foreach ($ctx['adoptions'] as $a) {
                      if ((int)$a['id'] === (int)$m['adoption_id']) { $rowsFor = [$a]; break; }
                  }
              }
              if (count($rowsFor) > 1) {
                  $split = bank_split_payment($opArr, $rowsFor,
                      bank_title_months((string)$op['title'], (string)$op['op_date'], $childNames));
              }
          }
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
                <?php if ($op['status'] === 'payment'): ?>
                  <?= (int)($op['target_count'] ?? 1) > 1
                      ? (int)$op['target_count'] . ' wpłaty (rozdzielona)'
                      : 'wpłata #' . (int)$op['target_id'] ?>
                <?php else: ?>
                  <?= $op['status'] === 'flow' ? 'przepływ #' . (int)$op['target_id'] : 'pominięta' ?>
                <?php endif; ?></span>
            <?php endif; ?>
          </div>

          <?php if ($status === 'open'): ?>
            <p class="hint" style="margin:6px 0 4px;">
              <?= $m['reason'] !== '' ? mada_esc('Podpowiedź: ' . $m['reason']) : '' ?>
              <?php if ((string)$op['account_key'] !== ''): ?>
                <span style="margin-left:8px;">rachunek: <?= mada_esc((string)$op['account']) ?></span>
              <?php endif; ?>
              <?php if ($part): ?>
                <span class="badge" style="margin-left:8px;background:var(--creamDk);color:#8a7963;border-color:var(--rule);">
                  rozliczono <?= mada_esc(imp_money((int)$op['allocated_grosze'], (string)$op['currency'])) ?>
                  z <?= mada_esc(imp_money(abs((int)$op['amount_grosze']), (string)$op['currency'])) ?>,
                  zostało <?= mada_esc(imp_money($left, (string)$op['currency'])) ?></span>
              <?php endif; ?>
            </p>

            <?php /* Ostrzeżenia merytoryczne: nachodzenie na opłacone miesiące,
                     rozjazd tytułu z kwotą. Nie blokują zapisu - mają być widoczne. */ ?>
            <?php foreach ((array)($m['warn'] ?? []) as $w): ?>
              <p class="hint" style="margin:0 0 4px;color:var(--err);">Uwaga: <?= mada_esc($w) ?></p>
            <?php endforeach; ?>

            <?php if (!empty($m['donor_candidates'])): ?>
              <p class="hint" style="margin:0 0 6px;">Kto to może być:
                <?php foreach ($m['donor_candidates'] as $c): ?>
                  <span class="badge" style="margin-right:4px;"><?= mada_esc($c['name']) ?>
                    <?= $c['level'] === 'fuzzy' ? '(podobne)' : '' ?></span>
                <?php endforeach; ?>
                - wskaż adopcję z listy.
              </p>
            <?php endif; ?>

            <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:flex-start;">
              <?php if ($isIn): ?>
              <form method="post" class="form" style="margin:0;flex:2;min-width:340px;">
                <?= mada_csrf_field() ?>
                <input type="hidden" name="action" value="save-payment">
                <input type="hidden" name="op_id" value="<?= (int)$op['id'] ?>">
                <p style="margin:0 0 6px;font-weight:600;">Wpłata na adopcję</p>

                <?php
                  $i = 0;
                  // Wiersze gotowe: adopcje rozpoznanego darczyńcy.
                  foreach ($rowsFor as $a):
                      $aid = (int)$a['id'];
                      $s = $split[$aid] ?? null;
                      $one = count($rowsFor) === 1 && (int)($m['adoption_id'] ?? 0) === $aid;
                      $checked = $s !== null || $one;
                      $amt  = $s['amount_grosze'] ?? ($one ? $left : null);
                      $from = $s['period_from'] ?? ($one ? ($m['period_from'] ?? '') : '');
                      $to   = $s['period_to']   ?? ($one ? ($m['period_to'] ?? '') : '');
                      $lbl  = $adoptionOptions[$aid] ?? ('adopcja #' . $aid);
                ?>
                  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin:0 0 6px;">
                    <input type="hidden" name="adoption_id[<?= $i ?>]" value="<?= $aid ?>">
                    <label style="flex-direction:row;align-items:center;gap:6px;flex:1;min-width:240px;">
                      <input type="checkbox" name="use[<?= $i ?>]" value="1" style="width:auto;"
                             <?= $checked ? 'checked' : '' ?>>
                      <span><?= mada_esc($lbl) ?></span>
                    </label>
                    <label>kwota<input type="text" name="amount[<?= $i ?>]" style="width:90px;"
                           value="<?= $amt !== null ? mada_esc(number_format($amt / 100, 2, ',', '')) : '' ?>"></label>
                    <label>okres od<input type="text" name="period_from[<?= $i ?>]" placeholder="RRRR-MM"
                           style="width:90px;" value="<?= mada_esc((string)$from) ?>"></label>
                    <label>do<input type="text" name="period_to[<?= $i ?>]" placeholder="RRRR-MM"
                           style="width:90px;" value="<?= mada_esc((string)$to) ?>"></label>
                  </div>
                <?php $i++; endforeach; ?>

                <?php /* Puste wiersze na wpłaty trafiające poza podpowiedź. Gdy nikogo nie
                         rozpoznaliśmy, potrzebne są dwa (żeby dało się od razu rozdzielić
                         wpłatę na dwoje dzieci); przy rozpoznanym darczyńcy wystarczy jeden,
                         bo jego adopcje stoją już wyżej - a przy 47 operacjach każdy zbędny
                         wiersz to szum na ekranie. */ ?>
                <?php $puste = $rowsFor ? 1 : 2; ?>
                <?php for ($k = 0; $k < $puste; $k++): $j = $i + $k; ?>
                  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin:0 0 6px;">
                    <label style="flex-direction:row;align-items:center;gap:6px;flex:1;min-width:240px;">
                      <input type="checkbox" name="use[<?= $j ?>]" value="1" style="width:auto;">
                      <select name="adoption_id[<?= $j ?>]" style="flex:1;min-width:220px;">
                        <option value="">- wskaż adopcję -</option>
                        <?php foreach ($adoptionOptions as $aid => $lbl): ?>
                          <option value="<?= $aid ?>"
                            <?= (!$rowsFor && $k === 0 && (int)($m['adoption_id'] ?? 0) === $aid) ? 'selected' : '' ?>>
                            <?= mada_esc($lbl) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label>kwota<input type="text" name="amount[<?= $j ?>]" style="width:90px;"
                           value="<?= (!$rowsFor && $k === 0 && (int)($m['adoption_id'] ?? 0) > 0)
                                      ? mada_esc(number_format($left / 100, 2, ',', '')) : '' ?>"></label>
                    <label>okres od<input type="text" name="period_from[<?= $j ?>]" placeholder="RRRR-MM"
                           style="width:90px;" value="<?= (!$rowsFor && $k === 0) ? mada_esc((string)($m['period_from'] ?? '')) : '' ?>"></label>
                    <label>do<input type="text" name="period_to[<?= $j ?>]" placeholder="RRRR-MM"
                           style="width:90px;" value="<?= (!$rowsFor && $k === 0) ? mada_esc((string)($m['period_to'] ?? '')) : '' ?>"></label>
                  </div>
                <?php endfor; ?>

                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:4px;">
                  <span class="hint">do rozliczenia:
                    <?= mada_esc(imp_money($left, (string)$op['currency'])) ?><?php
                      if (!empty($m['months'])): ?> · <?= mada_esc(bank_months_label((int)$m['months'])) ?><?php
                      endif; ?></span>
                  <?php if ((string)$op['account_key'] !== ''): ?>
                    <label style="flex-direction:row;align-items:center;gap:6px;margin:0;">
                      <input type="checkbox" name="zapamietaj" value="1" checked style="width:auto;">
                      <span class="hint">zapamiętaj rachunek</span>
                    </label>
                  <?php endif; ?>
                  <button type="submit" class="btn-primary btn-sm">Zapisz wpłatę</button>
                </div>
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
