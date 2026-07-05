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
 * Gwarantuje istnienie schematu - raz na proces (idempotentne, tanie).
 * Wołane na starcie endpointów backendu (recurring-first, cron), więc tabele
 * tworzą się same przy pierwszym użyciu - bez ręcznej migracji.
 */
function payu_db_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    payu_db_migrate();
    $done = true;
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

/* ─────────────────────────────────────────────────────────────────
   CRUD - subskrypcje
  ───────────────────────────────────────────────────────────────── */

/** Wstawia subskrypcję (status pending_first). Zwraca jej id. */
function payu_sub_insert(array $d): int {
    $pdo = payu_db();
    $sql = "INSERT INTO subscriptions
        (manage_token, email, first_name, last_name, phone, goal, goal_label,
         children, amount_grosze, currency, charge_day, start_date, next_charge_at,
         expiry_date, min_months, status)
        VALUES
        (:manage_token, :email, :first_name, :last_name, :phone, :goal, :goal_label,
         :children, :amount_grosze, :currency, :charge_day, :start_date, :next_charge_at,
         :expiry_date, :min_months, 'pending_first')";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':manage_token'  => $d['manage_token'],
        ':email'         => $d['email'],
        ':first_name'    => $d['first_name'],
        ':last_name'     => $d['last_name'],
        ':phone'         => $d['phone'] ?? null,
        ':goal'          => $d['goal'],
        ':goal_label'    => $d['goal_label'],
        ':children'      => $d['children'] ?? null,
        ':amount_grosze' => $d['amount_grosze'],
        ':currency'      => $d['currency'] ?? 'PLN',
        ':charge_day'    => $d['charge_day'],
        ':start_date'    => $d['start_date'],
        ':next_charge_at'=> $d['next_charge_at'],
        ':expiry_date'   => $d['expiry_date'] ?? null,
        ':min_months'    => $d['min_months'] ?? null,
    ]);
    return (int) $pdo->lastInsertId();
}

function payu_sub_get(int $id): ?array {
    $st = payu_db()->prepare('SELECT * FROM subscriptions WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function payu_sub_by_manage_token(string $token): ?array {
    $st = payu_db()->prepare('SELECT * FROM subscriptions WHERE manage_token = ?');
    $st->execute([$token]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Aktywuje subskrypcję po udanej pierwszej płatności (FIRST). Pierwsza płatność
 * liczy się jako miesiąc 1. Idempotentne (tylko z pending_first).
 * Zwraca true, jeśli FAKTYCZNIE aktywowano (do jednorazowego maila powitalnego).
 */
function payu_sub_activate(int $id, string $cardToken, string $cardMask, string $payuOrderId, string $nextChargeAt): bool {
    $st = payu_db()->prepare(
        "UPDATE subscriptions
            SET status='active', card_token=?, card_mask=?, payu_first_order_id=?,
                next_charge_at=?, months_paid=GREATEST(months_paid,1), retry_count=0,
                last_error=NULL, last_attempt_at=NOW()
          WHERE id=? AND status='pending_first'"
    );
    $st->execute([$cardToken, $cardMask, $payuOrderId, $nextChargeAt, $id]);
    return $st->rowCount() > 0;
}

/** Zapisuje id zamówienia FIRST (gdy płatność idzie przez 3DS - aktywacja po notyfikacji). */
function payu_sub_set_first_order(int $id, string $payuOrderId): void {
    $st = payu_db()->prepare('UPDATE subscriptions SET payu_first_order_id=? WHERE id=?');
    $st->execute([$payuOrderId, $id]);
}

/** Lista subskrypcji do panelu (najnowsze pierwsze). */
function payu_sub_list(int $limit = 500): array {
    $limit = max(1, min(2000, $limit));
    $st = payu_db()->query("SELECT * FROM subscriptions ORDER BY created_at DESC, id DESC LIMIT $limit");
    return $st->fetchAll();
}

/**
 * Higiena danych: zeruje token karty dla PORZUCONYCH subskrypcji `pending_first`
 * starszych niż $days dni (np. płatnik zaczął 3DS i nie dokończył). Taka subskrypcja
 * i tak NIGDY nie jest obciążana (cron bierze tylko `active`), więc trzymanie w niej
 * realnego TOKC_ to zbędna retencja wrażliwego sekretu. Zwraca liczbę wyczyszczonych.
 * $days walidowane jako int -> bezpieczne w interpolacji INTERVAL.
 */
function payu_sub_purge_abandoned_tokens(int $days = 7): int {
    $days = max(1, $days);
    $st = payu_db()->prepare(
        "UPDATE subscriptions
            SET card_token=NULL
          WHERE status='pending_first' AND card_token IS NOT NULL
            AND created_at < DATE_SUB(NOW(), INTERVAL $days DAY)"
    );
    $st->execute();
    return $st->rowCount();
}

/** Subskrypcje do obciążenia dziś (status active, termin <= dziś). */
function payu_sub_due(string $today): array {
    $st = payu_db()->prepare(
        "SELECT * FROM subscriptions WHERE status='active' AND next_charge_at <= ? ORDER BY id"
    );
    $st->execute([$today]);
    return $st->fetchAll();
}

/** Udane obciążenie cykliczne: +1 miesiąc opłacony, nowy termin, reset ponowień. */
function payu_sub_mark_success(int $id, string $nextChargeAt): void {
    $st = payu_db()->prepare(
        "UPDATE subscriptions
            SET months_paid=months_paid+1, next_charge_at=?, retry_count=0,
                last_error=NULL, last_attempt_at=NOW()
          WHERE id=?"
    );
    $st->execute([$nextChargeAt, $id]);
}

/** Nieudane obciążenie z zaplanowanym ponowieniem. */
function payu_sub_mark_retry(int $id, string $nextChargeAt, string $error): void {
    $st = payu_db()->prepare(
        "UPDATE subscriptions
            SET retry_count=retry_count+1, next_charge_at=?, last_error=?, last_attempt_at=NOW()
          WHERE id=?"
    );
    $st->execute([$nextChargeAt, mb_substr($error, 0, 255), $id]);
}

/** Wyczerpane ponowienia -> wstrzymanie subskrypcji. */
function payu_sub_mark_paused(int $id, string $error): void {
    $st = payu_db()->prepare(
        "UPDATE subscriptions
            SET status='paused', paused_at=NOW(), last_error=?, last_attempt_at=NOW()
          WHERE id=?"
    );
    $st->execute([mb_substr($error, 0, 255), $id]);
}

/** Anulowanie subskrypcji. Zwraca true, jeśli była aktywna/wstrzymana i została anulowana. */
function payu_sub_cancel(int $id): bool {
    $st = payu_db()->prepare(
        "UPDATE subscriptions SET status='cancelled', cancelled_at=NOW()
          WHERE id=? AND status IN ('active','paused','pending_first')"
    );
    $st->execute([$id]);
    return $st->rowCount() > 0;
}

/* ─────────────────────────────────────────────────────────────────
   CRUD - obciążenia (charges)
  ───────────────────────────────────────────────────────────────── */

/**
 * Wstawia wiersz obciążenia (status pending). Idempotentne: gdy ext_order_id już
 * istnieje (duplikat), zwraca null - obciążenie już było zainicjowane.
 */
function payu_charge_insert(int $subId, string $extOrderId, int $amount, string $currency, int $attempt): ?int {
    $pdo = payu_db();
    try {
        $st = $pdo->prepare(
            "INSERT INTO charges (subscription_id, ext_order_id, amount_grosze, currency, attempt_no, status)
             VALUES (?, ?, ?, ?, ?, 'pending')"
        );
        $st->execute([$subId, $extOrderId, $amount, $currency, $attempt]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') return null;   // naruszenie UNIQUE - duplikat
        throw $e;
    }
}

function payu_charge_by_ext(string $extOrderId): ?array {
    $st = payu_db()->prepare('SELECT * FROM charges WHERE ext_order_id = ?');
    $st->execute([$extOrderId]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Aktualizuje status obciążenia (po odpowiedzi PayU lub notyfikacji). */
function payu_charge_mark(string $extOrderId, string $status, ?string $payuOrderId = null, ?string $error = null): void {
    $completed = $status === 'completed' ? date('Y-m-d H:i:s') : null;
    $st = payu_db()->prepare(
        "UPDATE charges
            SET status=?, payu_order_id=COALESCE(?, payu_order_id), error_msg=?, completed_at=COALESCE(?, completed_at)
          WHERE ext_order_id=?"
    );
    $st->execute([$status, $payuOrderId, $error !== null ? mb_substr($error, 0, 255) : null, $completed, $extOrderId]);
}
