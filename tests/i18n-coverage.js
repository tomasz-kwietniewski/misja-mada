#!/usr/bin/env node
/* ═══════════════════════════════════════════════════════════════
   Bramka pokrycia tłumaczeń PL -> EN / FR
   ───────────────────────────────────────────────────────────────
   PO CO TO JEST
   i18n na tej stronie tłumaczy przez podmianę tekstu w węzłach DOM,
   a kluczem jest dosłowny tekst polski (assets/i18n.js). Brak wpisu w
   słowniku = tekst po prostu zostaje po polsku. Jest to bezpieczny
   fallback, ale CICHY - nic nie krzyczy, że fraza uciekła. Tak właśnie
   powstały zgłoszenia fundacji z lipca 2026 (zakładka Dokumenty, 404,
   oświadczenie o wizerunku itd.).

   Ten skrypt zamyka tę dziurę: przechodzi każdą podstronę tak samo jak
   i18n.js (węzeł po węźle) i wymaga, by KAŻDY tekst zawierający litery
   był albo w obu słownikach, albo na jawnej liście wyjątków poniżej.
   Żadnego zgadywania „czy to polski" - heurystyka przepuszczałaby frazy
   bez polskich znaków (np. „Statut Fundacji").

   URUCHOMIENIE
     node tests/i18n-coverage.js            # raport + kod wyjścia
     node tests/i18n-coverage.js --list-en  # same brakujące klucze EN
     node tests/i18n-coverage.js --list-fr  # same brakujące klucze FR

   Kod wyjścia 1 = są nieprzetłumaczone frazy (CI odrzuca push).
   Gdy fraza NIE MA być tłumaczona (nazwa własna, kod, numer) - dopisz ją
   do ALLOW, a nie do słownika.
   ═══════════════════════════════════════════════════════════════ */
'use strict';

var fs = require('fs');
var path = require('path');

var ROOT = path.join(__dirname, '..');

// newsletter.html to szablon e-maila do MailerLite (placeholdery {$email},
// brak nawigacji), a nie podstrona witryny - nie ładuje i18n i słusznie.
var SKIP_FILES = { 'newsletter.html': 1 };

// Atrybuty tłumaczone przez i18n.js - muszą być zgodne z ATTRS w assets/i18n.js
var ATTRS = ['placeholder', 'aria-label', 'title', 'alt', 'value'];

/* ── Wyjątki: teksty, które MAJĄ zostać nietłumaczone ──────────────
   Nazwy własne, marki, kody rejestrowe, adresy, liczby z jednostkami.
   Trzymamy je jawnie, żeby bramka miała sens: każda NOWA fraza spoza tej
   listy i spoza słownika = błąd CI, a nie cicha polska wstawka. */
var ALLOW = new Set([
  // marki / nazwy własne
  'Fundacja Misja MADA', 'Misja MADA', 'MADA', 'PayU', 'BLIK', 'Visa', 'Mastercard',
  'Facebook', 'MailerLite', 'MISEVI', 'Madagaskar', 'Itaosy', 'PDF', 'RODO',
  'Erste Bank Polska S.A.',
  // kody rejestrowe, bankowe i waluty - identyczne we wszystkich językach
  'KRS', 'NIP', 'REGON', 'IBAN', 'SWIFT', 'SWIFT / BIC', 'WBKPPLPP',
  'PLN', 'EUR', 'GBP',
  // adres siedziby (nazwa własna ulicy - nie tłumaczymy)
  'Szosa Chełmińska 271 A',
  // jednostki
  '5000 l',
  // domeny i adresy stron
  'salvatti.pl', 'Salvatti.pl', 'misevi.pl', 'suoredonorione.org',
  'tomaszkwietniewski.pl', 'poland.support.payu.com',
  'poland.payu.com/dokumenty-prawne-do-pobrania',
  // separatory / ozdobniki
  '·', '/', '→', '—', '-',
]);

// Numery kont (IBAN) - pomijamy wzorcem, żeby nie wpisywać każdego z osobna
var ALLOW_RE = [
  /^[A-Z]{2}\d{2}[\d ]{10,}$/,     // IBAN, np. PL49 1090 1056 ...
];

/* ── Mikro-tokenizer HTML (bez zależności) ─────────────────────────
   Nie parsujemy pełnego HTML - potrzebujemy tylko tego, co widzi
   TreeWalker w i18n.js: węzły tekstowe poza <script>/<style>/komentarzem
   i poza poddrzewem [translate="no"], plus wybrane atrybuty. */
function extract(html) {
  var texts = [];   // { text, attr? }
  var i = 0;
  var skipDepth = 0;      // >0 => jesteśmy w poddrzewie translate="no"
  var skipTagStack = [];  // nazwy tagów, które otworzyły translate="no"
  var stack = [];

  while (i < html.length) {
    var lt = html.indexOf('<', i);
    if (lt < 0) { pushText(html.slice(i)); break; }
    pushText(html.slice(i, lt));

    // komentarz
    if (html.startsWith('<!--', lt)) {
      var end = html.indexOf('-->', lt);
      i = end < 0 ? html.length : end + 3;
      continue;
    }
    // doctype
    if (html.startsWith('<!', lt)) {
      var gt0 = html.indexOf('>', lt);
      i = gt0 < 0 ? html.length : gt0 + 1;
      continue;
    }
    var gt = html.indexOf('>', lt);
    if (gt < 0) break;
    var raw = html.slice(lt + 1, gt);
    i = gt + 1;

    if (raw.startsWith('/')) {                       // tag zamykający
      var cname = raw.slice(1).trim().toLowerCase();
      if (skipTagStack.length && skipTagStack[skipTagStack.length - 1] === cname) {
        skipTagStack.pop();
        skipDepth--;
      }
      stack.pop();
      continue;
    }

    var m = /^([a-zA-Z0-9-]+)/.exec(raw);
    if (!m) continue;
    var tag = m[1].toLowerCase();
    var selfClosing = raw.endsWith('/') || /^(meta|link|img|br|hr|input|source|area|base|col)$/.test(tag);

    // <script> / <style> - przeskocz całą zawartość
    if (tag === 'script' || tag === 'style') {
      var close = html.toLowerCase().indexOf('</' + tag, i);
      i = close < 0 ? html.length : html.indexOf('>', close) + 1;
      continue;
    }

    var inSkip = skipDepth > 0;
    if (/translate\s*=\s*["']no["']/i.test(raw) && !selfClosing) {
      skipDepth++;
      skipTagStack.push(tag);
      inSkip = true;
    }

    if (!inSkip) {                                   // atrybuty tłumaczone
      ATTRS.forEach(function (a) {
        var am = new RegExp(a + '\\s*=\\s*"([^"]*)"', 'i').exec(raw);
        if (am && am[1].trim()) texts.push({ text: am[1], attr: a, tag: tag });
      });
    }
    if (!selfClosing) stack.push(tag);
  }

  function pushText(s) {
    if (skipDepth > 0) return;
    if (s && s.trim()) texts.push({ text: s });
  }
  return texts;
}

// musi być identyczne z norm() w assets/i18n.js
function norm(s) { return s.replace(/\s+/g, ' ').trim(); }

function decode(s) {
  return s.replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').replace(/&lt;/g, '<')
          .replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'")
          .replace(/&hellip;/g, '…').replace(/&middot;/g, '·').replace(/&rarr;/g, '→');
}

// Do tłumaczenia kwalifikuje się tekst zawierający jakąkolwiek literę.
// Czyste liczby, kwoty, symbole i strzałki pomijamy - i18n ich nie dotyczy.
function needsTranslation(key) {
  if (key.length < 2) return false;
  if (!/\p{L}/u.test(key)) return false;                 // brak liter
  if (ALLOW.has(key)) return false;
  if (ALLOW_RE.some(function (re) { return re.test(key); })) return false;
  if (/^[\d\s.,:/-]+$/.test(key)) return false;          // daty/liczby
  if (/^\S+@\S+\.\S+$/.test(key)) return false;          // e-mail
  if (/^(https?:\/\/|www\.)/i.test(key)) return false;   // URL
  return true;
}

/* ── Skan tekstów generowanych z JS/PHP ────────────────────────────
   Sam HTML to za mało: renderery (archiwum, wydarzenia, formularze) budują
   treść z literałów w JS, a domyślne kategorie wydarzeń siedzą w PHP. Checker
   patrzący tylko na HTML tego nie widzi - i dokładnie tak w lipcu 2026 uciekły
   „Czytaj relację", „Kiermasz" i „70 zł/mies.".

   Nie parsujemy JS - wyławiamy literały wyglądające na tekst dla człowieka:
   z polskimi znakami albo zaczynające się wielką literą i zawierające spację.
   Fragmenty HTML (`<span class="x">Tekst</span>`) rozbijamy na widoczny tekst. */
var JS_FILES = [
  'assets/archiwum-render.js', 'assets/wydarzenia-render.js', 'assets/wydarzenie-render.js',
  'assets/sprawozdania-render.js', 'assets/adopcja-form.js', 'assets/darowizna.js',
  'assets/newsletter.js', 'assets/site-search.js', 'assets/site-nav.js', 'assets/site-a11y.js',
];
/* UWAGA - inny poziom pewności niż przy HTML.
   HTML sprawdzamy wyczerpująco (każdy tekst musi być w słowniku albo w ALLOW).
   Dla JS to niemożliwe bez pełnego parsera: renderery sklejają HTML z kawałków
   (`'<a href="' + x + '">' + esc(t) + '</a>'`), więc wyrażenie regularne nie potrafi
   wiarygodnie odróżnić literału od kodu między nimi.

   Dlatego skan JS ma DWA poziomy pewności, zależnie od tego, jak wiarygodnie da się
   dany wzorzec sparsować:

   1. Tekst ze sklejanego HTML (`'<span>Tekst</span>' + zm`) - parsowanie zawodne,
      więc wymagamy polskich znaków diakrytycznych. Wyłapuje typowy przypadek
      („Czytaj relację", „Pokaż mniej") przy zerowym szumie ze ścieżek SVG.
      Świadome ograniczenie: polski BEZ diakrytyków (np. „Kiermasz") tu nie wpadnie -
      dlatego domyślne kategorie sprawdzamy niżej osobno i wprost.
   2. `x.textContent = 'Tekst'` - literał stoi po znaku „=" i kończy się średnikiem,
      więc regex trafia pewnie. Tu diakrytyków NIE wymagamy - inaczej przepadłyby
      etykiety mapy Madagaskaru („Antananarywa", „NASZA MISJA").

   Fragment ze wstawką ${...} pomijamy: zależy od zmiennej, więc nie jest stałym
   kluczem - taki tekst trzeba rozbić w kodzie (patrz .i18n-month w rendererach). */
var PL_CHARS = /[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/;
// Przy sklejaniu parowanie apostrofów bywa przesunięte i regex potrafi złapać kawałek
// KODU zamiast tekstu. Prawdziwy tekst na stronie nie ma klamer, `=>` ani `+ '`.
var CODEISH = /[{}]|=>|\+\s*'|\)\s*;|\.join\(|function\s*\(/;

function textsFromCode(src) {
  var loose = [];   // ze sklejanego HTML - poziom 1
  var solid = [];   // z .textContent - poziom 2
  src = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');   // bez komentarzy
  var re = /'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)"|`((?:[^`\\]|\\.)*)`/g, m;
  while ((m = re.exec(src))) {
    var lit = m[1] != null ? m[1] : (m[2] != null ? m[2] : m[3]);
    lit = lit.replace(/\\'/g, "'").replace(/\\"/g, '"').replace(/\\n/g, ' ');
    if (lit.indexOf('<') >= 0) {
      lit.replace(/>([^<>]*)</g, function (_, t) { loose.push(t); return _; });
    }
  }
  var rt = /\.textContent\s*=\s*([^;\n]+)/g, t;
  while ((t = rt.exec(src))) {
    // Wytnij operandy porównań: w `x.textContent = v === 'payu' ? 'A' : 'B'`
    // literał 'payu' to wartość techniczna, nie tekst dla użytkownika.
    var rhs = t[1].replace(/[=!]==?\s*(['"])(?:[^'"\\]|\\.)*\1/g, '');
    var mm, rq = /'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)"/g;
    while ((mm = rq.exec(rhs))) solid.push((mm[1] != null ? mm[1] : mm[2]).replace(/\\'/g, "'"));
  }
  function clean(arr, needPl) {
    return arr.filter(function (s) {
      return s.indexOf('${') < 0 && !CODEISH.test(s) && (!needPl || PL_CHARS.test(s));
    });
  }
  return clean(loose, true).concat(clean(solid, false));
}

function scanCode(dicts, add, htmlFiles) {
  function check(src, where) {
    textsFromCode(src).forEach(function (part) {
      var key = norm(decode(part));
      if (!needsTranslation(key)) return;
      if (dicts.en[key] == null) add('en', key, where);
      if (dicts.fr[key] == null) add('fr', key, where);
    });
  }
  JS_FILES.forEach(function (rel) {
    var p = path.join(ROOT, rel);
    if (fs.existsSync(p)) check(fs.readFileSync(p, 'utf8'), rel);
  });
  // Skrypty inline w HTML - np. mapa Madagaskaru w index.html ustawia etykiety
  // („Antananarywa", „NASZA MISJA") przez textContent. Walker i18n je tłumaczy,
  // bo trafiają do DOM, ale skan HTML ich nie widzi (pomija <script>).
  (htmlFiles || []).forEach(function (f) {
    var html = fs.readFileSync(path.join(ROOT, f), 'utf8');
    var re = /<script\b[^>]*>([\s\S]*?)<\/script>/gi, m;
    while ((m = re.exec(html))) check(m[1], f + ' (script inline)');
  });
  // Indeks wyszukiwarki (assets/site-search.js) - tu NIE zgadujemy: tablica jest
  // czystymi danymi, więc odczytujemy ją wprost i sprawdzamy, czy każdy wpis ma
  // komplet wariantów en/fr (page + title + body). Wyszukiwarka dopasowuje zapytanie
  // do treści indeksu, więc brak wariantu = w danym języku nic się nie znajdzie.
  var ssp = path.join(ROOT, 'assets', 'site-search.js');
  if (fs.existsSync(ssp)) {
    var ss = fs.readFileSync(ssp, 'utf8');
    var mi = /const INDEX = (\[[\s\S]*?\n {2}\]);/.exec(ss);
    if (!mi) {
      add('en', '[nie odnaleziono tablicy INDEX w site-search.js - sprawdź bramkę]', 'assets/site-search.js');
    } else {
      var index;
      try { index = new Function('return ' + mi[1])(); }
      catch (e) { index = null; add('en', '[INDEX w site-search.js nie daje się odczytać: ' + e.message + ']', 'assets/site-search.js'); }
      (index || []).forEach(function (e, i) {
        ['en', 'fr'].forEach(function (l) {
          var v = e[l];
          var brak = !v ? 'brak wariantu ' + l.toUpperCase()
                   : ['page', 'title', 'body'].filter(function (f) { return !v[f]; }).join(', ');
          if (brak) {
            add(l, 'INDEX[' + i + '] (' + (e.url || '?') + '): ' + (brak.indexOf('brak') === 0 ? brak : 'puste pola: ' + brak),
                'assets/site-search.js');
          }
        });
      });
    }
  }
  // Domyślne kategorie wydarzeń z panel/lib.php - trafiają wprost na chipy filtrów
  var libp = path.join(ROOT, 'panel', 'lib.php');
  if (fs.existsSync(libp)) {
    var lib = fs.readFileSync(libp, 'utf8');
    var block = /function mada_categories_defaults\(\)\s*\{[\s\S]*?\}/.exec(lib);
    if (block) {
      var mm, rr = /=>\s*'([^']+)'/g;
      while ((mm = rr.exec(block[0]))) {
        var k = norm(mm[1]);
        if (!needsTranslation(k)) continue;
        if (dicts.en[k] == null) add('en', k, 'panel/lib.php');
        if (dicts.fr[k] == null) add('fr', k, 'panel/lib.php');
      }
    }
  }
}

function loadDicts() {
  var win = {};
  var sandbox = { window: win };
  ['i18n-dict.js', 'i18n-dict-fr.js'].forEach(function (f) {
    var code = fs.readFileSync(path.join(ROOT, 'assets', f), 'utf8');
    new Function('window', code)(win);
  });
  return { en: win.MADA_I18N || {}, fr: win.MADA_I18N_FR || {} };
}

// i18n.js woła walkTranslate(document.body, ...) - <head> (w tym <title> i
// <meta description>) NIE jest tłumaczony. Checker musi patrzeć na to samo,
// inaczej zgłaszałby braki, których i18n i tak nigdy by nie ruszył.
function bodyOf(html) {
  var m = /<body\b[^>]*>/i.exec(html);
  if (!m) return html;
  var start = m.index + m[0].length;
  var end = html.toLowerCase().lastIndexOf('</body>');
  return html.slice(start, end < 0 ? html.length : end);
}

function main() {
  var dicts = loadDicts();
  var argv = process.argv.slice(2);
  var files = fs.readdirSync(ROOT).filter(function (f) {
    return f.endsWith('.html') && !SKIP_FILES[f];
  }).sort();

  var missEn = new Map();   // klucz -> Set(plików)
  var missFr = new Map();
  var checked = 0;

  files.forEach(function (f) {
    var html = bodyOf(fs.readFileSync(path.join(ROOT, f), 'utf8'));
    extract(html).forEach(function (item) {
      var key = norm(decode(item.text));
      if (!needsTranslation(key)) return;
      checked++;
      if (dicts.en[key] == null) add(missEn, key, f);
      if (dicts.fr[key] == null) add(missFr, key, f);
    });
  });

  function add(map, key, f) {
    if (!map.has(key)) map.set(key, new Set());
    map.get(key).add(f);
  }

  // teksty budowane w JS/PHP (renderery, formularze, skrypty inline, kategorie)
  scanCode(dicts, function (lang, key, file) {
    checked++;
    add(lang === 'en' ? missEn : missFr, key, file);
  }, files);

  if (argv.includes('--list-en')) { missEn.forEach(function (_, k) { console.log(k); }); return 0; }
  if (argv.includes('--list-fr')) { missFr.forEach(function (_, k) { console.log(k); }); return 0; }

  console.log('Pokrycie tłumaczeń PL -> EN / FR');
  console.log('  podstron sprawdzonych : ' + files.length + ' (pominięto: ' + Object.keys(SKIP_FILES).join(', ') + ')');
  console.log('  fraz do tłumaczenia   : ' + checked);
  console.log('  kluczy w słowniku EN  : ' + Object.keys(dicts.en).length);
  console.log('  kluczy w słowniku FR  : ' + Object.keys(dicts.fr).length);
  console.log('');

  function report(map, label) {
    if (!map.size) { console.log('OK  ' + label + ': brak brakujących tłumaczeń'); return; }
    console.log('BŁĄD  ' + label + ': ' + map.size + ' fraz bez tłumaczenia');
    var rows = Array.from(map.entries()).sort();
    rows.forEach(function (e) {
      var where = Array.from(e[1]).slice(0, 3).join(', ');
      var txt = e[0].length > 72 ? e[0].slice(0, 69) + '...' : e[0];
      console.log('   - "' + txt + '"');
      console.log('       w: ' + where);
    });
    console.log('');
  }
  report(missEn, 'EN');
  report(missFr, 'FR');

  if (missEn.size || missFr.size) {
    console.log('Napraw: dopisz tłumaczenie do assets/i18n-dict.js (EN) lub');
    console.log('assets/i18n-dict-fr.js (FR). Jeśli fraza NIE MA być tłumaczona');
    console.log('(nazwa własna, kod) - dopisz ją do ALLOW w tym pliku.');
    return 1;
  }
  console.log('Wszystkie frazy pokryte w EN i FR.');
  return 0;
}

process.exit(main());
