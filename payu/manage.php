<?php
/* ═══════════════════════════════════════════════════════════════
   PayU cykliczne - samoobsługowe zarządzanie subskrypcją (darczyńca).
   Dostęp przez sekretny link z maila: manage.php?token=<manage_token>.
   GET  -> podsumowanie + przycisk „Anuluj".
   POST -> anulowanie (CSRF = hash z manage_token).
  ═══════════════════════════════════════════════════════════════ */
require __DIR__ . '/db.php';
require __DIR__ . '/recurring-lib.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/sheet.php';

function manage_csrf(string $manageToken): string {
    return hash('sha256', $manageToken . '|misja-mada-cancel');
}

$token = isset($_GET['token']) ? (string) $_GET['token'] : (isset($_POST['token']) ? (string) $_POST['token'] : '');
$sub   = ($token !== '') ? payu_sub_by_manage_token($token) : null;

$done = false; $error = '';

if ($sub && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
    if (!hash_equals(manage_csrf($sub['manage_token']), $csrf)) {
        $error = 'Sesja wygasła. Odśwież stronę i spróbuj ponownie.';
    } elseif (($_POST['action'] ?? '') === 'cancel') {
        if (payu_sub_cancel((int) $sub['id'])) {
            $fresh = payu_sub_get((int) $sub['id']);
            mada_mail_cancelled($fresh);
            if (($fresh['goal'] ?? '') === 'adopcja') {
                // Adopcja: arkusz „Adopcja Serca" -> „anulowana" + powiadomienie fundacji
                // niezawodnym kanalem Gmail (Apps Script), zamiast PHP mail() lapanego jako spam.
                mada_adopcja_cancel_sheet($fresh);
            } else {
                mada_mail_foundation($fresh, 'anulowana');
            }
            $done = true;
            $sub  = $fresh;
        } else {
            $error = 'Subskrypcja była już zakończona.';
        }
    }
}

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
$amount = $sub ? (($sub['amount_grosze'] % 100 === 0) ? (string) intdiv($sub['amount_grosze'], 100) : number_format($sub['amount_grosze'] / 100, 2, ',', '')) : '';
http_response_code($sub ? 200 : 404);
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8" />
<title>Zarządzanie darowizną cykliczną - Misja MADA</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex" />
<link rel="icon" href="/favicon.svg" type="image/svg+xml" />
<link rel="stylesheet" href="/assets/site.css" />
<style>
  .mg-wrap{max-width:560px;margin:60px auto;padding:0 20px}
  .mg-card{background:#fff;border:1px solid var(--rule,#e7ddd0);border-radius:18px;padding:36px 32px;box-shadow:0 30px 70px -50px rgba(66,41,24,.4)}
  .mg-card h1{font-size:24px;margin:0 0 6px}
  .mg-row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f0e9de;font-size:15px}
  .mg-row b{color:#5a4836}
  .mg-actions{margin-top:24px}
  .mg-ok{background:#eaf6ec;border:1px solid #b9dcc0;color:#23613a;padding:14px 16px;border-radius:12px;margin:0 0 18px}
  .mg-err{background:#fcebea;border:1px solid #f0b8b3;color:#9a2b22;padding:14px 16px;border-radius:12px;margin:0 0 18px}
  .mg-note{color:#6b5a4a;font-size:13.5px;margin-top:18px;line-height:1.6}
  .btn-danger{background:#a23b2e;color:#fff;border:none;padding:13px 24px;border-radius:10px;font-size:15px;cursor:pointer}
  .btn-danger:hover{background:#8a3025}
</style>
</head>
<body>
<div class="mg-wrap">
  <div class="mg-card">
<?php if (!$sub): ?>
    <h1>Nie znaleziono subskrypcji</h1>
    <p>Link jest nieprawidłowy lub wygasł. W razie pytań napisz na <a href="mailto:kontakt@misjamada.pl">kontakt@misjamada.pl</a>.</p>
<?php else: ?>
    <h1>Twoja darowizna cykliczna</h1>
    <?php if ($done): ?>
      <div class="mg-ok">Subskrypcja została anulowana. Nie pobierzemy już kolejnych płatności. Dziękujemy za wsparcie!</div>
    <?php elseif ($error): ?>
      <div class="mg-err"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="mg-row"><span>Cel</span><b><?= h($sub['goal_label']) ?></b></div>
    <div class="mg-row"><span>Kwota</span><b><?= h($amount) ?> <?= h($sub['currency']) ?> / miesiąc</b></div>
    <?php if (!empty($sub['children'])): ?><div class="mg-row"><span>Liczba dzieci</span><b><?= (int)$sub['children'] ?></b></div><?php endif; ?>
    <div class="mg-row"><span>Status</span><b><?= $sub['status']==='active'?'aktywna':($sub['status']==='cancelled'?'anulowana':($sub['status']==='paused'?'wstrzymana':'oczekująca')) ?></b></div>
    <?php if ($sub['status']==='active'): ?>
      <div class="mg-row"><span>Kolejne obciążenie</span><b><?= h($sub['next_charge_at']) ?></b></div>
    <?php endif; ?>
    <div class="mg-row"><span>Opłaconych miesięcy</span><b><?= (int)$sub['months_paid'] ?></b></div>

    <?php if (in_array($sub['status'], ['active','paused','pending_first'], true)): ?>
      <div class="mg-actions">
        <form method="post" onsubmit="return confirm('Na pewno anulować comiesięczną darowiznę?');">
          <input type="hidden" name="token" value="<?= h($sub['manage_token']) ?>" />
          <input type="hidden" name="csrf" value="<?= h(manage_csrf($sub['manage_token'])) ?>" />
          <input type="hidden" name="action" value="cancel" />
          <button type="submit" class="btn-danger">Anuluj darowiznę cykliczną</button>
        </form>
        <p class="mg-note">Rezygnacja jest możliwa w każdej chwili i działa natychmiast. Dotychczasowe wsparcie pozostaje nieocenione 🤍</p>
      </div>
    <?php endif; ?>
<?php endif; ?>
  </div>
</div>
</body>
</html>
