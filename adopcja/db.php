<?php
/* ═══════════════════════════════════════════════════════════════
   Adopcja Serca - warstwa bazy danych (moduł panelu CMS).
   ───────────────────────────────────────────────────────────────
   Używa tego samego połączenia MySQL co PayU (payu_db() z payu/db.php,
   konfiguracja w payu/secret/db-config.php). Schemat idempotentny
   (CREATE TABLE IF NOT EXISTS) - jak payu_db_migrate().
   Tabele: adopt_children, adopt_donors, adopt_adoptions, adopt_payments,
   adopt_import_pending, fin_flows, panel_audit_log, panel_login_attempts.
  ═══════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/../payu/db.php';
require_once __DIR__ . '/lib.php';

/** Gwarantuje istnienie schematu - raz na proces. */
function adopt_db_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    adopt_db_migrate();
    $done = true;
}

function adopt_db_migrate(?PDO $pdo = null): void {
    $pdo = $pdo ?: payu_db();
    payu_db_migrate($pdo);   // subscriptions/charges muszą istnieć (FK)

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS adopt_children (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            number      SMALLINT UNSIGNED NOT NULL,
            name        VARCHAR(120)    NOT NULL,
            status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
            notes       TEXT            NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_number (number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS adopt_donors (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            full_name    VARCHAR(200)    NOT NULL,
            email        VARCHAR(255)    NULL,
            emails_extra VARCHAR(500)    NULL,
            phone        VARCHAR(32)     NULL,
            source       ENUM('import','site','manual') NOT NULL DEFAULT 'manual',
            notes        TEXT            NULL,
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_email (email),
            KEY idx_name (full_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS adopt_adoptions (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            donor_id        BIGINT UNSIGNED NOT NULL,
            child_id        BIGINT UNSIGNED NULL,
            subscription_id BIGINT UNSIGNED NULL,
            duration        ENUM('indefinite','fixed') NOT NULL DEFAULT 'indefinite',
            start_month     CHAR(7)         NULL,     -- NULL = start nieznany (uzupełnia się z 1. wpłaty)
            end_month       CHAR(7)         NULL,
            frequency       ENUM('monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
            amount_grosze   INT UNSIGNED    NOT NULL DEFAULT 7000,
            method          ENUM('transfer','card','cash') NOT NULL DEFAULT 'transfer',
            status          ENUM('pending','active','ended','cancelled') NOT NULL DEFAULT 'active',
            materials_sent  TINYINT(1)      NOT NULL DEFAULT 0,
            notes           TEXT            NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ended_at        DATETIME        NULL,
            PRIMARY KEY (id),
            KEY idx_donor (donor_id),
            KEY idx_child (child_id),
            KEY idx_sub (subscription_id),
            KEY idx_status (status),
            CONSTRAINT fk_adopt_donor FOREIGN KEY (donor_id)
                REFERENCES adopt_donors (id),
            CONSTRAINT fk_adopt_child FOREIGN KEY (child_id)
                REFERENCES adopt_children (id),
            CONSTRAINT fk_adopt_sub FOREIGN KEY (subscription_id)
                REFERENCES subscriptions (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS adopt_payments (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            adoption_id   BIGINT UNSIGNED NOT NULL,
            charge_id     BIGINT UNSIGNED NULL,
            amount_grosze INT UNSIGNED    NOT NULL,
            paid_at       DATE            NOT NULL,
            period_from   CHAR(7)         NOT NULL,
            period_to     CHAR(7)         NOT NULL,
            method        ENUM('transfer','cash','card') NOT NULL DEFAULT 'transfer',
            note          VARCHAR(255)    NULL,
            created_by    VARCHAR(64)     NULL,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_charge_adoption (charge_id, adoption_id),
            KEY idx_adoption_period (adoption_id, period_to),
            CONSTRAINT fk_pay_adoption FOREIGN KEY (adoption_id)
                REFERENCES adopt_adoptions (id) ON DELETE CASCADE,
            CONSTRAINT fk_pay_charge FOREIGN KEY (charge_id)
                REFERENCES charges (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    /* Wiersze importu wymagające ręcznej decyzji (ekran łączenia). */
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS adopt_import_pending (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            kind       VARCHAR(30)     NOT NULL,     -- 'payment-row' | 'donor' | ...
            label      VARCHAR(255)    NOT NULL,     -- np. nazwisko z macierzy wpłat
            payload    MEDIUMTEXT      NOT NULL,     -- JSON surowych danych
            hint       VARCHAR(500)    NULL,         -- podpowiedź dopasowania
            status     ENUM('open','resolved','skipped') NOT NULL DEFAULT 'open',
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME       NULL,
            PRIMARY KEY (id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS fin_flows (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            flow_date         DATE            NOT NULL,
            direction         ENUM('in','out') NOT NULL,
            category          ENUM('adopcja','zbiorka','darowizna','wyplata_adopcja',
                                   'wyplata_jedzenie','wyplata_studnia','koszt_statutowy',
                                   'koszt_administracyjny','wymiana_walut','inne') NOT NULL,
            amount_grosze     BIGINT          NOT NULL,
            currency          CHAR(3)         NOT NULL DEFAULT 'PLN',
            fx_rate           DECIMAL(10,4)   NULL,
            amount_pln_grosze BIGINT          NULL,
            method            ENUM('przelew','gotowka') NOT NULL DEFAULT 'przelew',
            counterparty      VARCHAR(200)    NULL,
            group_label       VARCHAR(120)    NULL,
            status            ENUM('zaplanowane','wykonane','przekazane') NOT NULL DEFAULT 'wykonane',
            note              VARCHAR(500)    NULL,
            created_by        VARCHAR(64)     NULL,
            created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_date (flow_date),
            KEY idx_cat (category, flow_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS panel_audit_log (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user       VARCHAR(64)     NULL,
            action     VARCHAR(40)     NOT NULL,
            entity     VARCHAR(30)     NOT NULL,
            entity_id  BIGINT UNSIGNED NULL,
            details    TEXT            NULL,
            ip         VARCHAR(45)     NULL,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_entity (entity, entity_id),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS panel_login_attempts (
            ip           VARCHAR(45) NOT NULL,
            fails        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            last_fail_at DATETIME    NOT NULL,
            PRIMARY KEY (ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/* ─────────────────────────────────────────────────────────────────
   Audyt (best-effort - nigdy nie przerywa akcji użytkownika)
  ───────────────────────────────────────────────────────────────── */

function mada_audit(string $action, string $entity, ?int $entityId, array $details = []): void {
    try {
        $user = function_exists('mada_current_user') ? mada_current_user() : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $st = payu_db()->prepare(
            'INSERT INTO panel_audit_log (user, action, entity, entity_id, details, ip)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $user !== null ? mb_substr((string)$user, 0, 64) : null,
            mb_substr($action, 0, 40),
            mb_substr($entity, 0, 30),
            $entityId,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            $ip !== null ? mb_substr((string)$ip, 0, 45) : null,
        ]);
    } catch (Throwable $e) {
        // celowo cicho - audyt nie może blokować pracy panelu
    }
}

/* ─────────────────────────────────────────────────────────────────
   Rate-limit logowania per IP (druga linia za throttlingiem sesyjnym)
  ───────────────────────────────────────────────────────────────── */

const ADOPT_IP_MAX_FAILS   = 10;
const ADOPT_IP_WINDOW_SECS = 900;   // 15 minut

/** Sekundy pozostałej blokady IP (0 = brak blokady). Best-effort: brak DB -> 0. */
function adopt_ip_locked_for(string $ip): int {
    try {
        $st = payu_db()->prepare('SELECT fails, last_fail_at FROM panel_login_attempts WHERE ip = ?');
        $st->execute([$ip]);
        $row = $st->fetch();
        if (!$row || (int)$row['fails'] < ADOPT_IP_MAX_FAILS) return 0;
        $left = ADOPT_IP_WINDOW_SECS - (time() - strtotime($row['last_fail_at']));
        return $left > 0 ? $left : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/** Rejestruje nieudaną próbę logowania z IP (okno przesuwne: stare wpisy zerowane).
 *  Znacznik czasu z zegara PHP (nie NOW() bazy) - zapis i odczyt muszą używać
 *  tego samego zegara/strefy, inaczej blokada trwa o offset stref za długo. */
function adopt_ip_register_fail(string $ip): void {
    try {
        $pdo = payu_db();
        $st = $pdo->prepare('SELECT fails, last_fail_at FROM panel_login_attempts WHERE ip = ?');
        $st->execute([$ip]);
        $row = $st->fetch();
        $fails = 1;
        if ($row && (time() - strtotime($row['last_fail_at'])) < ADOPT_IP_WINDOW_SECS) {
            $fails = (int)$row['fails'] + 1;
        }
        $up = $pdo->prepare(
            'INSERT INTO panel_login_attempts (ip, fails, last_fail_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE fails = VALUES(fails), last_fail_at = VALUES(last_fail_at)'
        );
        $up->execute([mb_substr($ip, 0, 45), $fails, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        // best-effort
    }
}

/** Czyści licznik po udanym logowaniu. */
function adopt_ip_clear(string $ip): void {
    try {
        $st = payu_db()->prepare('DELETE FROM panel_login_attempts WHERE ip = ?');
        $st->execute([$ip]);
    } catch (Throwable $e) {
        // best-effort
    }
}

/* ─────────────────────────────────────────────────────────────────
   CRUD - dzieci
  ───────────────────────────────────────────────────────────────── */

/** Wstawia lub aktualizuje dziecko po numerze (idempotentny import). Zwraca id. */
function adopt_child_upsert(int $number, string $name, ?string $notes = null): int {
    $pdo = payu_db();
    $st = $pdo->prepare('SELECT id FROM adopt_children WHERE number = ?');
    $st->execute([$number]);
    $id = $st->fetchColumn();
    if ($id !== false) {
        $up = $pdo->prepare('UPDATE adopt_children SET name = ?, notes = COALESCE(?, notes) WHERE id = ?');
        $up->execute([$name, $notes, (int)$id]);
        return (int)$id;
    }
    $in = $pdo->prepare('INSERT INTO adopt_children (number, name, notes) VALUES (?, ?, ?)');
    $in->execute([$number, $name, $notes]);
    return (int)$pdo->lastInsertId();
}

function adopt_child_by_number(int $number): ?array {
    $st = payu_db()->prepare('SELECT * FROM adopt_children WHERE number = ?');
    $st->execute([$number]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Lista dzieci z aktualnym darczyńcą (adopcje pending/active). */
function adopt_child_list(): array {
    $sql = "SELECT c.*,
                   GROUP_CONCAT(DISTINCT d.full_name ORDER BY d.full_name SEPARATOR '; ') AS donors,
                   MAX(a.materials_sent) AS materials_sent
              FROM adopt_children c
              LEFT JOIN adopt_adoptions a
                     ON a.child_id = c.id AND a.status IN ('pending','active')
              LEFT JOIN adopt_donors d ON d.id = a.donor_id
             GROUP BY c.id
             ORDER BY c.number";
    return payu_db()->query($sql)->fetchAll();
}

/* ─────────────────────────────────────────────────────────────────
   CRUD - darczyńcy
  ───────────────────────────────────────────────────────────────── */

function adopt_donor_insert(array $d): int {
    $pdo = payu_db();
    $st = $pdo->prepare(
        'INSERT INTO adopt_donors (full_name, email, emails_extra, phone, source, notes)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $d['full_name'],
        $d['email'] ?? null,
        $d['emails_extra'] ?? null,
        $d['phone'] ?? null,
        $d['source'] ?? 'manual',
        $d['notes'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

function adopt_donor_get(int $id): ?array {
    $st = payu_db()->prepare('SELECT * FROM adopt_donors WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Lista darczyńców (opcjonalny filtr po nazwisku/e-mailu) z agregatami adopcji. */
function adopt_donor_list(string $search = ''): array {
    $where = '';
    $args = [];
    if ($search !== '') {
        $where = 'WHERE d.full_name LIKE ? OR d.email LIKE ? OR d.emails_extra LIKE ?';
        $like = '%' . $search . '%';
        $args = [$like, $like, $like];
    }
    $sql = "SELECT d.*,
                   COUNT(a.id) AS adoptions_cnt,
                   GROUP_CONCAT(DISTINCT c.name ORDER BY c.number SEPARATOR '; ') AS children_names,
                   GROUP_CONCAT(DISTINCT c.number ORDER BY c.number SEPARATOR ', ') AS children_numbers,
                   GROUP_CONCAT(DISTINCT a.method) AS methods
              FROM adopt_donors d
              LEFT JOIN adopt_adoptions a
                     ON a.donor_id = d.id AND a.status IN ('pending','active')
              LEFT JOIN adopt_children c ON c.id = a.child_id
             $where
             GROUP BY d.id
             ORDER BY d.full_name";
    $st = payu_db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/* ─────────────────────────────────────────────────────────────────
   CRUD - adopcje i wpłaty
  ───────────────────────────────────────────────────────────────── */

function adopt_adoption_insert(array $d): int {
    $pdo = payu_db();
    $st = $pdo->prepare(
        'INSERT INTO adopt_adoptions
            (donor_id, child_id, subscription_id, duration, start_month, end_month,
             frequency, amount_grosze, method, status, materials_sent, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $d['donor_id'],
        $d['child_id'] ?? null,
        $d['subscription_id'] ?? null,
        $d['duration'] ?? 'indefinite',
        $d['start_month'] ?? null,
        $d['end_month'] ?? null,
        $d['frequency'] ?? 'monthly',
        $d['amount_grosze'] ?? 7000,
        $d['method'] ?? 'transfer',
        $d['status'] ?? 'active',
        !empty($d['materials_sent']) ? 1 : 0,
        $d['notes'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

/** Wszystkie adopcje z darczyńcą i dzieckiem (do selectów i list). */
function adopt_adoption_list_all(): array {
    return payu_db()->query(
        "SELECT a.*, d.full_name AS donor_name, c.number AS child_number, c.name AS child_name
           FROM adopt_adoptions a
           JOIN adopt_donors d ON d.id = a.donor_id
           LEFT JOIN adopt_children c ON c.id = a.child_id
          ORDER BY d.full_name, c.number"
    )->fetchAll();
}

/** Uzupełnia start adopcji, jeśli nieznany (np. po dopięciu pierwszych wpłat). */
function adopt_adoption_backfill_start(int $adoptionId): void {
    $st = payu_db()->prepare(
        "UPDATE adopt_adoptions a
            SET a.start_month = (SELECT MIN(p.period_from) FROM adopt_payments p WHERE p.adoption_id = a.id)
          WHERE a.id = ? AND a.start_month IS NULL"
    );
    $st->execute([$adoptionId]);
}

/** Adopcje darczyńcy (z dzieckiem). */
function adopt_adoptions_by_donor(int $donorId): array {
    $st = payu_db()->prepare(
        "SELECT a.*, c.number AS child_number, c.name AS child_name
           FROM adopt_adoptions a
           LEFT JOIN adopt_children c ON c.id = a.child_id
          WHERE a.donor_id = ?
          ORDER BY a.id"
    );
    $st->execute([$donorId]);
    return $st->fetchAll();
}

function adopt_payment_insert(array $d): int {
    $pdo = payu_db();
    $st = $pdo->prepare(
        'INSERT INTO adopt_payments
            (adoption_id, charge_id, amount_grosze, paid_at, period_from, period_to,
             method, note, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $d['adoption_id'],
        $d['charge_id'] ?? null,
        $d['amount_grosze'],
        $d['paid_at'],
        $d['period_from'],
        $d['period_to'],
        $d['method'] ?? 'transfer',
        isset($d['note']) ? mb_substr((string)$d['note'], 0, 255) : null,
        $d['created_by'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

function adopt_payments_by_adoption(int $adoptionId): array {
    $st = payu_db()->prepare(
        'SELECT * FROM adopt_payments WHERE adoption_id = ? ORDER BY period_from, id'
    );
    $st->execute([$adoptionId]);
    return $st->fetchAll();
}

/** Wpłaty dla wielu adopcji naraz (mapa adoption_id => wiersze) - do list i macierzy. */
function adopt_payments_by_adoptions(array $adoptionIds): array {
    if (!$adoptionIds) return [];
    $ids = array_map('intval', $adoptionIds);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = payu_db()->prepare(
        "SELECT * FROM adopt_payments WHERE adoption_id IN ($ph) ORDER BY period_from, id"
    );
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() as $row) $out[(int)$row['adoption_id']][] = $row;
    return $out;
}

/* ─────────────────────────────────────────────────────────────────
   Import - wiersze oczekujące na ręczną decyzję
  ───────────────────────────────────────────────────────────────── */

function adopt_pending_insert(string $kind, string $label, array $payload, ?string $hint = null): int {
    $pdo = payu_db();
    $st = $pdo->prepare(
        'INSERT INTO adopt_import_pending (kind, label, payload, hint) VALUES (?, ?, ?, ?)'
    );
    $st->execute([
        mb_substr($kind, 0, 30),
        mb_substr($label, 0, 255),
        json_encode($payload, JSON_UNESCAPED_UNICODE),
        $hint !== null ? mb_substr($hint, 0, 500) : null,
    ]);
    return (int)$pdo->lastInsertId();
}

function adopt_pending_open(): array {
    return payu_db()->query(
        "SELECT * FROM adopt_import_pending WHERE status = 'open' ORDER BY id"
    )->fetchAll();
}

function adopt_pending_get(int $id): ?array {
    $st = payu_db()->prepare('SELECT * FROM adopt_import_pending WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function adopt_pending_resolve(int $id, string $status): void {
    $st = payu_db()->prepare(
        "UPDATE adopt_import_pending SET status = ?, resolved_at = NOW() WHERE id = ? AND status = 'open'"
    );
    $st->execute([$status === 'skipped' ? 'skipped' : 'resolved', $id]);
}

/* ── Statystyki (dashboard / import) ───────────────────────────── */

function adopt_counts(): array {
    $pdo = payu_db();
    return [
        'children'  => (int)$pdo->query('SELECT COUNT(*) FROM adopt_children')->fetchColumn(),
        'donors'    => (int)$pdo->query('SELECT COUNT(*) FROM adopt_donors')->fetchColumn(),
        'adoptions' => (int)$pdo->query("SELECT COUNT(*) FROM adopt_adoptions WHERE status IN ('pending','active')")->fetchColumn(),
        'payments'  => (int)$pdo->query('SELECT COUNT(*) FROM adopt_payments')->fetchColumn(),
        'pending'   => (int)$pdo->query("SELECT COUNT(*) FROM adopt_import_pending WHERE status = 'open'")->fetchColumn(),
    ];
}
