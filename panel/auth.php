<?php
/* ═══════════════════════════════════════════════════════════════
   CMS wydarzeń - logowanie, sesja, CSRF (Fundacja Misja MADA)
   ───────────────────────────────────────────────────────────────
   Konta w panel/secret/users.php (poza repo):
     return ['login' => '<hash z password_hash()>', ...];
  ═══════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/lib.php';

const MADA_LOGIN_PAGE   = 'login.php';
const MADA_MAX_ATTEMPTS = 5;     // po tylu nieudanych próbach - blokada czasowa
const MADA_LOCK_SECONDS = 60;    // czas blokady

/* ── Sesja z bezpiecznym cookie ─────────────────────────────────── */
function mada_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['SERVER_PORT'] ?? '') == 443)
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);
    session_name('mada_panel');
    session_start();
}

/* ── Ochrona katalogu sekretów (defense-in-depth) ───────────────
   Gdyby serwer kiedyś nie wykonał plików .php (awaria/maintenance),
   surowa treść users.php/deepl-config.php NIE może pójść z weba.
   Tworzymy .htaccess deny, jeśli go brak. */
function mada_ensure_secret_protected() {
    $dir = __DIR__ . '/secret';
    if (!is_dir($dir)) return;
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht,
            "# Sekrety - niedostepne z weba\n" .
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n"
        );
    }
}
mada_ensure_secret_protected();

/* ── Konta ──────────────────────────────────────────────────────── */
function mada_users() {
    $f = __DIR__ . '/secret/users.php';
    if (!is_readable($f)) return [];
    $u = require $f;
    return is_array($u) ? $u : [];
}

function mada_current_user() {
    mada_session_start();
    return $_SESSION['mada_user'] ?? null;
}

function mada_require_login() {
    if (mada_current_user() === null) {
        mada_redirect(MADA_LOGIN_PAGE);
    }
}

/* ── Throttling nieudanych prób (per sesja) ─────────────────────── */
function mada_login_locked_for() {
    $fails = $_SESSION['mada_fails'] ?? 0;
    $last  = $_SESSION['mada_last_fail'] ?? 0;
    if ($fails >= MADA_MAX_ATTEMPTS) {
        $left = MADA_LOCK_SECONDS - (time() - $last);
        return $left > 0 ? $left : 0;
    }
    return 0;
}

/** Próba logowania. Zwraca true/false. Ustawia sesję przy sukcesie. */
function mada_attempt_login($login, $pass) {
    mada_session_start();
    if (mada_login_locked_for() > 0) return false;

    $login = trim((string)$login);
    $users = mada_users();
    $ok = false;
    if (isset($users[$login]) && is_string($users[$login])) {
        $ok = password_verify((string)$pass, $users[$login]);
    } else {
        // stały koszt nawet dla nieznanego loginu (utrudnia enumerację czasową)
        password_verify((string)$pass, '$2y$10$usesomesillystringfeartrugmw7Q9jR0p3sJ.0Z3z3z3z3z3z3z3');
    }

    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['mada_user'] = $login;
        unset($_SESSION['mada_fails'], $_SESSION['mada_last_fail']);
        return true;
    }
    $_SESSION['mada_fails'] = ($_SESSION['mada_fails'] ?? 0) + 1;
    $_SESSION['mada_last_fail'] = time();
    return false;
}

function mada_logout() {
    mada_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ── CSRF ───────────────────────────────────────────────────────── */
function mada_csrf_token() {
    mada_session_start();
    if (empty($_SESSION['mada_csrf'])) {
        $_SESSION['mada_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['mada_csrf'];
}

function mada_csrf_field() {
    return '<input type="hidden" name="csrf" value="' . mada_esc(mada_csrf_token()) . '">';
}

/** Sprawdza token CSRF z POST; przy błędzie kończy żądanie. */
function mada_csrf_check() {
    mada_session_start();
    $sent = $_POST['csrf'] ?? '';
    $real = $_SESSION['mada_csrf'] ?? '';
    if ($real === '' || !is_string($sent) || !hash_equals($real, $sent)) {
        http_response_code(403);
        exit('Błąd bezpieczeństwa (CSRF). Odśwież stronę i spróbuj ponownie.');
    }
}
