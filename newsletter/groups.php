<?php
/* TYMCZASOWY helper - listuje grupy MailerLite (id + nazwa), zeby ustalic GROUP_ID.
   NIE ujawnia tokena. DO USUNIECIA po konfiguracji. */
require __DIR__ . '/lib.php';
header('Content-Type: text/plain; charset=utf-8');

if (MAILERLITE_TOKEN === '') {
    echo "BRAK TOKENA - utworz newsletter/secret/mailerlite-config.php z define('MAILERLITE_TOKEN', '...').\n";
    exit;
}
try {
    list($code, $resp) = ml_request('GET', '/groups', null);
    echo "HTTP $code\n----\n";
    if (!empty($resp['data'])) {
        foreach ($resp['data'] as $g) {
            $cnt = isset($g['active_count']) ? $g['active_count'] : '?';
            echo $g['id'] . "  =  " . $g['name'] . "  (" . $cnt . " osob)\n";
        }
        echo "----\nUstawiony obecnie MAILERLITE_GROUP_ID: '" . MAILERLITE_GROUP_ID . "'\n";
    } else {
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo 'EXCEPTION: ' . $e->getMessage() . "\n";
}

// ?sub=email -> pokaz status i grupy konkretnego subskrybenta
$sub = isset($_GET['sub']) ? $_GET['sub'] : '';
if ($sub !== '') {
    try {
        list($c2, $r2) = ml_request('GET', '/subscribers/' . rawurlencode($sub), null);
        echo "\n--- subscriber: $sub (HTTP $c2) ---\n";
        if (isset($r2['data'])) {
            echo 'status: ' . $r2['data']['status'] . "\n";
            $gids = array_map(function ($g) { return $g['id']; }, isset($r2['data']['groups']) ? $r2['data']['groups'] : []);
            echo 'groups: ' . json_encode($gids) . "\n";
        } else {
            echo json_encode($r2, JSON_UNESCAPED_UNICODE) . "\n";
        }
    } catch (Exception $e) {
        echo 'EXCEPTION(sub): ' . $e->getMessage() . "\n";
    }
}
