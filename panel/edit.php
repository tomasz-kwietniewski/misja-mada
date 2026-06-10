<?php
/* ═══ CMS - formularz dodaj / edytuj wydarzenie ══════════════════ */
require_once __DIR__ . '/layout.php';
mada_require_login();

$id = $_GET['id'] ?? '';
$isEdit = false;
$e = [];
if ($id !== '') {
    $e = mada_read_event($id);
    if ($e === null) { mada_redirect('index.php?msg=nofound'); }
    $e['id'] = $id;
    $isEdit = true;
}

// Wartości do pól (puste przy dodawaniu)
$v = function ($key, $default = '') use ($e) { return mada_esc($e[$key] ?? $default); };
$bodyText = '';
if (!empty($e['body']) && is_array($e['body'])) {
    $bodyText = implode("\n\n", $e['body']);
}
$cats = mada_categories();
$curCat = $e['category'] ?? 'misja';
$sumLabel = $e['summary']['label'] ?? '';
$sumValue = $e['summary']['value'] ?? '';

panel_header($isEdit ? 'Edycja wydarzenia' : 'Nowe wydarzenie');
?>
    <div class="bar">
      <h2 style="margin:0;"><?= $isEdit ? 'Edycja wydarzenia' : 'Nowe wydarzenie' ?></h2>
      <a href="index.php" class="btn-ghost">← Wróć do listy</a>
    </div>
    <?= panel_flash() ?>

    <form class="form" method="post" action="save.php">
      <?= mada_csrf_field() ?>
      <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= mada_esc($e['id']) ?>"><?php endif; ?>

      <fieldset>
        <legend>Podstawowe</legend>
        <label>Tytuł wydarzenia
          <input type="text" name="title" required value="<?= $v('title') ?>">
        </label>
        <div class="row2">
          <label>Kategoria
            <select name="category">
              <?php foreach ($cats as $key => $lbl): ?>
                <option value="<?= mada_esc($key) ?>" <?= $curCat === $key ? 'selected' : '' ?>><?= mada_esc($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Etykieta kategorii (na kafelku)
            <input type="text" name="categoryLabel" value="<?= $v('categoryLabel') ?>" placeholder="np. Spotkanie misyjne">
            <span class="hint">Puste = użyjemy domyślnej etykiety kategorii.</span>
          </label>
        </div>
        <label>Miejsce
          <input type="text" name="place" value="<?= $v('place') ?>" placeholder="np. Parafia Św. Trójcy w Leżajsku, Rynek 35">
        </label>
        <label class="check">
          <input type="checkbox" name="featured" value="1" <?= !empty($e['featured']) ? 'checked' : '' ?>>
          Wyróżnione (duży kafel „nadchodzące") - tylko jedno na raz
        </label>
      </fieldset>

      <fieldset>
        <legend>Data</legend>
        <div class="row2">
          <label>Data (kalendarz)
            <input type="date" name="dateISO" required value="<?= $v('dateISO') ?>">
            <span class="hint">Decyduje, czy wydarzenie jest nadchodzące, czy w archiwum.</span>
          </label>
          <label>Data opisowa (na stronie)
            <input type="text" name="dateLabel" required value="<?= $v('dateLabel') ?>" placeholder="np. Niedziela, 26 lipca 2026">
          </label>
        </div>
      </fieldset>

      <fieldset>
        <legend>Treść</legend>
        <label>Krótki opis (na kafelku)
          <textarea name="lead" style="min-height:70px;"><?= $v('lead') ?></textarea>
        </label>
        <label>Pełny opis
          <textarea name="body"><?= mada_esc($bodyText) ?></textarea>
          <span class="hint">Oddzielaj akapity pustą linią (Enter, Enter).</span>
        </label>
        <label>Godziny Mszy Świętych (opcjonalne)
          <input type="text" name="masze" value="<?= $v('masze') ?>" placeholder="np. Msze Święte o godz.: 7:00, 8:30, 10:00">
        </label>
        <div class="row2">
          <label>Podsumowanie - etykieta (opcjonalne)
            <input type="text" name="summaryLabel" value="<?= mada_esc($sumLabel) ?>" placeholder="np. Zebrano na misje">
          </label>
          <label>Podsumowanie - wartość (opcjonalne)
            <input type="text" name="summaryValue" value="<?= mada_esc($sumValue) ?>" placeholder="np. 13 403,32 zł">
          </label>
        </div>
      </fieldset>

      <?php if (!$isEdit): ?>
      <p class="hint">Galerię zdjęć i filmów dodasz po zapisaniu wydarzenia.</p>
      <?php endif; ?>

      <div class="form-actions">
        <button type="submit" class="btn-primary"><?= $isEdit ? 'Zapisz zmiany' : 'Utwórz wydarzenie' ?></button>
        <a href="index.php" class="btn-ghost">Anuluj</a>
      </div>
    </form>

<?php if ($isEdit):
    $eid   = $e['id'];
    $media = (isset($e['media']) && is_array($e['media'])) ? $e['media'] : [];
    $imgCount = 0; foreach ($media as $it) { if (($it['type'] ?? '') === 'image') $imgCount++; }
?>
    <section class="form gallery" id="galeria" style="margin-top:24px;">
      <h2 style="margin:0 0 6px;">Galeria (zdjęcia i filmy)</h2>
      <p class="hint" style="margin:0 0 18px;">Zdjęcia hostujemy u nas (limit 20, obecnie <?= $imgCount ?>). Filmy dodajesz jako linki z YouTube lub Facebooka - bez limitu.</p>
      <?= panel_gmsg() ?>

      <div class="gallery-add">
        <form method="post" action="upload.php" enctype="multipart/form-data" class="g-form">
          <?= mada_csrf_field() ?>
          <input type="hidden" name="id" value="<?= mada_esc($eid) ?>">
          <label class="g-label">Dodaj zdjęcie (JPG, PNG, WEBP - maks. 12 MB)
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required <?= $imgCount >= 20 ? 'disabled' : '' ?>>
          </label>
          <button type="submit" class="btn-secondary" <?= $imgCount >= 20 ? 'disabled' : '' ?>>Wgraj zdjęcie</button>
        </form>

        <form method="post" action="media.php" class="g-form">
          <?= mada_csrf_field() ?>
          <input type="hidden" name="id" value="<?= mada_esc($eid) ?>">
          <input type="hidden" name="op" value="embed">
          <label class="g-label">Dodaj film (wklej link YouTube lub Facebook)
            <input type="url" name="url" placeholder="https://youtu.be/... lub https://www.facebook.com/...">
          </label>
          <button type="submit" class="btn-secondary">Dodaj film</button>
        </form>
      </div>

      <?php if (!$media): ?>
        <p class="hint">Brak pozycji w galerii. Dodaj pierwsze zdjęcie lub film powyżej.</p>
      <?php else: ?>
      <form method="post" action="media.php">
        <?= mada_csrf_field() ?>
        <input type="hidden" name="id" value="<?= mada_esc($eid) ?>">
        <ul class="media-list">
          <?php foreach ($media as $i => $it):
              $type = $it['type'] ?? 'image';
              $key  = $it['key'] ?? '';
          ?>
          <li class="media-item">
            <input type="hidden" name="mkey[]" value="<?= mada_esc($key) ?>">
            <div class="media-thumb">
              <?php if ($type === 'image'): ?>
                <img src="/<?= mada_esc($it['src'] ?? '') ?>" alt="">
              <?php elseif ($type === 'youtube'): ?>
                <img src="https://img.youtube.com/vi/<?= mada_esc($it['videoId'] ?? '') ?>/mqdefault.jpg" alt="">
                <span class="media-tag tag-yt">▶ YouTube</span>
              <?php else: ?>
                <span class="media-tag tag-fb">▶ Facebook</span>
              <?php endif; ?>
            </div>
            <div class="media-fields">
              <input type="text" name="alt[]" value="<?= mada_esc($it['alt'] ?? '') ?>" placeholder="Opis alternatywny (dostępność)<?= $type === 'image' ? ' - wymagany' : '' ?>">
              <input type="text" name="caption[]" value="<?= mada_esc($it['caption'] ?? '') ?>" placeholder="Podpis pod zdjęciem (opcjonalny, np. fot. ...)">
              <?php if ($type !== 'image'): ?><a href="<?= mada_esc($it['url'] ?? '#') ?>" target="_blank" rel="noopener" class="hint">otwórz film ↗</a><?php endif; ?>
            </div>
            <div class="media-ops">
              <button type="submit" name="action" value="up_<?= $i ?>" class="btn-ghost btn-sm" title="W górę" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
              <button type="submit" name="action" value="down_<?= $i ?>" class="btn-ghost btn-sm" title="W dół" <?= $i === count($media) - 1 ? 'disabled' : '' ?>>↓</button>
              <button type="submit" name="action" value="del_<?= $i ?>" class="btn-danger btn-sm" title="Usuń" onclick="return confirm('Usunąć tę pozycję z galerii?');">Usuń</button>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <div class="form-actions">
          <button type="submit" name="action" value="save" class="btn-primary">Zapisz opisy galerii</button>
        </div>
      </form>
      <?php endif; ?>
    </section>
<?php endif; ?>
<?php
panel_footer();
