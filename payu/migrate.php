<?php
/* ═══════════════════════════════════════════════════════════════
   PayU - migracja bazy płatności cyklicznych (uruchom raz).
   Użycie (przez SSH na serwerze):  php payu/migrate.php
   Wymaga payu/secret/db-config.php. Idempotentne (CREATE IF NOT EXISTS).
   NIE jest endpointem HTTP - tylko CLI (zabezpieczenie poniżej).
  ═══════════════════════════════════════════════════════════════ */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Tylko CLI.');
}

require __DIR__ . '/db.php';

try {
    payu_db_migrate();
    echo "OK - tabele subscriptions i charges gotowe.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "BŁĄD migracji: " . $e->getMessage() . "\n");
    exit(1);
}
