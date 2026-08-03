<?php
/* ═══════════════════════════════════════════════════════════════
   Adopcja Serca - mail przypominający o zaległych wpłatach.
   ───────────────────────────────────────────────────────────────
   Treść zaakceptowana przez fundację 2026-08-03. Ton jest celowo
   łagodny: przy dobrowolnym wsparciu najczęstszą przyczyną braku
   wpłaty jest przeoczenie albo przelew w drodze, a nie odmowa.
   Wysyłką steruje adopcja/cron-przypomnienia.php (próg 2 miesiące,
   ponawianie co 14 dni, kopia do fundacji).
  ═══════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/../payu/mail.php';
require_once __DIR__ . '/lib.php';

if (!defined('MADA_KONTO_PLN')) define('MADA_KONTO_PLN', '70 1090 1056 0000 0001 5832 5871');

/** „2026-05" -> „maj 2026" (do listy zaległych miesięcy w mailu). */
function adopt_month_pl(string $ym): string {
    $mies = [1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec',
             'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
    [$y, $m] = array_map('intval', explode('-', $ym));
    return ($mies[$m] ?? $ym) . ' ' . $y;
}

/**
 * Przypomnienie o zaległych wpłatach - JEDEN mail na darczyńcę, nawet gdy
 * zalega przy kilku dzieciach.
 *
 * @param array $donor wiersz adopt_donors (potrzebne: full_name, email)
 * @param array $items [['child_name','child_number','months'=>['YYYY-MM',...],'amount_grosze'], ...]
 * @param bool  $copyToFoundation kopia na adres fundacji (temat z prefiksem)
 * @return bool czy mail do darczyńcy poszedł
 */
function adopt_mail_arrears_reminder(array $donor, array $items, bool $copyToFoundation = true): bool {
    $email = trim((string)($donor['email'] ?? ''));
    if ($email === '' || !$items) return false;

    /* Zwrot bezosobowy („Dzień dobry, X!") jak w mailu-dossier. Formy typu
       „Szanowny Panie [imię]" psują się na małżeństwach i instytucjach -
       w bazie są wpisy „Krzysio i Kasia Miszkurka" i „Parafia Kłodzko". */
    $imie = trim((string)($donor['full_name'] ?? ''));

    $sumaGrosze = 0; $wszystkieMiesiace = 0; $dzieci = []; $doTytulu = [];
    $bloki = '';
    foreach ($items as $it) {
        $mies = $it['months'] ?? [];
        if (!$mies) continue;
        $kwota = count($mies) * (int)($it['amount_grosze'] ?? 7000);
        $sumaGrosze += $kwota;
        $wszystkieMiesiace += count($mies);
        $nazwa = trim((string)($it['child_name'] ?? '')) !== ''
            ? $it['child_name'] . ' (nr ' . (int)$it['child_number'] . ')'
            : 'dziecko oczekujące na przypisanie';
        $dzieci[] = $nazwa;
        // Do tytułu przelewu: samo imię i numer, bez nawiasów - część banków
        // wycina znaki specjalne, a fundacja księguje wpłaty po numerze dziecka.
        if (trim((string)($it['child_name'] ?? '')) !== '') {
            $doTytulu[] = $it['child_name'] . ' ' . (int)$it['child_number'];
        }
        $bloki .= '<p style="font-size:14.5px;line-height:1.7;margin:0 0 10px;">'
                . '<strong>' . mada_mail_esc($nazwa) . '</strong><br>'
                . 'brakujące miesiące: ' . mada_mail_esc(implode(', ', array_map('adopt_month_pl', $mies)))
                . '<br>razem: <strong>' . number_format($kwota / 100, 0, ',', ' ') . ' zł</strong></p>';
    }
    if (!$bloki) return false;

    /* Format tytułu ustalony przez fundację 2026-08-03: „Adopcja Serca - darowizna -
       imię i numer dziecka". Gdy adopcja czeka jeszcze na przypisanie dziecka, w tytule
       zostaje nazwa darczyńcy - inaczej przelew byłby nie do zidentyfikowania. */
    $tytulPrzelewu = 'Adopcja Serca - darowizna - ' . ($doTytulu ? implode(', ', $doTytulu) : $imie);
    $inner =
        '<h2 style="font-family:Georgia,serif;font-size:24px;color:#422918;margin:0 0 16px;">Przypomnienie o wpłacie</h2>'
      . '<p style="font-size:15px;line-height:1.65;margin:0 0 14px;">Dzień dobry, ' . mada_mail_esc($imie) . '!</p>'
      . '<p style="font-size:15px;line-height:1.65;margin:0 0 16px;">Dziękujemy za objęcie opieką '
      . mada_mail_esc(implode(', ', $dzieci)) . ' w programie <strong>Adopcja Serca</strong>. Dzięki temu wsparciu '
      . (count($dzieci) === 1 ? 'dziecko ma' : 'dzieci mają') . ' zapewnioną naukę i opiekę Sióstr na Madagaskarze.</p>'
      . '<p style="font-size:15px;line-height:1.65;margin:0 0 8px;">W naszej ewidencji brakuje wpłat za '
      . $wszystkieMiesiace . ' ' . adopt_odmiana_miesiac($wszystkieMiesiace) . ' - łącznie <strong>'
      . number_format($sumaGrosze / 100, 0, ',', ' ') . ' zł</strong>. Piszemy, bo najczęściej wynika to ze zwykłego '
      . 'przeoczenia albo z tego, że wpłata jeszcze do nas nie dotarła.</p>'
      . '<div style="background:#faf5ee;border-radius:12px;padding:18px 22px;margin:14px 0 18px;">' . $bloki
      . '<hr style="border:0;border-top:1px solid #e6d9c4;margin:12px 0;">'
      . '<p style="font-size:14px;line-height:1.7;margin:0;">'
      . 'Odbiorca: <strong>Fundacja Misja MADA</strong><br>'
      . 'Konto PLN: <strong>' . MADA_KONTO_PLN . '</strong><br>'
      . 'Tytuł przelewu: <strong>' . mada_mail_esc($tytulPrzelewu) . '</strong></p></div>'
      . '<p style="font-size:15px;line-height:1.65;margin:0 0 16px;">Jeśli wpłata została już wykonana, prosimy o '
      . 'zignorowanie tej wiadomości albo o krótką informację - poprawimy nasze zapisy. Gdyby dalsze wspieranie nie '
      . 'było teraz możliwe, prosimy o wiadomość: przyjmiemy każdą decyzję ze zrozumieniem i zadbamy, '
      . (count($dzieci) === 1 ? 'by dziecko znalazło nowego opiekuna' : 'by dzieci znalazły nowych opiekunów') . '.</p>'
      . '<p style="font-size:14px;line-height:1.6;color:#5a4836;margin:0;">Z wyrazami wdzięczności,<br>Fundacja Misja MADA</p>';

    $temat = 'Adopcja Serca - przypomnienie o wpłacie';
    $ok = mada_mail_html($email, $temat, $inner);

    if ($copyToFoundation) {
        // Kopia 1:1 tego, co dostał darczyńca - fundacja widzi treść i adresata.
        mada_mail_html(MADA_MAIL_FOUND, '[kopia] ' . $temat . ' - ' . $imie . ' <' . $email . '>',
            '<p style="font-size:13px;color:#8a7963;margin:0 0 14px;">Kopia automatycznego przypomnienia wysłanego do '
            . mada_mail_esc($imie) . ' &lt;' . mada_mail_esc($email) . '&gt;.</p>' . $inner);
    }
    return $ok;
}

/** Odmiana rzeczownika „miesiąc" po liczebniku (1 miesiąc / 2 miesiące / 5 miesięcy). */
function adopt_odmiana_miesiac(int $n): string {
    if ($n === 1) return 'miesiąc';
    $r10 = $n % 10; $r100 = $n % 100;
    if ($r10 >= 2 && $r10 <= 4 && ($r100 < 12 || $r100 > 14)) return 'miesiące';
    return 'miesięcy';
}
