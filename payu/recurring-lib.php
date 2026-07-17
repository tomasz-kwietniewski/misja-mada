<?php
/* ═══════════════════════════════════════════════════════════════
   PayU - płatności cykliczne: CZYSTA LOGIKA (bez bazy, bez sieci)
   ───────────────────────────────────────────────────────────────
   Funkcje deterministyczne, testowalne jednostkowo (tests/run-recurring.php).
   Reguły wynikają ze „Zbioru wymagań usługi cyklicznej" PayU
   (docs/payu-recurring-wymagania.md) oraz specyfikacji pakietu 6.
  ═══════════════════════════════════════════════════════════════ */

/**
 * Dzień miesiąca obciążenia (kotwica) wyliczony z daty startu.
 * Klamrowany do 1..28, by każdy miesiąc miał ten dzień (luty bezpieczny).
 */
function mada_sub_charge_day(string $startDate): int {
    $d = DateTime::createFromFormat('Y-m-d', $startDate);
    $day = $d ? (int)$d->format('j') : 1;
    return max(1, min(28, $day));
}

/**
 * Data kolejnego obciążenia: miesiąc(e) po $fromDate, w dniu $chargeDay.
 * Liczone przez „first day of" + N miesięcy, więc nie przeskakuje miesięcy
 * przy końcówkach (np. 31). $chargeDay i tak klamrowany do 1..28.
 */
function mada_sub_next_charge_date(string $fromDate, int $chargeDay, int $months = 1): string {
    $chargeDay = max(1, min(28, $chargeDay));
    $d = DateTime::createFromFormat('Y-m-d', $fromDate);
    if (!$d) return $fromDate;
    $d->modify('first day of this month');
    $d->modify('+' . max(1, $months) . ' month');
    $d->setDate((int)$d->format('Y'), (int)$d->format('n'), $chargeDay);
    return $d->format('Y-m-d');
}

/**
 * Data wygaśnięcia subskrypcji (do informacji dla płatnika oraz
 * threeDsAuthentication.recurring.expiry). Domyślnie start + 12 mies.
 */
function mada_sub_expiry_date(string $startDate, int $minMonths = 12): string {
    $d = DateTime::createFromFormat('Y-m-d', $startDate);
    if (!$d) return $startDate;
    $day = max(1, min(28, (int)$d->format('j')));
    $d->modify('first day of this month');
    $d->modify('+' . max(1, $minMonths) . ' month');
    $d->setDate((int)$d->format('Y'), (int)$d->format('n'), $day);
    return $d->format('Y-m-d');
}

/**
 * Publiczny numer subskrypcji w extOrderId = subId + stały offset. Widoczny jest
 * w mailu PayU jako „numer zlecenia"; sam offset ukrywa niską, kolejną liczbę
 * (np. „1" = pierwszy darczyńca). Odwracalny - notyfikacje wciąż trafiają do
 * właściwej subskrypcji. Offset to tylko zaciemnienie kosmetyczne, NIE zabezpieczenie.
 */
function mada_sub_id_offset(): int { return 548200; }
function mada_sub_public_id(int $subId): int { return $subId + mada_sub_id_offset(); }
function mada_sub_id_from_public(int $publicId): int { return $publicId - mada_sub_id_offset(); }

/**
 * Klucz idempotencji obciążenia - unikalny w obrębie POS (PayU odrzuca duplikat
 * extOrderId). Format: mada{publicId}_{RRRRMM} (+ _r{n} przy ponowieniu > 1),
 * gdzie publicId = subId + offset (patrz mada_sub_public_id).
 */
function mada_sub_ext_order_id(int $subId, string $period, int $attempt = 1): string {
    $base = 'mada' . mada_sub_public_id($subId) . '_' . $period;
    return $attempt > 1 ? $base . '_r' . $attempt : $base;
}

/**
 * Harmonogram ponowień po nieudanym obciążeniu.
 * Wejście: numer próby, która WŁAŚNIE się nie powiodła (1-based).
 * Wyjście: liczba dni do kolejnej próby, albo null = wyczerpano (-> pauza).
 * Zgodne z limitem PayU: nie częściej niż 1x/dzień, max 31 dni.
 *   próba 1 nieudana -> +1 dzień, próba 2 -> +3 dni, próba 3 -> koniec.
 */
function mada_sub_retry_offset_days(int $failedAttemptNo): ?int {
    $plan = [1 => 1, 2 => 3];   // po której próbie ile dni czekać
    return $plan[$failedAttemptNo] ?? null;
}

/** Maksymalna liczba prób obciążenia w jednym okresie (1 pierwotna + 2 ponowienia). */
function mada_sub_max_attempts(): int {
    return 3;
}

/**
 * Decyzja o wyniku próby obciążenia STANDARD - ODPORNA NA PODWÓJNE OBCIĄŻENIE.
 * $responseStatus:
 *   - statusCode z odpowiedzi PayU (np. 'SUCCESS', 'ERROR_...') gdy PayU ODPOWIEDZIAŁO,
 *   - null gdy sam request padł zanim doszła odpowiedź (timeout / błąd połączenia /
 *     niepoprawny JSON) - czyli wynik NIEZNANY (PayU mogło obciążyć kartę albo nie).
 * Zwraca:
 *   'success' - PayU potwierdziło (SUCCESS): zapisz, przesuń harmonogram, NIE ponawiaj.
 *   'retry'   - PayU JAWNIE odmówiło (odpowiedź != SUCCESS): PayU na pewno NIE obciążyło,
 *               więc bezpiecznie ponowić z nowym extOrderId.
 *   'hold'    - wynik NIEZNANY (transport padł): NIGDY nie ponawiać nowym extOrderId
 *               (to właśnie prowadziło do podwójnego obciążenia), tylko wstrzymać do
 *               ręcznej weryfikacji w PayU. Jeśli obciążenie jednak przeszło, notyfikacja
 *               COMPLETED oznaczy dany charge jako completed (self-reconcile).
 */
function mada_charge_decision(?string $responseStatus): string {
    // null = transport padł; '' = odpowiedź bez statusu (nietypowa) - oba niejednoznaczne -> hold.
    if ($responseStatus === null || $responseStatus === '') return 'hold';
    return $responseStatus === 'SUCCESS' ? 'success' : 'retry';
}

/** Czy subskrypcja w danym statusie może być obciążona przez scheduler. */
function mada_sub_can_charge(string $status): bool {
    return $status === 'active';
}

/** Statusy końcowe (nie wracają do obiegu). */
function mada_sub_is_final(string $status): bool {
    return $status === 'cancelled';
}

/**
 * Zwięzły opis subskrypcji (pole `description` zamówienia + informacja dla
 * płatnika). Wymóg PayU: identyfikacja subskrypcji + kwota + okres + data.
 */
function mada_sub_description(string $goalLabel, int $amountGrosze, string $currency, string $expiry): string {
    $amount = ($amountGrosze % 100 === 0)
        ? (string) intdiv($amountGrosze, 100)                 // całe złotówki: „70"
        : number_format($amountGrosze / 100, 2, '.', '');     // z groszami: „125.50"
    return sprintf('%s, %s %s/mies., obciążenie co miesiąc do %s', $goalLabel, $amount, $currency, $expiry);
}

/** Klucz idempotencji PIERWSZEJ płatności (FIRST) - unikalny w obrębie POS. Format: mada{publicId}. */
function mada_sub_first_ext_order_id(int $subId): string {
    return 'mada' . mada_sub_public_id($subId);
}

/**
 * Czy extOrderId należy do JEDNORAZOWEJ darowizny (create-order.php nadaje prefiks
 * 'madaone_'). Odróżnia je od FIRST/STANDARD subskrypcji cyklicznych, które mają
 * format mada{publicId}[...]. Używane w notify.php do logowania opłaconych darowizn.
 */
function mada_donation_is_ext(string $ext): bool {
    return strncmp($ext, 'madaone_', 8) === 0;
}

/**
 * Rozpoznaje typ zamówienia po extOrderId z notyfikacji PayU.
 * Zwraca ['type' => 'first'|'standard'|'other', 'subId' => ?int, 'period' => ?string, 'attempt' => int].
 * Rozpoznaje format bieżący (mada{publicId}[...]) ORAZ - dla wstecznej zgodności -
 * stary (mada_first{id} / mada_sub{id}_...), by nie zgubić notyfikacji subskrypcji
 * założonych przed zmianą numeracji.
 */
function mada_sub_classify_ext(string $ext): array {
    // Bieżący format: publicId = subId + offset (odwracany do subId).
    if (preg_match('/^mada(\d+)$/', $ext, $m)) {
        return ['type' => 'first', 'subId' => mada_sub_id_from_public((int) $m[1]), 'period' => null, 'attempt' => 1];
    }
    if (preg_match('/^mada(\d+)_(\d{6})(?:_r(\d+))?$/', $ext, $m)) {
        return ['type' => 'standard', 'subId' => mada_sub_id_from_public((int) $m[1]), 'period' => $m[2],
                'attempt' => isset($m[3]) ? (int) $m[3] : 1];
    }
    // Wsteczna zgodność: stary format bez offsetu (surowy subId).
    if (preg_match('/^mada_first(\d+)$/', $ext, $m)) {
        return ['type' => 'first', 'subId' => (int) $m[1], 'period' => null, 'attempt' => 1];
    }
    if (preg_match('/^mada_sub(\d+)_(\d{6})(?:_r(\d+))?$/', $ext, $m)) {
        return ['type' => 'standard', 'subId' => (int) $m[1], 'period' => $m[2],
                'attempt' => isset($m[3]) ? (int) $m[3] : 1];
    }
    return ['type' => 'other', 'subId' => null, 'period' => null, 'attempt' => 1];
}

/**
 * Wyłuskuje token wielokrotny (TOKC_) i zamaskowany numer karty z odpowiedzi PayU
 * (synchronicznej lub z pobranego zamówienia). Przeszukuje strukturę rekurencyjnie,
 * bo PayU umieszcza je w różnych miejscach (payMethods.payMethod / orders[].payMethod).
 * Zwraca ['token' => 'TOKC_...', 'mask' => '4444...1111'|null] albo null, gdy brak.
 */
function mada_sub_extract_token(array $data): ?array {
    $token = null;
    $mask  = null;
    $walk = function ($node) use (&$walk, &$token, &$mask) {
        if (is_array($node)) {
            foreach ($node as $v) $walk($v);
        } elseif (is_string($node)) {
            if ($token === null && strncmp($node, 'TOKC_', 5) === 0) $token = $node;
            if ($mask === null && preg_match('/^\d{4,6}\*+\d{2,4}$/', $node))  $mask  = $node;
        }
    };
    $walk($data);
    return $token === null ? null : ['token' => $token, 'mask' => $mask];
}

/** Token do linku zarządzania/anulowania subskrypcji (losowy, 64 hex). */
function mada_sub_gen_manage_token(): string {
    return bin2hex(random_bytes(32));
}

/**
 * Retencja logu notyfikacji PayU (RODO - ograniczenie przechowywania):
 *  - usuwa linie ze znacznikiem czasu (ISO-8601 na początku linii, do pierwszego
 *    TAB) starszym niż $cutoffTs; linie bez parsowalnej daty też usuwa (format
 *    w 100% kontroluje notify.php, więc nieparsowalna linia = uszkodzona),
 *  - z zachowanych linii wycina historyczne pole "email=..." (wpisy sprzed
 *    zmiany, po której notify.php nie loguje już e-maila darczyńcy).
 * Całość pod flock(LOCK_EX) - bezpieczne współbieżnie z dopisywaniem
 * FILE_APPEND|LOCK_EX w notify.php. Jedyne I/O to wskazany plik (testowalne).
 * Zwraca ['removed' => int, 'redacted' => int]; zera, gdy pliku brak.
 */
function mada_log_retention(string $file, int $cutoffTs): array {
    $out = ['removed' => 0, 'redacted' => 0];
    if (!is_file($file)) return $out;
    $fh = @fopen($file, 'c+');
    if (!$fh) return $out;
    try {
        if (!flock($fh, LOCK_EX)) return $out;
        $content = (string) stream_get_contents($fh);
        $kept = [];
        foreach (explode("\n", $content) as $line) {
            if ($line === '') continue;   // końcówka po ostatnim \n / puste linie
            $tab   = strpos($line, "\t");
            $stamp = $tab === false ? $line : substr($line, 0, $tab);
            $ts    = strtotime($stamp);
            if ($ts === false || $ts < $cutoffTs) { $out['removed']++; continue; }
            $clean = preg_replace('/\temail=[^\t]*/', '', $line, 1, $n);
            if ($n > 0) { $out['redacted']++; $line = $clean; }
            $kept[] = $line;
        }
        $newContent = $kept ? implode("\n", $kept) . "\n" : '';
        if ($newContent !== $content) {
            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, $newContent);
        }
        flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
    return $out;
}
