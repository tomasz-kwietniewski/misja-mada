<?php
/* ═══════════════════════════════════════════════════════════════
   Newsletter - KROK 1: przyjęcie zapisu + wysyłka maila potwierdzającego
   (własny double opt-in). Adres trafia do MailerLite dopiero po
   kliknięciu linku -> newsletter/confirm.php.
   Endpoint dla assets/newsletter.js -> window.MADA_NEWSLETTER_URL
  ═══════════════════════════════════════════════════════════════ */
require __DIR__ . '/lib.php';

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

// Polski mail potwierdzający
$confirmUrl = NL_SITE_BASE . '/newsletter/confirm.php?token=' . $token;
$esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$html = '<!doctype html><html lang="pl"><head><meta charset="utf-8"></head>'
  . '<body style="margin:0;padding:40px 20px;background:#faf5ee;font-family:\'Helvetica Neue\',Arial,sans-serif;color:#1b140e;">'
  . '<table cellpadding="0" cellspacing="0" border="0" style="max-width:560px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;">'
  . '<tr><td style="padding:32px 40px 22px;border-bottom:1px solid rgba(66,41,24,.12);text-align:center;">'
  . '<img src="' . NL_SITE_BASE . '/media/logo-kolor.png" alt="Fundacja Misja MADA" style="height:54px;width:auto;">'
  . '</td></tr>'
  . '<tr><td style="padding:34px 40px;">'
  . '<p style="font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:#c99d66;font-weight:700;margin:0 0 10px;">Newsletter</p>'
  . '<h1 style="font-family:Georgia,serif;font-size:24px;color:#422918;margin:0 0 16px;">Potwierdź zapis na newsletter</h1>'
  . '<p style="font-size:15px;line-height:1.65;color:#3c2913;margin:0 0 14px;">'
  . ($imie !== '' ? 'Cześć ' . $esc($imie) . '! ' : '')
  . 'Dziękujemy za chęć zapisania się do newslettera Fundacji Misja MADA. Aby dokończyć zapis, potwierdź, że adres <strong>' . $esc($email) . '</strong> należy do Ciebie.</p>'
  . '<div style="text-align:center;margin:28px 0;">'
  . '<a href="' . $confirmUrl . '" style="display:inline-block;background:#c99d66;color:#2a1a0e;padding:15px 32px;border-radius:10px;font-weight:700;font-size:15px;text-decoration:none;">Potwierdzam zapis →</a>'
  . '</div>'
  . '<p style="font-size:13px;color:#6b5a4a;line-height:1.6;margin:0 0 10px;">Jeśli przycisk nie działa, skopiuj ten link do przeglądarki:</p>'
  . '<p style="font-size:12px;color:#6b5a4a;word-break:break-all;background:#faf5ee;padding:10px 14px;border-radius:8px;margin:0;">' . $confirmUrl . '</p>'
  . '<p style="font-size:13px;color:#6b5a4a;line-height:1.6;margin:22px 0 0;">Jeśli to nie Ty zapisywałeś/zapisywałaś ten adres - zignoruj tę wiadomość. Bez potwierdzenia nie dodamy adresu do newslettera.</p>'
  . '</td></tr>'
  . '<tr><td style="padding:22px 40px;background:#2a1a0e;color:#faf5ee;font-size:12px;line-height:1.6;">'
  . '<strong style="color:#c99d66;">Fundacja Misja MADA</strong><br>ul. Szosa Chełmińska 271A, 87-100 Toruń<br>'
  . '<a href="' . NL_SITE_BASE . '" style="color:#c99d66;">misjamada.pl</a> · <a href="mailto:kontakt@misjamada.pl" style="color:#c99d66;">kontakt@misjamada.pl</a>'
  . '</td></tr></table></body></html>';

$subject = '=?UTF-8?B?' . base64_encode('Potwierdź zapis na newsletter - Fundacja Misja MADA') . '?=';
$headers = "MIME-Version: 1.0\r\n"
  . "Content-Type: text/html; charset=UTF-8\r\n"
  . 'From: =?UTF-8?B?' . base64_encode('Fundacja Misja MADA') . "?= <kontakt@misjamada.pl>\r\n"
  . "Reply-To: kontakt@misjamada.pl\r\n";

$sent = @mail($email, $subject, $html, $headers, '-fkontakt@misjamada.pl');
if (!$sent) {
    @unlink($path);
    error_log('[Newsletter] mail() nie powiodlo sie dla ' . $email);
    ml_json(['error' => 'Nie udało się wysłać e-maila potwierdzającego. Spróbuj ponownie lub napisz na kontakt@misjamada.pl.'], 502);
}

ml_json(['ok' => true]);
