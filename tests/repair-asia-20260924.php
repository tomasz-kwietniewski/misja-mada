<?php
/** Jednorazowa naprawa duplikatów darczyńcy #133. Uruchamiaj wyłącznie z katalogu projektu. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
require_once getcwd() . '/adopcja/db.php';

$apply = in_array('--apply', $argv, true);
$backupArg = current(array_filter($argv, static fn($x) => str_starts_with($x, '--backup='))) ?: '';
$backup = substr($backupArg, strlen('--backup='));
$pairs = [152 => [160, 140], 153 => [161, 141]]; // zgłoszenie => [nowy dubel, dziecko]
$expected = [
    152 => ['fixed', '2026-10', '2027-10', 0],
    153 => ['fixed', '2026-09', null, 0],
    160 => ['fixed', '2026-10', '2027-10', 1],
    161 => ['indefinite', '2026-10', null, 1],
];
$pdo = payu_db();
$pdo->beginTransaction();
try {
    $rows = $pdo->query('SELECT * FROM adopt_adoptions WHERE id IN (152,153,160,161) ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 4) throw new RuntimeException('Oczekiwano dokładnie czterech adopcji.');
    $byId = array_column($rows, null, 'id');
    foreach ($expected as $recordId => [$duration, $start, $end, $sentCount]) {
        $a = $byId[$recordId];
        if ($a['duration'] !== $duration || $a['start_month'] !== $start || $a['end_month'] !== $end
            || (int)$a['dossier_sent_count'] !== $sentCount || $a['frequency'] !== 'monthly'
            || (int)$a['amount_grosze'] !== 7000 || $a['method'] !== 'transfer') {
            throw new RuntimeException("Zawartość adopcji $recordId zmieniła się. Nic nie zapisano.");
        }
    }
    foreach ($pairs as $oldId => [$newId, $childId]) {
        $old = $byId[$oldId]; $new = $byId[$newId];
        if ((int)$old['donor_id'] !== 133 || (int)$new['donor_id'] !== 133
            || $old['status'] !== 'pending' || $old['child_id'] !== null
            || $new['status'] !== 'active' || (int)$new['child_id'] !== $childId
            || $old['subscription_id'] !== null || $new['subscription_id'] !== null) {
            throw new RuntimeException("Stan pary $oldId/$newId zmienił się. Nic nie zapisano.");
        }
    }
    $st = $pdo->query('SELECT adoption_id, COUNT(*) n FROM adopt_payments WHERE adoption_id IN (152,153,160,161) GROUP BY adoption_id');
    if ($st->fetch()) throw new RuntimeException('Co najmniej jedna adopcja ma wpłatę. Nic nie zapisano.');
    $total = (int)$pdo->query('SELECT COUNT(*) FROM adopt_adoptions WHERE donor_id = 133')->fetchColumn();
    if ($total !== 4) throw new RuntimeException('Liczba adopcji darczyńcy #133 zmieniła się. Nic nie zapisano.');
    foreach ($pairs as $oldId => [$newId, $childId]) {
        $old = $byId[$oldId]; $new = $byId[$newId];
        echo "$oldId: {$old['status']} bez dziecka -> {$new['status']} dziecko $childId ($newId), "
           . "okres {$new['duration']} {$new['start_month']}.." . ($new['end_month'] ?? 'bezterminowo')
           . ", liczba wysyłek dossier: {$new['dossier_sent_count']}\n";
    }
    if (!$apply) { $pdo->rollBack(); echo "PODGLĄD: bez zmian.\n"; exit(0); }

    if (!$backup || !is_file($backup) || filesize($backup) < 2048) {
        throw new RuntimeException('Wymagana istniejąca kopia bazy: --backup=/pełna/ścieżka.sql.gz');
    }
    $dump = @gzdecode((string)file_get_contents($backup));
    if ($dump === false || !str_contains($dump, 'CREATE TABLE')) {
        throw new RuntimeException('Kopia bazy jest uszkodzona lub nie zawiera schematu.');
    }
    unset($dump);

    $update = $pdo->prepare('UPDATE adopt_adoptions SET child_id=?, duration=?, start_month=?, end_month=?,
        frequency=?, amount_grosze=?, method=?, status=?, dossier_sent_at=?, dossier_sent_by=?,
        dossier_sent_count=? WHERE id=? AND donor_id=133 AND status="pending" AND child_id IS NULL');
    $del = $pdo->prepare('DELETE FROM adopt_adoptions WHERE id=? AND donor_id=133 AND status="active" AND child_id=?');
    $audit = $pdo->prepare('INSERT INTO panel_audit_log (user, action, entity, entity_id, details)
        VALUES (?, ?, ?, ?, ?)');
    foreach ($pairs as $oldId => [$newId, $childId]) {
        $new = $byId[$newId];
        $update->execute([$childId, $new['duration'], $new['start_month'], $new['end_month'],
            $new['frequency'], $new['amount_grosze'], $new['method'], 'active',
            $new['dossier_sent_at'], $new['dossier_sent_by'], $new['dossier_sent_count'], $oldId]);
        if ($update->rowCount() !== 1) throw new RuntimeException("Nie zaktualizowano $oldId.");
        $audit->execute(['Codex', 'adoption.repair', 'adoption', $oldId,
            json_encode(['source_duplicate' => $newId, 'child_id' => $childId,
                         'backup' => basename($backup)], JSON_UNESCAPED_UNICODE)]);
        $del->execute([$newId, $childId]);
        if ($del->rowCount() !== 1) throw new RuntimeException("Nie usunięto dubla $newId.");
    }
    $pdo->commit();
    echo "ZAPISANO: pozostały zgłoszenia #152 i #153, aktywne z dziećmi #140 i #141.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "PRZERWANO: {$e->getMessage()}\n");
    exit(1);
}
