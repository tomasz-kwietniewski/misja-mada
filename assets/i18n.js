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
   ═══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var KEY = 'mada_lang';
  var DICTS = {
    en: window.MADA_I18N || {},
    fr: window.MADA_I18N_FR || {}
  };
  var LANGS = ['pl', 'en', 'fr'];

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

  // Zwraca przetłumaczony tekst (zachowując wiodące/końcowe białe znaki) lub null
  function translateText(raw, target) {
    var dict = DICTS[target];
    if (!dict) return null;
    var key = norm(raw);
    if (!key) return null;
    var tr = dict[key];
    if (tr == null) return null;
    var lead = raw.match(/^\s*/)[0];
    var trail = raw.match(/\s*$/)[0];
    return lead + tr + trail;
  }

  var ATTRS = ['placeholder', 'aria-label', 'title', 'alt', 'value'];
  var SKIP_TAGS = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1 };

  function walkTranslate(root, target) {
    // węzły tekstowe
    var tw = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode: function (n) {
        if (n.parentNode && SKIP_TAGS[n.parentNode.tagName]) return NodeFilter.FILTER_REJECT;
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

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
