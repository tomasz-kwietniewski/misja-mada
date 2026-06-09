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
 *  1) Otwórz Google Drive → New → Google Sheets. Nazwij arkusz
 *     "Adopcja Serca - zgłoszenia".
 *  2) W pierwszym wierszu wpisz nagłówki kolumn:
 *       A: token
 *       B: status
 *       C: ts_received
 *       D: ts_verified
 *       E: imie
 *       F: nazwisko
 *       G: email
 *       H: telefon
 *       I: adres
 *       J: forma
 *       K: okres
 *       L: czestotliwosc
 *       M: zgoda_regulamin
 *       N: zgoda_wizerunek
 *       O: zgoda_rodo
 *  3) Extensions → Apps Script → wklej cały TEN plik
 *  4) Zmień u góry stałe:
 *       FOUNDATION_EMAIL
 *       FOUNDATION_NAME
 *  5) Deploy → New deployment
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

/**
 * Endpoint POST - odbiera zgłoszenie z formularza na stronie.
 * Dispatch po polu `type`:
 *   • type="adopcja" (lub brak)  → double opt-in + zapis do arkusza
 *   • type="kontakt"             → prosta wysyłka maila do fundacji
 */
function doPost(e) {
  try {
    const data = JSON.parse(e.postData.contents);

    if (data.type === 'kontakt') {
      return handleKontakt(data);
    }
    return handleAdopcja(data);
  } catch (err) {
    return ContentService
      .createTextOutput(JSON.stringify({ ok: false, error: err.toString() }))
      .setMimeType(ContentService.MimeType.JSON);
  }
}

function handleAdopcja(data) {
    const token = Utilities.getUuid().replace(/-/g, '');
    const sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
    const ts = new Date();

    sheet.appendRow([
      token,
      'pending',
      ts,
      '', // ts_verified - puste do potwierdzenia
      data.imie || '',
      data.nazwisko || '',
      data.email || '',
      data.telefon || '',
      data.adres || '',
      data.formaLabel || '',
      data.okres || '',
      data.czestotliwosc || '',
      data.zgoda_regulamin ? 'TAK' : '',
      data.zgoda_wizerunek ? 'TAK' : '',
      data.zgoda_rodo ? 'TAK' : '',
    ]);

    sendConfirmationEmail(data, token);

    return ContentService
      .createTextOutput(JSON.stringify({ ok: true }))
      .setMimeType(ContentService.MimeType.JSON);
}

    return ContentService
      .createTextOutput(JSON.stringify({ ok: true }))
      .setMimeType(ContentService.MimeType.JSON);
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

  const sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
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

      return htmlSuccess(
        'Dziękujemy za potwierdzenie zgłoszenia.',
        'Odezwiemy się do Ciebie w ciągu kilku dni roboczych z informacją o dziecku objętym Twoim wsparciem. ' +
        'Numer konta i dane do przelewu znajdziesz w stopce naszej strony.'
      );
    }
  }
  return htmlError('Nie znaleziono zgłoszenia o podanym tokenie. Link mógł wygasnąć.');
}

/* ────────── E-mail potwierdzający (do darczyńcy) ────────────── */
function sendConfirmationEmail(data, token) {
  const confirmUrl = ScriptApp.getService().getUrl() + '?confirm=' + token;
  const subject = 'Potwierdź swoje zgłoszenie - Adopcja Serca · ' + FOUNDATION_NAME;

  const html = `<!doctype html>
<html lang="pl"><head><meta charset="utf-8"></head>
<body style="margin:0; padding:40px 20px; background:#faf5ee; font-family:'Helvetica Neue',Arial,sans-serif; color:#1b140e;">
  <table cellpadding="0" cellspacing="0" border="0" style="max-width:560px; margin:0 auto; background:white; border-radius:14px; overflow:hidden;">
    <tr><td style="padding:36px 40px; border-bottom:1px solid rgba(66,41,24,.12);">
      <h1 style="font-family:Georgia,serif; font-size:24px; color:#422918; margin:0;">Fundacja Misja MADA</h1>
      <p style="font-size:11px; letter-spacing:.16em; text-transform:uppercase; color:#c99d66; font-weight:700; margin:6px 0 0;">Program Adopcja Serca</p>
    </td></tr>
    <tr><td style="padding:36px 40px;">
      <h2 style="font-family:Georgia,serif; font-size:26px; color:#422918; margin:0 0 18px; line-height:1.2;">Cześć ${esc(data.imie)}!</h2>
      <p style="font-size:15px; line-height:1.65; color:#3c2913; margin:0 0 14px;">
        Otrzymaliśmy Twoje zgłoszenie do programu <strong>Adopcja Serca</strong>. Aby dokończyć proces zgłoszenia, prosimy o potwierdzenie, że e-mail <strong>${esc(data.email)}</strong> należy do Ciebie.
      </p>
      <p style="font-size:15px; line-height:1.65; color:#3c2913; margin:0 0 28px;">
        Kliknij w przycisk poniżej. Bez tego kroku zgłoszenie nie trafi do fundacji.
      </p>
      <div style="text-align:center; margin:28px 0;">
        <a href="${confirmUrl}" style="display:inline-block; background:#c99d66; color:#2a1a0e; padding:16px 34px; border-radius:10px; font-weight:700; font-size:15px; text-decoration:none;">Potwierdzam zgłoszenie →</a>
      </div>
      <p style="font-size:13px; color:#6b5a4a; line-height:1.6; margin:0 0 14px;">
        Jeśli przycisk nie działa, skopiuj poniższy link i wklej do przeglądarki:
      </p>
      <p style="font-size:12px; color:#6b5a4a; word-break:break-all; background:#faf5ee; padding:10px 14px; border-radius:8px; margin:0;">
        ${confirmUrl}
      </p>
      <p style="font-size:13px; color:#6b5a4a; line-height:1.6; margin:24px 0 0;">
        Jeśli to nie Ty wypełniałeś/wypełniałaś formularz - możesz spokojnie zignorować tę wiadomość. Zgłoszenie pozostanie nieaktywne i zostanie automatycznie usunięte.
      </p>
    </td></tr>
    <tr><td style="padding:24px 40px; background:#2a1a0e; color:#faf5ee; font-size:12px; line-height:1.6;">
      <strong style="color:#c99d66;">Fundacja Misja MADA</strong><br/>
      ul. Szosa Chełmińska 271A, 87-100 Toruń<br/>
      <a href="${SITE_URL}" style="color:#c99d66;">${SITE_URL}</a> · <a href="mailto:${FOUNDATION_EMAIL}" style="color:#c99d66;">${FOUNDATION_EMAIL}</a>
    </td></tr>
  </table>
</body></html>`;

  GmailApp.sendEmail(data.email, subject, '', {
    htmlBody: html,
    name: FOUNDATION_NAME,
    replyTo: FOUNDATION_EMAIL,
  });
}

/* ────────── E-mail notyfikujący fundację po potwierdzeniu ──── */
function notifyFoundation(row, headers) {
  const get = (col) => row[headers.indexOf(col)];
  const subject = 'Nowe zgłoszenie do Adopcji Serca: ' + get('imie') + ' ' + get('nazwisko');
  const body =
`Pojawiło się nowe ZWERYFIKOWANE zgłoszenie do programu Adopcja Serca.

Imię i nazwisko: ${get('imie')} ${get('nazwisko')}
E-mail:          ${get('email')}
Telefon:         ${get('telefon')}
Adres:           ${get('adres')}

Forma adopcji:   ${get('forma')} ${get('okres') ? '(' + get('okres') + ')' : ''}
Częstotliwość:   ${get('czestotliwosc')}

Zgody:
  · regulamin:   ${get('zgoda_regulamin') || '-'}
  · wizerunek:   ${get('zgoda_wizerunek') || '-'}
  · RODO:        ${get('zgoda_rodo') || '-'}

Czas zgłoszenia:    ${get('ts_received')}
Czas potwierdzenia: ${get('ts_verified')}

Pełne dane w arkuszu: ${SpreadsheetApp.getActiveSpreadsheet().getUrl()}`;

  GmailApp.sendEmail(FOUNDATION_EMAIL, subject, body);
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
