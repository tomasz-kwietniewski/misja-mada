<?php
/* ═══════════════════════════════════════════════════════════════
   PayU - JEDNORAZOWA migracja/weryfikacja bazy przez HTTPS (bez SSH).
   ───────────────────────────────────────────────────────────────
   Wymaga w payu/secret/db-config.php:  define('PAYU_MIGRATE_TOKEN', '<token>');
   Wejście:  https://misjamada.pl/payu/migrate-web.php?token=<token>
   Tworzy tabele (idempotentnie) i wypisuje listę tabel.
   PLIK TYMCZASOWY - usuwany po weryfikacji (token można wtedy skasować z configu).
  ═══════════════════════════════════════════════════════════════ */

require __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
if (!defined('PAYU_MIGRATE_TOKEN') || PAYU_MIGRATE_TOKEN === '' || !hash_equals(PAYU_MIGRATE_TOKEN, $token)) {
    http_response_code(403);
    echo "Brak dostepu (nieprawidlowy lub brakujacy token).\n";
    exit;
}

try {
    payu_db_migrate();
    $pdo  = payu_db();
    $tabs = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo "OK - migracja wykonana.\n";
    echo "Tabele w bazie: " . (count($tabs) ? implode(', ', $tabs) : '(brak)') . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "BLAD: " . $e->getMessage() . "\n";
}
