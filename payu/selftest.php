<?php
/* TYMCZASOWY diagnostyk PayU (sandbox/dane publiczne). DO USUNIECIA po tescie. */
require __DIR__ . '/lib.php';
header('Content-Type: text/plain; charset=utf-8');

echo 'PHP: ' . PHP_VERSION . "\n";
echo 'curl: ' . (function_exists('curl_init') ? 'tak' : 'NIE') . "\n";
echo 'openssl: ' . (extension_loaded('openssl') ? 'tak' : 'NIE') . "\n";
echo 'ENV: ' . PAYU_ENV . "\n";
echo 'BASE: ' . PAYU_BASE . "\n";
echo "----\n";

try {
    $t = payu_get_token();
    echo 'TOKEN OK: ' . substr($t, 0, 10) . "...\n";
    $order = [
        'notifyUrl'     => 'https://misjamada.pl/payu/notify.php',
        'continueUrl'   => 'https://misjamada.pl/dziekujemy.html',
        'customerIp'    => '127.0.0.1',
        'merchantPosId' => PAYU_POS_ID,
        'description'   => 'Selftest',
        'currencyCode'  => 'PLN',
        'totalAmount'   => '1000',
        'extOrderId'    => uniqid('selftest_', true),
        'buyer' => ['email' => 'test@example.com', 'firstName' => 'Jan', 'lastName' => 'Test', 'language' => 'pl'],
        'products' => [['name' => 'Selftest', 'unitPrice' => '1000', 'quantity' => '1']],
    ];
    $r = payu_create_order($order, $t);
    echo 'ORDER OK: ' . $r['redirectUri'] . "\n";
} catch (Exception $e) {
    echo 'EXCEPTION: ' . $e->getMessage() . "\n";
}
