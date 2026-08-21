<?php
/* ═══ CMS - podopieczni Adopcji Serca ═════════════════════════════
   Numer dziecka to klucz, którym posługuje się fundacja.
   Dodawanie dzieci + przełącznik "materiały wysłane" na adopcjach. */
require_once __DIR__ . '/layout.php';
mada_require_login();
require_once __DIR__ . '/../adopcja/db.php';
require_once __DIR__ . '/../adopcja/lib.php';

$dbError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mada_csrf_check();
    try {
        adopt_db_ensure_schema();
        $action = $_POST['action'] ?? '';
        /* Dodawanie i edycja to TEN SAM formularz (komplet pól z dossier) -
           przy dodawaniu pracownik zwykle ma już PDF z danymi dziecka. */
        if ($action === 'add' || $action === 'edit') {
            $isAdd = $action === 'add';
            $cid = (int)($_POST['child_id'] ?? 0);
            $back = $isAdd ? 'dzieci.php?dodaj=1' : 'dzieci.php?edit=' . $cid;
            $d = [
                'number' => (int)($_POST['number'] ?? 0),
                'name' => trim((string)($_POST['name'] ?? '')),
                'status' => (string)($_POST['status'] ?? 'active'),
                'notes' => trim((string)($_POST['notes'] ?? '')),
                'dossier_name' => trim((string)($_POST['dossier_name'] ?? '')),
                'birth_date' => trim((string)($_POST['birth_date'] ?? '')),
                'father' => trim((string)($_POST['father'] ?? '')),
                'mother' => trim((string)($_POST['mother'] ?? '')),
                'siblings' => trim((string)($_POST['siblings'] ?? '')),
                'description' => trim((string)($_POST['description'] ?? '')),
            ];
            if ((!$isAdd && $cid <= 0) || $d['number'] <= 0 || $d['name'] === ''
                || ($d['birth_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['birth_date']))) {
                mada_redirect($back . '&msg=invalid');
            }
            if ($isAdd) {
                if (adopt_child_by_number($d['number']) !== null) mada_redirect($back . '&msg=taken');
                $cid = adopt_child_upsert($d['number'], $d['name'], $d['notes'] ?: null);
            }
            // Zdjęcie do dossier: uploads/dzieci/, losowa nazwa (nie do zgadnięcia
            // z zewnątrz - katalog jest publiczny jak inne uploads/).
            if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
                if ((int)$_FILES['photo']['size'] > 6 * 1024 * 1024) mada_redirect($back . '&msg=photobig');
                $info = @getimagesize($_FILES['photo']['tmp_name']);
                $extMap = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
                if ($info === false || !isset($extMap[$info[2]])) mada_redirect($back . '&msg=phototype');
                $dir = __DIR__ . '/../uploads/dzieci';
                if (!is_dir($dir) && !@mkdir($dir, 0755, true)) mada_redirect($back . '&msg=photoerr');
                $fname = 'dziecko-' . $cid . '-' . bin2hex(random_bytes(8)) . '.' . $extMap[$info[2]];
                if (!@move_uploaded_file($_FILES['photo']['tmp_name'], $dir . '/' . $fname)) {
                    mada_redirect($back . '&msg=photoerr');
                }
                $old = adopt_child_get($cid);
                if ($old && !empty($old['photo'])) @unlink($dir . '/' . basename($old['photo']));
                $d['photo'] = $fname;
            }
            if (!adopt_child_update($cid, $d)) {
                mada_redirect($back . '&msg=taken');
            }
            mada_audit($isAdd ? 'child.add' : 'child.edit', 'child', $cid, array_diff_key($d, ['description' => 1]));
            mada_redirect('dzieci.php?msg=' . ($isAdd ? 'added' : 'saved'));
        }

        /* Archiwum podopiecznych: dziecko poza programem znika z listy wyboru przy
           nowych adopcjach i z licznika „bez darczyńcy", ale CAŁA jego historia
           (adopcje, wpłaty, dossier) zostaje nietknięta. Przywrócenie to ten sam
           przycisk w drugą stronę - nic po drodze nie ginie. */
        if ($action === 'archive' || $action === 'restore') {
            $cid = (int)($_POST['child_id'] ?? 0);
            $ch = $cid > 0 ? adopt_child_get($cid) : null;
            if (!$ch) mada_redirect('dzieci.php?msg=invalid');
            adopt_child_set_status($cid, $action === 'archive' ? 'inactive' : 'active');
            mada_audit('child.' . $action, 'child', $cid, ['nr' => (int)$ch['number'], 'imie' => $ch['name']]);
            mada_redirect('dzieci.php?edit=' . $cid . '&msg=' . ($action === 'archive' ? 'archived' : 'restored') . '#formularz');
        }

        /* Usuwanie tylko dla POMYŁEK przy dodawaniu - dziecko, które nigdy nie
           miało adopcji. Wycofanie z programu robi się archiwum, nie kasowaniem. */
        if ($action === 'delete') {
            $cid = (int)($_POST['child_id'] ?? 0);
            $ch = $cid > 0 ? adopt_child_get($cid) : null;
            if (!$ch) mada_redirect('dzieci.php?msg=invalid');
            if (adopt_child_delete_if_unused($cid)) {
                mada_audit('child.delete', 'child', $cid, ['nr' => (int)$ch['number'], 'imie' => $ch['name']]);
                mada_redirect('dzieci.php?msg=deleted');
            }
            mada_redirect('dzieci.php?edit=' . $cid . '&msg=hasadopt#formularz');
        }
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

function dz_flash() {
    $codes = [
        'added'    => ['ok', 'Dziecko zostało dodane.'],
        'saved'    => ['ok', 'Zapisano.'],
        'archived' => ['ok', 'Dziecko przeniesione do archiwum - nie będzie proponowane przy nowych adopcjach. Historia i wpłaty zostają, można je przywrócić w każdej chwili.'],
        'restored' => ['ok', 'Dziecko wróciło do programu.'],
        'deleted'  => ['ok', 'Dziecko zostało usunięte (nie miało żadnej adopcji).'],
        /* Komunikaty z ekranu adopcji, gdy pracownik przyszedł tu z karty dziecka. */
        'adoptok'  => ['ok', 'Zapisano powiązanie darczyńca - dziecko.'],
        'adoptdel' => ['ok', 'Adopcja została usunięta (nie było przy niej żadnej wpłaty).'],
        'adopthaspay' => ['error', 'Nie usunięto: przy tej adopcji są wpłaty. Zamiast kasować, zakończ ją („Zakończ" na karcie darczyńcy) albo przenieś do właściwego darczyńcy.'],
        'mailok'   => ['ok', 'Mail z przedstawieniem dziecka (dossier) został wysłany - data wysyłki jest odnotowana przy adopcji.'],
        'mailfail' => ['error', 'Mail do darczyńcy NIE został wysłany (brak adresu albo błąd wysyłki). Zmiany w adopcji zostały zapisane.'],
        'hasadopt' => ['error', 'Nie usunięto: to dziecko ma adopcje, a razem z nimi historię wpłat. Zamiast kasować, przenieś je do archiwum.'],
        'invalid' => ['error', 'Podaj numer (liczba > 0) i imię; data urodzenia w formacie RRRR-MM-DD.'],
        'taken'   => ['error', 'Ten numer jest już zajęty.'],
        'photobig'  => ['error', 'Zdjęcie jest za duże (maks. 6 MB).'],
        'phototype' => ['error', 'Niedozwolony typ zdjęcia. Dozwolone: JPG, PNG, WEBP.'],
        'photoerr'  => ['error', 'Nie udało się zapisać zdjęcia na serwerze.'],
    ];
    $m = $_GET['msg'] ?? '';
    if (!isset($codes[$m])) return '';
    [$t, $txt] = $codes[$m];
    return '<div class="alert alert-' . ($t === 'ok' ? 'ok' : 'error') . '">' . mada_esc($txt) . '</div>';
}

$children = [];
$editChild = null;
$editAdoptions = [];
$showAdd = isset($_GET['dodaj']);
$q = trim((string)($_GET['q'] ?? ''));
$showArchived = ($_GET['arch'] ?? '') === '1';
$totalChildren = 0;
$archivedCnt = 0;
try {
    adopt_db_ensure_schema();
    $children = adopt_child_list();
    $totalChildren = count($children);
    $archivedCnt = count(array_filter($children, fn($c) => $c['status'] !== 'active'));
    /* Archiwalne dzieci są domyślnie schowane - tak samo jak archiwalni darczyńcy.
       Wyszukiwarka celowo obejmuje WSZYSTKIE: szuka się konkretnego dziecka,
       także tego, które wyszło z programu. */
    if (!$showArchived && $q === '') {
        $children = array_values(array_filter($children, fn($c) => $c['status'] === 'active'));
    }
    if ($q !== '') {
        /* Szukanie po imieniu dziecka, numerze, pełnym imieniu z dossier
           i po darczyńcy - fundacja pyta „u kogo jest Kiady" równie często
           jak „które dziecko ma numer 23". */
        $needle = mb_strtolower($q, 'UTF-8');
        $children = array_values(array_filter($children, function ($c) use ($needle) {
            $hay = mb_strtolower(implode(' ', [
                (string)$c['name'], (string)$c['number'], (string)($c['dossier_name'] ?? ''),
                (string)($c['donors'] ?? ''), (string)($c['notes'] ?? ''),
            ]), 'UTF-8');
            return mb_strpos($hay, $needle) !== false;
        }));
    }
    if (isset($_GET['edit'])) {
        $editChild = adopt_child_get((int)$_GET['edit']);
        if ($editChild) $editAdoptions = adopt_adoptions_by_child((int)$editChild['id']);
    }
} catch (Throwable $e) {
    $dbError = $dbError ?: $e->getMessage();
}

panel_header('Podopieczni - Adopcja Serca');
?>
    <div class="bar">
      <h2 style="margin:0;">Podopieczni (dzieci)</h2>
      <a href="dzieci.php?dodaj=1#formularz" class="btn-primary btn-sm">+ Dodaj dziecko</a>
    </div>
    <?= dz_flash() ?>

    <?php if ($editChild !== null): ?>
    <?php
      $statusLabel = ['pending' => 'oczekująca', 'active' => 'aktywna', 'ended' => 'zakończona', 'cancelled' => 'anulowana'];
      $openAd = array_values(array_filter($editAdoptions, fn($a) => in_array($a['status'], ['pending', 'active'], true)));
    ?>
    <?php
      /* Powiązania darczyńca-dziecko są edytowalne TAKŻE stąd. Wcześniej karta
         dziecka tylko pokazywała opiekuna i odsyłała „zrób to z karty darczyńcy" -
         a pracownik, który patrzy na dziecko, chce poprawić je na miejscu.
         Tabela pokazuje wszystkie okresy (także zakończone), bo dubel bywa właśnie
         w parze „jedna aktywna + jedna zakończona" i inaczej byłby niewidoczny. */
      $childBack = '&back=dziecko&child=' . (int)$editChild['id'];
    ?>
    <div class="donor-card">
      <div style="grid-column:1/-1;">
        <div class="bar" style="margin:0 0 10px;">
          <span class="dc-label" style="margin:0;">Darczyńca / opiekun</span>
          <a href="adopcja-edit.php?child=<?= (int)$editChild['id'] ?>&amp;back=dziecko" class="btn-primary btn-sm">+ Przypisz darczyńcę</a>
        </div>
        <?php if (!$editAdoptions): ?>
          <span class="badge badge-err">brak przypisanego darczyńcy</span>
          <span class="hint">- dziecko czeka na opiekuna. Przypisz go przyciskiem obok
            (albo z karty darczyńcy przyciskiem „+ Nowa adopcja").</span>
        <?php else: ?>
          <?php if (count($openAd) > 1): ?>
            <div class="alert alert-error" style="margin:0 0 10px;">
              To dziecko ma <b><?= count($openAd) ?> trwające adopcje naraz</b>. Bywa to celowe
              (kilku darczyńców składa się na jedno dziecko), ale najczęściej oznacza dubel -
              ten sam wpis założony dwa razy. Zbędny wpis usuwa się przez „Zmień darczyńcę"
              -> sekcja „Usuń tę adopcję" (możliwe tylko, gdy nie ma przy nim wpłat).
            </div>
          <?php endif; ?>
          <table class="events" style="margin:0;">
            <thead><tr><th>Darczyńca</th><th>Status</th><th>Okres</th><th>Kwota</th><th>Dossier</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($editAdoptions as $a): ?>
              <tr>
                <td>
                  <a href="darczynca.php?id=<?= (int)$a['donor_id'] ?>"><b><?= mada_esc($a['donor_name']) ?></b></a>
                  <?php $kontakt = implode(' · ', array_filter([(string)($a['donor_email'] ?? ''), (string)($a['donor_phone'] ?? '')])); ?>
                  <?php if ($kontakt !== ''): ?><br><span class="hint"><?= mada_esc($kontakt) ?></span><?php endif; ?>
                </td>
                <td><span class="badge <?= in_array($a['status'], ['pending', 'active'], true) ? 'badge-ok' : 'badge-arch' ?>">
                    <?= mada_esc($statusLabel[$a['status']] ?? $a['status']) ?></span></td>
                <td class="hint" style="white-space:nowrap;"><?= mada_esc(adopt_month_label($a['start_month'])) ?>
                    - <?= $a['end_month'] !== null ? mada_esc(adopt_month_label($a['end_month'])) : 'bezterm.' ?></td>
                <td class="hint" style="white-space:nowrap;"><?= number_format($a['amount_grosze'] / 100, 0, ',', ' ') ?> zł
                    <?= ['monthly' => 'mies.', 'quarterly' => 'kwart.', 'yearly' => 'rocznie'][$a['frequency']] ?? '' ?></td>
                <td><span class="badge <?= $a['dossier_sent_at'] !== null ? 'badge-ok' : 'badge-err' ?>"><?php
                    echo $a['dossier_sent_at'] !== null
                      ? 'wysłane ' . mada_esc(date('d.m.Y', strtotime((string)$a['dossier_sent_at'])))
                      : 'nie wysłano'; ?></span></td>
                <td style="white-space:nowrap;">
                  <a class="btn-secondary btn-sm" href="adopcja-edit.php?id=<?= (int)$a['id'] . $childBack ?>"
                     title="Przepnij dziecko do innego darczyńcy, popraw okres i kwotę albo usuń pomyłkowy wpis">✎ Zmień darczyńcę</a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="spraw-panel" style="display:block;" id="formularz">
      <div class="bar" style="margin-bottom:12px;">
        <h3 style="margin:0;">Edycja: nr <?= (int)$editChild['number'] ?> - <?= mada_esc($editChild['name']) ?>
          <?php if ($editChild['status'] !== 'active'): ?>
            <span class="badge badge-arch">w archiwum</span>
          <?php endif; ?>
        </h3>
        <span>
          <form method="post" style="display:inline;"
                onsubmit="return confirm(<?= $editChild['status'] === 'active'
                  ? "'Przenieść dziecko do archiwum?\\n\\nZniknie z listy i nie będzie proponowane przy nowych adopcjach. Historia, wpłaty i dossier zostają - można je przywrócić w każdej chwili.'"
                  : "'Przywrócić dziecko do programu?'" ?>);">
            <?= mada_csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editChild['status'] === 'active' ? 'archive' : 'restore' ?>">
            <input type="hidden" name="child_id" value="<?= (int)$editChild['id'] ?>">
            <button type="submit" class="btn-secondary btn-sm">
              <?= $editChild['status'] === 'active' ? '📦 Przenieś do archiwum' : '↩ Przywróć do programu' ?>
            </button>
          </form>
          <a href="dzieci.php" class="btn-ghost btn-sm">← Wróć do listy podopiecznych</a>
        </span>
      </div>
      <form method="post" enctype="multipart/form-data" class="form" style="margin:0;">
        <?= mada_csrf_field() ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="child_id" value="<?= (int)$editChild['id'] ?>">
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
          <label>Numer *<input type="number" name="number" min="1" required value="<?= (int)$editChild['number'] ?>" style="width:90px;"></label>
          <label>Imię (krótkie) *<input type="text" name="name" required value="<?= mada_esc($editChild['name']) ?>"></label>
          <?php /* Status zmienia WYŁĄCZNIE przycisk archiwum u góry - jedna droga zamiast dwóch.
                   To pole musi tu być, bo zapis formularza i tak ustawia status: bez niego
                   każdy zapis archiwalnego dziecka po cichu wracałby je do programu. */ ?>
          <input type="hidden" name="status" value="<?= mada_esc($editChild['status']) ?>">
          <label style="flex:1;min-width:180px;">Uwagi (robocze, nie idą do darczyńcy)<input type="text" name="notes" value="<?= mada_esc($editChild['notes'] ?? '') ?>"></label>
        </div>

        <h4 style="margin:16px 0 8px;color:var(--brown);">Dossier dziecka (treść maila do darczyńcy)</h4>
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
          <label style="flex:2;min-width:240px;">Pełne imię i nazwisko<input type="text" name="dossier_name"
                 placeholder="np. Avotriniaina Alvin RAKOTOZANANY" value="<?= mada_esc($editChild['dossier_name'] ?? '') ?>"></label>
          <label>Data urodzenia<input type="date" name="birth_date" value="<?= mada_esc($editChild['birth_date'] ?? '') ?>"></label>
          <label>Dzieci w rodzinie<input type="number" name="siblings" min="1" style="width:90px;" value="<?= mada_esc((string)($editChild['siblings'] ?? '')) ?>"></label>
        </div>
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
          <label style="flex:1;min-width:200px;">Ojciec<input type="text" name="father" value="<?= mada_esc($editChild['father'] ?? '') ?>"></label>
          <label style="flex:1;min-width:200px;">Matka<input type="text" name="mother" value="<?= mada_esc($editChild['mother'] ?? '') ?>"></label>
        </div>
        <label>Opis sytuacji dziecka
          <textarea name="description" rows="5" placeholder="Rodzina, w której wychowuje się..."><?= mada_esc($editChild['description'] ?? '') ?></textarea>
        </label>
        <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
          <?php if (!empty($editChild['photo'])): ?>
            <img src="../uploads/dzieci/<?= mada_esc($editChild['photo']) ?>" alt="" style="height:90px;border-radius:9px;border:1px solid var(--rule);">
          <?php endif; ?>
          <label style="margin:0;">Zdjęcie (JPG/PNG/WEBP, maks. 6 MB)<?= !empty($editChild['photo']) ? ' - wgranie nowego podmienia obecne' : '' ?>
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
          </label>
        </div>
        <div style="margin-top:10px;">
          <button type="submit" class="btn-primary btn-sm">Zapisz zmiany</button>
          <a href="dzieci.php" class="btn-ghost btn-sm">Anuluj</a>
        </div>
      </form>
      <p class="hint" style="margin:8px 0 0;">Zmiana numeru jest bezpieczna - adopcje i wpłaty są powiązane z dzieckiem, nie z numerem. Dossier trafia do maila „przedstawienie dziecka" wysyłanego przy przypisaniu darczyńcy.</p>

      <?php
        /* Usuwanie jest schowane i wymaga przepisania numeru dziecka. Powód: to
           jedyna nieodwracalna akcja w tym module, a leży obok przycisków, których
           używa się codziennie. Wycofanie dziecka z programu robi się archiwum. */
        $adoptCnt = count($editAdoptions);
      ?>
      <details class="danger-zone" style="margin:18px 0 0;">
        <summary>Usuń dziecko z bazy</summary>
        <?php if ($adoptCnt > 0): ?>
          <p class="hint" style="margin:10px 0 0;">
            <b>Tego dziecka nie można usunąć.</b> Ma <?= $adoptCnt ?>
            <?= $adoptCnt === 1 ? 'adopcję' : 'adopcje/adopcji' ?>, a razem z nimi historię wpłat -
            usunięcie skasowałoby ją bezpowrotnie i rozjechałoby sprawozdania.
            Jeśli dziecko wychodzi z programu, użyj <b>„Przenieś do archiwum"</b> u góry:
            zniknie z list, a wszystko zostanie na swoim miejscu.
          </p>
        <?php else: ?>
          <p style="margin:10px 0 12px;color:var(--err);font-weight:600;">
            Uwaga: tej operacji NIE DA SIĘ COFNĄĆ.
          </p>
          <p class="hint" style="margin:0 0 12px;">
            Usuwaj tylko wpisy dodane <b>przez pomyłkę</b> (dubel, literówka w numerze).
            Dziecko, które faktycznie było w programie, przenosi się do <b>archiwum</b> -
            wtedy nic nie ginie i można je przywrócić. To dziecko nie ma żadnej adopcji,
            więc usunięcie jest technicznie możliwe. Zdjęcie z serwera też zostanie skasowane.
          </p>
          <form method="post" style="margin:0;"
                onsubmit="var v=this.potwierdz.value.trim();
                          if (v !== '<?= (int)$editChild['number'] ?>') {
                            alert('Aby usunąć, wpisz numer dziecka: <?= (int)$editChild['number'] ?>');
                            return false;
                          }
                          return confirm('OSTATNIE OSTRZEŻENIE\n\nUsunąć nr <?= (int)$editChild['number'] ?> - <?= mada_esc($editChild['name']) ?> z bazy?\n\nTej operacji nie da się cofnąć.');">
            <?= mada_csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="child_id" value="<?= (int)$editChild['id'] ?>">
            <label style="max-width:320px;margin:0 0 10px;">Aby potwierdzić, przepisz numer dziecka (<b><?= (int)$editChild['number'] ?></b>)
              <input type="text" name="potwierdz" autocomplete="off" inputmode="numeric" placeholder="numer dziecka">
            </label>
            <button type="submit" class="btn-danger btn-sm">Usuń nr <?= (int)$editChild['number'] ?> - <?= mada_esc($editChild['name']) ?> na zawsze</button>
          </form>
        <?php endif; ?>
      </details>
    </div>
    <?php elseif ($showAdd): ?>
    <div class="spraw-panel" style="display:block;" id="formularz">
      <h3 style="margin:0 0 10px;">Nowe dziecko</h3>
      <form method="post" enctype="multipart/form-data" class="form" style="margin:0;">
        <?= mada_csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
          <label>Numer *<input type="number" name="number" min="1" required style="width:90px;"
                 value="<?= $children ? max(array_column($children, 'number')) + 1 : 1 ?>"></label>
          <label>Imię (krótkie) *<input type="text" name="name" required autofocus></label>
          <label style="flex:1;min-width:180px;">Uwagi (robocze, nie idą do darczyńcy)<input type="text" name="notes"></label>
        </div>

        <h4 style="margin:16px 0 8px;color:var(--brown);">Dossier dziecka (treść maila do darczyńcy - można uzupełnić później)</h4>
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
          <label style="flex:2;min-width:240px;">Pełne imię i nazwisko<input type="text" name="dossier_name"
                 placeholder="np. Avotriniaina Alvin RAKOTOZANANY"></label>
          <label>Data urodzenia<input type="date" name="birth_date"></label>
          <label>Dzieci w rodzinie<input type="number" name="siblings" min="1" style="width:90px;"></label>
        </div>
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
          <label style="flex:1;min-width:200px;">Ojciec<input type="text" name="father"></label>
          <label style="flex:1;min-width:200px;">Matka<input type="text" name="mother"></label>
        </div>
        <label>Opis sytuacji dziecka
          <textarea name="description" rows="5" placeholder="Rodzina, w której wychowuje się..."></textarea>
        </label>
        <label>Zdjęcie (JPG/PNG/WEBP, maks. 6 MB)
          <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
        </label>
        <div style="margin-top:10px;">
          <button type="submit" class="btn-primary btn-sm">Dodaj dziecko</button>
          <a href="dzieci.php" class="btn-ghost btn-sm">Anuluj</a>
        </div>
      </form>
      <p class="hint" style="margin:8px 0 0;">Numer podpowiedziany jako kolejny wolny - można zmienić. Wystarczą numer i imię; dossier można dopisać później przez „Edytuj".</p>
    </div>
    <?php endif; ?>

<?php if ($dbError !== ''): ?>
    <div class="alert alert-error">Baza danych jest niedostępna (sprawdź <code>payu/secret/db-config.php</code>): <?= mada_esc($dbError) ?></div>
<?php elseif ($totalChildren === 0): ?>
    <p class="hint">Baza podopiecznych jest pusta - dodaj pierwsze dziecko przyciskiem „+ Dodaj dziecko".</p>
<?php else: ?>
    <form method="get" style="margin:0 0 16px;display:flex;gap:10px;">
      <input type="search" name="q" value="<?= mada_esc($q) ?>" placeholder="Szukaj: imię, numer, darczyńca"
             style="flex:1;max-width:340px;padding:8px 12px;border:1px solid var(--rule);border-radius:9px;font:inherit;">
      <button type="submit" class="btn-secondary btn-sm">Szukaj</button>
      <?php if ($q !== ''): ?><a href="dzieci.php" class="btn-ghost btn-sm">Wyczyść</a><?php endif; ?>
      <?php if ($q === '' && $archivedCnt > 0): ?>
        <a href="dzieci.php<?= $showArchived ? '' : '?arch=1' ?>" class="btn-ghost btn-sm" style="margin-left:auto;">
          <?= $showArchived ? 'Ukryj archiwalne' : 'Pokaż archiwalne (' . $archivedCnt . ')' ?>
        </a>
      <?php endif; ?>
    </form>

    <?php if (!$children): ?>
      <p class="hint">Brak wyników dla „<?= mada_esc($q) ?>".</p>
    <?php else: ?>
    <?php
      $withDonor = count(array_filter($children, fn($c) => $c['donors'] !== null));
    ?>
    <p class="hint" style="margin:0 0 12px;"><?php
      if ($q !== ''): ?>Znaleziono: <?= count($children) ?> z <?= $totalChildren ?> (wyszukiwarka obejmuje też archiwalne)<?php
      else: ?>W programie: <?= $totalChildren - $archivedCnt ?><?php
        if ($archivedCnt > 0) echo ', w archiwum: ' . $archivedCnt . ($showArchived ? ' (pokazane)' : ' (ukryte)');
      endif; ?>,
       z darczyńcą: <?= $withDonor ?>, bez darczyńcy: <?= count($children) - $withDonor ?>.</p>
    <table class="events">
      <thead><tr>
        <th>Nr</th><th>Imię</th><th>Status</th><th>Darczyńca</th><th>Uwagi</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($children as $c): ?>
        <tr class="row-link" data-href="dzieci.php?edit=<?= (int)$c['id'] ?>#formularz">
          <td><b><?= (int)$c['number'] ?></b></td>
          <td><a href="dzieci.php?edit=<?= (int)$c['id'] ?>#formularz"><?= mada_esc($c['name']) ?></a><?= !empty($c['description']) || !empty($c['photo']) ? ' <span title="dossier uzupełnione">📋</span>' : '' ?></td>
          <td><?= $c['status'] === 'active' ? 'w programie' : '<span class="badge badge-arch">archiwum</span>' ?></td>
          <td><?php if ($c['donors'] !== null):
                $dids = array_values(array_filter(explode(',', (string)($c['donor_ids'] ?? '')))); ?>
                <?php if (count($dids) === 1): ?>
                  <a href="darczynca.php?id=<?= (int)$dids[0] ?>"><?= mada_esc($c['donors']) ?></a>
                <?php else: ?><?= mada_esc($c['donors']) ?><?php endif; ?>
              <?php else: ?><span class="badge" style="background:#fbeeec;color:var(--err);border-color:#e6b9b1;">brak</span><?php endif; ?></td>
          <td class="hint"><?= mada_esc($c['notes'] ?? '') ?></td>
          <td><a class="btn-secondary btn-sm" href="dzieci.php?edit=<?= (int)$c['id'] ?>#formularz">Edytuj</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
<?php endif; ?>
<?php
panel_footer();
