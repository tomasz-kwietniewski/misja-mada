<?php
/* ═══════════════════════════════════════════════════════════════
   Adopcja Serca - KROK 1: zgłoszenie przelewowe ze strony (POST JSON).
   Zastępuje bezpośredni POST do Apps Script: zapis do MySQL
   (adopt_signups, double opt-in po stronie PHP) + mail potwierdzający
   przez relay Gmail. Format payloadu 1:1 z assets/adopcja-form.js.
   Odpowiedź: {ok:true} albo {ok:false, error:...}.
  ═══════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../payu/mail.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function zg_out(bool $ok, ?string $error = null): void {
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    zg_out(false, 'method');
}

$raw = file_get_contents('php://input');
$d = json_decode((string)$raw, true);
if (!is_array($d)) zg_out(false, 'bad-json');

/* Walidacja - te same reguły co walidacja frontu (adopcja-form.js). */
$imie     = trim((string)($d['imie'] ?? ''));
$nazwisko = trim((string)($d['nazwisko'] ?? ''));
$email    = trim((string)($d['email'] ?? ''));
$telefon  = trim((string)($d['telefon'] ?? ''));
$forma    = (string)($d['forma'] ?? '');
$dzieci   = max(1, min(10, (int)($d['dzieci'] ?? 1)));

if (mb_strlen($imie) < 2 || mb_strlen($nazwisko) < 2
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || strlen(preg_replace('/\D/', '', $telefon)) < 9
    || !in_array($forma, ['nieokreslony', 'czasowa'], true)
    || empty($d['zgoda_regulamin']) || empty($d['zgoda_wizerunek']) || empty($d['zgoda_rodo'])) {
    zg_out(false, 'validation');
}

/* Adres korespondencyjny jest DOBROWOLNY (decyzja fundacji 2026-08-11) - rozbity
   na pola, żeby dało się z niego drukować koperty. Formatu kodu pocztowego NIE
   narzucamy tutaj: reguła 00-000 jest polska i pilnuje jej formularz, który zna
   język strony. Serwer tylko przycina długości do kolumn w bazie - inaczej
   Francuz z kodem „75001" dostałby cichą podmianę na „75-001". */
foreach (['ulica' => 160, 'nr_domu' => 30, 'kod_pocztowy' => 12, 'miejscowosc' => 120] as $k => $max) {
    $d[$k] = mb_substr(trim((string)($d[$k] ?? '')), 0, $max);
}

try {
    adopt_db_ensure_schema();
    $ip = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

    // Rate-limit: max 5 zgłoszeń / godzinę z jednego IP (anty-spam).
    $st = payu_db()->prepare(
        'SELECT COUNT(*) FROM adopt_signups WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= 5) zg_out(false, 'rate-limit');

    $token = bin2hex(random_bytes(32));
    $st = payu_db()->prepare(
        'INSERT INTO adopt_signups (token, email, payload, status, ip) VALUES (?, ?, ?, ?, ?)'
    );
    $st->execute([$token, $email, json_encode($d, JSON_UNESCAPED_UNICODE), 'pending', $ip]);

    // Mail potwierdzający (treść 1:1 z wcześniejszego Apps Script sendConfirmationEmail).
    $confirmUrl = MADA_SITE_BASE . '/adopcja/potwierdz.php?token=' . $token;
    $inner =
        '<h2 style="font-family:Georgia,serif;font-size:26px;color:#422918;margin:0 0 18px;">Cześć ' . mada_mail_esc($imie) . '!</h2>'
      . '<p style="font-size:15px;line-height:1.65;margin:0 0 14px;">Otrzymaliśmy Twoje zgłoszenie do programu <strong>Adopcja Serca</strong>. '
      . 'Aby je dokończyć, potwierdź, że e-mail <strong>' . mada_mail_esc($email) . '</strong> należy do Ciebie.</p>'
      . '<div style="text-align:center;margin:24px 0;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto;"><tr><td bgcolor="#c99d66" style="background:#c99d66;border-radius:10px;">'
      . '<a href="' . mada_mail_esc($confirmUrl) . '" style="display:inline-block;padding:15px 32px;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;color:#2a1a0e;text-decoration:none;">Potwierdzam zgłoszenie →</a>'
      . '</td></tr></table></div>'
      . '<p style="font-size:12px;color:#6b5a4a;word-break:break-all;background:#faf5ee;padding:10px 14px;border-radius:8px;margin:0;">' . mada_mail_esc($confirmUrl) . '</p>';
    $sent = mada_mail_html($email, 'Potwierdź swoje zgłoszenie - Adopcja Serca - Fundacja Misja MADA', $inner);
    if (!$sent) {
        error_log('[adopcja/zgloszenie] nie wyslano maila potwierdzajacego do ' . $email);
        zg_out(false, 'mail');
    }

    zg_out(true);
} catch (Throwable $e) {
    error_log('[adopcja/zgloszenie] ' . $e->getMessage());
    zg_out(false, 'server');
}
