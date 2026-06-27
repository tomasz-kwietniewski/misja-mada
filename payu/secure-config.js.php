<?php
/* ═══════════════════════════════════════════════════════════════
   PayU - konfiguracja Secure Form dla frontu (emituje JS).
   Zwraca window.MADA_PAYU = { posId, env, sdkUrl, recurringUrl }.
   posId i SDK zależą od środowiska (produkcja/sandbox) z payu/lib.php.
   posId NIE jest sekretem.
  ═══════════════════════════════════════════════════════════════ */
require __DIR__ . '/lib.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$cfg = [
    'posId'        => (string) PAYU_POS_ID,
    'env'          => PAYU_ENV,
    'sdkUrl'       => PAYU_BASE . '/javascript/sdk',
    'recurringUrl' => '/payu/recurring-first.php',
];

echo 'window.MADA_PAYU = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';';
