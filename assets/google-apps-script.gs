/**
 * ═══════════════════════════════════════════════════════════════
 *  Apps Script dla formularza „Adopcja Serca" - Misja MADA
 *  ═══════════════════════════════════════════════════════════════
 *
 *  CO ROBI:
 *  • Odbiera POST z formularza i dopisuje wpis do arkusza
 *    ze statusem `pending`
 *  • Wysyła e-mail potwierdzający (double opt-in) na adres
 *    podany przez użytkownika z unikalnym linkiem
 *  • Po kliknięciu linku przez użytkownika:
 *      - zmienia status w arkuszu na `verified`
 *      - wysyła powiadomienie do fundacji (kontakt@misjamada.pl)
 *      - wyświetla użytkownikowi stronę z podziękowaniem
 *
 *  ─── INSTRUKCJA WDROŻENIA ──────────────────────────────────────
 *  1) Otwórz Google Drive → New → Google Sheets. Utwórz PUSTY arkusz
 *     (nazwa dowolna, np. "Misja MADA - formularze"). NIE trzeba ręcznie
 *     tworzyć nagłówków - skrypt sam założy zakładki "Adopcja Serca"
 *     i "Newsletter" z nagłówkami przy pierwszym zgłoszeniu.
 *  2) Extensions → Apps Script → wklej cały TEN plik
 *  3) (opcjonalnie) Zmień u góry stałe FOUNDATION_EMAIL / FOUNDATION_NAME
 *  4) Deploy → New deployment
 *       Type: Web app
 *       Execute as: Me (kontakt@misjamada.pl)
 *       Who has access: Anyone
 *  6) Skopiuj wygenerowany URL (kończy się /exec)
 *  7) Wklej URL w pliku `assets/adopcja-form.js`:
 *       const SUBMIT_URL = '…';
 *  8) Pierwsze użycie zażąda autoryzacji: Allow → Advanced →
 *       Go to Apps Script → Allow (wymagane uprawnienia: Mail+Sheets).
 *  ═══════════════════════════════════════════════════════════════
 */

const FOUNDATION_EMAIL = 'kontakt@misjamada.pl';
const FOUNDATION_NAME  = 'Fundacja Misja MADA';
const SITE_URL         = 'https://misjamada.pl';

// Shared secret dla wywołań serwer-do-serwera (PHP -> Apps Script). USTAW przy wdrożeniu.
const SHEET_SECRET = 'USTAW_TEN_SAM_CO_W_PHP';
// Endpoint dopisu na newsletter (zweryfikowany mail) + jego sekret.
const NL_ADD_VERIFIED_URL = 'https://misjamada.pl/newsletter/add-verified.php';
const NL_VERIFIED_SECRET  = 'USTAW_TEN_SAM_CO_W_PHP';
const SHEET_DAROWIZNY = 'Darowizny';

// Nazwy zakładek (skrypt tworzy je automatycznie z nagłówkami przy pierwszym użyciu).
const SHEET_ADOPCJA    = 'Adopcja Serca';
const SHEET_NEWSLETTER = 'Newsletter';

const HEADERS_ADOPCJA = [
  'token', 'status', 'ts_received', 'ts_verified', 'imie', 'nazwisko', 'email',
  'telefon', 'adres', 'forma', 'okres', 'czestotliwosc', 'dzieci',
  'zgoda_regulamin', 'zgoda_wizerunek', 'zgoda_rodo', 'newsletter',
];
const HEADERS_DAROWIZNY = ['ts', 'imie', 'nazwisko', 'email', 'cel', 'kwota', 'waluta', 'extOrderId', 'payuOrderId'];
const HEADERS_NEWSLETTER = ['ts', 'imie', 'email', 'zgoda_rodo'];

/**
 * Zwraca zakładkę o danej nazwie; jeśli nie istnieje (lub jest pusta),
 * tworzy ją i wstawia wiersz nagłówków. Dzięki temu konfiguracja po stronie
 * fundacji to po prostu "utwórz pusty arkusz" - resztę robi skrypt.
 */
function getOrCreateSheet(name, headers) {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let sheet = ss.getSheetByName(name);
  if (!sheet) sheet = ss.insertSheet(name);
  if (sheet.getLastRow() === 0) {
    sheet.appendRow(headers);
    sheet.getRange(1, 1, 1, headers.length).setFontWeight('bold');
    sheet.setFrozenRows(1);
  }
  return sheet;
}

function jsonOut(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}

/**
 * Endpoint POST - odbiera zgłoszenie z formularza na stronie.
 * Dispatch po polu `type`:
 *   • type="adopcja" (lub brak)  → double opt-in + zapis do arkusza
 *   • type="kontakt"             → prosta wysyłka maila do fundacji
 */
function doPost(e) {
  try {
    const data = JSON.parse(e.postData.contents);

    // Wywołania serwer-do-serwera (PHP) wymagają sekretu.
    if (data.type === 'darowizna') {
      if (!secretOk(data)) return jsonOut({ ok: false, error: 'unauthorized' });
      return handleDarowizna(data);
    }
    if (data.type === 'adopcja' && data.status === 'oplacone-PayU') {
      if (!secretOk(data)) return jsonOut({ ok: false, error: 'unauthorized' });
      return handleAdopcjaPaid(data);
    }
    if (data.type === 'kontakt')    return handleKontakt(data);
    if (data.type === 'newsletter') return handleNewsletter(data);
    return handleAdopcja(data);  // domyślnie: adopcja-przelew (double opt-in)
  } catch (err) {
    return jsonOut({ ok: false, error: err.toString() });
  }
}

function secretOk(data) {
  return SHEET_SECRET !== '' && String(data.secret || '') === SHEET_SECRET;
}

function handleAdopcja(data) {
  const sheet = getOrCreateSheet(SHEET_ADOPCJA, HEADERS_ADOPCJA);
  const token = Utilities.getUuid().replace(/-/g, '');
  const ts = new Date();

  sheet.appendRow([
    token, 'pending', ts, '',
    data.imie || '', data.nazwisko || '', data.email || '',
    data.telefon || '', data.adres || '', data.formaLabel || '', data.okres || '',
    data.czestotliwosc || '', data.dzieci || '',
    data.zgoda_regulamin ? 'TAK' : '',
    data.zgoda_wizerunek ? 'TAK' : '',
    data.zgoda_rodo ? 'TAK' : '',
    data.newsletter ? 'TAK' : '',
  ]);

  sendConfirmationEmail(data, token);

  return jsonOut({ ok: true });
}

/* ────────── Newsletter (rozwiązanie pomostowe do czasu MailerLite) ── */
function handleNewsletter(data) {
  const email = String(data.email || '').trim();
  if (!email) return jsonOut({ ok: false, error: 'missing-email' });

  const sheet = getOrCreateSheet(SHEET_NEWSLETTER, HEADERS_NEWSLETTER);
  sheet.appendRow([
    new Date(),
    String(data.imie || '').trim(),
    email,
    data.zgoda_rodo ? 'TAK' : '',
  ]);

  return jsonOut({ ok: true });
}

/* ────────── Formularz kontaktowy ────────────────────────────── */
function handleKontakt(data) {
  const imie = String(data.imie || '').trim();
  const nazwisko = String(data.nazwisko || '').trim();
  const email = String(data.email || '').trim();
  const temat = String(data.temat || '').trim();
  const tresc = String(data.tresc || '').trim();

  if (!imie || !email || !tresc) {
    return ContentService
      .createTextOutput(JSON.stringify({ ok: false, error: 'missing-fields' }))
      .setMimeType(ContentService.MimeType.JSON);
  }

  const subject = '[Formularz kontaktowy] ' + (temat || 'Wiadomość ze strony');
  const body =
`Otrzymano nową wiadomość z formularza kontaktowego na stronie misjamada.pl:

Od:      ${imie} ${nazwisko} <${email}>
Temat:   ${temat || '(bez tematu)'}

${tresc}

---
Możesz odpowiedzieć bezpośrednio na ten e-mail - pole Reply-To wskazuje na nadawcę.`;

  GmailApp.sendEmail(FOUNDATION_EMAIL, subject, body, {
    name: (imie + ' ' + nazwisko).trim(),
    replyTo: email,
  });

  return ContentService
    .createTextOutput(JSON.stringify({ ok: true }))
    .setMimeType(ContentService.MimeType.JSON);
}

/**
 * Endpoint GET - obsługuje link potwierdzający z e-maila.
 *   …/exec?confirm=TOKEN
 */
function doGet(e) {
  const token = e.parameter.confirm;
  if (!token) return htmlError('Brak tokenu potwierdzenia.');

  const sheet = getOrCreateSheet(SHEET_ADOPCJA, HEADERS_ADOPCJA);
  const data = sheet.getDataRange().getValues();
  const headers = data[0];
  const tokenCol = headers.indexOf('token');
  const statusCol = headers.indexOf('status');
  const tsVerCol = headers.indexOf('ts_verified');

  for (let i = 1; i < data.length; i++) {
    if (data[i][tokenCol] === token) {
      const status = data[i][statusCol];
      if (status === 'verified') {
        return htmlSuccess('Zgłoszenie zostało już potwierdzone wcześniej. Dziękujemy!');
      }
      sheet.getRange(i + 1, statusCol + 1).setValue('verified');
      sheet.getRange(i + 1, tsVerCol + 1).setValue(new Date());

      notifyFoundation(data[i], headers);
      sendWelcomeEmail(data[i], headers);
      maybeAddNewsletter(data[i], headers);

      return htmlSuccess(
        'Dziękujemy za potwierdzenie zgłoszenia.',
        'Odezwiemy się do Ciebie w ciągu kilku dni roboczych z informacją o dziecku objętym Twoim wsparciem. ' +
        'Numer konta i dane do przelewu znajdziesz w mailu, który właśnie do Ciebie wysłaliśmy.'
      );
    }
  }
  return htmlError('Nie znaleziono zgłoszenia o podanym tokenie. Link mógł wygasnąć.');
}

/** Wspólna skorupa HTML maila (kolory fundacji). */
function emailShell(inner) {
  return '<!doctype html><html lang="pl"><head><meta charset="utf-8"></head>'
    + '<body style="margin:0;padding:40px 20px;background:#faf5ee;font-family:\'Helvetica Neue\',Arial,sans-serif;color:#1b140e;">'
    + '<table cellpadding="0" cellspacing="0" border="0" style="max-width:560px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;">'
    + '<tr><td style="padding:32px 40px;border-bottom:1px solid rgba(66,41,24,.12);">'
    + '<h1 style="font-family:Georgia,serif;font-size:22px;color:#422918;margin:0;">' + FOUNDATION_NAME + '</h1>'
    + '<p style="font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:#c99d66;font-weight:700;margin:6px 0 0;">Program Adopcja Serca</p></td></tr>'
    + '<tr><td style="padding:32px 40px;">' + inner + '</td></tr>'
    + '<tr><td style="padding:22px 40px;background:#2a1a0e;color:#faf5ee;font-size:12px;line-height:1.6;">'
    + '<strong style="color:#c99d66;">' + FOUNDATION_NAME + '</strong><br>'
    + 'ul. Szosa Chełmińska 271A, 87-100 Toruń<br>'
    + '<a href="' + SITE_URL + '" style="color:#c99d66;">' + SITE_URL + '</a> - '
    + '<a href="mailto:' + FOUNDATION_EMAIL + '" style="color:#c99d66;">' + FOUNDATION_EMAIL + '</a>'
    + '</td></tr></table></body></html>';
}

/** Mail powitalny po weryfikacji zgłoszenia adopcji (dane do przelewu, info o dziecku). */
function sendWelcomeEmail(row, headers) {
  const get = (c) => row[headers.indexOf(c)];
  const imie = esc(get('imie'));
  const nazwisko = esc(get('nazwisko'));
  const dzieci = parseInt(get('dzieci'), 10) || 1;
  const kwota = dzieci * 70;
  const tytul = 'Adopcja Serca Madagaskar - ' + get('imie') + ' ' + get('nazwisko');
  // Dla wsparcia w formie czasowej (okres od-do) podajemy, na jaki czas ustawic zlecenie stale.
  const okres = String(get('okres') || '');
  const forma = String(get('forma') || '');
  const okresRow = (okres && /czasow/i.test(forma))
    ? 'Okres zlecenia: <strong>' + esc(okres) + '</strong><br>'
    : '';
  const inner =
      '<h2 style="font-family:Georgia,serif;font-size:26px;color:#422918;margin:0 0 16px;">Witaj w programie Adopcja Serca, ' + imie + '!</h2>'
    + '<p style="font-size:15px;line-height:1.65;margin:0 0 16px;">Dziękujemy, że zdecydowałaś/eś się wesprzeć '
    + (dzieci === 1 ? 'dziecko' : (dzieci + ' dzieci')) + ' na Madagaskarze. Poniżej znajdziesz dane do przelewu.</p>'
    + '<div style="background:#faf5ee;border-radius:12px;padding:20px 22px;margin:0 0 18px;font-size:14px;line-height:1.7;">'
    + '<strong style="color:#c99d66;">Dane do przelewu (zlecenie stałe)</strong><br>'
    + 'Odbiorca: <strong>Fundacja Misja MADA</strong><br>'
    + 'Konto PLN: <strong>70 1090 1056 0000 0001 5832 5871</strong><br>'
    + 'Kwota: <strong>' + kwota + ' zł miesięcznie</strong> (' + dzieci + ' × 70 zł)<br>'
    + okresRow
    + 'Tytuł przelewu: <strong>' + esc(tytul) + '</strong></div>'
    + '<p style="font-size:15px;line-height:1.65;margin:0 0 16px;">Szczegóły dotyczące konkretnego dziecka objętego Twoim '
    + 'wsparciem przygotowujemy ręcznie - <strong>odezwiemy się do Ciebie w ciągu kilku dni roboczych</strong>, aby je przedstawić.</p>'
    + '<p style="font-size:14px;line-height:1.6;color:#5a4836;margin:0;">Z serca dziękujemy, że jesteś z nami. ❤︎</p>';
  GmailApp.sendEmail(get('email'), 'Witaj w programie Adopcja Serca - Fundacja Misja MADA', '', {
    htmlBody: emailShell(inner), name: FOUNDATION_NAME, replyTo: FOUNDATION_EMAIL,
  });
}

/** Jeśli w zgłoszeniu zaznaczono newsletter - dopisz zweryfikowany mail do MailerLite. */
function maybeAddNewsletter(row, headers) {
  const get = (c) => row[headers.indexOf(c)];
  if (String(get('newsletter')) !== 'TAK') return;
  try {
    UrlFetchApp.fetch(NL_ADD_VERIFIED_URL, {
      method: 'post', contentType: 'application/json', muteHttpExceptions: true,
      payload: JSON.stringify({ email: get('email'), imie: get('imie'), secret: NL_VERIFIED_SECRET }),
    });
  } catch (err) {
    // Best-effort - nie blokuj potwierdzenia zgłoszenia.
  }
}

/* ────────── E-mail potwierdzający (do darczyńcy) ────────────── */
function sendConfirmationEmail(data, token) {
  const confirmUrl = ScriptApp.getService().getUrl() + '?confirm=' + token;
  const subject = 'Potwierdź swoje zgłoszenie - Adopcja Serca - ' + FOUNDATION_NAME;

  const inner =
      '<h2 style="font-family:Georgia,serif;font-size:26px;color:#422918;margin:0 0 18px;">Cześć ' + esc(data.imie) + '!</h2>'
    + '<p style="font-size:15px;line-height:1.65;margin:0 0 14px;">Otrzymaliśmy Twoje zgłoszenie do programu <strong>Adopcja Serca</strong>. '
    + 'Aby je dokończyć, potwierdź, że e-mail <strong>' + esc(data.email) + '</strong> należy do Ciebie.</p>'
    + '<div style="text-align:center;margin:24px 0;"><a href="' + confirmUrl + '" style="display:inline-block;background:#c99d66;color:#2a1a0e;padding:16px 34px;border-radius:10px;font-weight:700;font-size:15px;text-decoration:none;">Potwierdzam zgłoszenie →</a></div>'
    + '<p style="font-size:12px;color:#6b5a4a;word-break:break-all;background:#faf5ee;padding:10px 14px;border-radius:8px;margin:0;">' + confirmUrl + '</p>';
  const html = emailShell(inner);

  GmailApp.sendEmail(data.email, subject, '', {
    htmlBody: html,
    name: FOUNDATION_NAME,
    replyTo: FOUNDATION_EMAIL,
  });
}

/* ────────── E-mail notyfikujący fundację po potwierdzeniu ──── */
function notifyFoundation(row, headers) {
  const get = (col) => row[headers.indexOf(col)];
  const inner =
      '<h2 style="font-family:Georgia,serif;font-size:22px;color:#422918;margin:0 0 16px;">Nowe zweryfikowane zgłoszenie - Adopcja Serca</h2>'
    + '<p style="font-size:14px;line-height:1.7;margin:0;">'
    + 'Imię i nazwisko: <strong>' + esc(get('imie')) + ' ' + esc(get('nazwisko')) + '</strong><br>'
    + 'E-mail: ' + esc(get('email')) + '<br>Telefon: ' + esc(get('telefon')) + '<br>'
    + 'Adres: ' + esc(get('adres')) + '<br>Forma: ' + esc(get('forma')) + ' ' + (get('okres') ? '(' + esc(get('okres')) + ')' : '') + '<br>'
    + 'Liczba dzieci: ' + esc(get('dzieci')) + '<br>Częstotliwość: ' + esc(get('czestotliwosc')) + '<br>'
    + 'Newsletter: ' + esc(get('newsletter') || '-') + '</p>';
  GmailApp.sendEmail(FOUNDATION_EMAIL, 'Nowe zgłoszenie do Adopcji Serca: ' + get('imie') + ' ' + get('nazwisko'), '', {
    htmlBody: emailShell(inner), name: FOUNDATION_NAME,
  });
}

/** Adopcja opłacona kartą (PayU) - zapis do arkusza Adopcja od razu jako verified. */
function handleAdopcjaPaid(data) {
  const sheet = getOrCreateSheet(SHEET_ADOPCJA, HEADERS_ADOPCJA);
  const ts = new Date();
  sheet.appendRow([
    Utilities.getUuid().replace(/-/g, ''), 'oplacone-PayU', ts, ts,
    data.imie || '', data.nazwisko || '', data.email || '',
    data.telefon || '', data.adres || '', data.forma || '', data.okres || '',
    'PayU (karta, cyklicznie)', data.dzieci || '',
    'TAK', data.zgoda_wizerunek || '', 'TAK', data.newsletter || '',
  ]);
  const inner =
      '<h2 style="font-family:Georgia,serif;font-size:22px;color:#422918;margin:0 0 16px;">Adopcja Serca opłacona kartą (PayU)</h2>'
    + '<p style="font-size:14px;line-height:1.7;margin:0;">'
    + esc(data.imie) + ' ' + esc(data.nazwisko) + ' &lt;' + esc(data.email) + '&gt;<br>'
    + 'Adres: ' + esc(data.adres) + '<br>Telefon: ' + esc(data.telefon) + '<br>'
    + 'Forma: ' + esc(data.forma) + ' ' + (data.okres ? '(' + esc(data.okres) + ')' : '') + '<br>'
    + 'Liczba dzieci: ' + esc(data.dzieci) + ' (subskrypcja w panelu PayU)</p>';
  GmailApp.sendEmail(FOUNDATION_EMAIL, 'Adopcja opłacona kartą: ' + data.imie + ' ' + data.nazwisko, '', {
    htmlBody: emailShell(inner), name: FOUNDATION_NAME,
  });
  return jsonOut({ ok: true });
}

/** Jednorazowa darowizna OPŁACONA (z notify.php) - zapis do arkusza Darowizny + powiadomienie. */
function handleDarowizna(data) {
  const sheet = getOrCreateSheet(SHEET_DAROWIZNY, HEADERS_DAROWIZNY);
  sheet.appendRow([
    new Date(), data.imie || '', data.nazwisko || '', data.email || '',
    data.goalLabel || data.goal || '', data.amount || '', data.currency || 'PLN',
    data.extOrderId || '', data.payuOrderId || '',
  ]);
  const inner =
      '<h2 style="font-family:Georgia,serif;font-size:22px;color:#422918;margin:0 0 16px;">Nowa darowizna (opłacona)</h2>'
    + '<p style="font-size:14px;line-height:1.7;margin:0;">'
    + 'Darczyńca: <strong>' + esc(data.imie) + ' ' + esc(data.nazwisko) + '</strong> &lt;' + esc(data.email) + '&gt;<br>'
    + 'Cel: ' + esc(data.goalLabel || data.goal) + '<br>'
    + 'Kwota: <strong>' + esc(data.amount) + ' ' + esc(data.currency || 'PLN') + '</strong><br>'
    + 'PayU order: ' + esc(data.payuOrderId) + '</p>';
  GmailApp.sendEmail(FOUNDATION_EMAIL, 'Nowa darowizna: ' + (data.amount || '') + ' ' + (data.currency || 'PLN'), '', {
    htmlBody: emailShell(inner), name: FOUNDATION_NAME,
  });
  return jsonOut({ ok: true });
}

/* ────────── Strony HTML wyświetlane po kliknięciu w link ────── */
function htmlSuccess(title, body) {
  return wrap(`
    <div style="text-align:center;">
      <div style="width:72px; height:72px; margin:0 auto 22px; background:#c99d66; color:#2a1a0e; border-radius:999px; display:flex; align-items:center; justify-content:center; font-size:40px; line-height:0;">✓</div>
      <h1 style="font-family:Georgia,serif; font-size:30px; color:#422918; margin:0 0 14px;">${esc(title)}</h1>
      ${body ? '<p style="font-size:16px; color:#3c2913; line-height:1.65; max-width:480px; margin:0 auto 30px;">' + esc(body) + '</p>' : ''}
      <a href="${SITE_URL}" style="display:inline-block; background:#422918; color:#faf5ee; padding:14px 30px; border-radius:10px; font-weight:600; text-decoration:none;">Wróć na stronę fundacji</a>
    </div>
  `);
}
function htmlError(msg) {
  return wrap(`
    <div style="text-align:center;">
      <h1 style="font-family:Georgia,serif; font-size:26px; color:#422918; margin:0 0 14px;">Wystąpił błąd</h1>
      <p style="font-size:16px; color:#3c2913; line-height:1.65; max-width:480px; margin:0 auto 30px;">${esc(msg)}</p>
      <a href="${SITE_URL}" style="display:inline-block; background:#422918; color:#faf5ee; padding:14px 30px; border-radius:10px; font-weight:600; text-decoration:none;">Wróć na stronę fundacji</a>
    </div>
  `);
}
function wrap(content) {
  return HtmlService.createHtmlOutput(`
    <!doctype html><html lang="pl"><head><meta charset="utf-8"><title>${FOUNDATION_NAME}</title></head>
    <body style="margin:0; padding:80px 20px; background:#faf5ee; font-family:'Helvetica Neue',Arial,sans-serif;">
      <div style="max-width:560px; margin:0 auto; background:white; border-radius:16px; padding:48px;">
        ${content}
      </div>
    </body></html>
  `);
}
function esc(s) { return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
