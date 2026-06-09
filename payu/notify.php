<?php
/* ═══════════════════════════════════════════════════════════════
   PayU - notyfikacja statusu (serwer-do-serwera)
   PayU wysyła tu POST przy zmianie statusu transakcji.
   Weryfikujemy podpis, logujemy wpłatę, odpowiadamy 200.
  ═══════════════════════════════════════════════════════════════ */
require __DIR__ . '/lib.php';

$raw = file_get_contents('php://input');

// Nagłówek podpisu (PHP: HTTP_OPENPAYU_SIGNATURE; fallback przez getallheaders).
$sig = isset($_SERVER['HTTP_OPENPAYU_SIGNATURE']) ? $_SERVER['HTTP_OPENPAYU_SIGNATURE'] : '';
if (!$sig && function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) {
        if (strcasecmp($k, 'OpenPayU-Signature') === 0) { $sig = $v; break; }
    }
}

if (!payu_verify_signature($raw, $sig)) {
    error_log('[PayU notify] Niepoprawny podpis notyfikacji.');
    http_response_code(400);
    echo 'BAD SIGNATURE';
    exit;
}

$data  = json_decode($raw, true);
$order = isset($data['order']) ? $data['order'] : [];

$line = sprintf(
    "%s\tstatus=%s\textOrderId=%s\torderId=%s\tamount=%s\t%s\temail=%s\n",
    date('c'),
    isset($order['status'])       ? $order['status']       : '?',
    isset($order['extOrderId'])   ? $order['extOrderId']   : '?',
    isset($order['orderId'])      ? $order['orderId']      : '?',
    isset($order['totalAmount'])  ? $order['totalAmount']  : '?',
    isset($order['currencyCode']) ? $order['currencyCode'] : '?',
    isset($order['buyer']['email']) ? $order['buyer']['email'] : '?'
);

// Log do data/ (poza repo, wykluczony z deployu - nie zniknie i nie wycieknie do repo).
$dir = __DIR__ . '/../data';
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
@file_put_contents($dir . '/payu-notifications.log', $line, FILE_APPEND | LOCK_EX);

// PayU wymaga 200, inaczej ponawia notyfikację.
http_response_code(200);
echo 'OK';
