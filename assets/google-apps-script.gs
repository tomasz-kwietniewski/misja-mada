/**
 * ═══════════════════════════════════════════════════════════════
 *  Apps Script dla formularza „Adopcja Serca" - Misja MADA
 *  ═══════════════════════════════════════════════════════════════
 *
 *  CO ROBI:
 *  • Odbiera POST z formularza i dopisuje wpis do arkusza
 *    (kolumna "Weryfikacja e-mail" = "Oczekuje")
 *  • Wysyła e-mail potwierdzający (double opt-in) na adres
 *    podany przez użytkownika z unikalnym linkiem
 *  • Po kliknięciu linku przez użytkownika:
 *      - ustawia "Weryfikacja e-mail" = "Potwierdzony"
 *      - wysyła powiadomienie do fundacji (kontakt@misjamada.pl)
 *      - wyświetla użytkownikowi stronę z podziękowaniem
 *  • Przyjmuje wywołania serwer-do-serwera z PHP (shared secret):
 *    adopcja opłacona kartą PayU, raty kartowe (adopcja-charge),
 *    anulowanie subskrypcji (adopcja-cancel), darowizny, relay poczty
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
 *  9) Uruchom RAZ funkcję setupArkuszAdopcja() (Run w edytorze) -
 *       przygotuje zakładkę dla pracowników: polskie nagłówki, migracja
 *       starych statusów, kolory wierszy, zakładka "Instrukcja".
 *
 *  AKTUALIZACJA istniejącego wdrożenia: wklej nową wersję pliku,
 *  Deploy → Manage deployments → Edit → New version (URL /exec musi
 *  zostać TEN SAM), potem uruchom raz setupArkuszAdopcja().
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

/* ── Zakładka „Adopcja Serca": nazwy kolumn (nagłówki wiersza 1) ──────
   Zapis wierszy odbywa się PO NAZWACH nagłówków (nie pozycyjnie), więc
   pracownicy fundacji mogą dowolnie przestawiać kolejność kolumn.
   NIE wolno natomiast zmieniać samych NAZW (wiersz 1). */
const COL = {
  TOKEN:         'Token (system)',
  WERYFIKACJA:   'Weryfikacja e-mail',
  ZGLOSZENIE:    'Data zgłoszenia',
  POTWIERDZENIE: 'Data potwierdzenia e-mail',
  IMIE:          'Imię',
  NAZWISKO:      'Nazwisko',
  EMAIL:         'E-mail',
  TELEFON:       'Telefon',
  ADRES:         'Adres',
  FORMA:         'Forma adopcji',
  OKRES:         'Okres (adopcja czasowa)',
  CZESTOTLIWOSC: 'Częstotliwość wpłat',
  DZIECI:        'Liczba dzieci',
  ZG_REGULAMIN:  'Zgoda: regulamin',
  ZG_WIZERUNEK:  'Zgoda: wizerunek',
  ZG_RODO:       'Zgoda: RODO',
  NEWSLETTER:    'Newsletter',
  SUBID:         'ID subskrypcji (system)',
  ANULOWANIE:    'Data anulowania',
  METODA:        'Metoda płatności',
  SUB_STATUS:    'Status subskrypcji PayU',
  OSTATNIA:      'Ostatnia wpłata (karta)',
  MIESIACE:      'Opłacone miesiące (karta)',
  F_DZIECI:      'Przypisane dzieci (fundacja)',
  F_WPLATY:      'Wpłaty przelewowe - opłacone do (fundacja)',
  F_NOTATKI:     'Notatki (fundacja)',
};

const HEADERS_ADOPCJA = [
  COL.TOKEN, COL.WERYFIKACJA, COL.ZGLOSZENIE, COL.POTWIERDZENIE,
  COL.IMIE, COL.NAZWISKO, COL.EMAIL, COL.TELEFON, COL.ADRES,
  COL.FORMA, COL.OKRES, COL.CZESTOTLIWOSC, COL.DZIECI,
  COL.ZG_REGULAMIN, COL.ZG_WIZERUNEK, COL.ZG_RODO, COL.NEWSLETTER,
  COL.SUBID, COL.ANULOWANIE,
  COL.METODA, COL.SUB_STATUS, COL.OSTATNIA, COL.MIESIACE,
  COL.F_DZIECI, COL.F_WPLATY, COL.F_NOTATKI,
];

/* Wartości stanów - po polsku, czytelne dla pracowników fundacji.
   Dwie OSOBNE kolumny stanów (świadomie, zamiast jednej mieszanej):
   • Weryfikacja e-mail  - dotyczy double opt-in (tylko ścieżka przelewowa)
   • Status subskrypcji PayU - dotyczy wyłącznie subskrypcji kartowych */
const WER_OCZEKUJE     = 'Oczekuje';      // przelew: przed kliknięciem linku z maila
const WER_POTWIERDZONY = 'Potwierdzony';  // przelew: po double opt-in
const WER_ND           = 'Nie dotyczy';   // karta PayU: płatność potwierdza e-mail
const SUB_AKTYWNA      = 'Aktywna';
const SUB_ANULOWANA    = 'Anulowana';
const METODA_PRZELEW   = 'Przelew';
const METODA_KARTA     = 'Karta PayU';

// Mapa migracji nagłówków ze starego (technicznego) schematu.
// Używana wyłącznie przez setupArkuszAdopcja() - jednorazowy rename wiersza 1.
const MIGRACJA_NAGLOWKOW = {
  token: COL.TOKEN, status: COL.WERYFIKACJA, ts_received: COL.ZGLOSZENIE,
  ts_verified: COL.POTWIERDZENIE, imie: COL.IMIE, nazwisko: COL.NAZWISKO,
  email: COL.EMAIL, telefon: COL.TELEFON, adres: COL.ADRES, forma: COL.FORMA,
  okres: COL.OKRES, czestotliwosc: COL.CZESTOTLIWOSC, dzieci: COL.DZIECI,
  zgoda_regulamin: COL.ZG_REGULAMIN, zgoda_wizerunek: COL.ZG_WIZERUNEK,
  zgoda_rodo: COL.ZG_RODO, newsletter: COL.NEWSLETTER, subId: COL.SUBID,
  ts_cancelled: COL.ANULOWANIE,
};
const HEADERS_DAROWIZNY = ['ts', 'imie', 'nazwisko', 'email', 'cel', 'typ', 'kwota', 'waluta', 'extOrderId', 'payuOrderId'];
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

/**
 * Samo-naprawa schematu: dokłada brakujące kolumny nagłówków na KOŃCU istniejącej
 * zakładki (zachowuje kolejność z `headers`). Dzięki temu dodanie nowej kolumny
 * (np. subId) nie wymaga ręcznej edycji istniejącego arkusza po stronie fundacji.
 */
function ensureHeaders(sheet, headers) {
  if (sheet.getLastRow() === 0) {
    sheet.appendRow(headers);
    sheet.getRange(1, 1, 1, headers.length).setFontWeight('bold');
    sheet.setFrozenRows(1);
    return;
  }
  const existing = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0].map(String);
  const missing = headers.filter(h => existing.indexOf(h) === -1);
  if (missing.length) {
    sheet.getRange(1, existing.length + 1, 1, missing.length).setValues([missing]);
    sheet.getRange(1, 1, 1, existing.length + missing.length).setFontWeight('bold');
  }
}

/** Nagłówki (wiersz 1) zakładki jako tablica stringów. */
function sheetHeaders(sheet) {
  return sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0].map(String);
}

/**
 * Dopisuje wiersz mapując wartości PO NAZWACH nagłówków (kolumny spoza mapy
 * zostają puste - m.in. kolumny robocze fundacji). Odporne na przestawianie
 * kolejności kolumn przez pracowników.
 */
function appendRowByHeaders(sheet, rowMap) {
  const headers = sheetHeaders(sheet);
  sheet.appendRow(headers.map(h => (h in rowMap) ? rowMap[h] : ''));
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
    if (data.type === 'adopcja-cancel') {
      if (!secretOk(data)) return jsonOut({ ok: false, error: 'unauthorized' });
      return handleAdopcjaCancel(data);
    }
    if (data.type === 'adopcja-charge') {
      if (!secretOk(data)) return jsonOut({ ok: false, error: 'unauthorized' });
      return handleAdopcjaCharge(data);
    }
    if (data.type === 'adopcja-mirror') {
      if (!secretOk(data)) return jsonOut({ ok: false, error: 'unauthorized' });
      return handleAdopcjaMirror(data);
    }
    if (data.type === 'relay') {
      if (!secretOk(data)) return jsonOut({ ok: false, error: 'unauthorized' });
      return handleRelay(data);
    }
    if (data.type === 'kontakt')    return handleKontakt(data);
    if (data.type === 'newsletter') return handleNewsletter(data);
    // Strażnik: wywołanie serwer-do-serwera (payload z sekretem) o nieznanym typie
    // NIE może wpaść do handleAdopcja - utworzyłoby fałszywy wiersz „Oczekuje"
    // i wysłało darczyńcy mail potwierdzający (scenariusz: nowe PHP + stary
    // Apps Script przy złej kolejności wdrożenia).
    if (typeof data.secret !== 'undefined') return jsonOut({ ok: false, error: 'unknown-type' });
    return handleAdopcja(data);  // domyślnie: adopcja-przelew (double opt-in)
  } catch (err) {
    return jsonOut({ ok: false, error: err.toString() });
  }
}

function secretOk(data) {
  return SHEET_SECRET !== '' && String(data.secret || '') === SHEET_SECRET;
}

/**
 * Relay poczty: PHP (payu/mail.php, newsletter) wysyła tu maile, by szły przez uwierzytelniony
 * Gmail (GmailApp) zamiast PHP mail() z serwera (ten bywa łapany jako spam). Payload:
 *   { to, subject, text, html?, name?, replyTo? }.  Sekret wymagany (dispatch w doPost).
 */
function handleRelay(data) {
  const to = String(data.to || '').trim();
  if (!to) return jsonOut({ ok: false, error: 'missing-to' });
  const opts = { name: String(data.name || FOUNDATION_NAME) };
  if (data.replyTo) opts.replyTo = String(data.replyTo);
  if (data.html)    opts.htmlBody = String(data.html);
  GmailApp.sendEmail(to, String(data.subject || ''), String(data.text || ''), opts);
  return jsonOut({ ok: true });
}

function handleAdopcja(data) {
  const sheet = getOrCreateSheet(SHEET_ADOPCJA, HEADERS_ADOPCJA);
  ensureHeaders(sheet, HEADERS_ADOPCJA);
  const token = Utilities.getUuid().replace(/-/g, '');

  appendRowByHeaders(sheet, {
    [COL.TOKEN]:         token,
    [COL.WERYFIKACJA]:   WER_OCZEKUJE,
    [COL.ZGLOSZENIE]:    new Date(),
    [COL.IMIE]:          data.imie || '',
    [COL.NAZWISKO]:      data.nazwisko || '',
    [COL.EMAIL]:         data.email || '',
    [COL.TELEFON]:       data.telefon || '',
    [COL.ADRES]:         data.adres || '',
    [COL.FORMA]:         data.formaLabel || '',
    [COL.OKRES]:         data.okres || '',
    [COL.CZESTOTLIWOSC]: data.czestotliwosc || '',
    [COL.DZIECI]:        data.dzieci || '',
    [COL.ZG_REGULAMIN]:  data.zgoda_regulamin ? 'TAK' : '',
    [COL.ZG_WIZERUNEK]:  data.zgoda_wizerunek ? 'TAK' : '',
    [COL.ZG_RODO]:       data.zgoda_rodo ? 'TAK' : '',
    [COL.NEWSLETTER]:    data.newsletter ? 'TAK' : '',
    [COL.METODA]:        METODA_PRZELEW,
  });

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
  const headers = data[0].map(String);
  const tokenCol = headers.indexOf(COL.TOKEN);
  const werCol = headers.indexOf(COL.WERYFIKACJA);
  const tsVerCol = headers.indexOf(COL.POTWIERDZENIE);

  for (let i = 1; i < data.length; i++) {
    if (data[i][tokenCol] === token) {
      if (String(data[i][werCol]) === WER_POTWIERDZONY) {
        return htmlSuccess('Zgłoszenie zostało już potwierdzone wcześniej. Dziękujemy!');
      }
      sheet.getRange(i + 1, werCol + 1).setValue(WER_POTWIERDZONY);
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
  return '<!doctype html><html lang="pl"><head><meta charset="utf-8">'
    + '<meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<!--[if mso]><style>table,td{border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt;}</style><![endif]--></head>'
    + '<body style="margin:0;padding:0;background:#faf5ee;font-family:\'Helvetica Neue\',Arial,sans-serif;color:#1b140e;">'
    + '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#faf5ee" style="background:#faf5ee;"><tr><td align="center" style="padding:40px 16px;">'
    + '<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width:100%;max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;">'
    + '<tr><td bgcolor="#ffffff" style="padding:30px 40px 24px;border-bottom:1px solid #e8ddcf;">'
    + '<div style="font-family:Georgia,\'Times New Roman\',serif;font-size:22px;color:#422918;font-weight:bold;">' + FOUNDATION_NAME + '</div>'
    + '<div style="font-family:Arial,sans-serif;font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#c99d66;font-weight:bold;padding-top:6px;">Program Adopcja Serca</div></td></tr>'
    + '<tr><td bgcolor="#ffffff" style="padding:30px 40px;font-family:\'Helvetica Neue\',Arial,sans-serif;color:#1b140e;font-size:15px;line-height:1.6;">' + inner + '</td></tr>'
    + '<tr><td bgcolor="#2a1a0e" style="padding:22px 40px;background:#2a1a0e;color:#faf5ee;font-family:Arial,sans-serif;font-size:12px;line-height:1.6;">'
    + '<span style="color:#c99d66;font-weight:bold;">' + FOUNDATION_NAME + '</span><br>'
    + 'ul. Szosa Chełmińska 271A, 87-100 Toruń<br>'
    + '<a href="' + SITE_URL + '" style="color:#c99d66;text-decoration:underline;">' + SITE_URL + '</a> - '
    + '<a href="mailto:' + FOUNDATION_EMAIL + '" style="color:#c99d66;text-decoration:underline;">' + FOUNDATION_EMAIL + '</a>'
    + '</td></tr></table></td></tr></table></body></html>';
}

/** Stawka za JEDNO dziecko wg częstotliwości wpłat (musi zgadzać się z adopcja/lib.php). */
function stawkaZaOkres(czestotliwosc) {
  const c = String(czestotliwosc || '');
  if (/kwart/i.test(c)) return { kwota: 210, etykieta: 'kwartalnie' };
  if (/rocz/i.test(c))  return { kwota: 840, etykieta: 'rocznie' };
  return { kwota: 70, etykieta: 'miesięcznie' };
}

/** Mail powitalny po weryfikacji zgłoszenia adopcji (dane do przelewu, info o dziecku).
 *  UWAGA: ścieżka historyczna - zgłoszenia ze strony obsługuje dziś adopcja/potwierdz.php.
 *  Treść trzymana zgodnie z PHP, żeby ewentualne stare linki nie wysyłały innych danych. */
function sendWelcomeEmail(row, headers) {
  const get = (c) => row[headers.indexOf(c)];
  const imie = esc(get(COL.IMIE));
  const nazwisko = esc(get(COL.NAZWISKO));
  const dzieci = parseInt(get(COL.DZIECI), 10) || 1;
  const okresStawka = stawkaZaOkres(get(COL.CZESTOTLIWOSC));
  const kwota = dzieci * okresStawka.kwota;
  const tytul = 'Adopcja Serca - darowizna - ' + get(COL.IMIE) + ' ' + get(COL.NAZWISKO);
  // Dla wsparcia w formie czasowej (okres od-do) podajemy, na jaki czas ustawic zlecenie stale.
  const okres = String(get(COL.OKRES) || '');
  const forma = String(get(COL.FORMA) || '');
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
    + 'Kwota: <strong>' + kwota + ' zł ' + okresStawka.etykieta + '</strong> ('
    + dzieci + ' × ' + okresStawka.kwota + ' zł)<br>'
    + okresRow
    + 'Tytuł przelewu: <strong>' + esc(tytul) + '</strong></div>'
    + '<p style="font-size:15px;line-height:1.65;margin:0 0 16px;">Szczegóły dotyczące konkretnego dziecka objętego Twoim '
    + 'wsparciem przygotowujemy ręcznie - <strong>odezwiemy się do Ciebie w ciągu kilku dni roboczych</strong>, aby je przedstawić.</p>'
    + '<p style="font-size:14px;line-height:1.6;color:#5a4836;margin:0;">Z serca dziękujemy, że jesteś z nami. ❤︎</p>';
  GmailApp.sendEmail(get(COL.EMAIL), 'Witaj w programie Adopcja Serca - Fundacja Misja MADA', '', {
    htmlBody: emailShell(inner), name: FOUNDATION_NAME, replyTo: FOUNDATION_EMAIL,
  });
}

/** Jeśli w zgłoszeniu zaznaczono newsletter - dopisz zweryfikowany mail do MailerLite. */
function maybeAddNewsletter(row, headers) {
  const get = (c) => row[headers.indexOf(c)];
  if (String(get(COL.NEWSLETTER)) !== 'TAK') return;
  try {
    UrlFetchApp.fetch(NL_ADD_VERIFIED_URL, {
      method: 'post', contentType: 'application/json', muteHttpExceptions: true,
      payload: JSON.stringify({ email: get(COL.EMAIL), imie: get(COL.IMIE), secret: NL_VERIFIED_SECRET }),
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
    + '<div style="text-align:center;margin:24px 0;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto;"><tr><td bgcolor="#c99d66" style="background:#c99d66;border-radius:10px;"><a href="' + confirmUrl + '" style="display:inline-block;padding:15px 32px;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;color:#2a1a0e;text-decoration:none;">Potwierdzam zgłoszenie →</a></td></tr></table></div>'
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
    + 'Imię i nazwisko: <strong>' + esc(get(COL.IMIE)) + ' ' + esc(get(COL.NAZWISKO)) + '</strong><br>'
    + 'E-mail: ' + esc(get(COL.EMAIL)) + '<br>Telefon: ' + esc(get(COL.TELEFON)) + '<br>'
    + 'Adres: ' + esc(get(COL.ADRES)) + '<br>Forma: ' + esc(get(COL.FORMA)) + ' ' + (get(COL.OKRES) ? '(' + esc(get(COL.OKRES)) + ')' : '') + '<br>'
    + 'Liczba dzieci: ' + esc(get(COL.DZIECI)) + '<br>Częstotliwość: ' + esc(get(COL.CZESTOTLIWOSC)) + '<br>'
    + 'Newsletter: ' + esc(get(COL.NEWSLETTER) || '-') + '</p>';
  GmailApp.sendEmail(FOUNDATION_EMAIL, 'Nowe zgłoszenie do Adopcji Serca: ' + get(COL.IMIE) + ' ' + get(COL.NAZWISKO), '', {
    htmlBody: emailShell(inner), name: FOUNDATION_NAME,
  });
}

/** Adopcja opłacona kartą (PayU) - zapis do arkusza Adopcja jako aktywna subskrypcja.
 *  Bez double opt-in (płatność kartą zweryfikowała e-mail) - Weryfikacja = "Nie dotyczy".
 *  Pierwsza rata jest już opłacona, więc od razu Ostatnia wpłata + Opłacone miesiące = 1. */
/** LUSTRO zgłoszenia przelewowego potwierdzonego już w PHP (adopcja/potwierdz.php).
 *  Double opt-in, maile i baza są po stronie PHP - tu tylko kopia wiersza w arkuszu
 *  (backup / znajomy widok fundacji). Bez żadnych maili. */
function handleAdopcjaMirror(data) {
  const sheet = getOrCreateSheet(SHEET_ADOPCJA, HEADERS_ADOPCJA);
  ensureHeaders(sheet, HEADERS_ADOPCJA);
  const ts = new Date();
  appendRowByHeaders(sheet, {
    [COL.TOKEN]:         Utilities.getUuid().replace(/-/g, ''),
    [COL.WERYFIKACJA]:   WER_POTWIERDZONY,
    [COL.ZGLOSZENIE]:    ts,
    [COL.POTWIERDZENIE]: ts,
    [COL.IMIE]:          data.imie || '',
    [COL.NAZWISKO]:      data.nazwisko || '',
    [COL.EMAIL]:         data.email || '',
    [COL.TELEFON]:       data.telefon || '',
    [COL.ADRES]:         data.adres || '',
    [COL.FORMA]:         data.forma || '',
    [COL.OKRES]:         data.okres || '',
    [COL.CZESTOTLIWOSC]: data.czestotliwosc || '',
    [COL.DZIECI]:        data.dzieci || '',
    [COL.ZG_REGULAMIN]:  'TAK',
    [COL.ZG_WIZERUNEK]:  data.zgoda_wizerunek || '',
    [COL.ZG_RODO]:       'TAK',
    [COL.NEWSLETTER]:    data.newsletter || '',
    [COL.METODA]:        METODA_PRZELEW,
  });
  return jsonOut({ ok: true });
}

function handleAdopcjaPaid(data) {
  const sheet = getOrCreateSheet(SHEET_ADOPCJA, HEADERS_ADOPCJA);
  ensureHeaders(sheet, HEADERS_ADOPCJA);
  const ts = new Date();
  appendRowByHeaders(sheet, {
    [COL.TOKEN]:         Utilities.getUuid().replace(/-/g, ''),
    [COL.WERYFIKACJA]:   WER_ND,
    [COL.ZGLOSZENIE]:    ts,
    [COL.POTWIERDZENIE]: ts,
    [COL.IMIE]:          data.imie || '',
    [COL.NAZWISKO]:      data.nazwisko || '',
    [COL.EMAIL]:         data.email || '',
    [COL.TELEFON]:       data.telefon || '',
    [COL.ADRES]:         data.adres || '',
    [COL.FORMA]:         data.forma || '',
    [COL.OKRES]:         data.okres || '',
    [COL.CZESTOTLIWOSC]: 'Miesięcznie',
    [COL.DZIECI]:        data.dzieci || '',
    [COL.ZG_REGULAMIN]:  'TAK',
    [COL.ZG_WIZERUNEK]:  data.zgoda_wizerunek || '',
    [COL.ZG_RODO]:       'TAK',
    [COL.NEWSLETTER]:    data.newsletter || '',
    [COL.SUBID]:         String(data.subId || ''),
    [COL.METODA]:        METODA_KARTA,
    [COL.SUB_STATUS]:    SUB_AKTYWNA,
    [COL.OSTATNIA]:      ts,
    [COL.MIESIACE]:      1,
  });
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

/**
 * Anulowanie subskrypcji ADOPCJI (z payu/manage.php lub panel/subskrypcje.php).
 * Znajduje wiersz w „Adopcja Serca" po kolumnie ID subskrypcji i ustawia
 * Status subskrypcji PayU = „Anulowana" + datę anulowania, a następnie powiadamia
 * fundację (Gmail - kanał niezawodny, w przeciwieństwie do PHP mail()).
 * Gdy wiersza nie ma (np. adopcja-przelew albo starszy wpis bez subId) - i tak wysyła
 * powiadomienie, zaznaczając, że wiersz trzeba zaktualizować ręcznie.
 */
function handleAdopcjaCancel(data) {
  const sheet = getOrCreateSheet(SHEET_ADOPCJA, HEADERS_ADOPCJA);
  ensureHeaders(sheet, HEADERS_ADOPCJA);
  const values = sheet.getDataRange().getValues();
  const headers = values[0].map(String);
  const subCol = headers.indexOf(COL.SUBID);
  const statusCol = headers.indexOf(COL.SUB_STATUS);
  const tsCancelCol = headers.indexOf(COL.ANULOWANIE);
  const wantId = String(data.subId || '');
  let updated = 0;
  if (subCol !== -1 && statusCol !== -1 && wantId !== '') {
    for (let i = 1; i < values.length; i++) {
      if (String(values[i][subCol]) === wantId && String(values[i][statusCol]) !== SUB_ANULOWANA) {
        sheet.getRange(i + 1, statusCol + 1).setValue(SUB_ANULOWANA);
        if (tsCancelCol !== -1) sheet.getRange(i + 1, tsCancelCol + 1).setValue(new Date());
        updated++;
      }
    }
  }
  notifyFoundationCancel(data, updated);
  return jsonOut({ ok: true, updated: updated });
}

/**
 * Kolejna OPŁACONA rata subskrypcji ADOPCJI (z payu/notify.php po notyfikacji
 * COMPLETED). Aktualizuje w wierszu darczyńcy (po ID subskrypcji) datę ostatniej
 * wpłaty i licznik opłaconych miesięcy. Licznik przychodzi z MySQL jako wartość
 * ABSOLUTNA (nie inkrementujemy w arkuszu) - ponowiona notyfikacja PayU jest
 * więc nieszkodliwa. Celowo BEZ maila do fundacji (rata co miesiąc = spam);
 * stan widać w arkuszu i w panelu CMS.
 */
function handleAdopcjaCharge(data) {
  const sheet = getOrCreateSheet(SHEET_ADOPCJA, HEADERS_ADOPCJA);
  ensureHeaders(sheet, HEADERS_ADOPCJA);
  const values = sheet.getDataRange().getValues();
  const headers = values[0].map(String);
  const subCol = headers.indexOf(COL.SUBID);
  const lastCol = headers.indexOf(COL.OSTATNIA);
  const monthsCol = headers.indexOf(COL.MIESIACE);
  const wantId = String(data.subId || '');
  const months = Number(data.monthsPaid);
  let updated = 0;
  if (subCol !== -1 && wantId !== '') {
    for (let i = 1; i < values.length; i++) {
      if (String(values[i][subCol]) === wantId) {
        if (lastCol !== -1) sheet.getRange(i + 1, lastCol + 1).setValue(new Date());
        if (monthsCol !== -1 && !isNaN(months)) sheet.getRange(i + 1, monthsCol + 1).setValue(months);
        updated++;
      }
    }
  }
  return jsonOut({ ok: true, updated: updated });
}

/** Powiadamia fundację (Gmail) o anulowaniu subskrypcji adopcji + wyniku aktualizacji arkusza. */
function notifyFoundationCancel(data, updated) {
  const inner =
      '<h2 style="font-family:Georgia,serif;font-size:22px;color:#422918;margin:0 0 16px;">Subskrypcja adopcji anulowana</h2>'
    + '<p style="font-size:14px;line-height:1.7;margin:0;">'
    + 'Darczyńca: <strong>' + esc(data.imie) + ' ' + esc(data.nazwisko) + '</strong> &lt;' + esc(data.email) + '&gt;<br>'
    + 'Cel: ' + esc(data.goalLabel || 'Adopcja Serca') + '<br>'
    + 'Kwota: ' + esc(data.amount) + ' ' + esc(data.currency || 'PLN') + ' / miesiąc'
    + (data.dzieci ? '<br>Liczba dzieci: ' + esc(data.dzieci) : '')
    + '<br>ID subskrypcji: ' + esc(data.subId)
    + '<br>Arkusz: ' + (updated > 0 ? 'ustawiono „Status subskrypcji PayU" = „Anulowana"' : 'nie znaleziono wiersza po ID subskrypcji - zaktualizuj ręcznie')
    + '</p>';
  GmailApp.sendEmail(FOUNDATION_EMAIL, 'Adopcja anulowana: ' + (data.imie || '') + ' ' + (data.nazwisko || ''), '', {
    htmlBody: emailShell(inner), name: FOUNDATION_NAME,
  });
}

/** Darowizna OPŁACONA (z notify.php) - zapis do arkusza Darowizny + powiadomienie.
 *  typ = 'jednorazowa' | 'cykliczna' (osobna kolumna dla pracownikow). Kwota zapisywana
 *  jako LICZBA (nie tekst) - inaczej w pl-PL kropka dziesietna robi z niej tekst i psuje sumy.
 *  Zapis po NAZWACH naglowkow (odporny na kolejnosc kolumn / dolozona kolumne typ). */
function handleDarowizna(data) {
  const sheet = getOrCreateSheet(SHEET_DAROWIZNY, HEADERS_DAROWIZNY);
  ensureHeaders(sheet, HEADERS_DAROWIZNY);
  const headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0].map(String);
  const amt = data.amount;
  const kwota = (amt === '' || amt == null || isNaN(Number(amt))) ? (amt || '') : Number(amt);
  const typ = (data.typ === 'cykliczna' || data.typ === 'jednorazowa') ? data.typ : 'jednorazowa';
  const rowMap = {
    ts: new Date(),
    imie: data.imie || '', nazwisko: data.nazwisko || '', email: data.email || '',
    cel: data.goalLabel || data.goal || '', typ: typ, kwota: kwota,
    waluta: data.currency || 'PLN', extOrderId: data.extOrderId || '', payuOrderId: data.payuOrderId || '',
  };
  sheet.appendRow(headers.map(function (h) { return (h in rowMap) ? rowMap[h] : ''; }));

  const inner =
      '<h2 style="font-family:Georgia,serif;font-size:22px;color:#422918;margin:0 0 16px;">Nowa darowizna (opłacona)</h2>'
    + '<p style="font-size:14px;line-height:1.7;margin:0;">'
    + 'Darczyńca: <strong>' + esc(data.imie) + ' ' + esc(data.nazwisko) + '</strong> &lt;' + esc(data.email) + '&gt;<br>'
    + 'Cel: ' + esc(data.goalLabel || data.goal) + '<br>'
    + 'Typ: <strong>' + esc(typ) + '</strong><br>'
    + 'Kwota: <strong>' + esc(data.amount) + ' ' + esc(data.currency || 'PLN') + '</strong><br>'
    + 'PayU order: ' + esc(data.payuOrderId) + '</p>';
  GmailApp.sendEmail(FOUNDATION_EMAIL, 'Nowa darowizna (' + typ + '): ' + (data.amount || '') + ' ' + (data.currency || 'PLN'), '', {
    htmlBody: emailShell(inner), name: FOUNDATION_NAME,
  });
  return jsonOut({ ok: true });
}

/* ═══════════════════════════════════════════════════════════════
   JEDNORAZOWA KONFIGURACJA ZAKŁADKI „Adopcja Serca"
   Uruchom ręcznie z edytora Apps Script (Run → setupArkuszAdopcja)
   po wklejeniu nowej wersji pliku. IDEMPOTENTNA - wielokrotne
   uruchomienie daje ten sam stan (można odpalać po każdej zmianie).
   ═══════════════════════════════════════════════════════════════ */
function setupArkuszAdopcja() {
  const sheet = getOrCreateSheet(SHEET_ADOPCJA, HEADERS_ADOPCJA);
  migrujNaglowki_(sheet);
  ensureHeaders(sheet, HEADERS_ADOPCJA);
  migrujWiersze_(sheet);
  ustawFormatyDat_(sheet);
  ustawWyglad_(sheet);
  ustawKolory_(sheet);
  utworzInstrukcje_();
}

/* Szerokości kolumn (px) i sposób pokazywania długich treści.
   ZAWIJANE są tylko kolumny z długim tekstem - reszta jest przycinana, żeby
   wiersze nie rosły na wysokość przez jedną rozwlekłą komórkę. */
const SZEROKOSCI = {};
SZEROKOSCI[COL.WERYFIKACJA] = 140; SZEROKOSCI[COL.ZGLOSZENIE] = 135;
SZEROKOSCI[COL.POTWIERDZENIE] = 135; SZEROKOSCI[COL.IMIE] = 110;
SZEROKOSCI[COL.NAZWISKO] = 130; SZEROKOSCI[COL.EMAIL] = 210;
SZEROKOSCI[COL.TELEFON] = 105; SZEROKOSCI[COL.ADRES] = 230;
SZEROKOSCI[COL.FORMA] = 150; SZEROKOSCI[COL.OKRES] = 165;
SZEROKOSCI[COL.CZESTOTLIWOSC] = 115; SZEROKOSCI[COL.DZIECI] = 70;
SZEROKOSCI[COL.ZG_REGULAMIN] = 85; SZEROKOSCI[COL.ZG_WIZERUNEK] = 85;
SZEROKOSCI[COL.ZG_RODO] = 85; SZEROKOSCI[COL.NEWSLETTER] = 90;
SZEROKOSCI[COL.SUBID] = 85; SZEROKOSCI[COL.ANULOWANIE] = 135;
SZEROKOSCI[COL.METODA] = 120; SZEROKOSCI[COL.SUB_STATUS] = 140;
SZEROKOSCI[COL.OSTATNIA] = 135; SZEROKOSCI[COL.MIESIACE] = 95;
SZEROKOSCI[COL.F_DZIECI] = 220; SZEROKOSCI[COL.F_WPLATY] = 160;
SZEROKOSCI[COL.F_NOTATKI] = 300;

const KOLUMNY_ZAWIJANE = [COL.ADRES, COL.F_DZIECI, COL.F_WPLATY, COL.F_NOTATKI];
const KOLUMNY_WYSRODKOWANE = [
  COL.WERYFIKACJA, COL.CZESTOTLIWOSC, COL.DZIECI, COL.ZG_REGULAMIN, COL.ZG_WIZERUNEK,
  COL.ZG_RODO, COL.NEWSLETTER, COL.SUBID, COL.METODA, COL.SUB_STATUS, COL.MIESIACE,
];

/**
 * Czytelny wygląd zakładki: wyróżniony i zamrożony nagłówek, sensowne szerokości
 * kolumn, zawijanie tylko tam gdzie treść bywa długa, wyśrodkowane krótkie wartości,
 * ukryta kolumna techniczna z tokenem (32 znaki losowego ciągu, nikomu niepotrzebne)
 * oraz filtr w wierszu nagłówka - pracownik może sortować i filtrować bez pomocy.
 * Idempotentne: wielokrotne uruchomienie daje ten sam efekt.
 */
function ustawWyglad_(sheet) {
  const headers = sheetHeaders(sheet);
  const rows = Math.max(sheet.getMaxRows() - 1, 1);
  const col = (name) => headers.indexOf(name) + 1;   // 0 gdy kolumny nie ma

  const head = sheet.getRange(1, 1, 1, headers.length);
  head.setFontWeight('bold').setBackground('#422918').setFontColor('#faf5ee')
      .setVerticalAlignment('middle').setHorizontalAlignment('center')
      .setWrapStrategy(SpreadsheetApp.WrapStrategy.WRAP);
  sheet.setFrozenRows(1);
  sheet.setRowHeight(1, 46);

  // Dane od góry komórki - przy zawijaniu czyta się lepiej niż wyśrodkowane w pionie.
  sheet.getRange(2, 1, rows, headers.length)
       .setVerticalAlignment('top')
       .setWrapStrategy(SpreadsheetApp.WrapStrategy.CLIP);

  headers.forEach(function (h) {
    if (SZEROKOSCI.hasOwnProperty(h)) sheet.setColumnWidth(col(h), SZEROKOSCI[h]);
  });
  KOLUMNY_ZAWIJANE.forEach(function (h) {
    const c = col(h);
    if (c) sheet.getRange(2, c, rows, 1).setWrapStrategy(SpreadsheetApp.WrapStrategy.WRAP);
  });
  KOLUMNY_WYSRODKOWANE.forEach(function (h) {
    const c = col(h);
    if (c) sheet.getRange(2, c, rows, 1).setHorizontalAlignment('center');
  });

  // Token jest wyłącznie techniczny (klucz linku potwierdzającego) - chowamy z oczu.
  const tokenCol = col(COL.TOKEN);
  if (tokenCol) sheet.hideColumns(tokenCol);

  // Filtr w nagłówku: sortowanie i filtrowanie (np. tylko przelewowcy) bez pomocy IT.
  const stary = sheet.getFilter();
  if (stary) stary.remove();
  sheet.getRange(1, 1, Math.max(sheet.getLastRow(), 2), headers.length).createFilter();
}

/**
 * Jednolity format kolumn datowych. Bez tego komórki dziedziczą format z historii
 * arkusza i ta sama kolumna potrafi pokazywać raz „2026-07-04 21:53", a raz samo
 * „2026-07-04" (wartość jest pełna, różni się tylko wyświetlanie). Formatujemy CAŁE
 * kolumny, więc nowe wiersze od razu trafiają w sformatowane komórki.
 * Kolumny robocze fundacji celowo pomijamy - to ich miejsce, wpisują co chcą.
 */
function ustawFormatyDat_(sheet) {
  const headers = sheetHeaders(sheet);
  const rows = Math.max(sheet.getMaxRows() - 1, 1);
  [COL.ZGLOSZENIE, COL.POTWIERDZENIE, COL.ANULOWANIE, COL.OSTATNIA].forEach(function (name) {
    const c = headers.indexOf(name);
    if (c !== -1) sheet.getRange(2, c + 1, rows, 1).setNumberFormat('yyyy-mm-dd hh:mm');
  });
  // Licznik rat to liczba całkowita - inaczej bywa „5,00" albo data.
  const m = headers.indexOf(COL.MIESIACE);
  if (m !== -1) sheet.getRange(2, m + 1, rows, 1).setNumberFormat('0');
  const d = headers.indexOf(COL.DZIECI);
  if (d !== -1) sheet.getRange(2, d + 1, rows, 1).setNumberFormat('0');
}

/** Rename nagłówków ze starego schematu technicznego (token, ts_received...)
 *  na polskie nazwy. Nagłówki już polskie / nieznane zostawia bez zmian. */
function migrujNaglowki_(sheet) {
  const range = sheet.getRange(1, 1, 1, sheet.getLastColumn());
  const current = range.getValues()[0].map(String);
  const renamed = current.map(h => MIGRACJA_NAGLOWKOW.hasOwnProperty(h) ? MIGRACJA_NAGLOWKOW[h] : h);
  if (JSON.stringify(renamed) !== JSON.stringify(current)) range.setValues([renamed]);
}

/** Migracja WARTOŚCI w istniejących wierszach: stare statusy (pending/verified/
 *  oplacone-PayU/anulowana) na dwie polskie kolumny stanów + backfill metody
 *  płatności i częstotliwości. Zmienia tylko to, co ma starą/pustą wartość. */
function migrujWiersze_(sheet) {
  const headers = sheetHeaders(sheet);
  const idx = {};
  headers.forEach((h, i) => { idx[h] = i; });
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return;
  const values = sheet.getRange(2, 1, lastRow - 1, headers.length).getValues();
  values.forEach((row, r) => {
    const get = (col) => String(row[idx[col]] == null ? '' : row[idx[col]]).trim();
    const set = (col, val) => sheet.getRange(r + 2, idx[col] + 1).setValue(val);
    if (get(COL.EMAIL) === '' && get(COL.TOKEN) === '') return;  // pomiń puste wiersze
    // 1) stara mieszana kolumna statusu -> dwie kolumny stanów
    const wer = get(COL.WERYFIKACJA);
    if (wer === 'pending')  set(COL.WERYFIKACJA, WER_OCZEKUJE);
    if (wer === 'verified') set(COL.WERYFIKACJA, WER_POTWIERDZONY);
    if (wer === 'oplacone-PayU' || wer === 'anulowana') {
      set(COL.WERYFIKACJA, WER_ND);
      set(COL.SUB_STATUS, wer === 'anulowana' ? SUB_ANULOWANA : SUB_AKTYWNA);
    }
    // 2) metoda płatności (tylko gdy pusta) - wyprowadzona z subId/częstotliwości/statusu
    const karta = get(COL.SUBID) !== '' || /PayU/i.test(get(COL.CZESTOTLIWOSC))
      || wer === 'oplacone-PayU' || wer === 'anulowana';
    if (get(COL.METODA) === '') set(COL.METODA, karta ? METODA_KARTA : METODA_PRZELEW);
    // 3) stary literał „PayU (karta, cyklicznie)" w częstotliwości -> zwykłe Miesięcznie
    if (/PayU/i.test(get(COL.CZESTOTLIWOSC))) set(COL.CZESTOTLIWOSC, 'Miesięcznie');
  });
}

/** Kolory wierszy wg stanów (formatowanie warunkowe). UWAGA: zastępuje
 *  WSZYSTKIE reguły formatowania warunkowego tej zakładki (idempotencja) -
 *  ręcznie dodane reguły znikną (opisane w zakładce Instrukcja). */
function ustawKolory_(sheet) {
  const headers = sheetHeaders(sheet);
  const rows = Math.max(sheet.getMaxRows() - 1, 1);
  const full = sheet.getRange(2, 1, rows, headers.length);
  const letter = (name) => columnLetter_(headers.indexOf(name) + 1);
  const rowRule = (formula, bg) => SpreadsheetApp.newConditionalFormatRule()
    .whenFormulaSatisfied(formula).setBackground(bg).setRanges([full]).build();
  const rules = [
    // kolejność ma znaczenie - pierwsza pasująca reguła wygrywa
    rowRule('=$' + letter(COL.SUB_STATUS) + '2="' + SUB_ANULOWANA + '"', '#e8e0d8'),   // szary: anulowana karta
    rowRule('=$' + letter(COL.WERYFIKACJA) + '2="' + WER_OCZEKUJE + '"', '#fff3cd'),   // żółty: czeka na klik w mail
    rowRule('=$' + letter(COL.SUB_STATUS) + '2="' + SUB_AKTYWNA + '"', '#e6f4ea'),     // zielony: aktywna karta
  ];
  // Przelewowcy: wyróżnienie samej komórki metody - pracownik od razu widzi,
  // kogo obsługuje ręcznie (wpłaty na wyciągu, nie w systemie).
  const metodaCol = sheet.getRange(2, headers.indexOf(COL.METODA) + 1, rows, 1);
  rules.push(SpreadsheetApp.newConditionalFormatRule()
    .whenFormulaSatisfied('=$' + letter(COL.METODA) + '2="' + METODA_PRZELEW + '"')
    .setBackground('#fce8d5').setBold(true).setRanges([metodaCol]).build());
  sheet.setConditionalFormatRules(rules);
}

/** Numer kolumny (1-based) -> litera arkusza (1=A, 27=AA...). */
function columnLetter_(n) {
  let s = '';
  while (n > 0) { const m = (n - 1) % 26; s = String.fromCharCode(65 + m) + s; n = Math.floor((n - 1) / 26); }
  return s;
}

/** Zakładka „Instrukcja" dla pracowników fundacji - czyszczona i pisana od nowa. */
function utworzInstrukcje_() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let s = ss.getSheetByName('Instrukcja');
  if (!s) s = ss.insertSheet('Instrukcja');
  s.clear();
  const rows = [
    ['INSTRUKCJA - zakładka „Adopcja Serca"', ''],
    ['Każdy wiersz to jedno zgłoszenie do programu (jeden darczyńca).', ''],
    ['Kolumny można dowolnie PRZESTAWIAĆ. NIE WOLNO zmieniać NAZW kolumn w wierszu 1 - system zapisuje dane po nazwach.', ''],
    ['Wiersz nagłówka ma FILTR', 'Kliknij ikonę filtra w nagłówku, żeby posortować albo zawęzić listę - np. pokazać samych darczyńców przelewowych albo tylko oczekujących na potwierdzenie.'],
    ['Kolumna „' + COL.TOKEN + '" jest UKRYTA', 'To 32-znakowy identyfikator techniczny linku potwierdzającego. Nikomu nie jest potrzebny na co dzień; w razie potrzeby odkryjecie ją klikając strzałki między nagłówkami kolumn.'],
    ['', ''],
    ['JAK ROZPOZNAĆ ŚCIEŻKĘ DARCZYŃCY', ''],
    ['Metoda płatności = „Przelew"', 'Darczyńca płaci zwykłym przelewem / zleceniem stałym. System NIE widzi jego wpłat - sprawdzacie wyciąg bankowy i notujecie w kolumnie „' + COL.F_WPLATY + '".'],
    ['Metoda płatności = „Karta PayU"', 'Płatność cykliczna kartą. Raty księgują się automatycznie: kolumny „' + COL.OSTATNIA + '" i „' + COL.MIESIACE + '" aktualizują się same po każdej udanej racie.'],
    ['', ''],
    ['KOLORY WIERSZY', ''],
    ['Żółty', 'Weryfikacja e-mail = „Oczekuje" - darczyńca (przelew) nie kliknął jeszcze linku z maila i NIE dostał jeszcze numeru konta.'],
    ['Zielony', 'Aktywna subskrypcja kartą PayU - płaci automatycznie.'],
    ['Szary', 'Subskrypcja kartą anulowana.'],
    ['Pomarańczowa komórka „Metoda płatności"', 'Darczyńca przelewowy - wymaga Waszej ręcznej obsługi wpłat.'],
    ['', ''],
    ['KOLUMNY AUTOMATYCZNE (wypełnia system - NIE edytować)', ''],
    [COL.WERYFIKACJA, '„Oczekuje" -> „Potwierdzony" po kliknięciu linku z maila (dotyczy tylko przelewu). „Nie dotyczy" przy karcie - płatność potwierdziła e-mail.'],
    [COL.SUB_STATUS, '„Aktywna" / „Anulowana" - tylko subskrypcje kartą PayU. Anulowanie (z panelu CMS albo przez darczyńcę) ustawia się samo, razem z datą w „' + COL.ANULOWANIE + '".'],
    [COL.METODA, '„Przelew" albo „Karta PayU".'],
    [COL.OSTATNIA, 'Data ostatniej udanej raty kartą (automat).'],
    [COL.MIESIACE, 'Ile rat kartą opłacono łącznie (automat).'],
    [COL.TOKEN + '  /  ' + COL.SUBID, 'Techniczne - potrzebne systemowi do potwierdzeń i synchronizacji. Nie ruszać.'],
    ['', ''],
    ['KOLUMNY ROBOCZE (wypełnia fundacja - system ich nigdy nie nadpisuje)', ''],
    [COL.F_DZIECI, 'Które dziecko/dzieci objęliście tym wsparciem. Przypisanie podopiecznego i mail do darczyńcy z informacją o dziecku przygotowujecie RĘCZNIE - wpiszcie tutaj, gdy wysłane.'],
    [COL.F_WPLATY, 'Dla przelewowców: do kiedy opłacone. System nie widzi banku - sprawdzacie wyciąg i uzupełniacie ręcznie.'],
    [COL.F_NOTATKI, 'Dowolne notatki (kontakt z darczyńcą, ustalenia, korespondencja).'],
    ['', ''],
    ['UWAGA TECHNICZNA', 'Wygląd zakładki (kolory wierszy, szerokości kolumn, zawijanie, filtr) jest zarządzany przez system. Ręczne zmiany formatowania i własne reguły formatowania warunkowego zostaną nadpisane przy kolejnym uruchomieniu konfiguracji. Same DANE, w tym Wasze kolumny robocze, nigdy nie są ruszane.'],
  ];
  s.getRange(1, 1, rows.length, 2).setValues(rows);
  s.getRange(1, 1, rows.length, 1).setFontWeight('bold');
  s.getRange(1, 1).setFontSize(14);
  s.setColumnWidth(1, 340);
  s.setColumnWidth(2, 760);
  s.getRange(1, 1, rows.length, 2).setWrap(true).setVerticalAlignment('top');
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
