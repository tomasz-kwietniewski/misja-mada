<?php
/* ═══════════════════════════════════════════════════════════════
   Adopcja Serca - jednorazowa migracja schematu (CLI).
   Uruchom na serwerze:  php adopcja/migrate.php
   Idempotentne (CREATE TABLE IF NOT EXISTS) - można wołać wielokrotnie.
  ═══════════════════════════════════════════════════════════════ */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Tylko CLI.');
}

require_once __DIR__ . '/db.php';

try {
    adopt_db_migrate();
    echo "OK: schemat modułu Adopcja Serca gotowy.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD: ' . $e->getMessage() . "\n");
    exit(1);
}
