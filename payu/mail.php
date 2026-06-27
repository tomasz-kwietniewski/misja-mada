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

function mada_mail_welcome(array $sub): void {
    $kwota = mada_mail_amount($sub);
    $body = "Dzień dobry,\n\n"
        . "dziękujemy za wsparcie cykliczne dla Fundacji Misja MADA.\n\n"
        . "Cel: {$sub['goal_label']}\n"
        . "Kwota: {$kwota} {$sub['currency']} miesięcznie\n"
        . "Kolejne obciążenie: {$sub['next_charge_at']}\n\n"
        . "Subskrypcję możesz anulować w każdej chwili tutaj:\n"
        . mada_manage_url($sub) . "\n\n"
        . "Z wyrazami wdzięczności,\nFundacja Misja MADA";
    mada_mail($sub['email'], 'Dziękujemy za wsparcie cykliczne - Misja MADA', $body);
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
    $body = "Subskrypcja {$event}.\n\n"
        . "ID: {$sub['id']}\n"
        . "Darczyńca: {$sub['first_name']} {$sub['last_name']} <{$sub['email']}>\n"
        . "Cel: {$sub['goal_label']}\n"
        . "Kwota: {$kwota} {$sub['currency']}/mies.\n"
        . (isset($sub['children']) && $sub['children'] ? "Dzieci: {$sub['children']}\n" : '');
    mada_mail(MADA_MAIL_FOUND, "Subskrypcja {$event} - Misja MADA (ID {$sub['id']})", $body);
}
