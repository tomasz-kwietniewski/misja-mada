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

    /* Zgłoszenia z formularza przelewowego (double opt-in po stronie PHP). */
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS adopt_signups (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token        CHAR(64)     NOT NULL,
            email        VARCHAR(255) NOT NULL,
            payload      MEDIUMTEXT   NOT NULL,
            status       ENUM('pending','confirmed','expired') NOT NULL DEFAULT 'pending',
            ip           VARCHAR(45)  NULL,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            confirmed_at DATETIME     NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_token (token),
            KEY idx_status (status, created_at),
            KEY idx_ip (ip, created_at)
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

function adopt_donor_update(int $id, array $d): void {
    $st = payu_db()->prepare(
        'UPDATE adopt_donors SET full_name = ?, email = ?, emails_extra = ?, phone = ?, notes = ? WHERE id = ?'
    );
    $st->execute([
        $d['full_name'],
        $d['email'] !== '' ? $d['email'] : null,
        $d['emails_extra'] !== '' ? $d['emails_extra'] : null,
        $d['phone'] !== '' ? $d['phone'] : null,
        $d['notes'] !== '' ? $d['notes'] : null,
        $id,
    ]);
}

function adopt_adoption_get(int $id): ?array {
    $st = payu_db()->prepare(
        "SELECT a.*, d.full_name AS donor_name, c.number AS child_number, c.name AS child_name
           FROM adopt_adoptions a
           JOIN adopt_donors d ON d.id = a.donor_id
           LEFT JOIN adopt_children c ON c.id = a.child_id
          WHERE a.id = ?"
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function adopt_adoption_update(int $id, array $d): void {
    $st = payu_db()->prepare(
        'UPDATE adopt_adoptions
            SET child_id = ?, subscription_id = ?, duration = ?, start_month = ?, end_month = ?,
                frequency = ?, amount_grosze = ?, method = ?, materials_sent = ?, notes = ?
          WHERE id = ?'
    );
    $st->execute([
        $d['child_id'] ?? null,
        $d['subscription_id'] ?? null,
        $d['duration'],
        $d['start_month'] ?? null,
        $d['end_month'] ?? null,
        $d['frequency'],
        $d['amount_grosze'],
        $d['method'],
        !empty($d['materials_sent']) ? 1 : 0,
        ($d['notes'] ?? '') !== '' ? $d['notes'] : null,
        $id,
    ]);
}

/** Zakończenie adopcji (przerwa/rezygnacja): zamyka okres - zaległości przestają rosnąć. */
function adopt_adoption_end(int $id, string $endMonth, string $status = 'ended'): void {
    $st = payu_db()->prepare(
        "UPDATE adopt_adoptions SET status = ?, end_month = ?, ended_at = NOW()
          WHERE id = ? AND status IN ('pending','active')"
    );
    $st->execute([$status === 'cancelled' ? 'cancelled' : 'ended', $endMonth, $id]);
}

/**
 * Wznowienie po przerwie: NOWY wiersz adopcji tego samego darczyńcy i dziecka
 * (historia i wpłaty starego okresu zostają; miesiące przerwy nie liczą się
 * jako zaległość). Zwraca id nowej adopcji.
 */
function adopt_adoption_resume(int $oldId, string $startMonth): int {
    $old = adopt_adoption_get($oldId);
    if (!$old) throw new RuntimeException('Brak adopcji do wznowienia.');
    return adopt_adoption_insert([
        'donor_id'      => (int)$old['donor_id'],
        'child_id'      => $old['child_id'] !== null ? (int)$old['child_id'] : null,
        'duration'      => 'indefinite',
        'start_month'   => $startMonth,
        'frequency'     => $old['frequency'],
        'amount_grosze' => (int)$old['amount_grosze'],
        'method'        => $old['method'],
        'status'        => 'active',
        'materials_sent'=> (int)$old['materials_sent'] === 1,
        'notes'         => 'Wznowienie adopcji #' . (int)$old['id'],
    ]);
}

function adopt_payment_get(int $id): ?array {
    $st = payu_db()->prepare('SELECT * FROM adopt_payments WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function adopt_payment_delete(int $id): void {
    $st = payu_db()->prepare('DELETE FROM adopt_payments WHERE id = ?');
    $st->execute([$id]);
}

/* ─────────────────────────────────────────────────────────────────
   Integracja z PayU (subscriptions/charges)
  ───────────────────────────────────────────────────────────────── */

/** Subskrypcje adopcyjne bez powiązanej adopcji (do selecta w edycji adopcji). */
function adopt_subscription_candidates(?int $includeSubId = null): array {
    $sql = "SELECT s.* FROM subscriptions s
             WHERE s.goal = 'adopcja'
               AND (s.id NOT IN (SELECT subscription_id FROM adopt_adoptions WHERE subscription_id IS NOT NULL)"
         . ($includeSubId !== null ? ' OR s.id = ' . (int)$includeSubId : '')
         . ") ORDER BY s.created_at DESC";
    return payu_db()->query($sql)->fetchAll();
}

/**
 * Dopisuje wpłatę adopcyjną z raty kartowej PayU do adopcji powiązanych
 * z subskrypcją. Best-effort (woła notify.php - nie może wywrócić płatności).
 * $periodYm = 'YYYY-MM' (miesiąc raty), $chargeId = wiersz charges (null dla FIRST).
 * Idempotencja: charge_id przez UNIQUE(charge_id, adoption_id); FIRST (charge_id
 * NULL) przez sprawdzenie istniejącej wpłaty kartowej za ten miesiąc.
 */
function adopt_payment_from_charge(array $sub, ?int $chargeId, string $periodYm): void {
    if (($sub['goal'] ?? '') !== 'adopcja' || !adopt_month_valid($periodYm)) return;
    $st = payu_db()->prepare(
        "SELECT * FROM adopt_adoptions WHERE subscription_id = ? AND status IN ('pending','active') ORDER BY id"
    );
    $st->execute([(int)$sub['id']]);
    $ads = $st->fetchAll();
    if (!$ads) return;   // fundacja jeszcze nie powiązała - nadrobi backfill przy powiązaniu

    $total = (int)$sub['amount_grosze'];
    $n = count($ads);
    $base = intdiv($total, $n);
    $rest = $total - $base * $n;   // reszta z dzielenia -> pierwsza adopcja

    $dupe = payu_db()->prepare(
        "SELECT id FROM adopt_payments WHERE adoption_id = ? AND period_from = ? AND method = 'card' LIMIT 1"
    );
    foreach ($ads as $i => $a) {
        try {
            if ($chargeId === null) {
                $dupe->execute([(int)$a['id'], $periodYm]);
                if ($dupe->fetchColumn() !== false) continue;
            }
            adopt_payment_insert([
                'adoption_id'   => (int)$a['id'],
                'charge_id'     => $chargeId,
                'amount_grosze' => $base + ($i === 0 ? $rest : 0),
                'paid_at'       => date('Y-m-d'),
                'period_from'   => $periodYm,
                'period_to'     => $periodYm,
                'method'        => 'card',
                'note'          => $chargeId === null ? 'PayU: pierwsza płatność' : 'PayU: rata cykliczna',
            ]);
            adopt_adoption_backfill_start((int)$a['id']);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;   // duplikat raty -> cicho pomiń
        }
    }
}

/**
 * Nadrabia wpłaty z historii subskrypcji (pierwsza płatność + completed charges).
 * Wołane przy powiązywaniu subskrypcji z adopcją w panelu. Idempotentne.
 */
function adopt_backfill_subscription(int $subId): int {
    require_once __DIR__ . '/../payu/recurring-lib.php';
    $sub = payu_sub_get($subId);
    if (!$sub || ($sub['goal'] ?? '') !== 'adopcja') return 0;
    $added = 0;

    // pierwsza płatność = miesiąc startu subskrypcji
    if ((int)$sub['months_paid'] >= 1 && !empty($sub['start_date'])) {
        $m = adopt_month_from_date((string)$sub['start_date']);
        if ($m !== null) {
            $before = adopt_sub_payment_count($subId);
            adopt_payment_from_charge($sub, null, $m);
            $added += adopt_sub_payment_count($subId) - $before;
        }
    }
    // raty cykliczne completed (miesiąc z extOrderId)
    $st = payu_db()->prepare("SELECT * FROM charges WHERE subscription_id = ? AND status = 'completed' ORDER BY id");
    $st->execute([$subId]);
    foreach ($st->fetchAll() as $ch) {
        $cls = mada_sub_classify_ext((string)$ch['ext_order_id']);
        if (($cls['period'] ?? null) === null) continue;
        $m = substr($cls['period'], 0, 4) . '-' . substr($cls['period'], 4, 2);
        $before = adopt_sub_payment_count($subId);
        adopt_payment_from_charge($sub, (int)$ch['id'], $m);
        $added += adopt_sub_payment_count($subId) - $before;
    }
    return $added;
}

/** Liczba wpłat kartowych podpiętych pod adopcje danej subskrypcji (do raportu backfillu). */
function adopt_sub_payment_count(int $subId): int {
    $st = payu_db()->prepare(
        "SELECT COUNT(*) FROM adopt_payments p
           JOIN adopt_adoptions a ON a.id = p.adoption_id
          WHERE a.subscription_id = ? AND p.method = 'card'"
    );
    $st->execute([$subId]);
    return (int)$st->fetchColumn();
}

/** Adopcje powiązane z subskrypcją (do akcji anulowania w subskrypcje.php). */
function adopt_adoptions_by_subscription(int $subId): array {
    $st = payu_db()->prepare(
        "SELECT * FROM adopt_adoptions WHERE subscription_id = ? AND status IN ('pending','active')"
    );
    $st->execute([$subId]);
    return $st->fetchAll();
}

/* ─────────────────────────────────────────────────────────────────
   Finanse misyjne (fin_flows) - rejestr przepływów na koncie
  ───────────────────────────────────────────────────────────────── */

const FIN_CATEGORIES = [
    'adopcja'               => 'Adopcja Serca (wpływ)',
    'zbiorka'               => 'Zbiórka (parafie)',
    'darowizna'             => 'Darowizna',
    'wyplata_adopcja'       => 'Wypłata do Sióstr - adopcja',
    'wyplata_jedzenie'      => 'Wypłata do Sióstr - jedzenie',
    'wyplata_studnia'       => 'Wypłata do Sióstr - studnia',
    'koszt_statutowy'       => 'Koszt statutowy',
    'koszt_administracyjny' => 'Koszt administracyjny',
    'wymiana_walut'         => 'Wymiana walut',
    'inne'                  => 'Inne',
];

function fin_flow_insert(array $d): int {
    $pdo = payu_db();
    $st = $pdo->prepare(
        'INSERT INTO fin_flows (flow_date, direction, category, amount_grosze, currency,
             fx_rate, amount_pln_grosze, method, counterparty, group_label, status, note, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $d['flow_date'],
        $d['direction'] === 'out' ? 'out' : 'in',
        isset(FIN_CATEGORIES[$d['category'] ?? '']) ? $d['category'] : 'inne',
        (int)$d['amount_grosze'],
        strtoupper(substr((string)($d['currency'] ?? 'PLN'), 0, 3)),
        $d['fx_rate'] ?? null,
        $d['amount_pln_grosze'] ?? null,
        ($d['method'] ?? '') === 'gotowka' ? 'gotowka' : 'przelew',
        ($d['counterparty'] ?? '') !== '' ? mb_substr((string)$d['counterparty'], 0, 200) : null,
        ($d['group_label'] ?? '') !== '' ? mb_substr((string)$d['group_label'], 0, 120) : null,
        in_array($d['status'] ?? '', ['zaplanowane', 'wykonane', 'przekazane'], true) ? $d['status'] : 'wykonane',
        ($d['note'] ?? '') !== '' ? mb_substr((string)$d['note'], 0, 500) : null,
        $d['created_by'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

function fin_flow_get(int $id): ?array {
    $st = payu_db()->prepare('SELECT * FROM fin_flows WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function fin_flow_delete(int $id): void {
    payu_db()->prepare('DELETE FROM fin_flows WHERE id = ?')->execute([$id]);
}

/** Lista przepływów z filtrami (rok, kategoria, kierunek). */
function fin_flow_list(?int $year = null, string $category = '', string $direction = ''): array {
    $where = [];
    $args = [];
    if ($year !== null) { $where[] = 'YEAR(flow_date) = ?'; $args[] = $year; }
    if (isset(FIN_CATEGORIES[$category])) { $where[] = 'category = ?'; $args[] = $category; }
    if (in_array($direction, ['in', 'out'], true)) { $where[] = 'direction = ?'; $args[] = $direction; }
    $sql = 'SELECT * FROM fin_flows'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY flow_date DESC, id DESC LIMIT 1000';
    $st = payu_db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** Sumy roczne per kategoria w PLN (amount_pln_grosze, dla PLN amount_grosze). */
function fin_flow_sums(int $year): array {
    $st = payu_db()->prepare(
        "SELECT category, direction,
                SUM(COALESCE(amount_pln_grosze, IF(currency = 'PLN', amount_grosze, 0))) AS pln,
                SUM(IF(currency <> 'PLN' AND amount_pln_grosze IS NULL, 1, 0)) AS unconverted
           FROM fin_flows
          WHERE YEAR(flow_date) = ?
          GROUP BY category, direction
          ORDER BY category"
    );
    $st->execute([$year]);
    return $st->fetchAll();
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
