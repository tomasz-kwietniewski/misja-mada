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

/** Dokłada brakujące kolumny (idempotentnie, zgodne z MySQL i MariaDB). */
function adopt_db_add_columns(PDO $pdo, string $table, array $cols): void {
    $st = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $st->execute([$table]);
    $have = $st->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cols as $name => $ddl) {
        if (!in_array($name, $have, true)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$name` $ddl");
        }
    }
}

/** Usuwa wycofane kolumny (idempotentnie, zgodne z MySQL i MariaDB). */
function adopt_db_drop_columns(PDO $pdo, string $table, array $cols): void {
    $st = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $st->execute([$table]);
    $have = $st->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cols as $name) {
        if (in_array($name, $have, true)) {
            $pdo->exec("ALTER TABLE `$table` DROP COLUMN `$name`");
        }
    }
}

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

    /* Dossier dziecka (wzór: PDF wysyłany darczyńcom) - kolumny dokładane do
       istniejących instalacji przez ALTER (MySQL 8 nie zna ADD COLUMN IF NOT EXISTS). */
    adopt_db_add_columns($pdo, 'adopt_children', [
        'dossier_name' => "VARCHAR(200) NULL",       // pełne imię i nazwisko (np. Avotriniaina Alvin RAKOTOZANANY)
        'birth_date'   => "DATE NULL",
        'father'       => "VARCHAR(150) NULL",
        'mother'       => "VARCHAR(150) NULL",
        'siblings'     => "SMALLINT UNSIGNED NULL",  // ilość dzieci w rodzinie
        'description'  => "TEXT NULL",               // opis sytuacji dziecka
        'photo'        => "VARCHAR(120) NULL",       // nazwa pliku w uploads/dzieci/
    ]);

    /* Kolumna „materiały wysłane" wycofana (2026-08-03): arkusz fundacji prowadził
       ją tylko dla GR1, więc dla pozostałych grup dawała fałszywe „nie". */
    adopt_db_drop_columns($pdo, 'adopt_adoptions', ['materials_sent']);

    /* Ślad wysyłki dossier (2026-08-11). Nie ma nic wspólnego z wycofanym
       `materials_sent` z arkusza: tę kolumnę zapisuje WYŁĄCZNIE panel w chwili
       realnej wysyłki maila, więc „nie wysłano" zawsze znaczy „naprawdę nie
       wysłano". Licznik zostaje, bo dossier wolno ponowić. */
    adopt_db_add_columns($pdo, 'adopt_adoptions', [
        'dossier_sent_at'    => 'DATETIME NULL',
        'dossier_sent_by'    => 'VARCHAR(64) NULL',
        'dossier_sent_count' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
    ]);

    /* Dane kontaktowe darczyńcy rozbite na pola (2026-08-11): fundacja drukuje
       z nich adresy korespondencyjne, a dotąd adres lądował w wolnym tekście
       `notes` („Adres: …") i nie było go widać w panelu ani w eksporcie.
       Imię i nazwisko osobno TYLKO dla osób - `full_name` zostaje nazwą
       wyświetlaną, bo w bazie są instytucje („Parafia Kłodzko") i małżeństwa. */
    adopt_db_add_columns($pdo, 'adopt_donors', [
        'first_name'   => 'VARCHAR(100) NULL',
        'last_name'    => 'VARCHAR(100) NULL',
        'street'       => 'VARCHAR(160) NULL',
        'house_no'     => 'VARCHAR(30)  NULL',
        'postcode'     => 'VARCHAR(12)  NULL',
        'city'         => 'VARCHAR(120) NULL',
        // Ślad AUDYTOWY: „przy tym zgłoszeniu wykryliśmy kolizję adresu". Widoki NIE
        // opierają się na tej kolumnie (liczą stan bazy na bieżąco - patrz
        // adopt_donor_list i adopt_donors_sharing_email), bo rekordy sprzed
        // wprowadzenia flagi nigdy by jej nie dostały.
        'shared_email' => 'TINYINT(1) NOT NULL DEFAULT 0',
        // Ręczne archiwum darczyńcy (2026-08-12). Dochodzi DO reguły automatycznej
        // („miał adopcje, żadna już nie trwa"), a nie zamiast niej: pozwala schować
        // z listy kogoś, kto zgłosił się i wycofał przed przypisaniem dziecka -
        // wcześniej taki wpis dało się tylko usunąć razem z danymi kontaktowymi.
        'archived_at'  => 'DATETIME NULL',
        'archived_by'  => 'VARCHAR(64) NULL',
    ]);

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

    /* Log wysłanych przypomnień o zaległościach - decyduje o ponawianiu
       (co 14 dni na darczyńcę) i daje fundacji ślad, co poszło i kiedy. */
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS adopt_reminders (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            donor_id      BIGINT UNSIGNED NOT NULL,
            sent_at       DATETIME        NOT NULL,
            months_total  SMALLINT UNSIGNED NOT NULL,
            amount_grosze INT UNSIGNED    NOT NULL,
            detail        VARCHAR(500)    NULL,
            PRIMARY KEY (id),
            KEY idx_donor_sent (donor_id, sent_at),
            CONSTRAINT fk_rem_donor FOREIGN KEY (donor_id)
                REFERENCES adopt_donors (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    /* Wiersze importu wymagające ręcznej decyzji. Ekrany importu zostały
       usunięte po domkniętej migracji (2026-08-03), ale tabela ZOSTAJE:
       trzyma historię 21 decyzji podjętych przy przenoszeniu danych z arkuszy.
       Przy ewentualnym ponownym imporcie przywrócić `panel/import*.php`
       z historii gita - schemat jest gotowy. */
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

    /* Operacje wczytane z wyciągu bankowego - POCZEKALNIA, nie księga.
       Wiersz żyje tu od wgrania pliku do decyzji pracownika (wpłata / przepływ
       / pomiń); `op_hash` chroni przed zdublowaniem przy ponownym imporcie
       tego samego okresu. Kwota ZE ZNAKIEM (wydatek ujemny), stąd BIGINT. */
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS adopt_bank_ops (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            op_hash       CHAR(40)        NOT NULL,
            op_date       DATE            NOT NULL,
            amount_grosze BIGINT          NOT NULL,
            currency      CHAR(3)         NOT NULL DEFAULT 'PLN',
            title         VARCHAR(255)    NULL,
            party         VARCHAR(200)    NULL,
            account       VARCHAR(40)     NULL,
            account_key   VARCHAR(34)     NULL,
            status        ENUM('open','payment','flow','skipped') NOT NULL DEFAULT 'open',
            target_id     BIGINT UNSIGNED NULL,        -- id wpłaty albo przepływu
            imported_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            imported_by   VARCHAR(64)     NULL,
            resolved_at   DATETIME        NULL,
            resolved_by   VARCHAR(64)     NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_op_hash (op_hash),
            KEY idx_status (status, op_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    /* Rachunki darczyńców potwierdzone przy imporcie - dzięki temu kolejne
       wpłaty tej samej osoby dopasowują się same, nawet przy byle jakim
       tytule przelewu. Numer rachunku to dana osobowa: trzymamy sam klucz
       cyfrowy, wyłącznie do dopasowań, i znika razem z darczyńcą (kaskada). */
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS adopt_donor_accounts (
            account_key VARCHAR(34)     NOT NULL,
            donor_id    BIGINT UNSIGNED NOT NULL,
            label       VARCHAR(120)    NULL,          -- jak podpisał się nadawca
            added_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            added_by    VARCHAR(64)     NULL,
            PRIMARY KEY (account_key),
            KEY idx_donor (donor_id),
            CONSTRAINT fk_acc_donor FOREIGN KEY (donor_id)
                REFERENCES adopt_donors (id) ON DELETE CASCADE
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

function adopt_child_get(int $id): ?array {
    $st = payu_db()->prepare('SELECT * FROM adopt_children WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Edycja dziecka (dane podstawowe + dossier). Zwraca false, gdy nowy numer
 *  jest zajęty przez inne dziecko. Klucz 'photo' aktualizowany tylko, gdy podany. */
function adopt_child_update(int $id, array $d): bool {
    $pdo = payu_db();
    $st = $pdo->prepare('SELECT id FROM adopt_children WHERE number = ? AND id <> ? LIMIT 1');
    $st->execute([(int)$d['number'], $id]);
    if ($st->fetchColumn() !== false) return false;
    $photoSql = array_key_exists('photo', $d) ? ', photo = :photo' : '';
    $up = $pdo->prepare(
        "UPDATE adopt_children
            SET number = :number, name = :name, status = :status, notes = :notes,
                dossier_name = :dossier_name, birth_date = :birth_date,
                father = :father, mother = :mother, siblings = :siblings,
                description = :description$photoSql
          WHERE id = :id"
    );
    $args = [
        ':number' => (int)$d['number'],
        ':name' => $d['name'],
        ':status' => ($d['status'] ?? '') === 'inactive' ? 'inactive' : 'active',
        ':notes' => ($d['notes'] ?? '') !== '' ? $d['notes'] : null,
        ':dossier_name' => ($d['dossier_name'] ?? '') !== '' ? $d['dossier_name'] : null,
        ':birth_date' => ($d['birth_date'] ?? '') !== '' ? $d['birth_date'] : null,
        ':father' => ($d['father'] ?? '') !== '' ? $d['father'] : null,
        ':mother' => ($d['mother'] ?? '') !== '' ? $d['mother'] : null,
        ':siblings' => ($d['siblings'] ?? '') !== '' ? (int)$d['siblings'] : null,
        ':description' => ($d['description'] ?? '') !== '' ? $d['description'] : null,
        ':id' => $id,
    ];
    if (array_key_exists('photo', $d)) $args[':photo'] = $d['photo'];
    $up->execute($args);
    return true;
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
                   GROUP_CONCAT(DISTINCT d.id) AS donor_ids
              FROM adopt_children c
              LEFT JOIN adopt_adoptions a
                     ON a.child_id = c.id AND a.status IN ('pending','active')
              LEFT JOIN adopt_donors d ON d.id = a.donor_id
             GROUP BY c.id
             ORDER BY c.number";
    return payu_db()->query($sql)->fetchAll();
}

/**
 * Adopcje danego dziecka wraz z darczyńcą - do bloku „opiekun" na karcie dziecka.
 * Zwraca też zakończone okresy (historia opieki nad dzieckiem bywa potrzebna),
 * posortowane od najnowszych.
 */
function adopt_adoptions_by_child(int $childId): array {
    $st = payu_db()->prepare(
        "SELECT a.*, d.full_name AS donor_name, d.email AS donor_email, d.phone AS donor_phone
           FROM adopt_adoptions a
           JOIN adopt_donors d ON d.id = a.donor_id
          WHERE a.child_id = ?
          ORDER BY FIELD(a.status,'active','pending','ended','cancelled'), a.id DESC"
    );
    $st->execute([$childId]);
    return $st->fetchAll();
}

/* ─────────────────────────────────────────────────────────────────
   CRUD - darczyńcy
  ───────────────────────────────────────────────────────────────── */

/** '' -> null (kolumny opcjonalne trzymamy jako NULL, nie pusty łańcuch). */
function adopt_nn($v): ?string {
    $s = trim((string)($v ?? ''));
    return $s === '' ? null : $s;
}

function adopt_donor_insert(array $d): int {
    $pdo = payu_db();
    $st = $pdo->prepare(
        'INSERT INTO adopt_donors (full_name, first_name, last_name, email, emails_extra, phone,
             street, house_no, postcode, city, source, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $d['full_name'],
        adopt_nn($d['first_name'] ?? null),
        adopt_nn($d['last_name'] ?? null),
        adopt_nn($d['email'] ?? null),
        adopt_nn($d['emails_extra'] ?? null),
        adopt_nn($d['phone'] ?? null),
        adopt_nn($d['street'] ?? null),
        adopt_nn($d['house_no'] ?? null),
        adopt_nn($d['postcode'] ?? null),
        adopt_nn($d['city'] ?? null),
        $d['source'] ?? 'manual',
        adopt_nn($d['notes'] ?? null),
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Darczyńca do zgłoszenia z formularza: dopina do istniejącego rekordu tylko
 * wtedy, gdy zgadza się e-mail ORAZ nazwa (adopt_same_donor). Ten sam e-mail
 * przy innej osobie tworzy OSOBNEGO darczyńcę - oba rekordy dostają wtedy
 * znacznik `shared_email`, żeby panel mógł ostrzec pracownika.
 * Zwraca [id darczyńcy, czy nowy, czy e-mail jest współdzielony].
 */
function adopt_donor_for_signup(array $d): array {
    $pdo = payu_db();
    $email = trim((string)($d['email'] ?? ''));
    $fullName = trim((string)$d['full_name']);
    $sameEmail = [];
    if ($email !== '') {
        $st = $pdo->prepare('SELECT id, full_name FROM adopt_donors WHERE email = ? ORDER BY id');
        $st->execute([$email]);
        $sameEmail = $st->fetchAll();
    }
    foreach ($sameEmail as $row) {
        if (adopt_same_donor((string)$row['full_name'], $fullName)) {
            // Uzupełnia dane, których wcześniej nie było (nie nadpisuje wypełnionych).
            adopt_donor_fill_missing((int)$row['id'], $d);
            return [(int)$row['id'], false, count($sameEmail) > 1];
        }
    }
    $newId = adopt_donor_insert($d);
    if ($sameEmail) {
        $ids = array_merge(array_map(fn($r) => (int)$r['id'], $sameEmail), [$newId]);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE adopt_donors SET shared_email = 1 WHERE id IN ($ph)")->execute($ids);
        mada_audit('donor.sharedemail', 'donor', $newId, ['email' => $email, 'z' => $ids]);
    }
    return [$newId, true, (bool)$sameEmail];
}

/** Uzupełnia PUSTE pola darczyńcy danymi ze zgłoszenia (nigdy nie nadpisuje). */
function adopt_donor_fill_missing(int $id, array $d): void {
    $cols = ['first_name', 'last_name', 'phone', 'street', 'house_no', 'postcode', 'city'];
    $set = []; $args = [];
    foreach ($cols as $c) {
        $v = adopt_nn($d[$c] ?? null);
        if ($v === null) continue;
        $set[] = "`$c` = COALESCE(NULLIF(`$c`, ''), ?)";
        $args[] = $v;
    }
    if (!$set) return;
    $args[] = $id;
    payu_db()->prepare('UPDATE adopt_donors SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
}

/**
 * Wpisy do przeglądu pod kątem retencji (polityka prywatności § 4 pkt 2, od 12.08.2026):
 * zgłoszenie, które NIE doprowadziło do objęcia dziecka wsparciem, usuwa się po roku.
 * Kryterium celowo najostrożniejsze z możliwych: darczyńca, przy którym NIGDY nie było
 * żadnej adopcji (a więc i żadnej wpłaty), starszy niż $months miesięcy. Wpis z choćby
 * zakończoną adopcją to już historia programu - takiego nie ruszamy i panel go nie pokaże.
 *
 * NIE kasujemy automatycznie: usunięcie danych darczyńcy jest nieodwracalne, a decyzja
 * należy do fundacji. Panel tylko wystawia listę do kliknięcia.
 */
function adopt_donors_retention_due(int $months = 12): array {
    $st = payu_db()->prepare(
        "SELECT d.* FROM adopt_donors d
          WHERE NOT EXISTS (SELECT 1 FROM adopt_adoptions a WHERE a.donor_id = d.id)
            AND d.created_at < DATE_SUB(NOW(), INTERVAL ? MONTH)
          ORDER BY d.created_at"
    );
    $st->execute([$months]);
    return $st->fetchAll();
}

/** Inni darczyńcy używający tego samego adresu e-mail (ostrzeżenie w panelu). */
function adopt_donors_sharing_email(?string $email, int $exceptId): array {
    $email = trim((string)$email);
    if ($email === '') return [];
    $st = payu_db()->prepare(
        'SELECT id, full_name FROM adopt_donors WHERE email = ? AND id <> ? ORDER BY full_name'
    );
    $st->execute([$email, $exceptId]);
    return $st->fetchAll();
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
        /* Szuka po nazwisku (osobna kolumna i nazwa wyświetlana), e-mailach,
           telefonie i miejscowości - pracownik zwykle ma pod ręką jedno z nich. */
        $where = 'WHERE d.full_name LIKE ? OR d.last_name LIKE ? OR d.first_name LIKE ?
                     OR d.email LIKE ? OR d.emails_extra LIKE ? OR d.phone LIKE ? OR d.city LIKE ?';
        $like = '%' . $search . '%';
        $args = array_fill(0, 7, $like);
    }
    /* Archiwum darczyńcy ma DWA źródła i jedno nie zastępuje drugiego:
       - automatyczne: MIAŁ adopcje, ale żadna nie jest już aktywna ani oczekująca
         (czyli po „Zakończ") - dzieje się samo, bez klikania,
       - ręczne (`archived_at`): pracownik chowa wpis z listy, np. gdy ktoś zgłosił
         się i wycofał, zanim dostał dziecko.
       Osoba dopiero dodana, jeszcze bez żadnej adopcji, NIE jest archiwalna
       automatycznie - musi być widoczna, żeby dało się jej przypisać dziecko. */
    $sql = "SELECT d.*,
                   COUNT(a.id) AS adoptions_cnt,
                   (SELECT COUNT(*) FROM adopt_adoptions x WHERE x.donor_id = d.id) AS adoptions_total,
                   CASE WHEN d.archived_at IS NOT NULL THEN 1 ELSE 0 END AS is_archived_manual,
                   CASE WHEN d.archived_at IS NOT NULL
                          OR (COUNT(a.id) = 0
                              AND (SELECT COUNT(*) FROM adopt_adoptions x WHERE x.donor_id = d.id) > 0)
                        THEN 1 ELSE 0 END AS is_archived,
                   GROUP_CONCAT(DISTINCT c.name ORDER BY c.number SEPARATOR '; ') AS children_names,
                   GROUP_CONCAT(DISTINCT c.number ORDER BY c.number SEPARATOR ', ') AS children_numbers,
                   GROUP_CONCAT(DISTINCT a.method) AS methods,
                   /* Współdzielony e-mail liczony NA BIEŻĄCO, nie z kolumny `shared_email`:
                      kolumna zapala się dopiero przy nowym zgłoszeniu, więc pary powstałe
                      wcześniej (import z arkusza - w bazie są 4) nigdy by się nie oznaczyły.
                      Podzapytanie idzie po idx_email i zawsze mówi prawdę o stanie bazy. */
                   (SELECT COUNT(*) FROM adopt_donors z
                     WHERE z.email = d.email AND d.email IS NOT NULL AND d.email <> '') > 1 AS email_shared_now,
                   SUM(CASE WHEN a.child_id IS NOT NULL THEN 1 ELSE 0 END) AS with_child_cnt,
                   SUM(CASE WHEN a.child_id IS NOT NULL AND a.dossier_sent_at IS NOT NULL THEN 1 ELSE 0 END) AS dossier_sent_cnt
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
             frequency, amount_grosze, method, status, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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

function adopt_donor_update(int $id, array $d): void {
    $st = payu_db()->prepare(
        'UPDATE adopt_donors
            SET full_name = ?, first_name = ?, last_name = ?, email = ?, emails_extra = ?, phone = ?,
                street = ?, house_no = ?, postcode = ?, city = ?, notes = ?
          WHERE id = ?'
    );
    $st->execute([
        $d['full_name'],
        adopt_nn($d['first_name'] ?? null),
        adopt_nn($d['last_name'] ?? null),
        adopt_nn($d['email'] ?? null),
        adopt_nn($d['emails_extra'] ?? null),
        adopt_nn($d['phone'] ?? null),
        adopt_nn($d['street'] ?? null),
        adopt_nn($d['house_no'] ?? null),
        adopt_nn($d['postcode'] ?? null),
        adopt_nn($d['city'] ?? null),
        adopt_nn($d['notes'] ?? null),
        $id,
    ]);
}

/**
 * Usuwa darczyńcę - WYŁĄCZNIE gdy nie ma żadnej adopcji (a więc i żadnej wpłaty,
 * bo te wiszą przy adopcji). Domyka scalanie duplikatów: adopcje przenosi się
 * do właściwego wpisu, pusty wpis znika z listy. Zwraca false, gdy coś przy nim
 * jeszcze wisi - wtedy nic nie kasujemy.
 */
function adopt_donor_delete_if_empty(int $id): bool {
    $pdo = payu_db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM adopt_adoptions WHERE donor_id = ?');
    $st->execute([$id]);
    if ((int)$st->fetchColumn() > 0) return false;
    $pdo->prepare('DELETE FROM adopt_reminders WHERE donor_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM adopt_donors WHERE id = ?')->execute([$id]);
    return true;
}

/**
 * Ręczne archiwum darczyńcy (bez dotykania jego historii i adopcji).
 * Zdjęcie flagi nie zawsze wyciąga wpis z archiwum - darczyńca z samymi
 * zakończonymi adopcjami zostaje archiwalny automatycznie i tak ma być.
 */
function adopt_donor_set_archived(int $id, bool $archived, ?string $user): void {
    $st = payu_db()->prepare('UPDATE adopt_donors SET archived_at = ?, archived_by = ? WHERE id = ?');
    $st->execute([
        $archived ? date('Y-m-d H:i:s') : null,
        $archived && $user !== null ? mb_substr($user, 0, 64) : null,
        $id,
    ]);
}

/**
 * Czy darczyńca jest archiwalny AUTOMATYCZNIE (miał adopcje, żadna już nie trwa).
 * Karta używa tego, żeby uczciwie powiedzieć, dlaczego wpis jest w archiwum -
 * inaczej „Przywróć" wyglądałoby na przycisk, który nic nie robi.
 */
function adopt_donor_auto_archived(int $id): bool {
    $st = payu_db()->prepare(
        "SELECT COUNT(*) total, SUM(status IN ('pending','active')) otwarte
           FROM adopt_adoptions WHERE donor_id = ?"
    );
    $st->execute([$id]);
    $r = $st->fetch();
    return (int)$r['total'] > 0 && (int)$r['otwarte'] === 0;
}

/** Przełącza dziecko między programem a archiwum (bez dotykania jego historii). */
function adopt_child_set_status(int $id, string $status): void {
    $st = payu_db()->prepare('UPDATE adopt_children SET status = ? WHERE id = ?');
    $st->execute([$status === 'inactive' ? 'inactive' : 'active', $id]);
}

/**
 * Usuwa dziecko - WYŁĄCZNIE gdy nigdy nie miało żadnej adopcji. To furtka na
 * POMYŁKI przy dodawaniu (literówka w numerze, dubel), a nie sposób na wycofanie
 * dziecka z programu - do tego służy archiwum, które zachowuje historię wpłat.
 * Kasuje też zdjęcie z uploads/dzieci/. Zwraca false, gdy dziecko ma adopcje.
 */
function adopt_child_delete_if_unused(int $id): bool {
    $pdo = payu_db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM adopt_adoptions WHERE child_id = ?');
    $st->execute([$id]);
    if ((int)$st->fetchColumn() > 0) return false;
    $child = adopt_child_get($id);
    $pdo->prepare('DELETE FROM adopt_children WHERE id = ?')->execute([$id]);
    if ($child && !empty($child['photo'])) {
        @unlink(__DIR__ . '/../uploads/dzieci/' . basename((string)$child['photo']));
    }
    return true;
}

/** Darczyńcy do selecta „przenieś adopcję" - posortowani po nazwisku. */
function adopt_donor_options(): array {
    $rows = payu_db()->query('SELECT id, full_name, email FROM adopt_donors')->fetchAll();
    return adopt_sort_by_surname($rows);
}

/** Odnotowuje realną wysyłkę dossier dziecka (data, kto, licznik ponowień). */
function adopt_adoption_mark_dossier_sent(int $adoptionId, ?string $user): void {
    $st = payu_db()->prepare(
        'UPDATE adopt_adoptions
            SET dossier_sent_at = NOW(), dossier_sent_by = ?, dossier_sent_count = dossier_sent_count + 1
          WHERE id = ?'
    );
    $st->execute([$user !== null ? mb_substr($user, 0, 64) : null, $adoptionId]);
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
    /* donor_id jest edytowalne: bez tego nie dało się PRZENIEŚĆ adopcji do innego
       darczyńcy, a to jedyna droga naprawy dwóch realnych sytuacji - zgłoszenia,
       które wpadło pod cudzy rekord przez wspólny e-mail, i scalenia dwóch wpisów
       tej samej osoby. Wpłaty wiszą przy adopcji, więc idą razem z nią. */
    $st = payu_db()->prepare(
        'UPDATE adopt_adoptions
            SET donor_id = ?, child_id = ?, subscription_id = ?, duration = ?, start_month = ?, end_month = ?,
                frequency = ?, amount_grosze = ?, method = ?, notes = ?
          WHERE id = ?'
    );
    $st->execute([
        $d['donor_id'],
        $d['child_id'] ?? null,
        $d['subscription_id'] ?? null,
        $d['duration'],
        $d['start_month'] ?? null,
        $d['end_month'] ?? null,
        $d['frequency'],
        $d['amount_grosze'],
        $d['method'],
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
 * Auto-rejestracja darczyńcy z opłaconej ADOPCJI KARTOWEJ (PayU): darczyńca
 * (dopięty po e-mailu albo nowy) + adopcje `pending` (bez dziecka) powiązane
 * z subskrypcją - dokładnie jak przy potwierdzonym zgłoszeniu przelewowym.
 * Dzięki temu baza darczyńców rośnie sama z OBU ścieżek formularza, a fundacja
 * tylko przypisuje dzieci w panelu (Zgłoszenia). Idempotentne: nic nie robi,
 * gdy subskrypcja ma już adopcje. Best-effort (wołane z płatności).
 * $ad = payload adopcyjny z data/adopcja-card-pending (imie, nazwisko, email,
 * telefon, adres, forma, okres, dzieci).
 */
function adopt_ensure_from_card(array $sub, array $ad): void {
    if (($sub['goal'] ?? '') !== 'adopcja') return;
    $pdo = payu_db();
    $st = $pdo->prepare('SELECT id FROM adopt_adoptions WHERE subscription_id = ? LIMIT 1');
    $st->execute([(int)$sub['id']]);
    if ($st->fetchColumn() !== false) return;   // już zarejestrowana (np. ręcznie)

    $email = trim((string)($ad['email'] ?? $sub['email'] ?? ''));
    $fullName = trim(trim((string)($ad['imie'] ?? $sub['first_name'] ?? '')) . ' '
              . trim((string)($ad['nazwisko'] ?? $sub['last_name'] ?? '')));
    if ($fullName === '') return;

    [$donorId] = adopt_donor_for_signup([
        'full_name'  => $fullName,
        'first_name' => (string)($ad['imie'] ?? $sub['first_name'] ?? ''),
        'last_name'  => (string)($ad['nazwisko'] ?? $sub['last_name'] ?? ''),
        'email'      => $email,
        'phone'      => (string)($ad['telefon'] ?? ''),
        'street'     => (string)($ad['ulica'] ?? ''),
        'house_no'   => (string)($ad['nr_domu'] ?? ''),
        'postcode'   => (string)($ad['kod_pocztowy'] ?? ''),
        'city'       => (string)($ad['miejscowosc'] ?? ''),
        'source'     => 'site',
    ]);

    // okres z formularza ("czasowa" ma zakres w polu okres, np. "2026-09 - 2027-08")
    $duration = ($ad['forma'] ?? '') === 'czasowa' || stripos((string)($ad['forma'] ?? ''), 'czasow') !== false
        ? 'fixed' : 'indefinite';
    $startM = adopt_month_from_date((string)($sub['start_date'] ?? '')) ?? date('Y-m');
    $endM = null;
    if ($duration === 'fixed' && preg_match_all('/\d{4}-\d{2}|\d{1,2}\.\d{4}/', (string)($ad['okres'] ?? ''), $mm)
        && count($mm[0]) >= 2) {
        $endM = adopt_parse_month_token(end($mm[0]));
    }
    if ($duration === 'indefinite') $endM = null;

    $dzieci = max(1, min(10, (int)($ad['dzieci'] ?? ($sub['children'] ?? 1))));
    $ids = [];
    for ($i = 0; $i < $dzieci; $i++) {
        $ids[] = adopt_adoption_insert([
            'donor_id' => $donorId, 'child_id' => null,
            'subscription_id' => (int)$sub['id'],
            'duration' => $duration, 'start_month' => $startM, 'end_month' => $endM,
            'frequency' => 'monthly', 'amount_grosze' => 7000, 'method' => 'card',
            'status' => 'pending',
            'notes' => 'Zgłoszenie przez stronę - karta PayU (' . date('Y-m-d') . ')',
        ]);
    }
    mada_audit('signup.card', 'donor', $donorId, ['sub' => (int)$sub['id'], 'adopcje' => $ids]);
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

/* ─────────────────────────────────────────────────────────────────
   Wyciąg bankowy: poczekalnia operacji i rachunki darczyńców
   (parsowanie i dopasowania siedzą w adopcja/bank.php - bez bazy)
  ───────────────────────────────────────────────────────────────── */

/** Dopisuje operacje z wgranego pliku. Zwraca [dodane, pominięte-duplikaty]. */
function bank_ops_insert_many(array $ops, ?string $user = null): array {
    $pdo = payu_db();
    $st = $pdo->prepare(
        'INSERT IGNORE INTO adopt_bank_ops
            (op_hash, op_date, amount_grosze, currency, title, party, account, account_key, imported_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $added = 0;
    foreach ($ops as $op) {
        $st->execute([
            $op['op_hash'],
            $op['op_date'],
            (int)$op['amount_grosze'],
            mb_substr((string)($op['currency'] ?? 'PLN'), 0, 3),
            mb_substr((string)($op['title'] ?? ''), 0, 255) ?: null,
            mb_substr((string)($op['party'] ?? ''), 0, 200) ?: null,
            mb_substr((string)($op['account'] ?? ''), 0, 40) ?: null,
            mb_substr((string)($op['account_key'] ?? ''), 0, 34) ?: null,
            $user,
        ]);
        $added += $st->rowCount();
    }
    return ['added' => $added, 'dups' => count($ops) - $added];
}

/** Operacje z poczekalni; '' = wszystkie statusy. Najnowsze u góry. */
function bank_ops_list(string $status = 'open', int $limit = 500): array {
    $sql = 'SELECT * FROM adopt_bank_ops';
    $args = [];
    if ($status !== '') { $sql .= ' WHERE status = ?'; $args[] = $status; }
    $sql .= ' ORDER BY op_date DESC, id DESC LIMIT ' . max(1, min(2000, $limit));
    $st = payu_db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function bank_op_get(int $id): ?array {
    $st = payu_db()->prepare('SELECT * FROM adopt_bank_ops WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Liczba operacji wg statusu (do licznika przy pozycji w menu). */
function bank_ops_counts(): array {
    $out = ['open' => 0, 'payment' => 0, 'flow' => 0, 'skipped' => 0];
    foreach (payu_db()->query('SELECT status, COUNT(*) c FROM adopt_bank_ops GROUP BY status') as $r) {
        $out[$r['status']] = (int)$r['c'];
    }
    return $out;
}

/** Zamyka operację decyzją pracownika (status + id utworzonego wiersza). */
function bank_op_resolve(int $id, string $status, ?int $targetId, ?string $user = null): void {
    $st = payu_db()->prepare(
        'UPDATE adopt_bank_ops
            SET status = ?, target_id = ?, resolved_at = NOW(), resolved_by = ?
          WHERE id = ?'
    );
    $st->execute([$status, $targetId, $user, $id]);
}

/** Przywraca operację do poczekalni (cofnięcie pomyłkowej decyzji). */
function bank_op_reopen(int $id): void {
    payu_db()->prepare(
        "UPDATE adopt_bank_ops SET status = 'open', target_id = NULL, resolved_at = NULL, resolved_by = NULL
          WHERE id = ?"
    )->execute([$id]);
}

/** Mapa zapamiętanych rachunków: klucz cyfrowy => id darczyńcy. */
function bank_accounts_map(): array {
    $out = [];
    foreach (payu_db()->query('SELECT account_key, donor_id FROM adopt_donor_accounts') as $r) {
        $out[$r['account_key']] = (int)$r['donor_id'];
    }
    return $out;
}

/** Zapamiętuje rachunek przy darczyńcy (ponowne wskazanie nadpisuje wpis). */
function bank_account_remember(string $accountKey, int $donorId, string $label = '', ?string $user = null): void {
    if ($accountKey === '') return;
    payu_db()->prepare(
        'INSERT INTO adopt_donor_accounts (account_key, donor_id, label, added_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE donor_id = VALUES(donor_id), label = VALUES(label)'
    )->execute([mb_substr($accountKey, 0, 34), $donorId, mb_substr($label, 0, 120) ?: null, $user]);
}

/** Rachunki zapamiętane przy darczyńcy (karta darczyńcy, RODO - do wglądu). */
function bank_accounts_by_donor(int $donorId): array {
    $st = payu_db()->prepare(
        'SELECT * FROM adopt_donor_accounts WHERE donor_id = ? ORDER BY added_at'
    );
    $st->execute([$donorId]);
    return $st->fetchAll();
}

/**
 * Materiał dla bank_match_op(): dzieci, darczyńcy, aktywne adopcje z policzonym
 * „opłacone do" i zapamiętane rachunki. Jedno zapytanie na tabelę - lista
 * operacji z pliku dopasowuje się potem w pamięci.
 */
function bank_match_context(): array {
    require_once __DIR__ . '/lib.php';
    $pdo = payu_db();
    $children = $pdo->query('SELECT id, number, name FROM adopt_children')->fetchAll();
    $donors   = $pdo->query('SELECT id, full_name FROM adopt_donors')->fetchAll();
    $adoptions = $pdo->query(
        "SELECT id, donor_id, child_id, amount_grosze, start_month, end_month, status
           FROM adopt_adoptions WHERE status IN ('pending','active')"
    )->fetchAll();

    $pays = adopt_payments_by_adoptions(array_map(fn($a) => (int)$a['id'], $adoptions));
    foreach ($adoptions as &$a) {
        $a['paid_until'] = adopt_paid_until($pays[(int)$a['id']] ?? []);
    }
    unset($a);

    return [
        'children'  => $children,
        'donors'    => $donors,
        'adoptions' => $adoptions,
        'accounts'  => bank_accounts_map(),
    ];
}

/* ── Statystyki (dashboard, eksport) ───────────────────────────── */

function adopt_counts(): array {
    $pdo = payu_db();
    return [
        'children'  => (int)$pdo->query('SELECT COUNT(*) FROM adopt_children')->fetchColumn(),
        'donors'    => (int)$pdo->query('SELECT COUNT(*) FROM adopt_donors')->fetchColumn(),
        'adoptions' => (int)$pdo->query("SELECT COUNT(*) FROM adopt_adoptions WHERE status IN ('pending','active')")->fetchColumn(),
        'payments'  => (int)$pdo->query('SELECT COUNT(*) FROM adopt_payments')->fetchColumn(),
    ];
}
