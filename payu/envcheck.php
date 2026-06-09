<?php
/* TYMCZASOWY diagnostyk konfiguracji (NIE ujawnia sekretow). DO USUNIECIA. */
require __DIR__ . '/lib.php';
header('Content-Type: text/plain; charset=utf-8');
$p = __DIR__ . '/secret/payu-config.php';
echo 'oczekiwana_sciezka: ' . $p . "\n";
echo 'plik_istnieje: ' . (file_exists($p) ? 'TAK' : 'NIE') . "\n";
echo 'plik_czytelny: '  . (is_readable($p) ? 'TAK' : 'NIE') . "\n";
echo 'PAYU_ENV: ' . PAYU_ENV . "\n";
echo 'PAYU_BASE: ' . PAYU_BASE . "\n";
echo 'uzywa_domyslnego_sandbox_POS: ' . (PAYU_POS_ID === '300746' ? 'TAK (config nie nadpisal)' : 'NIE (config dziala)') . "\n";
