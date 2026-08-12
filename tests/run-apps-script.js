/* ═══════════════════════════════════════════════════════════════
   Testy logiki Apps Script (assets/google-apps-script.gs) na ATRAPACH
   Google API (SpreadsheetApp / GmailApp / ...). Uruchom:
       node tests/run-apps-script.js
   Kod wyjścia != 0, gdy którykolwiek test nie przejdzie (dla CI).

   PO CO: plik .gs wdrażamy ręcznie (wklejenie w edytorze Apps Script),
   a jego funkcje modyfikują PRODUKCYJNE dane fundacji - m.in. jednorazowa
   migracja arkusza. Bez tego harnessu jedyną weryfikacją byłoby uruchomienie
   na żywym arkuszu. Tu sprawdzamy: zapis po nazwach nagłówków, double opt-in,
   raty i anulowanie kartowe oraz idempotencję migracji.
  ═══════════════════════════════════════════════════════════════ */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const T = { pass: 0, fail: 0 };
function ok(cond, msg) {
  if (cond) T.pass++;
  else { T.fail++; console.error('  ✗ ' + msg); }
}
function eq(actual, expected, msg) {
  ok(Object.is(actual, expected), `${msg}  (oczekiwano: ${JSON.stringify(expected)}, było: ${JSON.stringify(actual)})`);
}

/* ────────── Atrapa arkusza Google ────────────────────────────── */
class FakeSheet {
  constructor(name) {
    this.name = name; this.data = []; this.rules = []; this.frozen = 0;
    this.formats = {}; this.widths = {}; this.wraps = {}; this.align = {};
    this.hidden = []; this.filter = null; this.rowHeights = {}; this.headStyle = {};
  }
  _ensure(row, col) {
    while (this.data.length < row) this.data.push([]);
    for (const r of this.data) while (r.length < col) r.push('');
  }
  getLastRow() { return this.data.length; }
  getLastColumn() { return this.data.reduce((m, r) => Math.max(m, r.length), 0); }
  getMaxRows() { return Math.max(this.data.length, 1000); }
  appendRow(values) { this.data.push(values.slice()); this._ensure(this.data.length, values.length); }
  clear() { this.data = []; this.rules = []; }
  setColumnWidth(col, px) { this.widths[col] = px; return this; }
  setRowHeight(row, px) { this.rowHeights[row] = px; return this; }
  hideColumns(col) { if (this.hidden.indexOf(col) === -1) this.hidden.push(col); return this; }
  getFilter() { return this.filter; }
  setFrozenRows(n) { this.frozen = n; return this; }
  setConditionalFormatRules(rules) { this.rules = rules; }
  getConditionalFormatRules() { return this.rules; }
  getDataRange() { return this.getRange(1, 1, Math.max(this.data.length, 1), Math.max(this.getLastColumn(), 1)); }
  getRange(row, col, numRows, numCols) {
    const nR = numRows === undefined ? 1 : numRows;
    const nC = numCols === undefined ? 1 : numCols;
    const sheet = this;
    sheet._ensure(row + nR - 1, col + nC - 1);
    return {
      getValues() {
        const out = [];
        for (let r = 0; r < nR; r++) out.push(sheet.data[row - 1 + r].slice(col - 1, col - 1 + nC));
        return out;
      },
      setValues(vals) {
        for (let r = 0; r < nR; r++) for (let c = 0; c < nC; c++) sheet.data[row - 1 + r][col - 1 + c] = vals[r][c];
        return this;
      },
      setValue(v) { sheet.data[row - 1][col - 1] = v; return this; },
      // Format zapisujemy per kolumna (tak go ustawiamy w .gs - na całych kolumnach).
      setNumberFormat(fmt) {
        for (let c = 0; c < nC; c++) sheet.formats[col + c] = { fmt, fromRow: row, rows: nR };
        return this;
      },
      setWrapStrategy(s) {
        for (let c = 0; c < nC; c++) sheet.wraps[col + c] = { strategy: s, fromRow: row };
        return this;
      },
      setHorizontalAlignment(a) {
        for (let c = 0; c < nC; c++) sheet.align[col + c] = { align: a, fromRow: row };
        return this;
      },
      createFilter() { sheet.filter = { remove() { sheet.filter = null; }, cols: nC, rows: nR }; return sheet.filter; },
      setFontWeight(w) { if (row === 1) sheet.headStyle.weight = w; return this; },
      setBackground(b) { if (row === 1) sheet.headStyle.bg = b; return this; },
      setFontColor(c) { if (row === 1) sheet.headStyle.color = c; return this; },
      setFontSize() { return this; },
      setWrap() { return this; }, setVerticalAlignment() { return this; },
    };
  }
}

const sheets = {};
const sentMails = [];
let ruleCount = 0;

const sandbox = {
  console,
  SpreadsheetApp: {
    WrapStrategy: { WRAP: 'WRAP', CLIP: 'CLIP', OVERFLOW: 'OVERFLOW' },
    getActiveSpreadsheet: () => ({
      getSheetByName: (n) => sheets[n] || null,
      insertSheet: (n) => (sheets[n] = new FakeSheet(n)),
    }),
    newConditionalFormatRule: () => {
      const rule = { formula: null, bg: null, bold: false, ranges: [] };
      const b = {
        whenFormulaSatisfied(f) { rule.formula = f; return b; },
        setBackground(c) { rule.bg = c; return b; },
        setBold(v) { rule.bold = v; return b; },
        setRanges(r) { rule.ranges = r; return b; },
        build() { ruleCount++; return rule; },
      };
      return b;
    },
  },
  GmailApp: { sendEmail: (to, subject, body, opts) => sentMails.push({ to, subject, body, opts }) },
  Utilities: { getUuid: () => 'uuid-' + Math.random().toString(16).slice(2) },
  ContentService: { MimeType: { JSON: 'json' }, createTextOutput: (t) => ({ text: t, setMimeType() { return this; } }) },
  HtmlService: { createHtmlOutput: (h) => ({ html: h }) },
  ScriptApp: { getService: () => ({ getUrl: () => 'https://script.google.com/macros/s/TEST/exec' }) },
  UrlFetchApp: { fetch: () => ({ getResponseCode: () => 200 }) },
};

const src = fs.readFileSync(path.join(__dirname, '..', 'assets', 'google-apps-script.gs'), 'utf8');
vm.createContext(sandbox);
vm.runInContext(src, sandbox, { filename: 'google-apps-script.gs' });
// Deklaracje `const` żyją w leksykalnym zasięgu kontekstu (nie na obiekcie globalnym),
// więc stałe wyciągamy osobnym wywołaniem w TYM SAMYM kontekście.
vm.runInContext(`globalThis.__c = {
  COL, HEADERS_ADOPCJA, SHEET_SECRET, WER_OCZEKUJE, WER_POTWIERDZONY, WER_ND,
  SUB_AKTYWNA, SUB_ANULOWANA, METODA_PRZELEW, METODA_KARTA };`, sandbox);
const G = Object.assign({}, sandbox.__c, sandbox);
const ADOPCJA = 'Adopcja Serca';

// Daty tworzone wewnątrz kontekstu vm należą do innego realmu, więc `instanceof Date`
// tam nie działa - sprawdzamy po zachowaniu.
const isDate = (v) => !!v && typeof v.getTime === 'function' && !isNaN(v.getTime());

/** Wiersz arkusza jako obiekt {nazwaKolumny: wartość}. */
function rowObj(sheet, rowIdx) {
  const headers = sheet.data[0].map(String);
  const out = {};
  headers.forEach((h, i) => { out[h] = sheet.data[rowIdx][i]; });
  return out;
}
const post = (payload) => G.doPost({ postData: { contents: JSON.stringify(payload) } });
const parse = (res) => JSON.parse(res.text);

/* ══════════ 1. Ścieżka PRZELEWU (double opt-in) ═══════════════ */
post({
  imie: 'Anna', nazwisko: 'Kowalska', email: 'anna@example.com', telefon: '600100200',
  adres: 'ul. Kwiatowa 1, Toruń', formaLabel: 'Na czas nieokreślony', czestotliwosc: 'Miesięcznie',
  dzieci: 2, zgoda_regulamin: true, zgoda_rodo: true, newsletter: true,
});
const sheet = sheets[ADOPCJA];
ok(sheet, 'przelew: zakładka „Adopcja Serca" utworzona automatycznie');
eq(sheet.data[0].length, G.HEADERS_ADOPCJA.length, 'przelew: liczba nagłówków = schemat');
let r1 = rowObj(sheet, 1);
eq(r1[G.COL.WERYFIKACJA], G.WER_OCZEKUJE, 'przelew: nowy wiersz czeka na potwierdzenie e-mail');
eq(r1[G.COL.METODA], G.METODA_PRZELEW, 'przelew: jawna metoda płatności w osobnej kolumnie');
eq(r1[G.COL.IMIE], 'Anna', 'przelew: imię w swojej kolumnie');
eq(r1[G.COL.CZESTOTLIWOSC], 'Miesięcznie', 'przelew: częstotliwość z formularza');
eq(r1[G.COL.SUB_STATUS], '', 'przelew: kolumna subskrypcji PayU pusta (nie dotyczy)');
eq(r1[G.COL.F_DZIECI], '', 'przelew: kolumny robocze fundacji zostają puste');
eq(sentMails.length, 1, 'przelew: wysłany 1 mail (potwierdzenie do darczyńcy)');
ok(/Potwierd/i.test(sentMails[0].subject), 'przelew: to mail z prośbą o potwierdzenie');

// potwierdzenie linkiem z maila
const token = r1[G.COL.TOKEN];
ok(String(token).length > 0, 'przelew: token wygenerowany');
G.doGet({ parameter: { confirm: token } });
r1 = rowObj(sheet, 1);
eq(r1[G.COL.WERYFIKACJA], G.WER_POTWIERDZONY, 'przelew: po kliknięciu linku status „Potwierdzony"');
ok(isDate(r1[G.COL.POTWIERDZENIE]), 'przelew: zapisana data potwierdzenia');
ok(sentMails.some(m => /Witaj w programie/.test(m.subject)), 'przelew: wysłany mail powitalny z danymi do przelewu');
ok(sentMails.some(m => /Nowe zgłoszenie/.test(m.subject)), 'przelew: fundacja powiadomiona o zgłoszeniu');
const welcome = sentMails.find(m => /Witaj w programie/.test(m.subject));
ok(/140 zł/.test(welcome.opts.htmlBody), 'przelew: mail powitalny liczy kwotę 2 × 70 zł');
ok(/Anna Kowalska/.test(welcome.opts.htmlBody), 'przelew: tytuł przelewu z danymi darczyńcy');
ok(/Adopcja Serca - darowizna - Anna Kowalska/.test(welcome.opts.htmlBody),
   'przelew: tytuł przelewu w formacie wspólnym z PHP');
// Stawka musi iść za częstotliwością: deklaracja roczna to 840 zł, nie 70 zł.
eq(G.stawkaZaOkres('Rocznie').kwota, 840, 'stawka: deklaracja roczna = 840 zł za dziecko');
eq(G.stawkaZaOkres('Kwartalnie').kwota, 210, 'stawka: deklaracja kwartalna = 210 zł za dziecko');
eq(G.stawkaZaOkres('Miesięcznie').kwota, 70, 'stawka: deklaracja miesięczna = 70 zł za dziecko');
eq(G.stawkaZaOkres('').kwota, 70, 'stawka: brak deklaracji -> miesięcznie');
// ponowne kliknięcie tego samego linku nie psuje wiersza
const before = JSON.stringify(sheet.data[1]);
G.doGet({ parameter: { confirm: token } });
eq(JSON.stringify(sheet.data[1]), before, 'przelew: ponowne kliknięcie linku nic nie zmienia');

/* ══════════ 2. Ścieżka KARTY PayU ════════════════════════════ */
const SECRET = G.SHEET_SECRET;
post({
  type: 'adopcja', status: 'oplacone-PayU', secret: SECRET, subId: '42',
  imie: 'Jan', nazwisko: 'Nowak', email: 'jan@example.com', telefon: '600300400',
  adres: 'ul. Polna 2, Kraków', forma: 'Na czas nieokreślony', dzieci: 1, newsletter: 'TAK',
});
let r2 = rowObj(sheet, 2);
eq(r2[G.COL.METODA], G.METODA_KARTA, 'karta: metoda „Karta PayU"');
eq(r2[G.COL.WERYFIKACJA], G.WER_ND, 'karta: weryfikacja e-mail „Nie dotyczy" (płatność ją zastąpiła)');
eq(r2[G.COL.SUB_STATUS], G.SUB_AKTYWNA, 'karta: subskrypcja aktywna');
eq(r2[G.COL.CZESTOTLIWOSC], 'Miesięcznie', 'karta: czytelna częstotliwość zamiast literału „PayU (karta, cyklicznie)"');
eq(r2[G.COL.MIESIACE], 1, 'karta: pierwsza rata policzona');
eq(String(r2[G.COL.SUBID]), '42', 'karta: ID subskrypcji zapisane (klucz do rat i anulowania)');
ok(isDate(r2[G.COL.OSTATNIA]), 'karta: data pierwszej wpłaty');

// kolejna rata (notyfikacja PayU COMPLETED -> payu/notify.php)
const charge = parse(post({ type: 'adopcja-charge', secret: SECRET, subId: '42', monthsPaid: 5 }));
eq(charge.updated, 1, 'rata: zaktualizowano dokładnie 1 wiersz');
r2 = rowObj(sheet, 2);
eq(r2[G.COL.MIESIACE], 5, 'rata: licznik miesięcy z bazy (wartość absolutna)');
// ponowiona notyfikacja PayU nie zafałszuje licznika
post({ type: 'adopcja-charge', secret: SECRET, subId: '42', monthsPaid: 5 });
eq(rowObj(sheet, 2)[G.COL.MIESIACE], 5, 'rata: ponowiona notyfikacja nie zwiększa licznika');
const mailsBeforeCharge = sentMails.length;
post({ type: 'adopcja-charge', secret: SECRET, subId: '42', monthsPaid: 6 });
eq(sentMails.length, mailsBeforeCharge, 'rata: brak maila do fundacji (co miesiąc = spam)');
eq(parse(post({ type: 'adopcja-charge', secret: SECRET, subId: '999', monthsPaid: 3 })).updated, 0,
   'rata: nieznane subId -> 0 aktualizacji, bez błędu');

// anulowanie subskrypcji
const cancel = parse(post({ type: 'adopcja-cancel', secret: SECRET, subId: '42', imie: 'Jan', nazwisko: 'Nowak', email: 'jan@example.com', amount: '70.00' }));
eq(cancel.updated, 1, 'anulowanie: zaktualizowano wiersz');
r2 = rowObj(sheet, 2);
eq(r2[G.COL.SUB_STATUS], G.SUB_ANULOWANA, 'anulowanie: status subskrypcji „Anulowana"');
eq(r2[G.COL.WERYFIKACJA], G.WER_ND, 'anulowanie: NIE nadpisuje kolumny weryfikacji e-mail (osobne porządki)');
ok(isDate(r2[G.COL.ANULOWANIE]), 'anulowanie: data anulowania');
ok(sentMails.some(m => /anulowana/i.test(m.subject)), 'anulowanie: fundacja powiadomiona');
eq(parse(post({ type: 'adopcja-cancel', secret: SECRET, subId: '42' })).updated, 0,
   'anulowanie: powtórzone -> 0 (już anulowana)');

/* ══════════ 3. Bezpieczeństwo dispatchu ══════════════════════ */
eq(parse(post({ type: 'adopcja-charge', subId: '42', monthsPaid: 9 })).error, 'unauthorized',
   'bezpieczeństwo: rata bez sekretu odrzucona');
eq(rowObj(sheet, 2)[G.COL.MIESIACE], 6, 'bezpieczeństwo: odrzucone żądanie nie zmieniło arkusza');
eq(parse(post({ type: 'adopcja-cancel', subId: '42' })).error, 'unauthorized', 'bezpieczeństwo: anulowanie bez sekretu odrzucone');
const rowsBefore = sheet.data.length;
eq(parse(post({ type: 'cos-nowego', secret: SECRET, subId: '1' })).error, 'unknown-type',
   'bezpieczeństwo: nieznany typ z sekretem -> unknown-type');
eq(sheet.data.length, rowsBefore, 'bezpieczeństwo: nieznany typ NIE tworzy fałszywego wiersza (stare PHP vs nowy skrypt)');

/* ══════════ 4. Migracja starego arkusza (setupArkuszAdopcja) ══ */
// Odtwarzamy zakładkę w STARYM schemacie z danymi jak na produkcji.
sheets[ADOPCJA] = new FakeSheet(ADOPCJA);
const old = sheets[ADOPCJA];
old.appendRow(['token', 'status', 'ts_received', 'ts_verified', 'imie', 'nazwisko', 'email',
  'telefon', 'adres', 'forma', 'okres', 'czestotliwosc', 'dzieci',
  'zgoda_regulamin', 'zgoda_wizerunek', 'zgoda_rodo', 'newsletter', 'subId', 'ts_cancelled']);
old.appendRow(['t1', 'pending', new Date(), '', 'Ewa', 'Zielona', 'ewa@example.com', '1', 'Adres 1',
  'Na czas nieokreślony', '', 'Miesięcznie', 1, 'TAK', '', 'TAK', '', '', '']);
old.appendRow(['t2', 'verified', new Date(), new Date(), 'Piotr', 'Biały', 'piotr@example.com', '2', 'Adres 2',
  'Czasowa (min. 1 rok)', '2026-01-01 - 2027-01-01', 'Kwartalnie', 3, 'TAK', 'TAK', 'TAK', 'TAK', '', '']);
old.appendRow(['t3', 'oplacone-PayU', new Date(), new Date(), 'Maria', 'Czarna', 'maria@example.com', '3', 'Adres 3',
  'Na czas nieokreślony', '', 'PayU (karta, cyklicznie)', 2, 'TAK', 'TAK', 'TAK', '', '77', '']);
old.appendRow(['t4', 'anulowana', new Date(), new Date(), 'Adam', 'Szary', 'adam@example.com', '4', 'Adres 4',
  'Na czas nieokreślony', '', 'PayU (karta, cyklicznie)', 1, 'TAK', '', 'TAK', '', '88', new Date()]);

G.setupArkuszAdopcja();

const H = old.data[0].map(String);
ok(H.indexOf('status') === -1 && H.indexOf('ts_received') === -1, 'migracja: stare techniczne nagłówki zniknęły');
G.HEADERS_ADOPCJA.forEach(h => ok(H.indexOf(h) !== -1, 'migracja: jest kolumna „' + h + '"'));
eq(H.length, G.HEADERS_ADOPCJA.length, 'migracja: brak duplikatów kolumn');
// pierwotne dane nietknięte
eq(rowObj(old, 1)[G.COL.EMAIL], 'ewa@example.com', 'migracja: dane osobowe zachowane pod nowymi nagłówkami');
eq(rowObj(old, 2)[G.COL.OKRES], '2026-01-01 - 2027-01-01', 'migracja: okres adopcji czasowej zachowany');
// statusy rozdzielone na dwie kolumny
const m1 = rowObj(old, 1), m2 = rowObj(old, 2), m3 = rowObj(old, 3), m4 = rowObj(old, 4);
eq(m1[G.COL.WERYFIKACJA], G.WER_OCZEKUJE, 'migracja: pending -> Oczekuje');
eq(m1[G.COL.METODA], G.METODA_PRZELEW, 'migracja: brak subId -> Przelew');
eq(m1[G.COL.SUB_STATUS], '', 'migracja: przelewowiec bez statusu subskrypcji PayU');
eq(m2[G.COL.WERYFIKACJA], G.WER_POTWIERDZONY, 'migracja: verified -> Potwierdzony');
eq(m2[G.COL.CZESTOTLIWOSC], 'Kwartalnie', 'migracja: częstotliwość przelewowca nietknięta');
eq(m3[G.COL.WERYFIKACJA], G.WER_ND, 'migracja: oplacone-PayU -> weryfikacja Nie dotyczy');
eq(m3[G.COL.SUB_STATUS], G.SUB_AKTYWNA, 'migracja: oplacone-PayU -> subskrypcja Aktywna');
eq(m3[G.COL.METODA], G.METODA_KARTA, 'migracja: subId niepusty -> Karta PayU');
eq(m3[G.COL.CZESTOTLIWOSC], 'Miesięcznie', 'migracja: literał „PayU (karta, cyklicznie)" -> Miesięcznie');
eq(m4[G.COL.SUB_STATUS], G.SUB_ANULOWANA, 'migracja: anulowana -> subskrypcja Anulowana');
eq(m4[G.COL.WERYFIKACJA], G.WER_ND, 'migracja: anulowana karta -> weryfikacja Nie dotyczy');
// jednolity format kolumn datowych (bez tego ta sama kolumna pokazuje raz datę z godziną, raz samą datę)
const fmtOf = (name) => (old.formats[old.data[0].indexOf(name) + 1] || {});
[G.COL.ZGLOSZENIE, G.COL.POTWIERDZENIE, G.COL.ANULOWANIE, G.COL.OSTATNIA].forEach(name => {
  eq(fmtOf(name).fmt, 'yyyy-mm-dd hh:mm', 'format: „' + name + '" ma jednolity format daty z godziną');
  eq(fmtOf(name).fromRow, 2, 'format: „' + name + '" formatowana od wiersza 2 (nagłówek nietknięty)');
  ok(fmtOf(name).rows > 100, 'format: „' + name + '" formatuje całą kolumnę, więc nowe wiersze też');
});
eq(fmtOf(G.COL.MIESIACE).fmt, '0', 'format: licznik rat jako liczba całkowita');
eq(fmtOf(G.COL.DZIECI).fmt, '0', 'format: liczba dzieci jako liczba całkowita');
eq(fmtOf(G.COL.F_WPLATY).fmt, undefined, 'format: kolumny robocze fundacji bez narzuconego formatu');
eq(fmtOf(G.COL.EMAIL).fmt, undefined, 'format: kolumny tekstowe nietknięte');

// wygląd: szerokości, zawijanie, nagłówek, ukryty token, filtr
const colNum = (name) => old.data[0].indexOf(name) + 1;
ok(old.widths[colNum(G.COL.EMAIL)] >= 180, 'wygląd: kolumna e-mail dostatecznie szeroka');
ok(old.widths[colNum(G.COL.F_NOTATKI)] >= 250, 'wygląd: notatki fundacji najszersze');
ok(old.widths[colNum(G.COL.DZIECI)] <= 80, 'wygląd: liczba dzieci wąska');
ok(old.widths[colNum(G.COL.SUB_STATUS)] >= 130, 'wygląd: „Status subskrypcji PayU" mieści cały nagłówek');
ok(old.widths[colNum(G.COL.ANULOWANIE)] >= 130, 'wygląd: „Data anulowania" mieści cały nagłówek');
eq(old.wraps[colNum(G.COL.ADRES)].strategy, 'WRAP', 'wygląd: adres zawijany');
eq(old.wraps[colNum(G.COL.F_NOTATKI)].strategy, 'WRAP', 'wygląd: notatki zawijane');
eq(old.wraps[colNum(G.COL.EMAIL)].strategy, 'CLIP', 'wygląd: e-mail przycinany (nie rozpycha wiersza)');
eq(old.align[colNum(G.COL.METODA)].align, 'center', 'wygląd: metoda płatności wyśrodkowana');
eq(old.align[colNum(G.COL.METODA)].fromRow, 2, 'wygląd: wyśrodkowanie dotyczy danych, nie tylko nagłówka');
// e-mail: wyśrodkowany jest tylko nagłówek (fromRow 1), same adresy zostają do lewej
eq(old.align[colNum(G.COL.EMAIL)].fromRow, 1, 'wygląd: kolumny tekstowe bez wymuszonego wyrównania danych');
eq(old.headStyle.bg, '#422918', 'wygląd: nagłówek w kolorze fundacji');
eq(old.headStyle.color, '#faf5ee', 'wygląd: jasny tekst nagłówka');
eq(old.frozen, 1, 'wygląd: nagłówek zamrożony przy przewijaniu');
ok(old.rowHeights[1] >= 40, 'wygląd: wyższy wiersz nagłówka (nazwy się zawijają)');
eq(old.hidden.length, 1, 'wygląd: ukryta dokładnie jedna kolumna');
eq(old.hidden[0], colNum(G.COL.TOKEN), 'wygląd: ukryta jest kolumna techniczna z tokenem');
ok(old.filter !== null, 'wygląd: filtr w nagłówku (sortowanie/filtrowanie bez pomocy)');

// kolory + instrukcja
ok(old.rules.length >= 4, 'migracja: reguły kolorowania wierszy ustawione');
ok(old.rules.some(r => r.formula.indexOf(G.WER_OCZEKUJE) !== -1), 'migracja: reguła dla oczekujących na potwierdzenie');
ok(old.rules.some(r => r.formula.indexOf(G.METODA_PRZELEW) !== -1), 'migracja: reguła wyróżniająca przelewowców');
ok(sheets['Instrukcja'] && sheets['Instrukcja'].data.length > 10, 'migracja: zakładka „Instrukcja" utworzona');
const instr = JSON.stringify(sheets['Instrukcja'].data);
ok(instr.indexOf('wyciąg') !== -1, 'instrukcja: mówi wprost, że wpłaty przelewowe sprawdza się na wyciągu');
ok(instr.indexOf('RĘCZNIE') !== -1, 'instrukcja: mówi, że przypisanie dziecka i mail robi fundacja ręcznie');

// idempotencja: drugie uruchomienie nie zmienia niczego
const snapshot = JSON.stringify(old.data);
const rulesBefore = old.rules.length;
G.setupArkuszAdopcja();
eq(JSON.stringify(old.data), snapshot, 'idempotencja: drugie uruchomienie setup nie zmienia danych');
eq(old.rules.length, rulesBefore, 'idempotencja: reguły kolorów nie mnożą się');

// po migracji arkusz nadal przyjmuje nowe zgłoszenia (zapis po nazwach)
post({ imie: 'Nowa', nazwisko: 'Osoba', email: 'nowa@example.com', czestotliwosc: 'Rocznie', dzieci: 1, zgoda_rodo: true });
const added = rowObj(old, old.data.length - 1);
eq(added[G.COL.IMIE], 'Nowa', 'po migracji: nowe zgłoszenie trafia we właściwe kolumny');
eq(added[G.COL.METODA], G.METODA_PRZELEW, 'po migracji: metoda ustawiona');

/* ══════════ 5. Odporność na przestawione kolumny ═════════════ */
// Pracownik przenosi „Notatki (fundacja)" na początek - zapis po nazwach musi to znieść.
const moved = old.data[0].indexOf(G.COL.F_NOTATKI);
old.data.forEach(row => { const v = row.splice(moved, 1)[0]; row.unshift(v); });
post({ imie: 'Test', nazwisko: 'Kolumn', email: 'test@example.com', czestotliwosc: 'Miesięcznie', dzieci: 1 });
const shifted = rowObj(old, old.data.length - 1);
eq(shifted[G.COL.IMIE], 'Test', 'przestawione kolumny: imię nadal we właściwej kolumnie');
eq(shifted[G.COL.EMAIL], 'test@example.com', 'przestawione kolumny: e-mail nadal we właściwej kolumnie');
eq(shifted[G.COL.METODA], G.METODA_PRZELEW, 'przestawione kolumny: metoda nadal we właściwej kolumnie');

/* ══════════ Wynik ════════════════════════════════════════════ */
console.log(`\nTesty Apps Script (atrapy Google API): ${T.pass} OK`);
if (T.fail > 0) { console.error(`${T.fail} BŁĄD`); process.exit(1); }
console.log('0 błędów');
