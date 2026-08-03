<?php
/* ═══════════════════════════════════════════════════════════════
   Adopcja Serca - KROK 2: potwierdzenie e-maila (link z maila).
   …/adopcja/potwierdz.php?token=…
   Token OK -> signup confirmed, darczyńca + adopcje (pending, bez
   dziecka) w MySQL, mail powitalny z danymi do przelewu, powiadomienie
   fundacji, opcjonalny newsletter, lustro do arkusza Google.
   Wzorzec strony wynikowej: newsletter/confirm.php (inline HTML PL).
  ═══════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../payu/mail.php';
require_once __DIR__ . '/../payu/sheet.php';

const ADOPT_SIGNUP_TTL_DAYS = 7;

function pt_page(int $code, string $title, string $msg): void {
    http_response_code($code);
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex"><title>' . $t . ' - Fundacja Misja MADA</title>'
       . '<link rel="stylesheet" href="/assets/site.css"></head>'
       . '<body style="background:var(--cream,#faf5ee);font-family:system-ui,sans-serif;">'
       . '<div style="max-width:560px;margin:12vh auto;background:#fff;border-radius:18px;padding:48px 40px;text-align:center;box-shadow:0 18px 50px rgba(66,41,24,.10);">'
       . '<h1 style="font-family:var(--font-head,Georgia,serif);color:var(--brown,#422918);font-size:28px;margin:0 0 14px;">' . $t . '</h1>'
       . '<p style="color:#5a4836;line-height:1.65;margin:0 0 26px;">' . $m . '</p>'
       . '<a href="/index.html" class="btn btn-primary" style="text-decoration:none;">Wróć na stronę główną</a>'
       . '</div></body></html>';
    exit;
}

$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    pt_page(410, 'Nieprawidłowy link', 'Link potwierdzający jest nieprawidłowy. Wyślij zgłoszenie ponownie na stronie.');
}

try {
    adopt_db_ensure_schema();
    $pdo = payu_db();
    $st = $pdo->prepare('SELECT * FROM adopt_signups WHERE token = ?');
    $st->execute([$token]);
    $sg = $st->fetch();
    if (!$sg) {
        pt_page(410, 'Nie znaleziono zgłoszenia', 'Link mógł wygasnąć. Wyślij zgłoszenie ponownie na stronie.');
    }
    if ($sg['status'] === 'confirmed') {
        pt_page(200, 'Zgłoszenie już potwierdzone', 'To zgłoszenie zostało potwierdzone wcześniej. Dziękujemy!');
    }
    if ($sg['status'] === 'expired'
        || strtotime((string)$sg['created_at']) < time() - ADOPT_SIGNUP_TTL_DAYS * 86400) {
        pt_page(410, 'Link wygasł', 'Ten link stracił ważność. Wyślij zgłoszenie ponownie na stronie - dostaniesz nowy.');
    }

    $d = json_decode((string)$sg['payload'], true) ?: [];
    $imie = trim((string)($d['imie'] ?? ''));
    $nazwisko = trim((string)($d['nazwisko'] ?? ''));
    $email = (string)$sg['email'];
    $dzieci = max(1, min(10, (int)($d['dzieci'] ?? 1)));

    // ── Darczyńca: dopnij po e-mailu albo utwórz ─────────────────
    $st = $pdo->prepare('SELECT id FROM adopt_donors WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $donorId = $st->fetchColumn();
    if ($donorId === false) {
        $donorId = adopt_donor_insert([
            'full_name' => trim($imie . ' ' . $nazwisko),
            'email'     => $email,
            'phone'     => trim((string)($d['telefon'] ?? '')) ?: null,
            'source'    => 'site',
            'notes'     => 'Adres: ' . trim((string)($d['adres'] ?? '')),
        ]);
    }
    $donorId = (int)$donorId;

    // ── Okres i częstotliwość z formularza ───────────────────────
    $duration = ($d['forma'] ?? '') === 'czasowa' ? 'fixed' : 'indefinite';
    $startM = adopt_parse_month_token((string)($d['od'] ?? '')) ?? ((string)($d['od'] ?? '') ?: null);
    if (!adopt_month_valid((string)$startM)) $startM = date('Y-m');
    $endM = adopt_parse_month_token((string)($d['do'] ?? '')) ?? ((string)($d['do'] ?? '') ?: null);
    if (!adopt_month_valid((string)$endM)) $endM = null;
    if ($duration === 'indefinite') $endM = null;
    $freqMap = ['Miesięcznie' => 'monthly', 'Kwartalnie' => 'quarterly', 'Rocznie' => 'yearly'];
    $freq = $freqMap[(string)($d['czestotliwosc'] ?? '')] ?? 'monthly';

    // ── Adopcje: 1 wiersz na dziecko (pending - fundacja przypisze dziecko) ──
    $adoptionIds = [];
    for ($i = 0; $i < $dzieci; $i++) {
        $adoptionIds[] = adopt_adoption_insert([
            'donor_id' => $donorId, 'child_id' => null,
            'duration' => $duration, 'start_month' => $startM, 'end_month' => $endM,
            'frequency' => $freq, 'amount_grosze' => 7000, 'method' => 'transfer',
            'status' => 'pending',
            'notes' => 'Zgłoszenie przez stronę (' . date('Y-m-d') . ')',
        ]);
    }

    $up = $pdo->prepare("UPDATE adopt_signups SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?");
    $up->execute([(int)$sg['id']]);
    mada_audit('signup.confirm', 'donor', $donorId, ['signup' => (int)$sg['id'], 'adopcje' => $adoptionIds]);

    // ── Mail powitalny (dane do przelewu) - treść 1:1 z Apps Script ──
    $kwota = $dzieci * 70;
    $tytul = 'Adopcja Serca Madagaskar - ' . $imie . ' ' . $nazwisko;
    $okres = (string)($d['okres'] ?? '');
    $okresRow = ($okres !== '' && $duration === 'fixed')
        ? 'Okres zlecenia: <strong>' . mada_mail_esc($okres) . '</strong><br>' : '';
    $inner =
        '<h2 style="font-family:Georgia,serif;font-size:26px;color:#422918;margin:0 0 16px;">Witaj w programie Adopcja Serca, ' . mada_mail_esc($imie) . '!</h2>'
      . '<p style="font-size:15px;line-height:1.65;margin:0 0 16px;">Dziękujemy, że zdecydowałaś/eś się wesprzeć '
      . ($dzieci === 1 ? 'dziecko' : ($dzieci . ' dzieci')) . ' na Madagaskarze. Poniżej znajdziesz dane do przelewu.</p>'
      . '<div style="background:#faf5ee;border-radius:12px;padding:20px 22px;margin:0 0 18px;font-size:14px;line-height:1.7;">'
      . '<strong style="color:#c99d66;">Dane do przelewu (zlecenie stałe)</strong><br>'
      . 'Odbiorca: <strong>Fundacja Misja MADA</strong><br>'
      . 'Konto PLN: <strong>70 1090 1056 0000 0001 5832 5871</strong><br>'
      . 'Kwota: <strong>' . $kwota . ' zł miesięcznie</strong> (' . $dzieci . ' × 70 zł)<br>'
      . $okresRow
      . 'Tytuł przelewu: <strong>' . mada_mail_esc($tytul) . '</strong></div>'
      . '<p style="font-size:15px;line-height:1.65;margin:0 0 16px;">Szczegóły dotyczące konkretnego dziecka objętego Twoim '
      . 'wsparciem przygotowujemy ręcznie - <strong>odezwiemy się do Ciebie w ciągu kilku dni roboczych</strong>, aby je przedstawić.</p>'
      . '<p style="font-size:14px;line-height:1.6;color:#5a4836;margin:0;">Z serca dziękujemy, że jesteś z nami. ❤︎</p>';
    mada_mail_html($email, 'Witaj w programie Adopcja Serca - Fundacja Misja MADA', $inner);

    // ── Powiadomienie fundacji ───────────────────────────────────
    $fInner =
        '<h2 style="font-family:Georgia,serif;font-size:22px;color:#422918;margin:0 0 16px;">Nowe zweryfikowane zgłoszenie - Adopcja Serca</h2>'
      . '<p style="font-size:14px;line-height:1.7;margin:0;">'
      . 'Imię i nazwisko: <strong>' . mada_mail_esc($imie . ' ' . $nazwisko) . '</strong><br>'
      . 'E-mail: ' . mada_mail_esc($email) . '<br>Telefon: ' . mada_mail_esc((string)($d['telefon'] ?? '')) . '<br>'
      . 'Adres: ' . mada_mail_esc((string)($d['adres'] ?? '')) . '<br>'
      . 'Forma: ' . mada_mail_esc((string)($d['formaLabel'] ?? '')) . ($okres !== '' ? ' (' . mada_mail_esc($okres) . ')' : '') . '<br>'
      . 'Liczba dzieci: ' . $dzieci . '<br>Częstotliwość: ' . mada_mail_esc((string)($d['czestotliwosc'] ?? '')) . '<br>'
      . 'Newsletter: ' . (!empty($d['newsletter']) ? 'TAK' : '-') . '</p>'
      . '<p style="font-size:14px;margin:14px 0 0;">Przypisz dziecko w panelu: '
      . '<a href="' . mada_mail_esc(MADA_SITE_BASE . '/panel/zgloszenia.php') . '">panel → Zgłoszenia</a></p>';
    mada_mail_html(MADA_MAIL_FOUND, 'Nowe zgłoszenie do Adopcji Serca: ' . $imie . ' ' . $nazwisko, $fInner);

    // ── Newsletter (jeśli zaznaczony) + lustro do arkusza (best-effort) ──
    if (!empty($d['newsletter'])) {
        mada_newsletter_add_verified($email, $imie);
    }
    mada_sheet_post([
        'type'           => 'adopcja-mirror',
        'imie'           => $imie, 'nazwisko' => $nazwisko, 'email' => $email,
        'telefon'        => (string)($d['telefon'] ?? ''), 'adres' => (string)($d['adres'] ?? ''),
        'forma'          => (string)($d['formaLabel'] ?? ''), 'okres' => $okres,
        'czestotliwosc'  => (string)($d['czestotliwosc'] ?? ''), 'dzieci' => $dzieci,
        'zgoda_wizerunek'=> !empty($d['zgoda_wizerunek']) ? 'TAK' : '',
        'newsletter'     => !empty($d['newsletter']) ? 'TAK' : '',
    ]);

    pt_page(200, 'Dziękujemy za potwierdzenie zgłoszenia!',
        'Odezwiemy się do Ciebie w ciągu kilku dni roboczych z informacją o dziecku objętym Twoim wsparciem. '
      . 'Numer konta i dane do przelewu znajdziesz w mailu, który właśnie do Ciebie wysłaliśmy.');
} catch (Throwable $e) {
    error_log('[adopcja/potwierdz] ' . $e->getMessage());
    pt_page(500, 'Coś poszło nie tak', 'Nie udało się przetworzyć potwierdzenia. Spróbuj ponownie za chwilę albo napisz na kontakt@misjamada.pl.');
}
