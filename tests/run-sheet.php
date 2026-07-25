<?php
/* ═══════════════════════════════════════════════════════════════
   Testy CZYSTEJ LOGIKI payloadów do arkusza Google (payu/sheet.php).
   BEZ zależności (sieci, bazy) - sprawdzamy budowanie payloadów, nie wysyłkę.
   Uruchom:  php tests/run-sheet.php
   Kod wyjścia != 0, gdy którykolwiek test nie przejdzie (dla CI).
  ═══════════════════════════════════════════════════════════════ */

require __DIR__ . '/../payu/sheet.php';

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

// Wiersz subskrypcji jak z bazy (payu_sub_get).
$subAdopcja = [
    'id' => 42, 'goal' => 'adopcja', 'goal_label' => 'Adopcja Serca',
    'first_name' => 'Anna', 'last_name' => 'Kowalska', 'email' => 'anna@example.com',
    'children' => 2, 'amount_grosze' => 14000, 'currency' => 'PLN', 'months_paid' => 7,
];
$subInny = array_merge($subAdopcja, ['goal' => 'statutowe', 'goal_label' => 'Cele statutowe']);

// ── adopcja-charge: kolejna opłacona rata karty ────────────────
$p = mada_adopcja_charge_payload($subAdopcja, 'mada548242_202607', 'PAYU123');
eq($p['type'], 'adopcja-charge', 'charge: typ zdarzenia');
eq($p['subId'], '42', 'charge: subId jako string (arkusz porównuje tekstowo)');
eq($p['monthsPaid'], 7, 'charge: licznik miesięcy jako int (wartość ABSOLUTNA z bazy)');
eq($p['extOrderId'], 'mada548242_202607', 'charge: extOrderId przekazany');
eq($p['payuOrderId'], 'PAYU123', 'charge: payuOrderId przekazany');
ok(!isset($p['secret']), 'charge: payload NIE zawiera sekretu (dokłada go mada_sheet_post)');
// cele inne niż adopcja nie mają wiersza w zakładce „Adopcja Serca"
eq(mada_adopcja_charge_payload($subInny, 'x', 'y'), null, 'charge: cel != adopcja -> null');
eq(mada_adopcja_charge_payload(['goal' => 'adopcja', 'id' => 5], 'x', 'y')['monthsPaid'], 0,
   'charge: brak months_paid -> 0 (nie null/pusty string)');
eq(mada_adopcja_charge_payload(['goal' => 'adopcja', 'id' => 5, 'months_paid' => '12'], 'x', 'y')['monthsPaid'], 12,
   'charge: months_paid ze stringa rzutowane na int');
eq(mada_adopcja_charge_payload([], 'x', 'y'), null, 'charge: pusta subskrypcja -> null');

// ── adopcja-cancel: anulowanie subskrypcji (dotąd nietestowane) ──
$c = mada_adopcja_cancel_payload($subAdopcja);
eq($c['type'], 'adopcja-cancel', 'cancel: typ zdarzenia');
eq($c['subId'], '42', 'cancel: subId jako string');
eq($c['amount'], '140.00', 'cancel: kwota z groszy na złote z dwoma miejscami');
eq($c['currency'], 'PLN', 'cancel: waluta');
eq($c['dzieci'], 2, 'cancel: liczba dzieci');
eq(mada_adopcja_cancel_payload($subInny), null, 'cancel: cel != adopcja -> null');
eq(mada_adopcja_cancel_payload(['goal' => 'adopcja', 'id' => 1])['amount'], '0.00',
   'cancel: brak kwoty -> 0.00');

// ── oba helpery rozdzielają cele rozłącznie (nie ma wiersza w obu zakładkach) ──
ok(mada_adopcja_charge_payload($subInny, 'x', 'y') === null
   && mada_adopcja_cancel_payload($subInny) === null,
   'rozdział celów: cele inne niż adopcja pomijane przez oba helpery adopcyjne');

// ── Wynik ──────────────────────────────────────────────────────
echo "\nTesty payloadów arkusza: {$T['pass']} OK";
if ($T['fail'] > 0) { echo ", {$T['fail']} BŁĄD\n"; exit(1); }
echo ", 0 błędów\n";
exit(0);
