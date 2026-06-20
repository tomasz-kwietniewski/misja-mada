<?php
/* ═══════════════════════════════════════════════════════════════
   PayU - warstwa bazy danych (płatności cykliczne, pakiet 6)
   ───────────────────────────────────────────────────────────────
   Konfiguracja w payu/secret/db-config.php (poza repo, .htaccess deny):
       define('PAYU_DB_HOST', 'localhost');
       define('PAYU_DB_NAME', '...');
       define('PAYU_DB_USER', '...');
       define('PAYU_DB_PASS', '...');
   Token karty (TOKC_) = dane płatnicze -> dostęp tylko stąd, nigdy na froncie.
  ═══════════════════════════════════════════════════════════════ */

$__db_secret = __DIR__ . '/secret/db-config.php';
if (is_readable($__db_secret)) {
    require_once $__db_secret;
}

/** Połączenie PDO (singleton). Rzuca wyjątek, gdy brak konfiguracji/połączenia. */
function payu_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (!defined('PAYU_DB_NAME')) {
        throw new RuntimeException('Brak konfiguracji bazy (payu/secret/db-config.php).');
    }
    $host = defined('PAYU_DB_HOST') ? PAYU_DB_HOST : 'localhost';
    $dsn  = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, PAYU_DB_NAME);
    $pdo = new PDO($dsn, PAYU_DB_USER, PAYU_DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/**
 * Tworzy tabele, jeśli nie istnieją (idempotentne). Wołać raz przy wdrożeniu
 * (np. payu/migrate.php) lub na starcie crona.
 */
function payu_db_migrate(?PDO $pdo = null): void {
    $pdo = $pdo ?: payu_db();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS subscriptions (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            manage_token      CHAR(64)        NOT NULL,
            card_token        VARCHAR(255)    NULL,
            card_mask         VARCHAR(32)     NULL,
            payu_first_order_id VARCHAR(64)   NULL,
            email             VARCHAR(255)    NOT NULL,
            first_name        VARCHAR(100)    NOT NULL,
            last_name         VARCHAR(100)    NOT NULL,
            phone             VARCHAR(32)     NULL,
            goal              VARCHAR(32)     NOT NULL,
            goal_label        VARCHAR(255)    NOT NULL,
            children          SMALLINT UNSIGNED NULL,
            amount_grosze     INT UNSIGNED    NOT NULL,
            currency          CHAR(3)         NOT NULL DEFAULT 'PLN',
            status            ENUM('pending_first','active','paused','cancelled') NOT NULL DEFAULT 'pending_first',
            charge_day        TINYINT UNSIGNED NOT NULL,
            start_date        DATE            NULL,
            next_charge_at    DATE            NULL,
            expiry_date       DATE            NULL,
            months_paid       INT UNSIGNED    NOT NULL DEFAULT 0,
            min_months        SMALLINT UNSIGNED NULL,
            retry_count       TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_error        VARCHAR(255)    NULL,
            last_attempt_at   DATETIME        NULL,
            created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            cancelled_at      DATETIME        NULL,
            paused_at         DATETIME        NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_manage_token (manage_token),
            KEY idx_due (status, next_charge_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS charges (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subscription_id   BIGINT UNSIGNED NOT NULL,
            ext_order_id      VARCHAR(64)     NOT NULL,
            payu_order_id     VARCHAR(64)     NULL,
            amount_grosze     INT UNSIGNED    NOT NULL,
            currency          CHAR(3)         NOT NULL DEFAULT 'PLN',
            status            ENUM('pending','completed','failed','canceled') NOT NULL DEFAULT 'pending',
            attempt_no        TINYINT UNSIGNED NOT NULL DEFAULT 1,
            error_msg         VARCHAR(255)    NULL,
            created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at      DATETIME        NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ext_order (ext_order_id),
            KEY idx_sub (subscription_id),
            CONSTRAINT fk_charge_sub FOREIGN KEY (subscription_id)
                REFERENCES subscriptions (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}
