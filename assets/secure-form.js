/* ═══════════════════════════════════════════════════════════════
   PayU Secure Form - wrapper dla płatności cyklicznych (Misja MADA)
   ───────────────────────────────────────────────────────────────
   Ładuje JS SDK PayU z serwera PayU, renderuje pola karty w iframe
   i tokenizuje (typ MULTI -> token wielokrotny do zapisu karty).
   Konfiguracja z window.MADA_PAYU (payu/secure-config.js.php).

   API:
     MadaSecureForm.ensureConfig()      -> Promise (ładuje secure-config.js.php)
     MadaSecureForm.mount(elementId)    -> Promise (renderuje pola karty)
     MadaSecureForm.tokenize()          -> Promise<string TOK_>  (rzuca przy błędzie)
     MadaSecureForm.reset()             -> czyści instancję (po zamknięciu modala)
  ═══════════════════════════════════════════════════════════════ */
window.MadaSecureForm = (function () {
  'use strict';

  var CONFIG_URL = '/payu/secure-config.js.php';
  var payu = null, secureForms = null, cardForm = null, mounted = false;
  var sdkPromise = null, cfgPromise = null;

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src; s.async = true;
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('Nie udało się załadować skryptu: ' + src)); };
      document.head.appendChild(s);
    });
  }

  // Pobiera window.MADA_PAYU (posId, sdkUrl) - raz.
  function ensureConfig() {
    if (window.MADA_PAYU) return Promise.resolve(window.MADA_PAYU);
    if (cfgPromise) return cfgPromise;
    cfgPromise = loadScript(CONFIG_URL).then(function () {
      if (!window.MADA_PAYU) throw new Error('Brak konfiguracji PayU.');
      return window.MADA_PAYU;
    });
    return cfgPromise;
  }

  // Ładuje SDK PayU (musi iść z serwera PayU) - raz.
  function ensureSdk() {
    if (window.PayU) return Promise.resolve();
    if (sdkPromise) return sdkPromise;
    sdkPromise = ensureConfig().then(function (cfg) { return loadScript(cfg.sdkUrl); });
    return sdkPromise;
  }

  // Renderuje pola karty w elemencie o podanym id.
  function mount(elementId) {
    return ensureSdk().then(function () {
      var cfg = window.MADA_PAYU;
      // tryb deweloperski tylko dla sandboxa poza https (np. localhost)
      var opts;
      if (cfg.env !== 'production' && location.protocol !== 'https:') opts = { dev: true };
      payu = window.PayU(cfg.posId, opts);
      // Język pól karty idzie za językiem strony - inaczej darczyńca w EN/FR widzi polskie
      // „Numer karty"/„Kod CVV" w kluczowym miejscu. Nieznany język -> 'en' (uniwersalniejszy
      // niż 'pl' dla obcojęzycznego); gdyby PayU nie wspierało kodu, użyje swojego domyślnego.
      var PAYU_LANGS = { pl: 'pl', en: 'en', fr: 'fr' };
      var pageLang = (window.MadaI18n && typeof window.MadaI18n.lang === 'function')
        ? window.MadaI18n.lang() : 'pl';
      secureForms = payu.secureForms({ lang: PAYU_LANGS[pageLang] || 'en' });

      var style = {
        basic: { fontSize: '16px', fontColor: '#3a2a1c' },
        invalid: { fontColor: '#9a2b22' }
      };
      cardForm = secureForms.add('card', { style: style });
      cardForm.render('#' + elementId);
      mounted = true;
    });
  }

  // Tokenizacja karty (MULTI = zapis karty). Zwraca token TOK_.
  function tokenize() {
    if (!mounted || !payu) return Promise.reject(new Error('Formularz karty nie jest gotowy.'));
    return payu.tokenize('MULTI').then(function (result) {
      if (result.status !== 'SUCCESS') {
        var msg = 'Sprawdź dane karty.';
        try { msg = result.error.messages[0].message || msg; } catch (e) {}
        throw new Error(msg);
      }
      return result.body.token;
    });
  }

  function reset() {
    payu = null; secureForms = null; cardForm = null; mounted = false;
  }

  return { ensureConfig: ensureConfig, mount: mount, tokenize: tokenize, reset: reset };
})();
