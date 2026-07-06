<?php
/* ═══════════════════════════════════════════════════════════════
   Testy CZYSTEJ LOGIKI płatności cyklicznych (payu/recurring-lib.php).
   BEZ zależności (bazy, sieci). Uruchom:  php tests/run-recurring.php
   Kod wyjścia != 0, gdy którykolwiek test nie przejdzie (dla CI).
  ═══════════════════════════════════════════════════════════════ */

require __DIR__ . '/../payu/recurring-lib.php';

$T = ['pass' => 0, 'fail' => 0];
function ok($cond, $msg) {
    global $T;
    if ($cond) { $T['pass']++; }
    else { $T['fail']++; fwrite(STDERR, "  ✗ $msg\n"); }
}
function eq($actual, $expected, $msg) {
    ok($actual === $expected,
       $msg . "  (oczekiwano: " . var_export($expected, true) . ", było: " . var_export($actual, true) . ")");
}

// ── charge_day: dzień kotwicy klamrowany do 1..28 ──────────────
eq(mada_sub_charge_day('2026-01-15'), 15, 'charge_day: 15 -> 15');
eq(mada_sub_charge_day('2026-01-31'), 28, 'charge_day: 31 -> 28 (klamra)');
eq(mada_sub_charge_day('2026-01-29'), 28, 'charge_day: 29 -> 28');
eq(mada_sub_charge_day('2026-01-01'), 1,  'charge_day: 1 -> 1');

// ── next_charge_date: +1 miesiąc w dniu kotwicy ────────────────
eq(mada_sub_next_charge_date('2026-01-15', 15), '2026-02-15', 'next: 01-15 -> 02-15');
eq(mada_sub_next_charge_date('2026-01-31', 28), '2026-02-28', 'next: koniec stycznia -> 28 lutego');
eq(mada_sub_next_charge_date('2026-02-15', 15), '2026-03-15', 'next: luty -> marzec');
eq(mada_sub_next_charge_date('2026-12-10', 10), '2027-01-10', 'next: grudzień -> styczeń nast. roku');
eq(mada_sub_next_charge_date('2026-01-15', 15, 2), '2026-03-15', 'next: +2 miesiące');
// nie przeskakuje miesięcy przez końcówkę (31): start 31.01, kotwica 28 -> 28.02 (nie marzec)
eq(mada_sub_next_charge_date('2026-01-31', 28), '2026-02-28', 'next: brak przeskoku przez 31');

// ── expiry_date: start + 12 miesięcy ───────────────────────────
eq(mada_sub_expiry_date('2026-06-20'), '2027-06-20', 'expiry: +12 mies.');
eq(mada_sub_expiry_date('2026-01-31'), '2027-01-28', 'expiry: 31 sty -> 28 sty nast. roku (klamra)');
eq(mada_sub_expiry_date('2026-06-20', 6), '2026-12-20', 'expiry: +6 mies.');

// ── ext_order_id: idempotencja ─────────────────────────────────
eq(mada_sub_ext_order_id(42, '202606'),    'mada548242_202606',    'ext_order_id: pierwsza próba (publicId = 42+offset)');
eq(mada_sub_ext_order_id(42, '202606', 1), 'mada548242_202606',    'ext_order_id: attempt=1 bez sufiksu');
eq(mada_sub_ext_order_id(42, '202606', 2), 'mada548242_202606_r2', 'ext_order_id: ponowienie ma sufiks _r2');
eq(mada_sub_ext_order_id(7,  '202612', 3), 'mada548207_202612_r3', 'ext_order_id: inna sub/okres/próba');
ok(mada_sub_ext_order_id(1,'202601') !== mada_sub_ext_order_id(1,'202602'), 'ext_order_id: różne okresy != ');

// ── retry: max 1x/dzień, max 3 próby ───────────────────────────
eq(mada_sub_retry_offset_days(1), 1,    'retry: po 1. próbie -> +1 dzień');
eq(mada_sub_retry_offset_days(2), 3,    'retry: po 2. próbie -> +3 dni');
eq(mada_sub_retry_offset_days(3), null, 'retry: po 3. próbie -> koniec (pauza)');
eq(mada_sub_max_attempts(), 3, 'max_attempts: 3');
// suma dni ponowień mieści się w limicie 31 dni i nie częściej niż 1x/dzień
ok((mada_sub_retry_offset_days(1) + mada_sub_retry_offset_days(2)) <= 31, 'retry: łączny rozrzut <= 31 dni');
ok(mada_sub_retry_offset_days(1) >= 1 && mada_sub_retry_offset_days(2) >= 1, 'retry: każdy odstęp >= 1 dzień');

// ── decyzja o wyniku obciążenia STANDARD (ochrona przed double-charge) ──
eq(mada_charge_decision('SUCCESS'), 'success', 'charge_decision: SUCCESS -> success');
eq(mada_charge_decision(null),      'hold',    'charge_decision: brak odpowiedzi (timeout) -> hold, NIE ponawiaj');
eq(mada_charge_decision('ERROR_VALUE_INVALID'), 'retry', 'charge_decision: jawna odmowa PayU -> retry');
eq(mada_charge_decision('WARNING_CONTINUE_CVV'), 'retry', 'charge_decision: inny status != SUCCESS -> retry');
eq(mada_charge_decision(''), 'hold', 'charge_decision: pusty statusCode (odpowiedź bez statusu) -> hold (niejednoznaczne)');
// KLUCZOWE: SUCCESS nigdy nie prowadzi do ponowienia; jawna odmowa nigdy nie prowadzi do holda
ok(mada_charge_decision('SUCCESS') !== 'hold' && mada_charge_decision('SUCCESS') !== 'retry', 'charge_decision: SUCCESS = tylko success');
ok(mada_charge_decision('ERROR_X') === 'retry', 'charge_decision: jawny błąd = retry (PayU nie obciążyło)');

// ── przejścia statusów ─────────────────────────────────────────
ok(mada_sub_can_charge('active'),     'can_charge: active -> tak');
ok(!mada_sub_can_charge('paused'),    'can_charge: paused -> nie');
ok(!mada_sub_can_charge('cancelled'), 'can_charge: cancelled -> nie');
ok(!mada_sub_can_charge('pending_first'), 'can_charge: pending_first -> nie');
ok(mada_sub_is_final('cancelled'),  'is_final: cancelled -> tak');
ok(!mada_sub_is_final('paused'),    'is_final: paused -> nie (można wznowić)');

// ── opis subskrypcji (wymóg informacyjny PayU) ─────────────────
eq(mada_sub_description('Adopcja Serca', 7000, 'PLN', '2027-06-20'),
   'Adopcja Serca, 70 PLN/mies., obciążenie co miesiąc do 2027-06-20',
   'description: pełna kwota bez zer');
eq(mada_sub_description('Darowizna', 12550, 'PLN', '2027-01-10'),
   'Darowizna, 125.50 PLN/mies., obciążenie co miesiąc do 2027-01-10',
   'description: grosze zachowane');

// ── first_ext_order_id + klasyfikacja extOrderId (dla notyfikacji) ──
eq(mada_sub_first_ext_order_id(42), 'mada548242', 'first_ext: format (publicId = 42+offset, maskuje surowe id)');
// klasyfikacja karmiona wyjściem generatorów (odporna na zmianę offsetu)
$cf = mada_sub_classify_ext(mada_sub_first_ext_order_id(42));
eq($cf['type'], 'first', 'classify: FIRST typ');
eq($cf['subId'], 42, 'classify: FIRST subId (odwrócony z publicId)');
$cs = mada_sub_classify_ext(mada_sub_ext_order_id(7, '202606'));
eq($cs['type'], 'standard', 'classify: STANDARD typ');
eq($cs['subId'], 7, 'classify: STANDARD subId');
eq($cs['period'], '202606', 'classify: STANDARD period');
eq($cs['attempt'], 1, 'classify: STANDARD attempt domyslny 1');
$cr = mada_sub_classify_ext(mada_sub_ext_order_id(7, '202606', 3));
eq($cr['attempt'], 3, 'classify: STANDARD ponowienie attempt=3');
$co = mada_sub_classify_ext('mada_5f3a9b');
eq($co['type'], 'other', 'classify: jednorazowe -> other');
eq($co['subId'], null, 'classify: other subId null');
// round-trip: ext_order_id -> classify
$rt = mada_sub_classify_ext(mada_sub_ext_order_id(99, '202701', 2));
eq($rt['subId'], 99, 'classify round-trip: subId');
eq($rt['attempt'], 2, 'classify round-trip: attempt');
// wsteczna zgodność: stary format (mada_first{id} / mada_sub{id}_...) nadal rozpoznawany
$obc = mada_sub_classify_ext('mada_first1');
eq($obc['type'], 'first', 'classify back-compat: stary FIRST typ');
eq($obc['subId'], 1, 'classify back-compat: stary FIRST subId (bez offsetu)');
$obs = mada_sub_classify_ext('mada_sub7_202606_r3');
eq($obs['type'], 'standard', 'classify back-compat: stary STANDARD typ');
eq($obs['subId'], 7, 'classify back-compat: stary STANDARD subId');
eq($obs['attempt'], 3, 'classify back-compat: stary STANDARD attempt');

// ── ekstrakcja tokena TOKC_ + maski z odpowiedzi PayU ──────────
$resp = ['payMethods' => ['payMethod' => [
    'card' => ['number' => '424242******4242', 'expirationMonth' => '12', 'expirationYear' => '2030'],
    'type' => 'CARD_TOKEN', 'value' => 'TOKC_KPNZVSLJUNR4DHF5NPVKDPJGMX7',
]]];
$ext = mada_sub_extract_token($resp);
eq($ext['token'], 'TOKC_KPNZVSLJUNR4DHF5NPVKDPJGMX7', 'extract: token TOKC_');
eq($ext['mask'],  '424242******4242', 'extract: maska karty');
// zagniezdzone w orders[] (pobranie zamowienia)
$nested = ['orders' => [['payMethod' => ['card' => ['number' => '555544******1111'], 'value' => 'TOKC_ABC123']]]];
eq(mada_sub_extract_token($nested)['token'], 'TOKC_ABC123', 'extract: token z orders[]');
// brak tokena (np. jednorazowy TOK_) -> null
ok(mada_sub_extract_token(['payMethods' => ['payMethod' => ['value' => 'TOK_oneuse']]]) === null, 'extract: brak TOKC_ -> null');
ok(mada_sub_extract_token([]) === null, 'extract: pusta odpowiedz -> null');

// ── manage_token: 64 hex, losowy ───────────────────────────────
$tok = mada_sub_gen_manage_token();
ok(strlen($tok) === 64, 'manage_token: długość 64');
ok(ctype_xdigit($tok), 'manage_token: same znaki hex');
ok(mada_sub_gen_manage_token() !== mada_sub_gen_manage_token(), 'manage_token: dwa wywołania różne');

// ── donation_is_ext: rozpoznanie jednorazowej darowizny (prefiks madaone_) ──
ok(mada_donation_is_ext('madaone_64f8a1b2c3.45678901'), 'donation_is_ext: madaone_ -> true');
ok(!mada_donation_is_ext('mada548242'),          'donation_is_ext: FIRST subskrypcji -> false');
ok(!mada_donation_is_ext('mada548242_202606'),   'donation_is_ext: STANDARD subskrypcji -> false');
ok(!mada_donation_is_ext(''),                    'donation_is_ext: puste -> false');
ok(!mada_donation_is_ext('madaone'),             'donation_is_ext: bez podkreslnika -> false');

// ── Wynik ──────────────────────────────────────────────────────
echo "\nTesty logiki recurring: {$T['pass']} OK";
if ($T['fail'] > 0) { echo ", {$T['fail']} BŁĄD\n"; exit(1); }
echo ", 0 błędów\n";
exit(0);
