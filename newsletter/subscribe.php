<?php
/* ═══════════════════════════════════════════════════════════════
   Newsletter - KROK 1: przyjęcie zapisu + wysyłka maila potwierdzającego
   (własny double opt-in). Adres trafia do MailerLite dopiero po
   kliknięciu linku -> newsletter/confirm.php.
   Szablon maila: newsletter/confirm-email.html ({{CONFIRM_URL}}).
   Endpoint dla assets/newsletter.js -> window.MADA_NEWSLETTER_URL
  ═══════════════════════════════════════════════════════════════ */
require __DIR__ . '/lib.php';
require_once __DIR__ . '/../payu/mail.php';   // mada_mail_relay (Gmail Apps Script)

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ml_json(['error' => 'Metoda niedozwolona.'], 405);
}

$data  = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    ml_json(['error' => 'Nieprawidłowe dane.'], 400);
}

$imie  = trim((string)($data['imie'] ?? ''));
$email = trim((string)($data['email'] ?? ''));
$zgoda = !empty($data['zgoda_rodo']);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ml_json(['error' => 'Podaj prawidłowy adres e-mail.'], 422);
}
if (!$zgoda) {
    ml_json(['error' => 'Wymagana zgoda na otrzymywanie newslettera.'], 422);
}
if (MAILERLITE_TOKEN === '') {
    ml_json(['error' => 'Newsletter jest w trakcie konfiguracji. Spróbuj ponownie wkrótce lub napisz na kontakt@misjamada.pl.'], 503);
}

// Token + zapis zgłoszenia oczekującego
$token = bin2hex(random_bytes(20));
$path  = nl_pending_path($token);
$record = [
    'imie'  => mb_substr($imie, 0, 100),
    'email' => $email,
    'ip'    => ml_client_ip(),
    'ts'    => time(),
];
if (@file_put_contents($path, json_encode($record, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
    error_log('[Newsletter] Nie udalo sie zapisac pending: ' . $path);
    ml_json(['error' => 'Wystąpił błąd. Spróbuj ponownie za chwilę.'], 500);
}

// Treść maila - z szablonu newsletter/confirm-email.html, podmiana {{CONFIRM_URL}}
$confirmUrl = NL_SITE_BASE . '/newsletter/confirm.php?token=' . $token;
$tpl = @file_get_contents(__DIR__ . '/confirm-email.html');
if ($tpl === false || strpos($tpl, '{{CONFIRM_URL}}') === false) {
    // Fallback, gdyby szablon byl niedostepny
    $tpl = '<!doctype html><meta charset="utf-8"><p style="font-family:Arial,sans-serif;">'
         . 'Potwierdź zapis na newsletter Fundacji Misja MADA, klikając w link:</p>'
         . '<p><a href="{{CONFIRM_URL}}">{{CONFIRM_URL}}</a></p>';
}
$html = str_replace('{{CONFIRM_URL}}', htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8'), $tpl);

$subjectText = 'Potwierdź zapis na newsletter - Fundacja Misja MADA';

// Najpierw relay Gmail (Apps Script) - dobra dostarczalność; fallback na PHP mail() (bywa w spamie).
$sent = mada_mail_relay($email, $subjectText, '', $html);
if (!$sent) {
    $subject = '=?UTF-8?B?' . base64_encode($subjectText) . '?=';
    $headers = "MIME-Version: 1.0\r\n"
      . "Content-Type: text/html; charset=UTF-8\r\n"
      . 'From: =?UTF-8?B?' . base64_encode('Fundacja Misja MADA') . "?= <kontakt@misjamada.pl>\r\n"
      . "Reply-To: kontakt@misjamada.pl\r\n";
    $sent = @mail($email, $subject, $html, $headers, '-fkontakt@misjamada.pl');
}
if (!$sent) {
    @unlink($path);
    error_log('[Newsletter] mail() nie powiodlo sie dla ' . $email);
    ml_json(['error' => 'Nie udało się wysłać e-maila potwierdzającego. Spróbuj ponownie lub napisz na kontakt@misjamada.pl.'], 502);
}

ml_json(['ok' => true]);
