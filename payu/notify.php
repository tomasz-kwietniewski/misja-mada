<?php
/* ═══════════════════════════════════════════════════════════════
   PayU - notyfikacja statusu (serwer-do-serwera)
   PayU wysyła tu POST przy zmianie statusu transakcji.
   Weryfikujemy podpis, logujemy wpłatę, odpowiadamy 200.
  ═══════════════════════════════════════════════════════════════ */
require __DIR__ . '/lib.php';
require __DIR__ . '/db.php';
require __DIR__ . '/recurring-lib.php';
require __DIR__ . '/mail.php';

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

// ── Płatności cykliczne: rozpoznanie po extOrderId ────────────────
$extOrderId = isset($order['extOrderId']) ? (string) $order['extOrderId'] : '';
$status     = isset($order['status'])     ? (string) $order['status']     : '';
$payuOrderId = isset($order['orderId'])   ? (string) $order['orderId']    : '';
$cls = mada_sub_classify_ext($extOrderId);

try {
    if ($cls['type'] === 'first' && $status === 'COMPLETED') {
        // Pierwsza płatność zakończona (po 3DS) -> aktywuj subskrypcję.
        $sub = payu_sub_get((int) $cls['subId']);
        if ($sub && $sub['status'] === 'pending_first') {
            // Token wielorazowy zapisaliśmy już przy tworzeniu zamówienia (synchroniczna
            // odpowiedź PayU, recurring-first.php). To jest źródło prawdy - tu go tylko używamy.
            $cardToken = (string) ($sub['card_token'] ?? '');
            $cardMask  = (string) ($sub['card_mask'] ?? '');
            // Fallback (starsze subskrypcje / brak zapisu): spróbuj z notyfikacji, potem z GET order.
            if ($cardToken === '') {
                $tok = mada_sub_extract_token($data);
                if (!$tok && $payuOrderId) {
                    $full = payu_get_order($payuOrderId, payu_get_token());
                    $tok  = mada_sub_extract_token($full);
                }
                if ($tok) { $cardToken = $tok['token']; $cardMask = (string)($tok['mask'] ?? ''); }
            }
            if ($cardToken !== '') {
                $activated = payu_sub_activate(
                    (int) $sub['id'], $cardToken, $cardMask,
                    $payuOrderId, $sub['next_charge_at']
                );
                if ($activated) {
                    $fresh = payu_sub_get((int) $sub['id']);
                    mada_mail_welcome($fresh);
                    mada_mail_foundation($fresh, 'nowa');
                }
            } else {
                error_log('[PayU notify] FIRST sub=' . $cls['subId'] . ' COMPLETED bez tokena TOKC_.');
            }
        }
    } elseif ($cls['type'] === 'standard') {
        // Kolejne obciążenie - aktualizuj status wiersza charges.
        $chargeStatus = $status === 'COMPLETED' ? 'completed'
                      : (($status === 'CANCELED') ? 'failed' : 'pending');
        payu_charge_mark($extOrderId, $chargeStatus, $payuOrderId);
    }
} catch (Throwable $e) {
    // Nie blokujemy odpowiedzi 200 - błąd logujemy, PayU i tak ponowi przy potrzebie.
    error_log('[PayU notify] obsluga cykliczna: ' . $e->getMessage());
}

// PayU wymaga 200, inaczej ponawia notyfikację.
http_response_code(200);
echo 'OK';
