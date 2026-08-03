<?php
/* ═══ CMS - import danych Adopcji Serca (jednorazowa migracja) ════
   Przyjmuje plik JSON wygenerowany lokalnie przez
   tools/import/parse-adopcje.php i wgrywa go w transakcji do MySQL.
   Wiersze niedopasowane (fuzzy/none) lądują w adopt_import_pending -
   rozwiązuje się je na panel/import-lacz.php.
   Strona tymczasowa - do usunięcia po zakończonej migracji (etap E). */
require_once __DIR__ . '/layout.php';
mada_require_login();
require_once __DIR__ . '/../adopcja/db.php';

$result = null;
$dbError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mada_csrf_check();
    try {
        adopt_db_ensure_schema();
        if (empty($_FILES['plik']['tmp_name']) || !is_uploaded_file($_FILES['plik']['tmp_name'])) {
            mada_redirect('import.php?msg=nofile');
        }
        $json = file_get_contents($_FILES['plik']['tmp_name']);
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['generated'] ?? '') !== 'parse-adopcje'
            || !isset($data['children'], $data['donors'], $data['adoptions'])) {
            mada_redirect('import.php?msg=badjson');
        }

        $pdo = payu_db();
        $pdo->beginTransaction();
        $st = [
            'children' => 0, 'donors_new' => 0, 'donors_existing' => 0,
            'adoptions' => 0, 'adoptions_skipped' => 0, 'payments' => 0, 'pending' => 0,
        ];

        // 1. dzieci (idempotentnie po numerze)
        $childIds = [];   // number -> id
        foreach ($data['children'] as $c) {
            $childIds[(int)$c['number']] = adopt_child_upsert((int)$c['number'], (string)$c['name'], $c['notes'] ?? null);
            $st['children']++;
        }

        // 2. darczyńcy (re-run: dopasuj po dokładnej nazwie)
        $donorIds = [];   // key -> id
        $find = $pdo->prepare('SELECT id FROM adopt_donors WHERE full_name = ? LIMIT 1');
        foreach ($data['donors'] as $d) {
            $find->execute([$d['full_name']]);
            $id = $find->fetchColumn();
            if ($id !== false) {
                $donorIds[$d['key']] = (int)$id;
                $st['donors_existing']++;
                continue;
            }
            $donorIds[$d['key']] = adopt_donor_insert([
                'full_name'    => $d['full_name'],
                'email'        => $d['email'] ?? null,
                'emails_extra' => $d['emails_extra'] ?? null,
                'notes'        => $d['notes'] ?? null,
                'source'       => 'import',
            ]);
            $st['donors_new']++;
        }

        // 3. adopcje + wpłaty (re-run: pomiń istniejącą parę darczyńca+dziecko)
        $dupe = $pdo->prepare(
            'SELECT id FROM adopt_adoptions WHERE donor_id = ? AND (child_id = ? OR (child_id IS NULL AND ? IS NULL)) LIMIT 1'
        );
        foreach ($data['adoptions'] as $a) {
            $donorId = $donorIds[$a['donor_key']] ?? null;
            if ($donorId === null) continue;
            $childId = $a['child_number'] !== null ? ($childIds[(int)$a['child_number']] ?? null) : null;
            $dupe->execute([$donorId, $childId, $childId]);
            if ($dupe->fetchColumn() !== false) { $st['adoptions_skipped']++; continue; }

            $adoptionId = adopt_adoption_insert([
                'donor_id'      => $donorId,
                'child_id'      => $childId,
                'duration'      => $a['duration'] ?? 'indefinite',
                'start_month'   => $a['start_month'] ?? null,
                'end_month'     => $a['end_month'] ?? null,
                'frequency'     => $a['frequency'] ?? 'monthly',
                'amount_grosze' => $a['amount_grosze'] ?? 7000,
                'method'        => $a['method'] ?? 'transfer',
                'status'        => 'active',
                'materials_sent'=> !empty($a['materials_sent']),
                'notes'         => $a['notes'] ?? null,
            ]);
            $st['adoptions']++;
            foreach ($a['payments'] ?? [] as $p) {
                adopt_payment_insert([
                    'adoption_id'   => $adoptionId,
                    'amount_grosze' => (int)$p['amount_grosze'],
                    'paid_at'       => $p['paid_at'],
                    'period_from'   => $p['period_from'],
                    'period_to'     => $p['period_to'],
                    'method'        => $p['method'] ?? 'transfer',
                    'note'          => $p['note'] ?? null,
                    'created_by'    => mada_current_user(),
                ]);
                $st['payments']++;
            }
        }

        // 4. wiersze do ręcznego łączenia (bez duplikatów wśród otwartych;
        //    ta sama etykieta może wystąpić 2x z RÓŻNYMI wpłatami - porównuj treść)
        $openRows = $pdo->query(
            "SELECT kind, label, payload FROM adopt_import_pending WHERE status = 'open'"
        )->fetchAll();
        $seen = [];
        foreach ($openRows as $o) $seen[$o['kind'] . '|' . $o['label'] . '|' . md5($o['payload'])] = true;
        foreach ($data['pending'] ?? [] as $p) {
            $label = ($p['data']['group'] ?? '') . ' poz. ' . ($p['data']['lp'] ?? '?') . ': ' . ($p['data']['name'] ?? '?');
            $payload = json_encode($p['data'], JSON_UNESCAPED_UNICODE);
            $sig = $p['kind'] . '|' . $label . '|' . md5($payload);
            if (isset($seen[$sig])) continue;
            $seen[$sig] = true;
            adopt_pending_insert($p['kind'], $label, $p['data'], $p['data']['reason'] ?? null);
            $st['pending']++;
        }

        // 5. przepływy finansowe (Zbiórki/Wypłaty/Wymiana walut) - dedupe po
        //    (data, kategoria, kwota, waluta), żeby re-import nie duplikował
        $st['flows'] = 0;
        $flowDupe = $pdo->prepare(
            'SELECT id FROM fin_flows WHERE flow_date = ? AND category = ? AND amount_grosze = ? AND currency = ? LIMIT 1'
        );
        foreach ($data['flows'] ?? [] as $f) {
            $flowDupe->execute([$f['flow_date'], $f['category'], (int)$f['amount_grosze'], $f['currency'] ?? 'PLN']);
            if ($flowDupe->fetchColumn() !== false) continue;
            fin_flow_insert($f + ['created_by' => mada_current_user()]);
            $st['flows']++;
        }

        $pdo->commit();
        mada_audit('import.run', 'import', null, $st + ['checksums' => $data['checksums'] ?? []]);
        $result = ['stats' => $st, 'checksums' => $data['checksums'] ?? []];
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $dbError = $e->getMessage();
    }
}

function imp_flash() {
    $codes = [
        'nofile'  => ['error', 'Nie wybrano pliku JSON.'],
        'badjson' => ['error', 'To nie jest poprawny plik importu (oczekuję JSON z parse-adopcje).'],
    ];
    $m = $_GET['msg'] ?? '';
    if (!isset($codes[$m])) return '';
    [$t, $txt] = $codes[$m];
    return '<div class="alert alert-' . ($t === 'ok' ? 'ok' : 'error') . '">' . mada_esc($txt) . '</div>';
}

$counts = null;
try {
    adopt_db_ensure_schema();
    $counts = adopt_counts();
} catch (Throwable $e) {
    $dbError = $dbError ?: $e->getMessage();
}

panel_header('Import - Adopcja Serca');
?>
    <div class="bar">
      <h2 style="margin:0;">Import danych Adopcji Serca</h2>
      <a href="darczyncy.php" class="btn-ghost">← Darczyńcy</a>
    </div>
    <?= imp_flash() ?>

<?php if ($dbError !== ''): ?>
    <div class="alert alert-error">Błąd bazy danych: <?= mada_esc($dbError) ?></div>
<?php endif; ?>

<?php if ($counts !== null): ?>
    <p class="hint">Stan bazy: <b><?= $counts['children'] ?></b> dzieci,
       <b><?= $counts['donors'] ?></b> darczyńców,
       <b><?= $counts['adoptions'] ?></b> adopcji (aktywne/oczekujące),
       <b><?= $counts['payments'] ?></b> wpłat,
       <b><?= $counts['pending'] ?></b> pozycji do ręcznego łączenia
       <?php if ($counts['pending'] > 0): ?>
         - <a href="import-lacz.php">przejdź do łączenia →</a>
       <?php endif; ?>
    </p>
<?php endif; ?>

<?php if ($result !== null): ?>
    <div class="alert alert-ok">Import zakończony.</div>
    <table class="events" style="max-width:560px;">
      <tbody>
        <tr><td>Dzieci (wstawione/odświeżone)</td><td><?= (int)$result['stats']['children'] ?></td></tr>
        <tr><td>Darczyńcy nowi / istniejący</td><td><?= (int)$result['stats']['donors_new'] ?> / <?= (int)$result['stats']['donors_existing'] ?></td></tr>
        <tr><td>Adopcje wstawione / pominięte (duplikaty)</td><td><?= (int)$result['stats']['adoptions'] ?> / <?= (int)$result['stats']['adoptions_skipped'] ?></td></tr>
        <tr><td>Wpłaty</td><td><?= (int)$result['stats']['payments'] ?></td></tr>
        <tr><td>Przepływy finansowe</td><td><?= (int)($result['stats']['flows'] ?? 0) ?></td></tr>
        <tr><td>Do ręcznego łączenia</td><td><?= (int)$result['stats']['pending'] ?></td></tr>
      </tbody>
    </table>
    <?php if (!empty($result['checksums'])): ?>
      <h3>Sumy kontrolne z parsera (porównaj z arkuszami fundacji)</h3>
      <pre class="hint" style="white-space:pre-wrap;"><?= mada_esc(json_encode($result['checksums'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
    <?php endif; ?>
    <p><a href="import-lacz.php" class="btn-primary">Przejdź do ręcznego łączenia →</a></p>
<?php endif; ?>

    <h3>Wgraj plik importu</h3>
    <p class="hint">Plik JSON generuje się lokalnie: <code>php -d extension=zip tools/import/parse-adopcje.php --lista "LISTA....xlsx" --platnosci "Platnosci/" --out import-adopcje.json</code>.
       Import można bezpiecznie powtórzyć - istniejące dzieci, darczyńcy i adopcje nie są duplikowane.</p>
    <form method="post" enctype="multipart/form-data" class="form" style="max-width:560px;">
      <?= mada_csrf_field() ?>
      <label>Plik JSON z parsera
        <input type="file" name="plik" accept=".json,application/json" required>
      </label>
      <button type="submit" class="btn-primary">Importuj</button>
    </form>
<?php
panel_footer();
