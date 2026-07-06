<?php
/* ═══════════════════════════════════════════════════════════════
   PayU - scheduler obciążeń cyklicznych (STANDARD). CLI / cron.
   ───────────────────────────────────────────────────────────────
   DirectAdmin „Cron Jobs", codziennie ~05:00:
       php /home/.../public_html/payu/cron-charge.php
   Obciąża tokeny TOKC_ subskrypcji wymagalnych dziś. Ponowienia: max 1x/dzień,
   do 3 prób (zgodnie z limitem PayU), potem pauza. Idempotencja przez charges.ext_order_id.
  ═══════════════════════════════════════════════════════════════ */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Tylko CLI.');
}

require __DIR__ . '/lib.php';
require __DIR__ . '/db.php';
require __DIR__ . '/recurring-lib.php';
require __DIR__ . '/mail.php';

const SITE_BASE = 'https://misjamada.pl';

function cron_log(string $msg): void {
    fwrite(STDOUT, date('c') . "\t" . $msg . "\n");
}

/**
 * Kasuje z katalogu porzucone efemeryczne pliki .json starsze niż $days dni
 * (wg czasu modyfikacji). Używane dla data/donation-pending (nieopłacone jednorazówki)
 * i data/adopcja-card-pending (zgłoszenia adopcji-karty bez zakończonego 3DS).
 * Zwraca liczbę usuniętych plików.
 */
function mada_purge_stale_pending(string $dir, int $days): int {
    if (!is_dir($dir)) return 0;
    $cutoff = time() - $days * 86400;
    $n = 0;
    foreach ((glob($dir . '/*.json') ?: []) as $f) {
        if (@filemtime($f) !== false && filemtime($f) < $cutoff && @unlink($f)) { $n++; }
    }
    return $n;
}

// ── Blokada nakładania się uruchomień ────────────────────────────
$lockFile = __DIR__ . '/../data/cron-charge.lock';
@mkdir(dirname($lockFile), 0755, true);
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    cron_log('Inny przebieg crona trwa - pomijam.');
    exit(0);
}

try {
    payu_db_ensure_schema();
    $today = date('Y-m-d');

    // Higiena danych: zeruj tokeny porzuconych pending_first (>7 dni) - co przebieg,
    // niezależnie od tego, czy dziś są obciążenia (te subskrypcje i tak nigdy nie są obciążane).
    $purged = payu_sub_purge_abandoned_tokens(7);
    if ($purged > 0) { cron_log("Wyczyszczono tokeny porzuconych subskrypcji: {$purged}."); }

    // Higiena: skasuj porzucone (nieopłacone / bez zakończonego 3DS) efemeryczne rekordy > 7 dni.
    $purgedFiles = mada_purge_stale_pending(__DIR__ . '/../data/donation-pending', 7)
                 + mada_purge_stale_pending(__DIR__ . '/../data/adopcja-card-pending', 7);
    if ($purgedFiles > 0) { cron_log("Wyczyszczono porzucone pliki pending: {$purgedFiles}."); }

    $due   = payu_sub_due($today);
    cron_log('Subskrypcji do obciążenia: ' . count($due));

    if (!$due) { exit(0); }

    $token = payu_get_token();   // jeden token OAuth na cały przebieg

    foreach ($due as $sub) {
        $id     = (int) $sub['id'];
        $amount = (int) $sub['amount_grosze'];
        $cur    = $sub['currency'];
        $period = str_replace('-', '', substr($sub['next_charge_at'], 0, 7));   // RRRRMM
        $attempt = (int) $sub['retry_count'] + 1;
        $ext    = mada_sub_ext_order_id($id, $period, $attempt);

        if (empty($sub['card_token'])) {
            cron_log("sub={$id} POMINIETO - brak tokena karty.");
            continue;
        }

        // Idempotencja: wstaw wiersz obciążenia; duplikat -> już zainicjowane.
        $chargeId = payu_charge_insert($id, $ext, $amount, $cur, $attempt);
        if ($chargeId === null) {
            cron_log("sub={$id} ext={$ext} - obciazenie juz istnieje, pomijam.");
            continue;
        }

        $descr = mb_substr(mada_sub_description($sub['goal_label'], $amount, $cur, (string)$sub['expiry_date']), 0, 250);
        $order = [
            'notifyUrl'     => SITE_BASE . '/payu/notify.php',
            'customerIp'    => '127.0.0.1',
            'merchantPosId' => PAYU_POS_ID,
            'recurring'     => 'STANDARD',
            'description'   => $descr,
            'currencyCode'  => $cur,
            'totalAmount'   => (string) $amount,
            'extOrderId'    => $ext,
            'buyer' => [
                'email'     => $sub['email'],
                'firstName' => $sub['first_name'],
                'lastName'  => $sub['last_name'],
                'language'  => 'pl',
            ],
            'products' => [[
                'name'      => $descr,
                'unitPrice' => (string) $amount,
                'quantity'  => '1',
            ]],
            'payMethods' => [
                'payMethod' => ['type' => 'CARD_TOKEN', 'value' => $sub['card_token']],
            ],
        ];

        // Wynik obciążenia:
        //  - PayU ODPOWIEDZIAŁO -> mamy $sc (SUCCESS albo błąd) - decyzja PEWNA.
        //  - payu_order_request RZUCIŁ (timeout / brak lub niepoprawna odpowiedź) -> $sc = null,
        //    wynik NIEZNANY: PayU mogło obciążyć kartę albo nie. Wtedy NIGDY nie ponawiamy z nowym
        //    extOrderId (to prowadziło do podwójnego obciążenia) - wstrzymujemy do ręcznej kontroli.
        $sc = null;
        $payuOrderId = null;
        $err = '';
        try {
            $resp = payu_order_request($order, $token);
            $sc = (string) $resp['statusCode'];
            $payuOrderId = $resp['data']['orderId'] ?? null;
            if ($sc !== 'SUCCESS') {
                $err = 'PayU status=' . $sc . ' ' . ($resp['data']['status']['statusDesc'] ?? '');
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();   // transport/JSON padł -> wynik nieznany ($sc pozostaje null)
        }

        $decision = mada_charge_decision($sc);

        if ($decision === 'success') {
            payu_charge_mark($ext, 'pending', $payuOrderId);   // COMPLETED dojdzie notyfikacją
            $next = mada_sub_next_charge_date($today, (int)$sub['charge_day'], 1);
            payu_sub_mark_success($id, $next);
            mada_mail_receipt($sub);
            cron_log("sub={$id} OBCIAZONO {$amount} {$cur}, nastepne {$next}.");
        } elseif ($decision === 'retry') {
            // PayU JAWNIE odmówiło - na pewno bez obciążenia. Bezpiecznie ponowić (nowy ext) lub pauza.
            payu_charge_mark($ext, 'failed', $payuOrderId, $err);
            $offset = mada_sub_retry_offset_days($attempt);
            if ($offset === null) {
                payu_sub_mark_paused($id, $err);
                mada_mail_paused($sub);
                mada_mail_foundation($sub, 'wstrzymana');
                cron_log("sub={$id} WSTRZYMANO po {$attempt} probach: {$err}");
            } else {
                $retryDate = date('Y-m-d', strtotime($today . ' +' . $offset . ' day'));
                payu_sub_mark_retry($id, $retryDate, $err);
                mada_mail_charge_failed($sub);
                cron_log("sub={$id} NIEUDANE (proba {$attempt}), ponowienie {$retryDate}: {$err}");
            }
        } else {   // 'hold' - wynik NIEZNANY (transport). NIE ponawiać - ryzyko podwójnego obciążenia.
            // Charge zostaje 'pending' (nie 'failed'): jeśli PayU jednak obciążyło, notyfikacja
            // COMPLETED oznaczy go jako completed (self-reconcile). Subskrypcję WSTRZYMUJEMY do
            // ręcznej weryfikacji w panelu PayU po extOrderId - człowiek wznowi albo anuluje.
            payu_charge_mark($ext, 'pending', $payuOrderId, 'HOLD (wynik nieznany): ' . $err);
            payu_sub_mark_paused($id, 'Nieznany wynik obciazenia - sprawdz w PayU ext=' . $ext . '. ' . $err);
            mada_mail_foundation($sub, 'wstrzymana');
            cron_log("sub={$id} WSTRZYMANO - wynik NIEZNANY (ext={$ext}, sprawdz w PayU): {$err}");
        }
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

cron_log('Koniec.');
exit(0);
