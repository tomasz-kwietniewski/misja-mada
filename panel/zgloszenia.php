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
$sort = ($_GET['sort'] ?? '') === 'surname' ? 'surname' : 'date';
try {
    adopt_db_ensure_schema();
    $signups = adopt_form_signup_rows(
        payu_db()->query("SELECT * FROM adopt_signups ORDER BY id DESC LIMIT 100")->fetchAll(),
        payu_db()->query(
            "SELECT s.*, (SELECT COUNT(*) FROM adopt_adoptions a WHERE a.subscription_id = s.id) AS n_adoptions
               FROM subscriptions s WHERE s.goal = 'adopcja' ORDER BY s.id DESC LIMIT 100"
        )->fetchAll()
    );
    $pendingAds = adopt_sort_pending_adoptions(adopt_pending_unassigned_list(), $sort);
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

panel_header('Zgłoszenia - Adopcja Serca');
?>
    <div class="bar">
      <h2 style="margin:0;">Zgłoszenia ze strony</h2>
    </div>

<?php if ($dbError !== ''): ?>
    <div class="alert alert-error">Błąd bazy danych: <?= mada_esc($dbError) ?></div>
<?php else: ?>

    <div class="bar">
      <h3 style="margin:0;">Adopcje czekające na przypisanie dziecka (<?= count($pendingAds) ?>)</h3>
      <form method="get" style="display:flex;align-items:center;gap:8px;">
        <label for="pending-sort">Sortuj:</label>
        <select id="pending-sort" name="sort" onchange="this.form.submit()">
          <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>>od najstarszych oczekujących</option>
          <option value="surname" <?= $sort === 'surname' ? 'selected' : '' ?>>nazwisko darczyńcy</option>
        </select>
        <button type="submit" class="btn-secondary btn-sm">Pokaż</button>
      </form>
    </div>
    <?php if (!$pendingAds): ?>
      <p class="hint">Wszystkie potwierdzone zgłoszenia mają już przypisane dzieci.</p>
    <?php else: ?>
      <table class="events">
        <thead><tr><th>Dodano do oczekujących</th><th>Darczyńca</th><th>Start</th><th>Częst.</th><th>Kwota</th><th>Notatki</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pendingAds as $a): ?>
          <tr>
            <td><?= mada_esc(date('d.m.Y H:i', strtotime((string)$a['created_at']))) ?></td>
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
        <thead><tr><th>Data</th><th>E-mail</th><th>Imię i nazwisko</th><th>Dzieci</th><th>Płatność</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($signups as $s): ?>
          <tr>
            <td><?= mada_esc(substr($s['created_at'], 0, 16)) ?></td>
            <td><?= mada_esc($s['email']) ?></td>
            <td><?= mada_esc($s['name']) ?></td>
            <td><?= (int)$s['children'] ?></td>
            <td><?= mada_esc($s['method']) ?></td>
            <td><span class="badge <?= $s['badge'] ?>"><?= mada_esc($s['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="hint">„Czeka na e-mail" - ktoś wysłał formularz z przelewem, ale nie kliknął jeszcze linku
        w mailu potwierdzającym. Do tego czasu nie ma go w kartotece i fundacja nie dostaje powiadomienia;
        po 7 dniach bez potwierdzenia zgłoszenie wygasa. Zgłoszenia opłacone kartą nie wymagają
        potwierdzenia - trafiają do kartoteki od razu po płatności.</p>
    <?php endif; ?>
<?php endif; ?>
<?php
panel_footer();
