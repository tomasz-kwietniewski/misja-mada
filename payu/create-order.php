<?php
/* ═══════════════════════════════════════════════════════════════
   PayU - utworzenie zamówienia (płatność jednorazowa, PLN)
   Endpoint dla assets/darowizna.js -> window.MADA_PAYU_URL
   Zwraca { redirectUri } albo { error }.
  ═══════════════════════════════════════════════════════════════ */
require __DIR__ . '/lib.php';

const SITE_BASE = 'https://misjamada.pl';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    payu_json(['error' => 'Metoda niedozwolona.'], 405);
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    payu_json(['error' => 'Nieprawidłowe dane formularza.'], 400);
}

// ── Walidacja (etap 1: tylko PLN, tylko jednorazowo) ──────────────
$imie     = trim((string)($data['imie'] ?? ''));
$nazwisko = trim((string)($data['nazwisko'] ?? ''));
$email    = trim((string)($data['email'] ?? ''));
$currency = strtoupper(trim((string)($data['currency'] ?? 'PLN')));
$recurring = !empty($data['recurring']);
$amount   = isset($data['amount']) ? (float)$data['amount'] : 0;
$goalLabel = trim((string)($data['goalLabel'] ?? 'Darowizna na rzecz Fundacji Misja MADA'));

if ($recurring) {
    payu_json(['error' => 'Płatności cykliczne uruchomimy wkrótce. Wybierz „jednorazowo" lub skorzystaj z przelewu tradycyjnego (dane w stopce).'], 422);
}
if ($currency !== 'PLN') {
    payu_json(['error' => 'Płatność online dostępna na razie tylko w PLN. Wybierz PLN albo skorzystaj z przelewu tradycyjnego (konto EUR w stopce).'], 422);
}
if ($imie === '' || $nazwisko === '') {
    payu_json(['error' => 'Podaj imię i nazwisko.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    payu_json(['error' => 'Podaj prawidłowy adres e-mail.'], 422);
}
if ($amount < 1 || $amount > 100000) {
    payu_json(['error' => 'Nieprawidłowa kwota darowizny.'], 422);
}

$grosze = (string) intval(round($amount * 100));   // PayU oczekuje kwoty w groszach
$descr  = mb_substr($goalLabel, 0, 200);

$order = [
    'notifyUrl'     => SITE_BASE . '/payu/notify.php',
    'continueUrl'   => SITE_BASE . '/dziekujemy.html',
    'customerIp'    => payu_client_ip(),
    'merchantPosId' => PAYU_POS_ID,
    'description'   => $descr,
    'currencyCode'  => 'PLN',
    'totalAmount'   => $grosze,
    'extOrderId'    => uniqid('mada_', true),
    'buyer' => [
        'email'     => $email,
        'firstName' => mb_substr($imie, 0, 100),
        'lastName'  => mb_substr($nazwisko, 0, 100),
        'language'  => 'pl',
    ],
    'products' => [[
        'name'      => $descr,
        'unitPrice' => $grosze,
        'quantity'  => '1',
    ]],
];

try {
    $token  = payu_get_token();
    $result = payu_create_order($order, $token);
    payu_json(['redirectUri' => $result['redirectUri']]);
} catch (Exception $e) {
    error_log('[PayU create-order] ' . $e->getMessage());
    payu_json(['error' => 'Nie udało się połączyć z bramką płatności. Spróbuj ponownie za chwilę lub napisz na kontakt@misjamada.pl.'], 502);
}
