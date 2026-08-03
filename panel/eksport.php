<?php
/* ═══ CMS - eksport danych Adopcji Serca (wyjście awaryjne) ═══════
   Jeden klik = plik XLSX w układach znanych fundacji (Lista
   darczyńców + macierz wpłat + finanse) albo pojedyncze CSV.
   Gwarancja: w każdej chwili można wrócić do ręcznego arkusza. */
require_once __DIR__ . '/layout.php';
mada_require_login();
require_once __DIR__ . '/../adopcja/db.php';
require_once __DIR__ . '/../adopcja/lib.php';
require_once __DIR__ . '/../adopcja/xlsx.php';

$typ = (string)($_GET['typ'] ?? '');

/* ── Budowa danych eksportu (wspólne dla CSV i XLSX) ───────────── */
function eks_lista_rows(): array {
    $rows = [['Lp', 'IMIĘ I NAZWISKO DARCZYŃCY', 'e-mail', 'Telefon', 'DZIECKO', 'Numer Dziecka',
              'CZAS ADOPCJI', 'PŁATNOŚĆ', 'Metoda', 'Status', 'Opłacone do', 'Zaległe miesiące', 'UWAGI']];
    $all = adopt_adoption_list_all();
    $pays = adopt_payments_by_adoptions(array_column($all, 'id'));
    $today = date('Y-m-d');
    $freqL = ['monthly' => 'MIESIĘCZNIE', 'quarterly' => 'KWARTALNIE', 'yearly' => 'ROCZNIE'];
    $metL = ['transfer' => 'przelew', 'card' => 'karta', 'cash' => 'gotówka'];
    $stL = ['pending' => 'oczekująca', 'active' => 'aktywna', 'ended' => 'zakończona', 'cancelled' => 'anulowana'];
    $lp = 0;
    foreach ($all as $a) {
        $p = $pays[(int)$a['id']] ?? [];
        $czas = $a['duration'] === 'fixed'
            ? 'OKREŚLONY (' . adopt_month_label($a['start_month']) . ' - ' . adopt_month_label($a['end_month']) . ')'
            : 'NIEOKREŚLONY' . ($a['start_month'] !== null ? ' (od ' . adopt_month_label($a['start_month']) . ')' : '');
        $miss = in_array($a['status'], ['pending', 'active'], true) && $a['start_month'] !== null
            ? count(adopt_arrears($a['start_month'], $a['end_month'], $p, $today)) : 0;
        $donor = adopt_donor_get((int)$a['donor_id']);
        $rows[] = [
            ++$lp, $a['donor_name'],
            trim(($donor['email'] ?? '') . (($donor['emails_extra'] ?? '') ? '; ' . $donor['emails_extra'] : '')),
            $donor['phone'] ?? '',
            $a['child_name'] ?? '', $a['child_number'] !== null ? (int)$a['child_number'] : '',
            $czas, $freqL[$a['frequency']] ?? '', $metL[$a['method']] ?? '', $stL[$a['status']] ?? '',
            adopt_month_label(adopt_paid_until($p)), $miss, $a['notes'] ?? '',
        ];
    }
    return $rows;
}

function eks_wplaty_rows(): array {
    $from = adopt_month_add(date('Y-m'), -23);
    $months = adopt_month_range($from, adopt_month_add(date('Y-m'), 3));
    $head = array_merge(['Darczyńca', 'Dziecko', 'Nr'], array_map('adopt_month_label', $months));
    $rows = [$head];
    $all = array_values(array_filter(adopt_adoption_list_all(),
        fn($a) => in_array($a['status'], ['pending', 'active'], true)));
    $pays = adopt_payments_by_adoptions(array_column($all, 'id'));
    foreach ($all as $a) {
        $covered = array_flip(adopt_coverage($pays[(int)$a['id']] ?? []));
        $row = [$a['donor_name'], $a['child_name'] ?? '', $a['child_number'] !== null ? (int)$a['child_number'] : ''];
        foreach ($months as $m) {
            $row[] = isset($covered[$m]) ? ((int)$a['amount_grosze']) / 100 : '';
        }
        $rows[] = $row;
    }
    return $rows;
}

function eks_wplaty_lista_rows(int $year): array {
    $rows = [['Data wpłaty', 'Darczyńca', 'Dziecko', 'Okres od', 'Okres do', 'Kwota (zł)', 'Metoda', 'Notatka', 'Kto wpisał']];
    $st = payu_db()->prepare(
        "SELECT p.*, d.full_name, c.name AS child_name
           FROM adopt_payments p
           JOIN adopt_adoptions a ON a.id = p.adoption_id
           JOIN adopt_donors d ON d.id = a.donor_id
           LEFT JOIN adopt_children c ON c.id = a.child_id
          WHERE YEAR(p.paid_at) = ?
          ORDER BY p.paid_at, p.id"
    );
    $st->execute([$year]);
    foreach ($st->fetchAll() as $p) {
        $rows[] = [$p['paid_at'], $p['full_name'], $p['child_name'] ?? '',
                   adopt_month_label($p['period_from']), adopt_month_label($p['period_to']),
                   $p['amount_grosze'] / 100, $p['method'], $p['note'] ?? '', $p['created_by'] ?? 'auto'];
    }
    return $rows;
}

function eks_finanse_rows(?int $year): array {
    $rows = [['Data', 'Kierunek', 'Kategoria', 'Kwota', 'Waluta', 'Kurs', 'Kwota PLN', 'Forma', 'Kontrahent', 'Grupa', 'Status', 'Notatka']];
    foreach (fin_flow_list($year) as $f) {
        $rows[] = [$f['flow_date'], $f['direction'] === 'in' ? 'wpływ' : 'wydatek',
                   FIN_CATEGORIES[$f['category']] ?? $f['category'],
                   $f['amount_grosze'] / 100, $f['currency'], $f['fx_rate'] !== null ? (float)$f['fx_rate'] : '',
                   $f['amount_pln_grosze'] !== null ? $f['amount_pln_grosze'] / 100 : '',
                   $f['method'], $f['counterparty'] ?? '', $f['group_label'] ?? '', $f['status'], $f['note'] ?? ''];
    }
    return $rows;
}

function eks_csv(array $rows, string $filename): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";   // BOM - polskie znaki w Excelu
    $out = fopen('php://output', 'w');
    foreach ($rows as $r) fputcsv($out, array_map(fn($v) => is_float($v) ? number_format($v, 2, ',', '') : $v, $r), ';', '"', '\\');
    fclose($out);
    exit;
}

if ($typ !== '') {
    try {
        adopt_db_ensure_schema();
        $rok = (int)($_GET['rok'] ?? date('Y'));
        $stamp = date('Y-m-d');
        switch ($typ) {
            case 'darczyncy-csv': eks_csv(eks_lista_rows(), "adopcja-darczyncy-$stamp.csv");
            case 'wplaty-csv':    eks_csv(eks_wplaty_lista_rows($rok), "adopcja-wplaty-$rok-$stamp.csv");
            case 'finanse-csv':   eks_csv(eks_finanse_rows($rok), "finanse-$rok-$stamp.csv");
            case 'xlsx':
                $bin = adopt_xlsx_build([
                    ['name' => 'Lista Wszystkich Darczyńców', 'rows' => eks_lista_rows()],
                    ['name' => 'Wpłaty (macierz)',            'rows' => eks_wplaty_rows()],
                    ['name' => 'Wpłaty ' . $rok,              'rows' => eks_wplaty_lista_rows($rok)],
                    ['name' => 'Finanse',                     'rows' => eks_finanse_rows(null)],
                ]);
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="adopcja-serca-backup-' . $stamp . '.xlsx"');
                header('Content-Length: ' . strlen($bin));
                echo $bin;
                exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        exit('Błąd eksportu: ' . mada_esc($e->getMessage()));
    }
}

$dbError = '';
$counts = null;
try {
    adopt_db_ensure_schema();
    $counts = adopt_counts();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}
$hasZip = class_exists('ZipArchive');

panel_header('Eksport - Adopcja Serca');
?>
    <div class="bar">
      <h2 style="margin:0;">Eksport / backup danych</h2>
      <a href="adopcje.php" class="btn-ghost btn-sm">← Przegląd</a>
    </div>

<?php if ($dbError !== ''): ?>
    <div class="alert alert-error">Błąd bazy danych: <?= mada_esc($dbError) ?></div>
<?php else: ?>
    <p class="hint">Wyjście awaryjne: w każdej chwili można pobrać dane w formacie arkusza i wrócić do ręcznej pracy.
       Stan bazy: <?= $counts['adoptions'] ?> adopcji, <?= $counts['payments'] ?> wpłat.</p>

    <?php if ($hasZip): ?>
    <div class="spraw-panel">
      <div class="spraw-panel-text">
        <span class="spraw-panel-eyebrow">Pełny backup</span>
        <h3>Plik XLSX (układ znany z arkuszy fundacji)</h3>
        <p>Cztery zakładki: „Lista Wszystkich Darczyńców" (jak plik fundacji), macierz wpłat darczyńca × miesiąc (24 mies. wstecz), lista wpłat za rok, rejestr finansów.</p>
      </div>
      <a href="eksport.php?typ=xlsx" class="btn-spraw">Pobierz XLSX →</a>
    </div>
    <?php else: ?>
    <div class="alert alert-error">Rozszerzenie zip niedostępne - XLSX wyłączony, użyj CSV poniżej.</div>
    <?php endif; ?>

    <h3>Pojedyncze pliki CSV</h3>
    <p>
      <a class="btn-secondary" href="eksport.php?typ=darczyncy-csv">Darczyńcy + pokrycie</a>
      <a class="btn-secondary" href="eksport.php?typ=wplaty-csv&rok=<?= date('Y') ?>">Wpłaty <?= date('Y') ?></a>
      <a class="btn-secondary" href="eksport.php?typ=finanse-csv&rok=<?= date('Y') ?>">Finanse <?= date('Y') ?></a>
    </p>
    <p class="hint">CSV otwiera się w Excelu i Arkuszach Google (separator średnik, polskie znaki OK).</p>
<?php endif; ?>
<?php
panel_footer();
