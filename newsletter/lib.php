<?php
/* ═══════════════════════════════════════════════════════════════
   Newsletter / MailerLite - wspólna biblioteka (Fundacja Misja MADA)
   ───────────────────────────────────────────────────────────────
   Konfiguracja w newsletter/secret/mailerlite-config.php:
     define('MAILERLITE_TOKEN', '...');     // token API (sekret)
     define('MAILERLITE_GROUP_ID', '...');  // id grupy subskrybentów
  ═══════════════════════════════════════════════════════════════ */

$__secret = __DIR__ . '/secret/mailerlite-config.php';
if (is_readable($__secret)) {
    require $__secret;
}
if (!defined('MAILERLITE_TOKEN'))    define('MAILERLITE_TOKEN', '');
if (!defined('MAILERLITE_GROUP_ID')) define('MAILERLITE_GROUP_ID', '');

define('MAILERLITE_BASE', 'https://connect.mailerlite.com/api');

function ml_json($obj, $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($obj, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Wywołanie API MailerLite. Zwraca [httpCode, decodedBody]. */
function ml_request($method, $path, $body = null) {
    $ch = curl_init(MAILERLITE_BASE . $path);
    $headers = [
        'Authorization: Bearer ' . MAILERLITE_TOKEN,
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false) throw new Exception('MailerLite - blad polaczenia: ' . $err);
    return [$code, json_decode($res, true)];
}

function ml_client_ip() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}
