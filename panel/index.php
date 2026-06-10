<?php
/* ═══ CMS - lista wydarzeń ════════════════════════════════════════ */
require_once __DIR__ . '/layout.php';
mada_require_login();

$events = mada_all_events();

// Sort: nadchodzące (rosnąco po dacie) najpierw, potem archiwum (malejąco).
usort($events, function ($a, $b) {
    $sa = mada_event_status($a);
    $sb = mada_event_status($b);
    if ($sa !== $sb) return $sa === 'nadchodzace' ? -1 : 1;
    $da = $a['dateISO'] ?? '';
    $db = $b['dateISO'] ?? '';
    return $sa === 'nadchodzace' ? strcmp($da, $db) : strcmp($db, $da);
});

panel_header('Panel wydarzeń');
echo panel_flash();
?>
    <div class="bar">
      <h2 style="margin:0;">Wydarzenia <span style="color:#7a6550;font-weight:400;font-size:15px;">(<?= count($events) ?>)</span></h2>
      <div style="display:flex;gap:10px;">
        <a href="categories.php" class="btn-secondary">Kategorie</a>
        <a href="edit.php" class="btn-primary">+ Dodaj wydarzenie</a>
      </div>
    </div>

    <?php if (!$events): ?>
      <p>Nie ma jeszcze żadnych wydarzeń. Kliknij „Dodaj wydarzenie", aby utworzyć pierwsze.</p>
    <?php else: ?>
    <table class="events">
      <thead>
        <tr><th>Tytuł</th><th>Data</th><th>Status</th><th>Wyróżnione</th><th>Akcje</th></tr>
      </thead>
      <tbody>
        <?php foreach ($events as $e):
            $status = mada_event_status($e);
            $isUp = $status === 'nadchodzace';
        ?>
        <tr>
          <td><?= mada_esc($e['title'] ?? '(bez tytułu)') ?></td>
          <td><?= mada_esc($e['dateLabel'] ?? ($e['dateISO'] ?? '')) ?></td>
          <td><span class="badge <?= $isUp ? 'badge-up' : 'badge-arch' ?>"><?= $isUp ? 'nadchodzące' : 'archiwum' ?></span></td>
          <td><?= !empty($e['featured']) ? '<span class="star" title="Wyróżnione">★</span>' : '' ?></td>
          <td>
            <div class="row-actions">
              <a class="btn-secondary btn-sm" href="edit.php?id=<?= urlencode($e['id']) ?>">Edytuj</a>
              <form method="post" action="delete.php" onsubmit="return confirm('Na pewno trwale usunąć to wydarzenie wraz ze zdjęciami?');" style="margin:0;">
                <?= mada_csrf_field() ?>
                <input type="hidden" name="id" value="<?= mada_esc($e['id']) ?>">
                <button type="submit" class="btn-danger btn-sm">Usuń</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
<?php
panel_footer();
