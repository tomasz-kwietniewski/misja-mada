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

        try {
            $resp = payu_order_request($order, $token);
            $sc   = $resp['statusCode'];
            $payuOrderId = $resp['data']['orderId'] ?? null;

            if ($sc === 'SUCCESS') {
                payu_charge_mark($ext, 'pending', $payuOrderId);   // COMPLETED dojdzie notyfikacją
                $next = mada_sub_next_charge_date($today, (int)$sub['charge_day'], 1);
                payu_sub_mark_success($id, $next);
                mada_mail_receipt($sub);
                cron_log("sub={$id} OBCIAZONO {$amount} {$cur}, nastepne {$next}.");
            } else {
                throw new Exception('PayU status=' . $sc . ' ' . ($resp['data']['status']['statusDesc'] ?? ''));
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
            payu_charge_mark($ext, 'failed', null, $err);
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
        }
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

cron_log('Koniec.');
exit(0);
