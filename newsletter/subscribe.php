<?php
/* ═══════════════════════════════════════════════════════════════
   Newsletter - zapis subskrybenta do MailerLite
   Endpoint dla assets/newsletter.js -> window.MADA_NEWSLETTER_URL
   Zwraca { ok:true } albo { error }.
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

$now = gmdate('Y-m-d H:i:s');
$ip  = ml_client_ip();
$payload = [
    'email'  => $email,
    'fields' => ['name' => mb_substr($imie, 0, 100)],
    'opted_in_at' => $now,
];
if ($ip !== '')                   { $payload['ip_address'] = $ip; $payload['optin_ip'] = $ip; }
if (MAILERLITE_GROUP_ID !== '')   { $payload['groups'] = [MAILERLITE_GROUP_ID]; }

try {
    list($code, $resp) = ml_request('POST', '/subscribers', $payload);
    if ($code === 200 || $code === 201) {
        ml_json(['ok' => true]);
    }
    error_log('[Newsletter] MailerLite HTTP ' . $code . ': ' . json_encode($resp));
    ml_json(['error' => 'Nie udało się zapisać na newsletter. Spróbuj ponownie za chwilę.'], 502);
} catch (Exception $e) {
    error_log('[Newsletter] ' . $e->getMessage());
    ml_json(['error' => 'Nie udało się połączyć z systemem newslettera. Spróbuj ponownie za chwilę.'], 502);
}
