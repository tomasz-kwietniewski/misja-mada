/* ═══════════════════════════════════════════════════════════════
   i18n - przełącznik PL / EN / FR dla Fundacji Misja MADA
   ───────────────────────────────────────────────────────────────
   • Słowniki: window.MADA_I18N (PL→EN, plik assets/i18n-dict.js),
     window.MADA_I18N_FR (PL→FR, plik assets/i18n-dict-fr.js).
   • Tłumaczenie działa przez podmianę tekstu w węzłach DOM -
     dzięki temu nie trzeba dodawać data-i18n do każdego elementu.
   • Polski jest podstawą (oryginały). Brak wpisu w słowniku = tekst
     pozostaje po polsku (bezpieczny fallback).
   • Wybór języka zapamiętywany w localStorage (mada_lang) i wspólny
     dla wszystkich podstron.
   • MutationObserver tłumaczy treść dodaną dynamicznie (wydarzenia).
   • Aby poprawić/dodać tłumaczenie - edytuj assets/i18n-dict.js (EN)
     lub assets/i18n-dict-fr.js (FR). Klucz = tekst PL.

   ⚠ STRAŻNIK SYNCHRONIZACJI: tests/i18n-coverage.js REIMPLEMENTUJE
     logikę tego silnika (norm(), listę ATTRS, [translate="no"],
     tokenizację). Jeśli zmieniasz tu COKOLWIEK z powyższych,
     zaktualizuj bramkę w tym samym commicie - inaczej CI będzie
     sprawdzać co innego, niż strona robi.
   ═══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var KEY = 'mada_lang';
  var LANGS = ['pl', 'en', 'fr'];

  // Słownik czytamy z window ZA KAŻDYM RAZEM, a nie zapamiętujemy raz przy starcie.
  // Powód: events.js.php dokłada tłumaczenia wydarzeń przez
  //   window.MADA_I18N = Object.assign({}, window.MADA_I18N, {...})
  // czyli PODMIENIA obiekt. Zapamiętana referencja wskazywałaby wtedy na starą wersję
  // i tłumaczenia wydarzeń z CMS po cichu by przepadły. Dziś kolejność skryptów jest
  // dobra (dict -> events.js.php -> render -> i18n.js), ale to była cicha pułapka
  // czekająca na pierwszą zmianę kolejności.
  function dictFor(target) {
    if (target === 'en') return window.MADA_I18N || {};
    if (target === 'fr') return window.MADA_I18N_FR || {};
    return null;
  }

  var lang = (function () {
    try {
      var v = localStorage.getItem(KEY);
      return (LANGS.indexOf(v) >= 0) ? v : 'pl';
    } catch (e) { return 'pl'; }
  })();

  // Pamięć oryginałów (PL) dla węzłów tekstowych i atrybutów
  var originals = new WeakMap();   // node -> original nodeValue
  var attrOrig = new WeakMap();    // element -> { attr: originalValue }
  var observer = null;
  var toggleBtns = [];

  function norm(s) { return s.replace(/\s+/g, ' ').trim(); }

  // Indeks pomocniczy (klucz małymi literami -> tłumaczenie) budowany leniwie dla
  // każdego słownika. Fallback, gdy treść na stronie różni się od klucza TYLKO
  // wielkością liter (np. „Strona Partnera" vs słownik „Strona partnera"). Dzięki
  // temu drobna niespójność wielkości liter nie zostawia tekstu nieprzetłumaczonego.
  // Tylko klucze >= 5 znaków, by uniknąć kolizji krótkich słów (np. „Do"/„do").
  // Kolizje (różne tłumaczenia tego samego klucza-małymi) są wyłączane (null).
  // Cache trzyma referencję do słownika, z którego powstał - gdy events.js.php podmieni
  // obiekt, indeks przeliczamy zamiast oddawać nieaktualny.
  var LOWER = {};
  function lowerIndex(target) {
    var dict = dictFor(target) || {};
    var cached = LOWER[target];
    if (cached && cached.dict === dict) return cached.idx;
    var idx = {};
    for (var k in dict) {
      if (!Object.prototype.hasOwnProperty.call(dict, k) || k.length < 5) continue;
      var lk = k.toLowerCase();
      if (lk in idx) { if (idx[lk] !== dict[k]) idx[lk] = null; }
      else idx[lk] = dict[k];
    }
    LOWER[target] = { dict: dict, idx: idx };
    return idx;
  }

  // Zwraca przetłumaczony tekst (zachowując wiodące/końcowe białe znaki) lub null
  function translateText(raw, target) {
    var dict = dictFor(target);
    if (!dict) return null;
    var key = norm(raw);
    if (!key) return null;
    var tr = dict[key];
    if (tr == null) {
      // fallback: dopasowanie bez względu na wielkość liter
      var li = lowerIndex(target)[key.toLowerCase()];
      if (li == null) return null;
      tr = li;
    }
    var lead = raw.match(/^\s*/)[0];
    var trail = raw.match(/\s*$/)[0];
    return lead + tr + trail;
  }

  // 'href' jest na liście wyłącznie po to, by przetłumaczyć temat w linkach mailto
  // (np. „?subject=Partner Biznesowy" -> „Business Partner"). Nie zmienia to zwykłych
  // odnośników: tłumaczony jest tylko adres mający DOKŁADNY wpis w słowniku, a zwykłe
  // ścieżki („o-nas.html") żadnego wpisu nie mają, więc zostają nietknięte.
  var ATTRS = ['placeholder', 'aria-label', 'title', 'alt', 'value', 'href'];
  var SKIP_TAGS = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1 };

  function walkTranslate(root, target) {
    // węzły tekstowe
    var tw = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode: function (n) {
        if (n.parentNode && SKIP_TAGS[n.parentNode.tagName]) return NodeFilter.FILTER_REJECT;
        // Pomiń treść oznaczoną translate="no" (np. nazwa odbiorcy przelewu - musi
        // zostać dosłowna „Fundacja Misja MADA" we wszystkich językach).
        if (n.parentElement && n.parentElement.closest('[translate="no"]')) return NodeFilter.FILTER_REJECT;
        return n.nodeValue && n.nodeValue.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
      }
    });
    var n;
    while ((n = tw.nextNode())) {
      // bazą tłumaczenia jest oryginalny PL (jeśli już zapamiętany)
      var base = originals.has(n) ? originals.get(n) : n.nodeValue;
      var t = translateText(base, target);
      if (t != null) {
        if (!originals.has(n)) originals.set(n, n.nodeValue);
        n.nodeValue = t;
      }
    }
    // atrybuty
    var els = root.nodeType === 1 ? [root] : [];
    if (root.querySelectorAll) els = els.concat(Array.prototype.slice.call(root.querySelectorAll('*')));
    els.forEach(function (el) {
      if (!el.getAttribute) return;
      if (el.closest && el.closest('[translate="no"]')) return;
      ATTRS.forEach(function (a) {
        if (!el.hasAttribute(a)) return;
        var store = attrOrig.get(el) || {};
        var base = (a in store) ? store[a] : el.getAttribute(a);
        var t = translateText(base, target);
        if (t != null) {
          if (!(a in store)) { store[a] = el.getAttribute(a); attrOrig.set(el, store); }
          el.setAttribute(a, t);
        }
      });
    });
  }

  function restorePL() {
    var tw = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null);
    var n;
    while ((n = tw.nextNode())) {
      if (originals.has(n)) n.nodeValue = originals.get(n);
    }
    var all = document.querySelectorAll('*');
    Array.prototype.forEach.call(all, function (el) {
      var store = attrOrig.get(el);
      if (store) {
        for (var a in store) if (el.hasAttribute(a)) el.setAttribute(a, store[a]);
      }
    });
  }

  function setupObserver() {
    if (observer) return;
    observer = new MutationObserver(function (muts) {
      if (lang === 'pl') return;
      muts.forEach(function (m) {
        Array.prototype.forEach.call(m.addedNodes, function (node) {
          if (node.nodeType === 1) walkTranslate(node, lang);
          else if (node.nodeType === 3) {
            var t = translateText(node.nodeValue, lang);
            if (t != null) { if (!originals.has(node)) originals.set(node, node.nodeValue); node.nodeValue = t; }
          }
        });
      });
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  function apply(l, persist) {
    if (LANGS.indexOf(l) < 0) l = 'pl';
    lang = l;
    if (persist) { try { localStorage.setItem(KEY, l); } catch (e) {} }
    document.documentElement.setAttribute('lang', l);
    // Zawsze najpierw przywróć PL (baza), potem ewentualnie przetłumacz
    restorePL();
    if (l !== 'pl') { walkTranslate(document.body, l); setupObserver(); }
    updateToggle();
  }

  function updateToggle() {
    toggleBtns.forEach(function (b) {
      var isActive = b.getAttribute('data-lang') === lang;
      b.classList.toggle('is-active', isActive);
      b.setAttribute('aria-pressed', String(isActive));
    });
  }

  function makeSwitchHTML() {
    return '<button type="button" class="lang-opt" data-lang="pl">PL</button>' +
           '<span class="lang-sep" aria-hidden="true">/</span>' +
           '<button type="button" class="lang-opt" data-lang="en">EN</button>' +
           '<span class="lang-sep" aria-hidden="true">/</span>' +
           '<button type="button" class="lang-opt" data-lang="fr">FR</button>';
  }

  function buildToggle() {
    var actions = document.querySelector('nav.main-nav .nav-actions');
    if (actions && !actions.querySelector('.lang-switch')) {
      var sw = document.createElement('div');
      sw.className = 'lang-switch';
      sw.setAttribute('role', 'group');
      sw.setAttribute('translate', 'no');
      sw.setAttribute('aria-label', 'Wybór języka / Language / Langue');
      sw.innerHTML = makeSwitchHTML();
      actions.insertBefore(sw, actions.firstChild);
      bind(sw);
    }
    // wersja mobilna (szuflada)
    var drawerActions = document.querySelector('.nav-drawer .drawer-socials');
    if (drawerActions && !drawerActions.parentNode.querySelector('.lang-switch')) {
      var sw2 = document.createElement('div');
      sw2.className = 'lang-switch lang-switch-drawer';
      sw2.setAttribute('role', 'group');
      sw2.setAttribute('translate', 'no');
      sw2.setAttribute('aria-label', 'Wybór języka / Language / Langue');
      sw2.innerHTML = makeSwitchHTML();
      drawerActions.parentNode.insertBefore(sw2, drawerActions);
      bind(sw2);
    }
    updateToggle();
  }

  function bind(sw) {
    Array.prototype.forEach.call(sw.querySelectorAll('.lang-opt'), function (b) {
      toggleBtns.push(b);
      b.addEventListener('click', function () { apply(b.getAttribute('data-lang'), true); });
    });
  }

  function init() {
    buildToggle();
    // szuflada mobilna budowana przez site-nav.js (też na DOMContentLoaded) - dołóż toggle po chwili
    setTimeout(buildToggle, 200);
    if (lang !== 'pl') apply(lang, false);
    else updateToggle();
  }

  // ── Publiczne API dla kodu, który buduje tekst w locie ──────────────
  // Podmiana w DOM działa tylko dla tekstu, który JEST w DOM jako stały węzeł.
  // Gdy skrypt skleja zdanie ze zmiennej (np. „Znaleziono 3 wyniki dla zapytania X"),
  // powstaje węzeł unikalny dla każdego wywołania - nie da się go trzymać w słowniku.
  // Wtedy tłumaczymy części składowe TUTAJ, przed wstawieniem do DOM.
  // Używać oszczędnie: domyślną drogą jest zwykły tekst w HTML + wpis w słowniku.
  window.MadaI18n = {
    lang: function () { return lang; },
    // t('Znaleziono') -> 'Found' / 'Résultats'; brak wpisu = tekst PL bez zmian
    t: function (pl) {
      if (lang === 'pl') return pl;
      var tr = translateText(pl, lang);
      return tr == null ? pl : tr;
    }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
