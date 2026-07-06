<?php
/* ═══════════════════════════════════════════════════════════════
   Newsletter - dodanie JUŻ ZWERYFIKOWANEGO subskrybenta do MailerLite
   (bez double opt-in). Wołane serwer-do-serwera po weryfikacji maila w
   innym przepływie (adopcja: klik linku DOI lub płatność kartą), więc
   mail jest już potwierdzony. Chronione shared secret NL_VERIFIED_SECRET.
   Konfiguracja: newsletter/secret/verified-config.php
     define('NL_VERIFIED_SECRET', '...');
  ═══════════════════════════════════════════════════════════════ */
require __DIR__ . '/lib.php';

$__vs = __DIR__ . '/secret/verified-config.php';
if (is_readable($__vs)) { require $__vs; }
if (!defined('NL_VERIFIED_SECRET')) define('NL_VERIFIED_SECRET', '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ml_json(['error' => 'Metoda niedozwolona.'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    ml_json(['error' => 'Nieprawidłowe dane.'], 400);
}

$secret = (string)($data['secret'] ?? '');
if (NL_VERIFIED_SECRET === '' || !hash_equals(NL_VERIFIED_SECRET, $secret)) {
    ml_json(['error' => 'Brak autoryzacji.'], 403);
}

$email = trim((string)($data['email'] ?? ''));
$imie  = trim((string)($data['imie'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ml_json(['error' => 'Nieprawidłowy e-mail.'], 422);
}
if (MAILERLITE_TOKEN === '') {
    ml_json(['error' => 'MailerLite niezłożony.'], 503);
}

try {
    list($code, $resp) = ml_add_subscriber($email, $imie, ml_client_ip());
    if ($code === 200 || $code === 201) {
        ml_json(['ok' => true]);
    }
    error_log('[add-verified] MailerLite HTTP ' . $code . ': ' . json_encode($resp));
    ml_json(['error' => 'MailerLite odrzucił zapis.'], 502);
} catch (Exception $e) {
    error_log('[add-verified] ' . $e->getMessage());
    ml_json(['error' => 'Błąd MailerLite.'], 502);
}
