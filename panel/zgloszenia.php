<?php
/* ═══ CMS - zgłoszenia Adopcji Serca ze strony ════════════════════
   Zgłoszenia przelewowe z formularza (double opt-in w PHP):
   oczekujące na potwierdzenie e-maila + potwierdzone adopcje
   czekające na przypisanie dziecka. */
require_once __DIR__ . '/layout.php';
mada_require_login();
require_once __DIR__ . '/../adopcja/db.php';
require_once __DIR__ . '/../adopcja/lib.php';

$dbError = '';
$signups = [];
$pendingAds = [];
try {
    adopt_db_ensure_schema();
    $signups = payu_db()->query(
        "SELECT * FROM adopt_signups ORDER BY id DESC LIMIT 100"
    )->fetchAll();
    $pendingAds = array_values(array_filter(adopt_adoption_list_all(),
        fn($a) => $a['status'] === 'pending'));
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$sgLabel = ['pending' => 'czeka na e-mail', 'confirmed' => 'potwierdzone', 'expired' => 'wygasłe'];

panel_header('Zgłoszenia - Adopcja Serca');
?>
    <div class="bar">
      <h2 style="margin:0;">Zgłoszenia ze strony</h2>
    </div>

<?php if ($dbError !== ''): ?>
    <div class="alert alert-error">Błąd bazy danych: <?= mada_esc($dbError) ?></div>
<?php else: ?>

    <h3>Adopcje czekające na przypisanie dziecka (<?= count($pendingAds) ?>)</h3>
    <?php if (!$pendingAds): ?>
      <p class="hint">Wszystkie potwierdzone zgłoszenia mają już przypisane dzieci.</p>
    <?php else: ?>
      <table class="events">
        <thead><tr><th>Darczyńca</th><th>Start</th><th>Częst.</th><th>Kwota</th><th>Notatki</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pendingAds as $a): ?>
          <tr>
            <td><a href="darczynca.php?id=<?= (int)$a['donor_id'] ?>"><?= mada_esc($a['donor_name']) ?></a></td>
            <td><?= mada_esc(adopt_month_label($a['start_month'])) ?></td>
            <td><?= ['monthly' => 'mies.', 'quarterly' => 'kwart.', 'yearly' => 'roczna'][$a['frequency']] ?? '' ?></td>
            <td><?= number_format($a['amount_grosze'] / 100, 0, ',', ' ') ?> zł</td>
            <td class="hint"><?= mada_esc($a['notes'] ?? '') ?></td>
            <td><a class="btn-primary btn-sm" href="adopcja-edit.php?id=<?= (int)$a['id'] ?>">Przypisz dziecko →</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <h3>Ostatnie zgłoszenia z formularza (<?= count($signups) ?>)</h3>
    <?php if (!$signups): ?>
      <p class="hint">Brak zgłoszeń. Pojawią się tu po wysłaniu formularza „Zostań rodzicem adopcyjnym" (ścieżka przelewowa).</p>
    <?php else: ?>
      <table class="events">
        <thead><tr><th>Data</th><th>E-mail</th><th>Imię i nazwisko</th><th>Dzieci</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($signups as $s):
            $d = json_decode((string)$s['payload'], true) ?: []; ?>
          <tr>
            <td><?= mada_esc(substr((string)$s['created_at'], 0, 16)) ?></td>
            <td><?= mada_esc($s['email']) ?></td>
            <td><?= mada_esc(trim(($d['imie'] ?? '') . ' ' . ($d['nazwisko'] ?? ''))) ?></td>
            <td><?= (int)($d['dzieci'] ?? 1) ?></td>
            <td><span class="badge <?= $s['status'] === 'confirmed' ? 'badge-ok' : ($s['status'] === 'pending' ? 'badge-err' : 'badge-arch') ?>">
                <?= mada_esc($sgLabel[$s['status']] ?? $s['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="hint">Zgłoszenia „czeka na e-mail" starsze niż 7 dni wygasają automatycznie (cron).</p>
    <?php endif; ?>
<?php endif; ?>
<?php
panel_footer();
