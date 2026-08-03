<?php
/* ═══════════════════════════════════════════════════════════════
   Adopcja Serca - mail "dossier dziecka" do darczyńcy.
   ───────────────────────────────────────────────────────────────
   Wzór: PDF wysyłany dotąd ręcznie przez fundację. Treść składana
   z pól dossier (Podopieczni -> Edytuj) + opcjonalny dopisek
   pracownika. Wysyłka ZAWSZE na wyraźne życzenie (checkbox przy
   przypisaniu dziecka albo przycisk „Wyślij dossier" na karcie
   darczyńcy) - nigdy automatycznie.
  ═══════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/../payu/mail.php';

/** Data urodzenia w formacie dossier: "23 października 2014 r." */
function adopt_birth_pl(?string $date): string {
    if (!$date || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) return '';
    $mies = [1 => 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca',
             'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
    return (int)$m[3] . ' ' . $mies[(int)$m[2]] . ' ' . $m[1] . ' r.';
}

/**
 * Mail-DOSSIER do darczyńcy z przedstawieniem przypisanego dziecka (wzór:
 * PDF "Adopcja Serca" wysyłany dotąd ręcznie). Renderuje tylko uzupełnione
 * pola dossier (Podopieczni -> Edytuj) + opcjonalny dopisek pracownika.
 * Zdjęcie ładowane z uploads/dzieci/ (losowa nazwa pliku). Wysyłka wyłącznie
 * na życzenie (checkbox). Zwraca true przy wysłaniu.
 */
function adopt_mail_child_dossier(array $donor, array $child, string $personalNote): bool {
    $email = trim((string)($donor['email'] ?? ''));
    if ($email === '') return false;
    $dossierName = trim((string)($child['dossier_name'] ?? '')) ?: $child['name'];

    $rows = '';
    $row = function (string $label, string $val) use (&$rows) {
        if ($val === '') return;
        $rows .= '<tr><td style="padding:3px 14px 3px 0;font-weight:bold;color:#422918;white-space:nowrap;vertical-align:top;">'
               . $label . ':</td><td style="padding:3px 0;">' . mada_mail_esc($val) . '</td></tr>';
    };
    $row('Imię', trim((string)($child['dossier_name'] ?? '')) !== ''
        ? trim(preg_replace('/\s*\S+$/u', '', (string)$child['dossier_name']))   // bez ostatniego członu (nazwiska)
        : $child['name']);
    if (trim((string)($child['dossier_name'] ?? '')) !== ''
        && preg_match('/(\S+)$/u', (string)$child['dossier_name'], $m)) {
        $row('Nazwisko', $m[1]);
    }
    $row('Data urodzenia', adopt_birth_pl($child['birth_date'] ?? null));
    $row('Ojciec', trim((string)($child['father'] ?? '')));
    $row('Matka', trim((string)($child['mother'] ?? '')));
    if (!empty($child['siblings'])) $row('Ilość dzieci w rodzinie', (string)(int)$child['siblings']);

    $photoHtml = '';
    if (!empty($child['photo'])) {
        $photoUrl = MADA_SITE_BASE . '/uploads/dzieci/' . rawurlencode((string)$child['photo']);
        $photoHtml = '<td style="width:190px;padding:0 20px 0 0;vertical-align:top;">'
                   . '<img src="' . mada_mail_esc($photoUrl) . '" alt="' . mada_mail_esc($dossierName) . '" width="180" '
                   . 'style="width:180px;max-width:100%;border-radius:12px;display:block;"></td>';
    }
    $descHtml = trim((string)($child['description'] ?? '')) !== ''
        ? '<h3 style="font-family:Georgia,serif;font-size:17px;color:#422918;margin:20px 0 8px;">Opis sytuacji dziecka</h3>'
          . '<p style="font-size:14.5px;line-height:1.7;margin:0 0 16px;white-space:pre-line;">'
          . mada_mail_esc(trim((string)$child['description'])) . '</p>'
        : '';
    $noteHtml = $personalNote !== ''
        ? '<div style="background:#faf5ee;border-left:3px solid #c8922e;border-radius:0 10px 10px 0;padding:14px 18px;margin:0 0 18px;font-size:14.5px;line-height:1.65;white-space:pre-line;">'
          . mada_mail_esc($personalNote) . '</div>'
        : '';

    $inner =
        '<p style="font-size:15px;line-height:1.65;margin:0 0 14px;">Dzień dobry, ' . mada_mail_esc($donor['full_name']) . '!</p>'
      . '<p style="font-size:15px;line-height:1.65;margin:0 0 20px;">Z radością przedstawiamy dziecko objęte Twoim wsparciem '
      . 'w programie <strong>Adopcja Serca</strong>:</p>'
      . '<h2 style="font-family:Georgia,serif;font-size:24px;color:#422918;margin:0 0 16px;text-align:center;">ADOPCJA SERCA ❤︎<br>'
      . '<span style="font-size:19px;">' . mada_mail_esc($dossierName) . '</span></h2>'
      . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:0 0 6px;"><tr>'
      . $photoHtml
      . '<td style="vertical-align:top;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="font-size:14.5px;line-height:1.6;">'
      . $rows . '</table></td></tr></table>'
      . $descHtml
      . $noteHtml
      . '<p style="font-size:15px;line-height:1.65;margin:0 0 16px;">W razie pytań po prostu odpisz na tego maila.</p>'
      . '<p style="font-size:14px;line-height:1.6;color:#5a4836;margin:0;">Dziękujemy, że jesteś z nami!<br>Fundacja Misja MADA</p>';
    return mada_mail_html($email, 'Twój podopieczny w programie Adopcja Serca - ' . $dossierName, $inner);
}
