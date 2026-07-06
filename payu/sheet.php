<?php
/* ═══════════════════════════════════════════════════════════════
   Warstwa zapisu do arkusza Google (Apps Script Web App) z PHP.
   Apps Script jest jedyną warstwą dostępu do arkusza; PHP woła go
   serwer-do-serwera z shared secret (MADA_SHEET_SECRET). Best-effort:
   błąd logujemy, NIE przerywamy głównej ścieżki (np. odpowiedzi PayU).
   Konfiguracja w payu/secret/sheet-config.php:
     define('MADA_SHEET_URL', 'https://script.google.com/.../exec');
     define('MADA_SHEET_SECRET', '...');
  ═══════════════════════════════════════════════════════════════ */

$__sheet_secret = __DIR__ . '/secret/sheet-config.php';
if (is_readable($__sheet_secret)) {
    require $__sheet_secret;
}
if (!defined('MADA_SHEET_URL'))    define('MADA_SHEET_URL', '');
if (!defined('MADA_SHEET_SECRET')) define('MADA_SHEET_SECRET', '');

/**
 * Wysyła payload do Apps Script (dopisanie do arkusza + powiadomienie fundacji).
 * $payload MUSI zawierać 'type' (np. 'darowizna' | 'adopcja'). Dokłada 'secret'.
 * Zwraca true przy HTTP 2xx, false w przeciwnym razie (i loguje).
 */
function mada_sheet_post(array $payload): bool {
    if (MADA_SHEET_URL === '' || MADA_SHEET_SECRET === '') {
        error_log('[mada_sheet_post] Brak MADA_SHEET_URL/SECRET - pomijam zapis do arkusza.');
        return false;
    }
    $payload['secret'] = MADA_SHEET_SECRET;
    $ch = curl_init(MADA_SHEET_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,   // Apps Script exec przekierowuje na googleusercontent
        CURLOPT_HTTPHEADER => ['Content-Type: text/plain;charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($res === false || $code < 200 || $code >= 300) {
        error_log('[mada_sheet_post] type=' . ($payload['type'] ?? '?') . ' HTTP ' . $code . ' ' . $err);
        return false;
    }
    return true;
}

/** Dopisuje zweryfikowany mail na newsletter przez wewnetrzny endpoint add-verified.php. */
function mada_newsletter_add_verified(string $email, string $imie): void {
    $cfg = __DIR__ . '/../newsletter/secret/verified-config.php';
    if (is_readable($cfg)) { require_once $cfg; }
    if (!defined('NL_VERIFIED_SECRET') || NL_VERIFIED_SECRET === '') return;
    $ch = curl_init('https://misjamada.pl/newsletter/add-verified.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'imie' => $imie, 'secret' => NL_VERIFIED_SECRET], JSON_UNESCAPED_UNICODE),
    ]);
    @curl_exec($ch); curl_close($ch);
}
