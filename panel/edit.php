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

      <?php if ($isEdit): ?>
      <fieldset>
        <legend>Galeria (zdjęcia i filmy)</legend>
        <p class="hint" style="margin:0;">Zarządzanie galerią pojawi się tutaj w kolejnym etapie (ETAP 3).</p>
      </fieldset>
      <?php else: ?>
      <p class="hint">Galerię zdjęć i filmów dodasz po zapisaniu wydarzenia.</p>
      <?php endif; ?>

      <div class="form-actions">
        <button type="submit" class="btn-primary"><?= $isEdit ? 'Zapisz zmiany' : 'Utwórz wydarzenie' ?></button>
        <a href="index.php" class="btn-ghost">Anuluj</a>
      </div>
    </form>
<?php
panel_footer();
