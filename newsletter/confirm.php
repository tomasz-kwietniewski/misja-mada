<?php
/* ═══════════════════════════════════════════════════════════════
   Newsletter - KROK 2: potwierdzenie adresu (link z maila)
   …/newsletter/confirm.php?token=…
   Sprawdza token -> dodaje do MailerLite (active) -> przekierowuje
   na newsletter-zapisano.html. Token zły/wygasły -> strona błędu.
  ═══════════════════════════════════════════════════════════════ */
require __DIR__ . '/lib.php';

const NL_PENDING_TTL = 604800; // 7 dni

function nl_error_page($title, $msg) {
    http_response_code(410);
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex"><title>' . $t . ' - Fundacja Misja MADA</title>'
       . '<link rel="stylesheet" href="/assets/site.css"></head>'
       . '<body style="background:var(--cream,#faf5ee);font-family:system-ui,sans-serif;">'
       . '<div style="max-width:560px;margin:12vh auto;background:#fff;border-radius:18px;padding:48px 40px;text-align:center;box-shadow:0 18px 50px rgba(66,41,24,.10);">'
       . '<h1 style="font-family:var(--font-head,Georgia,serif);color:var(--brown,#422918);font-size:28px;margin:0 0 14px;">' . $t . '</h1>'
       . '<p style="color:#5a4836;line-height:1.65;margin:0 0 26px;">' . $m . '</p>'
       . '<a href="/strona-glowna.html" class="btn btn-primary" style="text-decoration:none;">Wróć na stronę główną</a>'
       . '</div></body></html>';
    exit;
}

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
if ($token === '' || !preg_match('/^[a-f0-9]{20,64}$/', $token)) {
    nl_error_page('Nieprawidłowy link', 'Link potwierdzający jest nieprawidłowy. Spróbuj zapisać się ponownie na stronie.');
}

$path = nl_pending_path($token);
if (!is_readable($path)) {
    nl_error_page('Link wygasł lub został już użyty', 'Ten link potwierdzający został już wykorzystany albo wygasł. Jeśli to pomyłka, zapisz się ponownie na stronie.');
}

$rec = json_decode(@file_get_contents($path), true);
if (!is_array($rec) || empty($rec['email'])) {
    @unlink($path);
    nl_error_page('Nieprawidłowy link', 'Nie udało się odczytać zgłoszenia. Zapisz się ponownie na stronie.');
}
if (isset($rec['ts']) && (time() - (int)$rec['ts']) > NL_PENDING_TTL) {
    @unlink($path);
    nl_error_page('Link wygasł', 'Ten link potwierdzający stracił ważność. Zapisz się ponownie na stronie - wyślemy nowy.');
}

try {
    list($code, $resp) = ml_add_subscriber($rec['email'], isset($rec['imie']) ? $rec['imie'] : '', isset($rec['ip']) ? $rec['ip'] : '');
    if ($code === 200 || $code === 201) {
        @unlink($path);
        header('Location: ' . NL_SITE_BASE . '/newsletter-zapisano.html', true, 302);
        exit;
    }
    error_log('[Newsletter confirm] MailerLite HTTP ' . $code . ': ' . json_encode($resp));
} catch (Exception $e) {
    error_log('[Newsletter confirm] ' . $e->getMessage());
}
nl_error_page('Coś poszło nie tak', 'Nie udało się dokończyć zapisu na newsletter. Spróbuj ponownie za chwilę lub napisz na kontakt@misjamada.pl.');
