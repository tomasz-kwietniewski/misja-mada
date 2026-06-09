<?php
/* ═══════════════════════════════════════════════════════════════
   PayU - wspólna biblioteka (Fundacja Misja MADA)
   ───────────────────────────────────────────────────────────────
   Konfiguracja:
   - Jeśli istnieje payu/secret/payu-config.php -> używamy go (PRODUKCJA):
       define('PAYU_ENV', 'production');
       define('PAYU_POS_ID', '...');
       define('PAYU_CLIENT_ID', '...');     // zwykle = POS ID
       define('PAYU_CLIENT_SECRET', '...');
       define('PAYU_MD5', '...');           // "drugi klucz" do podpisu notyfikacji
   - Jeśli pliku nie ma -> domyślnie SANDBOX z PUBLICZNYMI danymi testowymi PayU
     (jawne, opublikowane przez PayU - bezpieczne w repo).
  ═══════════════════════════════════════════════════════════════ */

$__secret = __DIR__ . '/secret/payu-config.php';
if (is_readable($__secret)) {
    require $__secret;
}

// Domyślne wartości SANDBOX (publiczne dane testowe PayU) - jeśli config nie zdefiniował.
if (!defined('PAYU_ENV'))           define('PAYU_ENV', 'sandbox');
if (!defined('PAYU_POS_ID'))        define('PAYU_POS_ID', '145227');
if (!defined('PAYU_CLIENT_ID'))     define('PAYU_CLIENT_ID', '145227');
if (!defined('PAYU_CLIENT_SECRET')) define('PAYU_CLIENT_SECRET', '12f071174cb7eb79d4aac5bc2f07563f');
if (!defined('PAYU_MD5'))           define('PAYU_MD5', '13a980d4f851f3d9a1cfc792fb1f5e50');

define('PAYU_BASE', PAYU_ENV === 'production'
    ? 'https://secure.payu.com'
    : 'https://secure.snd.payu.com');

/** Zwraca JSON i kończy żądanie. */
function payu_json($obj, $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($obj, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Pobiera token OAuth (client_credentials). Rzuca wyjątek przy błędzie. */
function payu_get_token() {
    $ch = curl_init(PAYU_BASE . '/pl/standard/user/oauth/authorize');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => PAYU_CLIENT_ID,
            'client_secret' => PAYU_CLIENT_SECRET,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false)       throw new Exception('OAuth - blad polaczenia: ' . $err);
    $data = json_decode($res, true);
    if (empty($data['access_token'])) {
        throw new Exception('OAuth - brak access_token (HTTP ' . $code . '): ' . $res);
    }
    return $data['access_token'];
}

/**
 * Tworzy zamówienie. $order = pełny payload zgodny z PayU REST.
 * WAŻNE: nie podążamy za przekierowaniem (302), żeby odczytać JSON z redirectUri.
 * Zwraca tablicę z odpowiedzi PayU (redirectUri, orderId, extOrderId, status).
 */
function payu_create_order(array $order, $token) {
    $ch = curl_init(PAYU_BASE . '/api/v2_1/orders');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,   // kluczowe - chcemy body z redirectUri, nie stronę bramki
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => json_encode($order, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false) throw new Exception('OrderCreate - blad polaczenia: ' . $err);

    $data = json_decode($res, true);
    if (!is_array($data)) {
        throw new Exception('OrderCreate - nieprawidlowa odpowiedz (HTTP ' . $code . '): ' . $res);
    }
    $statusCode = isset($data['status']['statusCode']) ? $data['status']['statusCode'] : '';
    if ($statusCode !== 'SUCCESS' || empty($data['redirectUri'])) {
        $msg = isset($data['status']['statusDesc']) ? $data['status']['statusDesc'] : 'brak redirectUri';
        throw new Exception('OrderCreate - PayU odrzucilo zamowienie: ' . $msg . ' (' . $statusCode . ')');
    }
    return $data;
}

/**
 * Weryfikuje nagłówek OpenPayU-Signature notyfikacji.
 * Format: "sender=...;signature=<hash>;algorithm=MD5;content=DOCUMENT"
 * Oczekiwany hash = algorytm(body . drugi_klucz).
 */
function payu_verify_signature($rawBody, $signatureHeader) {
    if (!$signatureHeader) return false;
    $parts = [];
    foreach (explode(';', $signatureHeader) as $kv) {
        $pair = explode('=', $kv, 2);
        if (count($pair) === 2) $parts[trim($pair[0])] = trim($pair[1]);
    }
    if (empty($parts['signature'])) return false;

    $algo = isset($parts['algorithm']) ? strtoupper($parts['algorithm']) : 'MD5';
    $expected = ($algo === 'SHA-256' || $algo === 'SHA256')
        ? hash('sha256', $rawBody . PAYU_MD5)
        : md5($rawBody . PAYU_MD5);

    return hash_equals($expected, strtolower($parts['signature']));
}

/** IP klienta (uwzględnia proxy/LiteSpeed). */
function payu_client_ip() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
}
