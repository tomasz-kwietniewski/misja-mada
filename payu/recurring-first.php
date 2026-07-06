<?php
/* ═══════════════════════════════════════════════════════════════
   PayU - pierwsza płatność cykliczna (FIRST) + założenie subskrypcji
   ───────────────────────────────────────────────────────────────
   Wejście (POST JSON z frontu Secure Form):
     { token (TOK_ z tokenize), imie, nazwisko, email, telefon?,
       goal, goalLabel, amount, currency, dzieci?, consent:true }
   Tworzy subskrypcję (pending_first), wysyła order recurring=FIRST.
   Zwraca:
     { status:'active' }                 -> od razu opłacona (bez 3DS)
     { redirectUri:'...' }               -> wymagane 3DS (aktywacja po notyfikacji)
     { error:'...' }                     -> błąd walidacji/PayU
  ═══════════════════════════════════════════════════════════════ */
require __DIR__ . '/lib.php';
require __DIR__ . '/db.php';
require __DIR__ . '/recurring-lib.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/sheet.php';

const SITE_BASE = 'https://misjamada.pl';

/**
 * Po zalozeniu subskrypcji adopcji przez karte: zaloguj komplet danych adopcyjnych
 * do arkusza „Adopcja Serca" (status oplacone-PayU, bez DOI - karta zweryfikowala mail)
 * oraz - jesli zaznaczono - dopisz na newsletter (mail juz zweryfikowany karta).
 * mada_sheet_post() i mada_newsletter_add_verified() pochodza z payu/sheet.php.
 */
function mada_adopcja_after_card(string $goal, array $ctx): void {
    if ($goal !== 'adopcja') return;
    mada_sheet_post([
        'type'            => 'adopcja',
        'status'          => 'oplacone-PayU',
        'imie'            => $ctx['imie'],
        'nazwisko'        => $ctx['nazwisko'],
        'email'           => $ctx['email'],
        'telefon'         => $ctx['telefon'],
        'adres'           => $ctx['adres'],
        'forma'           => $ctx['forma'],
        'okres'           => $ctx['okres'],
        'dzieci'          => $ctx['dzieci'],
        'zgoda_wizerunek' => $ctx['wizerunek'] ? 'TAK' : '',
        'newsletter'      => $ctx['newsletter'] ? 'TAK' : '',
    ]);
    if ($ctx['newsletter']) {
        mada_newsletter_add_verified($ctx['email'], $ctx['imie']);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    payu_json(['error' => 'Metoda niedozwolona.'], 405);
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    payu_json(['error' => 'Nieprawidłowe dane formularza.'], 400);
}

// ── Walidacja ────────────────────────────────────────────────────
$cardToken = trim((string)($data['token'] ?? ''));
$imie      = trim((string)($data['imie'] ?? ''));
$nazwisko  = trim((string)($data['nazwisko'] ?? ''));
$email     = trim((string)($data['email'] ?? ''));
$telefon   = trim((string)($data['telefon'] ?? ''));
$currency  = strtoupper(trim((string)($data['currency'] ?? 'PLN')));
$goal      = trim((string)($data['goal'] ?? ''));
$goalLabel = trim((string)($data['goalLabel'] ?? 'Darowizna cykliczna na rzecz Fundacji Misja MADA'));
$amount    = isset($data['amount']) ? (float)$data['amount'] : 0;
$dzieci    = isset($data['dzieci']) ? (int)$data['dzieci'] : null;
$adres     = trim((string)($data['adres'] ?? ''));
$forma     = trim((string)($data['forma'] ?? ''));
$okres     = trim((string)($data['okres'] ?? ''));
$wizerunek = !empty($data['zgoda_wizerunek']);
$newsletter = !empty($data['newsletter']);
$consent   = !empty($data['consent']);

$GOALS = ['statutowe', 'adopcja', 'centrum', 'atelier'];

if (!$consent) {
    payu_json(['error' => 'Wymagana zgoda na cykliczne obciążanie karty.'], 422);
}
if ($cardToken === '') {
    payu_json(['error' => 'Brak danych karty. Spróbuj ponownie.'], 422);
}
if ($currency !== 'PLN') {
    payu_json(['error' => 'Płatność cykliczna dostępna na razie tylko w PLN.'], 422);
}
if (!in_array($goal, $GOALS, true)) {
    payu_json(['error' => 'Nieprawidłowy cel darowizny.'], 422);
}
if ($imie === '' || $nazwisko === '') {
    payu_json(['error' => 'Podaj imię i nazwisko.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    payu_json(['error' => 'Podaj prawidłowy adres e-mail.'], 422);
}
if ($amount < 1 || $amount > 100000) {
    payu_json(['error' => 'Nieprawidłowa kwota darowizny.'], 422);
}

$grosze    = (int) round($amount * 100);
$today     = date('Y-m-d');
$chargeDay = mada_sub_charge_day($today);
$nextCharge = mada_sub_next_charge_date($today, $chargeDay, 1);
$minMonths = $goal === 'adopcja' ? 12 : null;
$expiry    = mada_sub_expiry_date($today, $minMonths ?? 12);
$descr     = mb_substr(mada_sub_description($goalLabel, $grosze, $currency, $expiry), 0, 250);

try {
    payu_db_ensure_schema();

    // 1) Załóż subskrypcję (pending_first)
    $subId = payu_sub_insert([
        'manage_token'   => mada_sub_gen_manage_token(),
        'email'          => $email,
        'first_name'     => mb_substr($imie, 0, 100),
        'last_name'      => mb_substr($nazwisko, 0, 100),
        'phone'          => $telefon !== '' ? mb_substr($telefon, 0, 32) : null,
        'goal'           => $goal,
        'goal_label'     => mb_substr($goalLabel, 0, 255),
        'children'       => ($goal === 'adopcja' && $dzieci) ? $dzieci : null,
        'amount_grosze'  => $grosze,
        'currency'       => $currency,
        'charge_day'     => $chargeDay,
        'start_date'     => $today,
        'next_charge_at' => $nextCharge,
        'expiry_date'    => $expiry,
        'min_months'     => $minMonths,
    ]);

    // Dane adopcyjne z formularza sa potrzebne przy aktywacji subskrypcji. Sciezka 3DS
    // aktywuje ja dopiero w notify.php, gdzie tych danych juz nie ma - zapisujemy je
    // efemerycznie (poza repo, wykluczone z deployu; kasowane po zuzyciu).
    if ($goal === 'adopcja') {
        $adDir = __DIR__ . '/../data/adopcja-card-pending';
        if (!is_dir($adDir)) { @mkdir($adDir, 0755, true); }
        @file_put_contents($adDir . '/' . (int)$subId . '.json', json_encode([
            'imie' => $imie, 'nazwisko' => $nazwisko, 'email' => $email, 'telefon' => $telefon,
            'adres' => $adres, 'forma' => $forma, 'okres' => $okres, 'dzieci' => $dzieci,
            'wizerunek' => $wizerunek, 'newsletter' => $newsletter, 'ts' => time(),
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    // 2) Zamówienie FIRST (tokenizacja + 3DS)
    $order = [
        'notifyUrl'     => SITE_BASE . '/payu/notify.php',
        'continueUrl'   => SITE_BASE . '/dziekujemy.html',
        'customerIp'    => payu_client_ip(),
        'merchantPosId' => PAYU_POS_ID,
        'recurring'     => 'FIRST',
        'description'   => $descr,
        'currencyCode'  => $currency,
        'totalAmount'   => (string) $grosze,
        'extOrderId'    => mada_sub_first_ext_order_id($subId),
        'buyer' => [
            'email'     => $email,
            'firstName' => mb_substr($imie, 0, 100),
            'lastName'  => mb_substr($nazwisko, 0, 100),
            'language'  => 'pl',
        ],
        'products' => [[
            'name'      => $descr,
            'unitPrice' => (string) $grosze,
            'quantity'  => '1',
        ]],
        'payMethods' => [
            'payMethod' => ['type' => 'CARD_TOKEN', 'value' => $cardToken],
        ],
        'threeDsAuthentication' => [
            // Wymuszamy pełne Silne Uwierzytelnienie (challenge 3DS) na PIERWSZEJ
            // płatności zakładającej token. Powód: aneks PayU 13.1 ppkt (g) - za
            // transakcje kartą przeprocesowane BEZ Silnego Uwierzytelnienia
            // odpowiedzialność finansową za fraudowe chargebacki ponosi Akceptant
            // (Fundacja). MANDATE zmusza wydawcę karty do challenge (push w aplikacji
            // / SMS / PIN), co odcina m.in. "card testing" skradzionymi kartami na
            // formularzu darowizn. Kolejne obciążenia (STANDARD/MIT, cron-charge.php)
            // celowo BEZ challenge - płatnik nieobecny, token założono z 3DS.
            'challengeRequested' => 'MANDATE',
            'recurring' => [
                'frequency' => 30,
                'expiry'    => $expiry . 'T00:00:00Z',
            ],
        ],
    ];

    $token = payu_get_token();
    $resp  = payu_order_request($order, $token);
    $sc    = $resp['statusCode'];
    $payuOrderId = $resp['data']['orderId'] ?? '';

    if ($payuOrderId) {
        payu_sub_set_first_order($subId, $payuOrderId);
    }

    // Token wielorazowy (TOKC_) i maska karty przychodzą w SYNCHRONICZNEJ odpowiedzi na
    // utworzenie zamówienia (payMethods.payMethod), TAKŻE przy WARNING_CONTINUE_3DS -
    // czyli PRZED zakończeniem 3DS. Zapisujemy je od razu na subskrypcji; sama aktywacja
    // (status=active) nastąpi dopiero po notyfikacji COMPLETED (notify.php). Bez tego kroku
    // przy płatności z 3DS token przepadał i notify.php nie miał czym aktywować.
    $tok = mada_sub_extract_token($resp['data']);
    if ($tok) {
        payu_sub_set_card($subId, $tok['token'], (string)($tok['mask'] ?? ''));
    }

    if ($sc === 'SUCCESS') {
        // Płatność opłacona od razu (bez 3DS) - aktywuj subskrypcję natychmiast.
        if ($tok && payu_sub_activate($subId, $tok['token'], (string)($tok['mask'] ?? ''), $payuOrderId, $nextCharge)) {
            $fresh = payu_sub_get($subId);
            if ($fresh) { mada_mail_welcome($fresh); mada_mail_foundation($fresh, 'nowa'); }
            mada_adopcja_after_card($goal, [
                'imie' => $imie, 'nazwisko' => $nazwisko, 'email' => $email, 'telefon' => $telefon,
                'adres' => $adres, 'forma' => $forma, 'okres' => $okres, 'dzieci' => $dzieci,
                'wizerunek' => $wizerunek, 'newsletter' => $newsletter,
            ]);
            // SUCCESS synchroniczny nie przechodzi przez notify FIRST - skasuj rekord tutaj.
            @unlink(__DIR__ . '/../data/adopcja-card-pending/' . (int)$subId . '.json');
        }
        payu_json(['status' => 'active']);
    }

    if ($sc === 'WARNING_CONTINUE_3DS' && !empty($resp['data']['redirectUri'])) {
        // Płatnik przechodzi 3DS; token już zapisany, aktywacja po notyfikacji COMPLETED.
        payu_json(['redirectUri' => $resp['data']['redirectUri']]);
    }

    // Inny status (np. CVV, błąd) - logujemy i zwracamy komunikat
    $desc = $resp['data']['status']['statusDesc'] ?? $sc;
    error_log('[PayU recurring-first] sub=' . $subId . ' status=' . $sc . ' ' . $desc);
    payu_json(['error' => 'Nie udało się rozpocząć płatności cyklicznej. Spróbuj ponownie lub napisz na kontakt@misjamada.pl.'], 502);

} catch (Throwable $e) {
    error_log('[PayU recurring-first] ' . $e->getMessage());
    payu_json(['error' => 'Nie udało się połączyć z bramką płatności. Spróbuj ponownie za chwilę lub napisz na kontakt@misjamada.pl.'], 502);
}
