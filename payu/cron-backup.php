<?php
/* ═══════════════════════════════════════════════════════════════
   Kopia zapasowa bazy (subskrypcje PayU + cały moduł Adopcja Serca
   + panel) - jeden zrzut `mysqldump`, spakowany gzipem.
   Uruchamiać z crona:  php payu/cron-backup.php
   Podgląd bez zapisu:  php payu/cron-backup.php --dry
   ───────────────────────────────────────────────────────────────
   Zasady:
   - dumpy lądują POZA `public_html` (katalog `~/backups`, prawa 700) -
     nikt nie pobierze ich przez WWW,
   - hasło NIE trafia do linii poleceń (byłoby widoczne w `ps` na
     współdzielonym hostingu) - idzie przez tymczasowy `--defaults-extra-file`
     z prawami 600, kasowany w `finally`,
   - `--single-transaction`: spójny zrzut InnoDB bez blokowania zapisów,
   - po zapisie plik jest sprawdzany `gzip -t`; uszkodzony NIE zostaje
     na dysku i skrypt kończy się błędem (cichy, zepsuty backup jest
     gorszy niż jego brak),
   - retencja dotyczy WYŁĄCZNIE plików `auto-*` - ręczne zrzuty robione
     przed większymi operacjami (`mada-...-przed-...`) zostają nietknięte.
  ═══════════════════════════════════════════════════════════════ */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Tylko CLI.\n"); }

require_once __DIR__ . '/secret/db-config.php';

const BACKUP_DIR        = '/home/srv84712/backups';
const BACKUP_RETENCJA_D = 30;     // ile dni trzymamy zrzuty automatyczne
const BACKUP_MIN_BAJTOW = 2048;   // mniejszy plik = coś poszło nie tak

$dry = in_array('--dry', $argv, true);
$now = date('Y-m-d H:i:s');
echo "[$now] kopia zapasowa bazy" . ($dry ? ' (PODGLĄD)' : '') . "\n";

/* Host bywa zapisany jako '127.0.0.1;port=3307' (DSN nie ma osobnego pola). */
$host = defined('PAYU_DB_HOST') ? PAYU_DB_HOST : 'localhost';
$port = null;
if (str_contains($host, ';port=')) [$host, $port] = explode(';port=', $host, 2);

$plik = BACKUP_DIR . '/auto-' . date('Ymd-His') . '.sql.gz';
echo "  cel: $plik\n";
if ($dry) { echo "  (podgląd - nic nie zapisuję)\n"; exit(0); }

if (!is_dir(BACKUP_DIR) && !@mkdir(BACKUP_DIR, 0700, true)) {
    fwrite(STDERR, "BŁĄD: nie mogę utworzyć " . BACKUP_DIR . "\n");
    exit(1);
}

$cnf = tempnam(sys_get_temp_dir(), 'madabk');
try {
    chmod($cnf, 0600);
    file_put_contents($cnf,
        "[client]\nuser=" . PAYU_DB_USER . "\npassword=\"" . PAYU_DB_PASS . "\"\n"
        . "host=$host\n" . ($port !== null ? "port=$port\n" : ''));

    $cmd = 'mysqldump --defaults-extra-file=' . escapeshellarg($cnf)
         . ' --single-transaction --quick --default-character-set=utf8mb4 '
         . escapeshellarg(PAYU_DB_NAME)
         . ' 2>/dev/null | gzip -9 > ' . escapeshellarg($plik);
    exec($cmd, $out, $rc);

    $rozmiar = is_file($plik) ? filesize($plik) : 0;
    if ($rc !== 0 || $rozmiar < BACKUP_MIN_BAJTOW) {
        @unlink($plik);
        fwrite(STDERR, "BŁĄD: mysqldump zakończył się kodem $rc, rozmiar $rozmiar B - plik usunięty\n");
        exit(1);
    }
    exec('gzip -t ' . escapeshellarg($plik) . ' 2>&1', $o2, $rc2);
    if ($rc2 !== 0) {
        @unlink($plik);
        fwrite(STDERR, "BŁĄD: archiwum nie przechodzi gzip -t - plik usunięty\n");
        exit(1);
    }
    printf("  zapisane: %s (%s)\n", basename($plik), mada_backup_rozmiar($rozmiar));
} finally {
    @unlink($cnf);
}

/* ── Retencja: tylko zrzuty automatyczne ─────────────────────────── */
$prog = time() - BACKUP_RETENCJA_D * 86400;
$usuniete = 0;
foreach (glob(BACKUP_DIR . '/auto-*.sql.gz') ?: [] as $f) {
    if (filemtime($f) < $prog && @unlink($f)) $usuniete++;
}

$wszystkie = glob(BACKUP_DIR . '/*.sql.gz') ?: [];
$suma = array_sum(array_map('filesize', $wszystkie));
printf("  retencja %d dni: usunięto %d, w katalogu %d plików (%s)\n",
    BACKUP_RETENCJA_D, $usuniete, count($wszystkie), mada_backup_rozmiar($suma));

function mada_backup_rozmiar(int $b): string {
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024)    return round($b / 1024) . ' KB';
    return $b . ' B';
}
