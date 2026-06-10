<?php
/* ═══ CMS - wylogowanie ═══════════════════════════════════════════ */
require_once __DIR__ . '/auth.php';
mada_logout();
mada_redirect('login.php');
