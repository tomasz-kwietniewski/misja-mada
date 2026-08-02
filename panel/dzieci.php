<?php
/* ═══ CMS - lista podopiecznych Adopcji Serca (etap A: tylko odczyt) ═
   Numer dziecka to klucz, którym posługuje się fundacja. */
require_once __DIR__ . '/layout.php';
mada_require_login();
require_once __DIR__ . '/../adopcja/db.php';

$dbError = '';
$children = [];
try {
    adopt_db_ensure_schema();
    $children = adopt_child_list();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

panel_header('Podopieczni - Adopcja Serca');
?>
    <div class="bar">
      <h2 style="margin:0;">Podopieczni (dzieci)</h2>
      <span>
        <a href="darczyncy.php" class="btn-secondary btn-sm">Darczyńcy</a>
        <a href="index.php" class="btn-ghost btn-sm">← Panel</a>
      </span>
    </div>

<?php if ($dbError !== ''): ?>
    <div class="alert alert-error">Baza danych jest niedostępna (sprawdź <code>payu/secret/db-config.php</code>): <?= mada_esc($dbError) ?></div>
<?php elseif (!$children): ?>
    <p class="hint">Baza podopiecznych jest pusta - zacznij od strony <a href="import.php">Import</a>.</p>
<?php else: ?>
    <?php
      $withDonor = count(array_filter($children, fn($c) => $c['donors'] !== null));
    ?>
    <p class="hint" style="margin:0 0 12px;">Łącznie: <?= count($children) ?>,
       z darczyńcą: <?= $withDonor ?>, bez darczyńcy: <?= count($children) - $withDonor ?>.</p>
    <table class="events">
      <thead><tr>
        <th>Nr</th><th>Imię</th><th>Status</th><th>Darczyńca</th><th>Materiały wysłane</th><th>Uwagi</th>
      </tr></thead>
      <tbody>
      <?php foreach ($children as $c): ?>
        <tr>
          <td><b><?= (int)$c['number'] ?></b></td>
          <td><?= mada_esc($c['name']) ?></td>
          <td><?= $c['status'] === 'active' ? 'aktywne' : '<span class="hint">nieaktywne</span>' ?></td>
          <td><?php if ($c['donors'] !== null): ?><?= mada_esc($c['donors']) ?>
              <?php else: ?><span class="badge" style="background:#fbeeec;color:var(--err);border-color:#e6b9b1;">brak</span><?php endif; ?></td>
          <td><?= ((int)($c['materials_sent'] ?? 0)) === 1 ? 'TAK' : '<span class="hint">nie</span>' ?></td>
          <td class="hint"><?= mada_esc($c['notes'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
<?php endif; ?>
<?php
panel_footer();
