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
eq(mada_sub_ext_order_id(42, '202606'),    'mada_sub42_202606',    'ext_order_id: pierwsza próba');
eq(mada_sub_ext_order_id(42, '202606', 1), 'mada_sub42_202606',    'ext_order_id: attempt=1 bez sufiksu');
eq(mada_sub_ext_order_id(42, '202606', 2), 'mada_sub42_202606_r2', 'ext_order_id: ponowienie ma sufiks _r2');
eq(mada_sub_ext_order_id(7,  '202612', 3), 'mada_sub7_202612_r3',  'ext_order_id: inna sub/okres/próba');
ok(mada_sub_ext_order_id(1,'202601') !== mada_sub_ext_order_id(1,'202602'), 'ext_order_id: różne okresy != ');

// ── retry: max 1x/dzień, max 3 próby ───────────────────────────
eq(mada_sub_retry_offset_days(1), 1,    'retry: po 1. próbie -> +1 dzień');
eq(mada_sub_retry_offset_days(2), 3,    'retry: po 2. próbie -> +3 dni');
eq(mada_sub_retry_offset_days(3), null, 'retry: po 3. próbie -> koniec (pauza)');
eq(mada_sub_max_attempts(), 3, 'max_attempts: 3');
// suma dni ponowień mieści się w limicie 31 dni i nie częściej niż 1x/dzień
ok((mada_sub_retry_offset_days(1) + mada_sub_retry_offset_days(2)) <= 31, 'retry: łączny rozrzut <= 31 dni');
ok(mada_sub_retry_offset_days(1) >= 1 && mada_sub_retry_offset_days(2) >= 1, 'retry: każdy odstęp >= 1 dzień');

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

// ── manage_token: 64 hex, losowy ───────────────────────────────
$tok = mada_sub_gen_manage_token();
ok(strlen($tok) === 64, 'manage_token: długość 64');
ok(ctype_xdigit($tok), 'manage_token: same znaki hex');
ok(mada_sub_gen_manage_token() !== mada_sub_gen_manage_token(), 'manage_token: dwa wywołania różne');

// ── Wynik ──────────────────────────────────────────────────────
echo "\nTesty logiki recurring: {$T['pass']} OK";
if ($T['fail'] > 0) { echo ", {$T['fail']} BŁĄD\n"; exit(1); }
echo ", 0 błędów\n";
exit(0);
