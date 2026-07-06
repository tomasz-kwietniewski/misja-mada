<?php
/* ═══════════════════════════════════════════════════════════════
   PayU cykliczne - powiadomienia mailowe (PHP mail(), From kontakt@misjamada.pl).
   Zwięzłe, polskie. Link zarządzania = manage.php?token=<manage_token>.
   (Dostarczalność mail() jak w newsletterze - jak spam, przejść na SMTP.)
  ═══════════════════════════════════════════════════════════════ */

if (!defined('MADA_MAIL_FROM'))     define('MADA_MAIL_FROM', 'kontakt@misjamada.pl');
if (!defined('MADA_MAIL_FOUND'))    define('MADA_MAIL_FOUND', 'kontakt@misjamada.pl');
if (!defined('MADA_SITE_BASE'))     define('MADA_SITE_BASE', 'https://misjamada.pl');

/** Wysyła maila (UTF-8, From fundacji). Zwraca wynik mail(). */
function mada_mail($to, string $subject, string $body): bool {
    $headers  = 'From: Fundacja Misja MADA <' . MADA_MAIL_FROM . '>' . "\r\n";
    $headers .= 'Reply-To: ' . MADA_MAIL_FROM . "\r\n";
    $headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
    $headers .= 'MIME-Version: 1.0' . "\r\n";
    $subjEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return @mail($to, $subjEnc, $body, $headers);
}

/** Link do zarządzania/anulowania subskrypcji. */
function mada_manage_url(array $sub): string {
    return MADA_SITE_BASE . '/payu/manage.php?token=' . $sub['manage_token'];
}

/** Kwota w PLN do treści (np. „70" albo „125,50"). */
function mada_mail_amount(array $sub): string {
    $g = (int) $sub['amount_grosze'];
    return $g % 100 === 0 ? (string) intdiv($g, 100) : number_format($g / 100, 2, ',', '');
}

/** Wspólna skorupa HTML maila (kolory fundacji). $inner = treść (HTML). */
function mada_mail_shell(string $title, string $inner): string {
    $site = MADA_SITE_BASE;
    return '<!doctype html><html lang="pl"><head><meta charset="utf-8"></head>'
      . '<body style="margin:0;padding:40px 20px;background:#faf5ee;font-family:\'Helvetica Neue\',Arial,sans-serif;color:#1b140e;">'
      . '<table cellpadding="0" cellspacing="0" border="0" style="max-width:560px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;">'
      . '<tr><td style="padding:32px 40px;border-bottom:1px solid rgba(66,41,24,.12);">'
      . '<h1 style="font-family:Georgia,serif;font-size:22px;color:#422918;margin:0;">Fundacja Misja MADA</h1></td></tr>'
      . '<tr><td style="padding:32px 40px;">' . $inner . '</td></tr>'
      . '<tr><td style="padding:22px 40px;background:#2a1a0e;color:#faf5ee;font-size:12px;line-height:1.6;">'
      . '<strong style="color:#c99d66;">Fundacja Misja MADA</strong><br>'
      . 'ul. Szosa Chełmińska 271A, 87-100 Toruń<br>'
      . '<a href="' . $site . '" style="color:#c99d66;">' . $site . '</a> · '
      . '<a href="mailto:' . MADA_MAIL_FROM . '" style="color:#c99d66;">' . MADA_MAIL_FROM . '</a>'
      . '</td></tr></table></body></html>';
}

/** Wysyła maila HTML (UTF-8, From fundacji). */
function mada_mail_html($to, string $subject, string $innerHtml): bool {
    $html = mada_mail_shell($subject, $innerHtml);
    $headers  = 'From: Fundacja Misja MADA <' . MADA_MAIL_FROM . '>' . "\r\n";
    $headers .= 'Reply-To: ' . MADA_MAIL_FROM . "\r\n";
    $headers .= 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $subjEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return @mail($to, $subjEnc, $html, $headers);
}

/** Bezpieczne escapowanie do treści HTML maila. */
function mada_mail_esc($s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function mada_mail_welcome(array $sub): void {
    $kwota = mada_mail_amount($sub);
    $cel = mada_mail_esc($sub['goal_label']);
    $url = mada_mail_esc(mada_manage_url($sub));
    $inner =
        '<h2 style="font-family:Georgia,serif;font-size:24px;color:#422918;margin:0 0 16px;">Dziękujemy za wsparcie cykliczne!</h2>'
      . '<p style="font-size:15px;line-height:1.65;margin:0 0 12px;">Cel: <strong>' . $cel . '</strong><br>'
      . 'Kwota: <strong>' . mada_mail_esc($kwota) . ' ' . mada_mail_esc($sub['currency']) . ' miesięcznie</strong><br>'
      . 'Kolejne obciążenie: ' . mada_mail_esc($sub['next_charge_at']) . '</p>'
      . '<p style="font-size:14px;line-height:1.6;color:#5a4836;margin:0 0 20px;">Subskrypcję możesz anulować w każdej chwili:</p>'
      . '<div style="text-align:center;margin:8px 0 4px;"><a href="' . $url . '" style="display:inline-block;background:#c99d66;color:#2a1a0e;padding:14px 28px;border-radius:10px;font-weight:700;font-size:14px;text-decoration:none;">Zarządzaj subskrypcją</a></div>';
    mada_mail_html($sub['email'], 'Dziękujemy za wsparcie cykliczne - Misja MADA', $inner);
}

function mada_mail_receipt(array $sub): void {
    $kwota = mada_mail_amount($sub);
    $body = "Dzień dobry,\n\n"
        . "potwierdzamy comiesięczną darowiznę {$kwota} {$sub['currency']} na cel: {$sub['goal_label']}.\n"
        . "Dziękujemy, że jesteś z nami!\n\n"
        . "Zarządzaj subskrypcją / anuluj:\n" . mada_manage_url($sub) . "\n\n"
        . "Fundacja Misja MADA";
    mada_mail($sub['email'], 'Potwierdzenie darowizny cyklicznej - Misja MADA', $body);
}

function mada_mail_charge_failed(array $sub): void {
    $body = "Dzień dobry,\n\n"
        . "nie udało się pobrać comiesięcznej darowizny dla celu: {$sub['goal_label']}.\n"
        . "Spróbujemy ponownie w najbliższych dniach. Prosimy o sprawdzenie, czy karta jest aktywna i ma środki.\n\n"
        . "Zarządzaj subskrypcją:\n" . mada_manage_url($sub) . "\n\n"
        . "Fundacja Misja MADA";
    mada_mail($sub['email'], 'Problem z płatnością cykliczną - Misja MADA', $body);
}

function mada_mail_paused(array $sub): void {
    $body = "Dzień dobry,\n\n"
        . "po kilku nieudanych próbach wstrzymaliśmy Twoją darowiznę cykliczną (cel: {$sub['goal_label']}).\n"
        . "Jeśli chcesz ją wznowić, skontaktuj się z nami: " . MADA_MAIL_FROM . "\n"
        . "albo ustanów ją ponownie na stronie.\n\n"
        . "Fundacja Misja MADA";
    mada_mail($sub['email'], 'Darowizna cykliczna wstrzymana - Misja MADA', $body);
}

function mada_mail_cancelled(array $sub): void {
    $body = "Dzień dobry,\n\n"
        . "potwierdzamy anulowanie Twojej darowizny cyklicznej (cel: {$sub['goal_label']}).\n"
        . "Nie pobierzemy już kolejnych płatności. Dziękujemy za dotychczasowe wsparcie!\n\n"
        . "Fundacja Misja MADA";
    mada_mail($sub['email'], 'Darowizna cykliczna anulowana - Misja MADA', $body);
}

/** Powiadomienie fundacji o zdarzeniu subskrypcji. $event: 'nowa'|'anulowana'|'wstrzymana'. */
function mada_mail_foundation(array $sub, string $event): void {
    $kwota = mada_mail_amount($sub);
    $inner =
        '<h2 style="font-family:Georgia,serif;font-size:22px;color:#422918;margin:0 0 16px;">Subskrypcja ' . mada_mail_esc($event) . '</h2>'
      . '<p style="font-size:14px;line-height:1.7;margin:0;">'
      . 'ID: <strong>' . mada_mail_esc($sub['id']) . '</strong><br>'
      . 'Darczyńca: ' . mada_mail_esc($sub['first_name'] . ' ' . $sub['last_name']) . ' &lt;' . mada_mail_esc($sub['email']) . '&gt;<br>'
      . 'Cel: ' . mada_mail_esc($sub['goal_label']) . '<br>'
      . 'Kwota: ' . mada_mail_esc($kwota) . ' ' . mada_mail_esc($sub['currency']) . '/mies.'
      . (isset($sub['children']) && $sub['children'] ? '<br>Dzieci: ' . mada_mail_esc($sub['children']) : '')
      . '</p>';
    mada_mail_html(MADA_MAIL_FOUND, "Subskrypcja {$event} - Misja MADA (ID {$sub['id']})", $inner);
}
