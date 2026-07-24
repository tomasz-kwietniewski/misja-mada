/* ═══════════════════════════════════════════════════════════════
   Wspólne helpery formularzy/modali - Fundacja Misja MADA
   ───────────────────────────────────────────────────────────────
   Deduplikacja (audyt 2026-07-24): pułapka fokusu, walidacja e-mail,
   detekcja hosta produkcyjnego i loader Secure Form były skopiowane
   w darowizna.js / adopcja-form.js / newsletter.js / kontakt.html.
   Ten plik jest jedynym źródłem prawdy; konsumenci sięgają po
   window.MadaCommon WYŁĄCZNIE wewnątrz funkcji (init/handlery), więc
   kolejność tagów <script defer> nie ma znaczenia - wszystkie defer
   wykonują się przed DOMContentLoaded.
   ═══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  // Prosta, celowo liberalna walidacja e-mail (dokładną robi backend/FILTER_VALIDATE_EMAIL).
  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  // Czy działamy na PRAWDZIWEJ produkcji (misjamada.pl / www.misjamada.pl)?
  // Używane do ochrony zasobów zewnętrznych zapisywanych na twardo (Google Apps Script).
  // UWAGA: endpointy względne (/payu/*, /newsletter/*) NIE potrzebują tego strażnika -
  // na localhost trafiają w lokalny backend PHP (sandbox), co jest pożądane w dev.
  function isLiveHost() {
    return /(^|\.)misjamada\.pl$/i.test(location.hostname);
  }

  // Ładuje moduł Secure Form (assets/secure-form.js) na żądanie - raz.
  function loadSecureForm() {
    if (window.MadaSecureForm) return Promise.resolve();
    return new Promise(function (res, rej) {
      var s = document.createElement('script');
      s.src = '/assets/secure-form.js'; s.async = true;
      s.onload = function () { res(); };
      s.onerror = function () { rej(new Error('Nie udało się załadować modułu płatności cyklicznej.')); };
      document.head.appendChild(s);
    });
  }

  /**
   * Pułapka fokusu dla modalu + przywrócenie fokusu po zamknięciu (a11y).
   * Listę elementów fokusowalnych liczymy ZA KAŻDYM RAZEM w handlerze - modale
   * budują treść dynamicznie (kroki formularza), a zapamiętane first/last byłyby
   * nieaktualne. Przy off(): fokus wraca na element, który otworzył modal, a gdyby
   * wciąż tkwił w modalu - blur() PRZED aria-hidden="true" (inaczej Chrome zgłasza
   * ostrzeżenie „Blocked aria-hidden ... descendant retained focus").
   * Użycie: var trap = MadaCommon.focusTrap(modal); trap.on({focusFirst:true}); trap.off();
   */
  function focusTrap(modal) {
    var lastFocused = null;
    function focusables() {
      return Array.prototype.slice.call(modal.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )).filter(function (el) { return el.offsetWidth || el.offsetHeight || el.getClientRects().length; });
    }
    function trapKey(e) {
      if (e.key !== 'Tab') return;
      var f = focusables();
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (!modal.contains(document.activeElement)) { e.preventDefault(); first.focus(); return; }
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
    return {
      on: function (opts) {
        lastFocused = document.activeElement;
        document.addEventListener('keydown', trapKey, true);
        // focusFirst: modale bez własnej logiki fokusu startowego (darowizna) proszą
        // pułapkę o ustawienie fokusu; pozostałe (adopcja/newsletter) robią to same w open().
        if (opts && opts.focusFirst) {
          setTimeout(function () { var f = focusables()[0]; if (f) { try { f.focus(); } catch (e) {} } }, 40);
        }
      },
      off: function () {
        document.removeEventListener('keydown', trapKey, true);
        var back = lastFocused;
        lastFocused = null;
        if (back && back !== document.body && document.contains(back) && (back.offsetWidth || back.offsetHeight || back.getClientRects().length)) {
          try { back.focus(); } catch (e) {}
        }
        if (modal.contains(document.activeElement)) {
          try { document.activeElement.blur(); } catch (e) {}
        }
      }
    };
  }

  window.MadaCommon = {
    EMAIL_RE: EMAIL_RE,
    isLiveHost: isLiveHost,
    loadSecureForm: loadSecureForm,
    focusTrap: focusTrap,
  };
})();
