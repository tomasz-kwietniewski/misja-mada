/* ═══════════════════════════════════════════════════════════════
   Formularz "Zostań rodzicem adopcyjnym" - modal + walidacja + POST
   ═══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  // Samonaprawa skew cache: stary HTML z cache nie ładuje site-common.js - doładuj sam
  // (szczegóły: komentarz w darowizna.js). Helpery niżej mają fallbacki na czas ładowania.
  if (!window.MadaCommon && !document.querySelector('script[src^="/assets/site-common.js"], script[src^="assets/site-common.js"]')) {
    var mcs = document.createElement('script');
    mcs.src = '/assets/site-common.js?v=20260724'; mcs.async = true;
    document.head.appendChild(mcs);
  }

  // Skrót tłumaczenia dla komunikatów/etykiet budowanych w JS. Brak wpisu w słowniku =
  // zwraca PL bez zmian (bezpieczny fallback). Dotyczy WYŁĄCZNIE tekstu widocznego dla
  // użytkownika - dane wysyłane do arkusza fundacji (collectData) zostają po polsku.
  const t = (s) => (window.MadaI18n && typeof window.MadaI18n.t === 'function') ? window.MadaI18n.t(s) : s;

  // Czy działamy na PRAWDZIWEJ produkcji (misjamada.pl / www.misjamada.pl)? Formularz Adopcji
  // wysyła dane na twardo zaszyty PRODUKCYJNY Google Apps Script - z localhost/preview NIE wolno
  // go dotykać, bo każdy submit tworzy realny wiersz w arkuszu fundacji. Na produkcji: bez zmian.
  // (Implementacja wspólna: assets/site-common.js; inline fallback na czas skew cache.)
  const madaIsLiveHost = () => window.MadaCommon
    ? window.MadaCommon.isLiveHost()
    : /(^|\.)misjamada\.pl$/i.test(location.hostname);

  // Loader Secure Form - wspólny (assets/site-common.js), ładowany na żądanie, raz.
  const loadSecureFormLib = () => window.MadaCommon
    ? window.MadaCommon.loadSecureForm()
    : Promise.reject(new Error('Nie udało się załadować modułu płatności cyklicznej.'));

  // Walidacja e-mail - wspólna, z fallbackiem na czas skew cache.
  const madaEmailRe = () => window.MadaCommon ? window.MadaCommon.EMAIL_RE : /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  // Backend płatności cyklicznej (Secure Form -> recurring FIRST).
  window.MADA_RECURRING_URL = '/payu/recurring-first.php';

  /* ────────── KONFIGURACJA ────────────────────────────────────
     Po wdrożeniu wklej tutaj URL do swojego Google Apps Script
     Web App. Ten sam URL obsługuje formularz Adopcji Serca
     ORAZ formularz kontaktowy (różnią się polem `type` w JSON).
     Patrz: assets/google-apps-script.gs + DEPLOY-FORMULARZ.md.
  ──────────────────────────────────────────────────────────── */
  const SUBMIT_URL = 'https://script.google.com/macros/s/AKfycbz_x1eI5yH3tT8xLxSpNL_Y1c-Td56oFEqdYO4s1DV_UT3VYE6g8GnR6sEvW6Mcdavm/exec';
  window.MADA_SUBMIT_URL = SUBMIT_URL;  // udostępniamy globalnie dla kontakt.html

  function init() {
    // 1) Podpinamy modal do każdego "Zostań rodzicem adopcyjnym"
    const triggers = Array.from(document.querySelectorAll('a, button')).filter(el =>
      /zosta\u0144\s+rodzicem\s+adopcyjnym/i.test(el.textContent || '')
    );
    if (!triggers.length) return;

    // 2) Buduj modal raz
    if (!document.getElementById('adopcja-modal')) {
      const modal = document.createElement('div');
      modal.className = 'adopcja-modal';
      modal.id = 'adopcja-modal';
      modal.setAttribute('aria-hidden', 'true');
      modal.innerHTML = renderModalHtml();
      document.body.appendChild(modal);
    }

    const modal = document.getElementById('adopcja-modal');
    const form = modal.querySelector('form');
    const closeBtn = modal.querySelector('.am-close');
    const successPane = modal.querySelector('.am-success');

    // ── Dostępność: wspólna pułapka fokusu (assets/site-common.js) ──
    // Fokus startowy na pierwsze pole ustawia istniejąca logika open() (setTimeout),
    // dlatego on() bez focusFirst. Tworzona leniwie (fallback na czas skew cache).
    let amTrapObj = null;
    const amTrap = {
      on: () => { if (!amTrapObj && window.MadaCommon) amTrapObj = window.MadaCommon.focusTrap(modal); if (amTrapObj) amTrapObj.on(); },
      off: () => { if (amTrapObj) amTrapObj.off(); },
    };

    // Liczba dzieci (kwota = dzieci × 70 zł). Ustawiana też przez window.MadaAdopcja.open({dzieci}).
    let dzieci = 1;
    const STAWKA = 70;
    const dziSpan = form.querySelector('#am-dzieci');
    const calcEl = form.querySelector('#am-calc');
    function refreshDzieci() {
      if (dziSpan) dziSpan.textContent = dzieci;
      if (calcEl) calcEl.innerHTML = `${dzieci} × ${STAWKA} <span class="i18n-word">zł</span> = <strong>${dzieci * STAWKA} <span class="i18n-word">zł/mies.</span></strong>`;
      // Kwoty przy częstotliwości wpłat przelewem (miesięcznie/kwartalnie/rocznie)
      // nadążają za liczbą dzieci: kwota = dzieci × stawka-bazowa dla danego okresu.
      form.querySelectorAll('.am-freq').forEach(function (el) {
        el.textContent = dzieci * (parseInt(el.dataset.base, 10) || 0);
      });
    }
    // Kwota w zgodzie cyklicznej (PayU) nadąża za liczbą dzieci - deklarujemy wysoko,
    // by stepper i handler metody korzystały z tej samej funkcji.
    const cyklAmount = form.querySelector('.am-cykl-amount');
    function refreshCyklAmount() { if (cyklAmount) cyklAmount.innerHTML = (dzieci * STAWKA) + ' <span class="i18n-word">zł</span>'; }
    const minusBtn = form.querySelector('#am-minus');
    const plusBtn = form.querySelector('#am-plus');
    if (minusBtn) minusBtn.addEventListener('click', () => { if (dzieci > 1) { dzieci--; refreshDzieci(); refreshCyklAmount(); } });
    if (plusBtn) plusBtn.addEventListener('click', () => { if (dzieci < 20) { dzieci++; refreshDzieci(); refreshCyklAmount(); } });

    function open(e, opts) {
      if (e && typeof e.preventDefault === 'function') e.preventDefault();
      // Liczba dzieci: z opcji (przejście z darowizny) lub domyślnie 1.
      dzieci = (opts && opts.dzieci && opts.dzieci > 0) ? Math.min(20, opts.dzieci) : 1;
      refreshDzieci();
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('drawer-open');
      amTrap.on();
      // reset state
      form.style.display = '';
      successPane.style.display = 'none';
      form.reset();
      const errs = form.querySelectorAll('.field-error');
      errs.forEach(e => e.remove());
      form.querySelectorAll('.invalid').forEach(el => el.classList.remove('invalid'));
      setTimeout(() => {
        const first = form.querySelector('input, select, textarea');
        if (first) first.focus();
      }, 80);
    }
    function close() {
      // Fokus wyprowadzamy z modalu PRZED aria-hidden="true" (robi to trap.off()).
      amTrap.off();
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('drawer-open');
    }
    // Zamknięcie z ekranu sukcesu przechodzi tą samą drogą (przywrócenie fokusu).
    modal.addEventListener('click', e => {
      if (e.target.closest && e.target.closest('.am-close-success')) close();
    });

    triggers.forEach(t => t.addEventListener('click', open));

    // Publiczny hook - inny modal (darowizna) otwiera ten formularz z przeniesioną liczbą dzieci.
    window.MadaAdopcja = { open: function (opts) { open(null, opts); } };

    closeBtn.addEventListener('click', close);
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    document.addEventListener('keydown', e => {
      if (modal.classList.contains('is-open') && e.key === 'Escape') close();
    });

    // Walidacja na żywo - sprawdza pole po opuszczeniu (blur)
    const emailInput = form.querySelector('input[name="email"]');
    if (emailInput) {
      emailInput.addEventListener('blur', () => {
        const wrap = emailInput.closest('.am-field');
        // wyczyść poprzedni błąd e-mail
        const prev = wrap.querySelector('.field-error');
        if (prev) prev.remove();
        emailInput.classList.remove('invalid');
        const v = emailInput.value.trim();
        if (v && !madaEmailRe().test(v)) {
          emailInput.classList.add('invalid');
          const span = document.createElement('div');
          span.className = 'field-error';
          span.textContent = t('Podaj prawidłowy adres e-mail (np. jan@przykład.pl).');
          wrap.appendChild(span);
        }
      });
      // wyczyść błąd gdy zacznie poprawiać
      emailInput.addEventListener('input', () => {
        const wrap = emailInput.closest('.am-field');
        const err = wrap.querySelector('.field-error');
        if (err) err.remove();
        emailInput.classList.remove('invalid');
      });
    }

    // Show/hide date range fields based on radio selection
    const formaRadios = form.querySelectorAll('input[name="forma"]');
    const okresWrap = form.querySelector('.am-okres');
    formaRadios.forEach(r => r.addEventListener('change', () => {
      okresWrap.style.display = form.querySelector('input[name="forma"]:checked').value === 'czasowa' ? 'grid' : 'none';
    }));

    // Metoda wsparcia: PayU vs przelew - steruje częstotliwością i etykietą przycisku
    const metodaRadios = form.querySelectorAll('input[name="metoda"]');
    const czestWrap = form.querySelector('.am-czestotliwosc');
    const submitBtnEl = form.querySelector('button[type="submit"]');
    let cardMounted = false;
    const cardWrap = form.querySelector('.am-card-wrap');
    const cyklConsent = form.querySelector('.am-cykl-consent');
    metodaRadios.forEach(r => r.addEventListener('change', () => {
      const val = form.querySelector('input[name="metoda"]:checked').value;
      czestWrap.style.display = val === 'przelew' ? '' : 'none';
      cardWrap.style.display = val === 'payu' ? '' : 'none';
      cyklConsent.style.display = val === 'payu' ? '' : 'none';
      submitBtnEl.textContent = val === 'payu' ? t('Przejdź do płatności PayU →') : t('Wyślij zgłoszenie →');
      if (val === 'payu' && !cardMounted) {
        refreshCyklAmount();
        const loadingEl = form.querySelector('#am-card-loading');
        loadSecureFormLib()
          .then(() => window.MadaSecureForm.mount('am-card'))
          .then(() => { cardMounted = true; if (loadingEl) loadingEl.style.display = 'none'; })
          .catch(err => {
            if (loadingEl) loadingEl.style.display = 'none';
            showErrors(form, [{ field: null, msg: (err && err.message) ? err.message : 'Nie udało się załadować formularza karty.' }]);
          });
      }
    }));

    // Submit handler
    form.addEventListener('submit', async e => {
      e.preventDefault();
      // clear prior errors
      form.querySelectorAll('.field-error').forEach(el => el.remove());
      form.querySelectorAll('.invalid').forEach(el => el.classList.remove('invalid'));

      const data = collectData(form, dzieci);
      const errors = validate(data);
      if (errors.length) {
        showErrors(form, errors);
        const firstBad = form.querySelector('.invalid');
        if (firstBad) firstBad.focus();
        return;
      }

      const submitBtn = form.querySelector('button[type="submit"]');
      submitBtn.disabled = true;
      submitBtn.textContent = data.metoda === 'payu' ? t('Łączę z PayU…') : t('Wysyłam…');

      // ŚCIEŻKA A - automatyczna płatność cykliczna PayU (Secure Form + recurring FIRST)
      if (data.metoda === 'payu') {
        const RURL = window.MADA_RECURRING_URL || '/payu/recurring-first.php';
        try {
          if (!window.MadaSecureForm) throw new Error('Formularz karty nie jest gotowy. Odśwież stronę.');
          const cardToken = await window.MadaSecureForm.tokenize();
          const payload = {
            token: cardToken, consent: true,
            imie: data.imie, nazwisko: data.nazwisko, email: data.email, telefon: data.telefon,
            goal: 'adopcja', goalLabel: 'Adopcja Serca - ' + data.dzieci + ' dziecko/dzieci',
            amount: data.amount, currency: 'PLN', dzieci: data.dzieci,
            adres: data.adres, forma: data.formaLabel, okres: data.okres,
            zgoda_wizerunek: data.zgoda_wizerunek, newsletter: data.newsletter,
          };
          const res = await fetch(RURL, { method: 'POST', headers: { 'Content-Type': 'text/plain;charset=utf-8' }, body: JSON.stringify(payload) });
          const json = await res.json();
          if (json && json.redirectUri) { window.location.href = json.redirectUri; return; }
          if (json && json.status === 'active') { window.location.href = 'dziekujemy.html'; return; }
          throw new Error((json && json.error) ? json.error : 'Nie udało się rozpocząć płatności cyklicznej.');
        } catch (err2) {
          showErrors(form, [{ field: null, msg: (err2 && err2.message) ? err2.message : 'Nie udało się połączyć z bramką PayU. Spróbuj ponownie lub wybierz przelew tradycyjny.' }]);
          submitBtn.disabled = false; submitBtn.textContent = t('Przejdź do płatności PayU →');
          return;
        }
      }

      // ŚCIEŻKA B - przelew tradycyjny (zgłoszenie do fundacji, double opt-in)
      try {
        if (!madaIsLiveHost()) {
          // Środowisko nieprodukcyjne (localhost/preview) - NIE wysyłamy do arkusza fundacji.
          // Pokazujemy ekran sukcesu, by można było testować UI bez skutków na produkcji.
          console.info('[Adopcja] host nieprodukcyjny (' + location.hostname + ') - pomijam wysyłkę do Apps Script.');
        } else if (SUBMIT_URL) {
          // Zwykły CORS zamiast mode:'no-cors' (audyt 2026-07-24, W3): Apps Script
          // (ContentService) odpowiada z Access-Control-Allow-Origin:* (zweryfikowane
          // na wdrożonym web appie), a Content-Type text/plain nie wyzwala preflight.
          // Dzięki temu CZYTAMY odpowiedź {ok:true/false} i nie pokazujemy ekranu
          // sukcesu, gdy zapis do arkusza się nie powiódł (wcześniej odpowiedź była
          // nieprzezroczysta i sukces pokazywał się zawsze).
          const res = await fetch(SUBMIT_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'text/plain;charset=utf-8' },
            body: JSON.stringify(data),
          });
          const json = await res.json().catch(() => null);
          if (!res.ok || !json || !json.ok) {
            throw new Error('apps-script-error');
          }
        } else {
          // Brak skonfigurowanej bramki - fallback mailto z prefilled body
          const subject = encodeURIComponent('Zgłoszenie do programu Adopcja Serca');
          const body = encodeURIComponent(
            'Zgłaszam się do programu Adopcja Serca z następującymi danymi:\n\n' +
            'Imię i nazwisko: ' + data.imieNazwisko + '\n' +
            'E-mail: ' + data.email + '\n' +
            'Telefon: ' + data.telefon + '\n' +
            'Adres: ' + data.adres + '\n\n' +
            'Forma adopcji: ' + data.formaLabel + (data.okres ? ' (' + data.okres + ')' : '') + '\n' +
            'Sposób wsparcia: ' + data.metodaLabel + '\n' +
            'Częstotliwość wpłat: ' + data.czestotliwosc + '\n\n' +
            'Akceptuję regulamin oraz oświadczenie o wykorzystaniu wizerunku dziecka.'
          );
          // Otwiera klienta poczty z prefilled treścią
          window.location.href = 'mailto:kontakt@misjamada.pl?subject=' + subject + '&body=' + body;
        }
        // Show success
        const emailSpan = modal.querySelector('.am-success-email');
        if (emailSpan) emailSpan.textContent = data.email;
        form.style.display = 'none';
        successPane.style.display = '';
      } catch (err) {
        showErrors(form, [{ field: null, msg: 'Wystąpił błąd wysyłki. Spróbuj ponownie lub napisz na kontakt@misjamada.pl' }]);
      } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = data.metoda === 'payu' ? t('Przejdź do płatności PayU →') : t('Wyślij zgłoszenie →');
      }
    });
  }

  function collectData(form, dzieci) {
    const fd = new FormData(form);
    const forma = fd.get('forma') || '';
    const imie = (fd.get('imie') || '').toString().trim();
    const nazwisko = (fd.get('nazwisko') || '').toString().trim();
    return {
      imie: imie,
      nazwisko: nazwisko,
      imieNazwisko: (imie + ' ' + nazwisko).trim(),
      email: (fd.get('email') || '').toString().trim(),
      telefon: (fd.get('telefon') || '').toString().trim(),
      adres: (fd.get('adres') || '').toString().trim(),
      dzieci: dzieci || 1,
      amount: (dzieci || 1) * 70,
      forma: forma,
      od: (fd.get('od') || '').toString().trim(),
      do: (fd.get('do') || '').toString().trim(),
      formaLabel: forma === 'nieokreslony' ? 'Na czas nieokreślony' :
                  forma === 'czasowa' ? 'Czasowa (min. 1 rok)' : '',
      okres: forma === 'czasowa' ? `${fd.get('od') || ''} - ${fd.get('do') || ''}` : '',
      metoda: (fd.get('metoda') || '').toString(),
      metodaLabel: fd.get('metoda') === 'payu' ? 'Automatyczna płatność cykliczna (PayU)' :
                   fd.get('metoda') === 'przelew' ? 'Przelew tradycyjny / zlecenie stałe' : '',
      czestotliwosc: ({ miesiecznie: 'Miesięcznie', kwartalnie: 'Kwartalnie', rocznie: 'Rocznie' })[fd.get('czestotliwosc')] || '',
      zgoda_regulamin: !!fd.get('zgoda_regulamin'),
      zgoda_wizerunek: !!fd.get('zgoda_wizerunek'),
      zgoda_rodo: !!fd.get('zgoda_rodo'),
      zgoda_cykl: !!fd.get('zgoda_cykl'),
      newsletter: !!fd.get('newsletter'),
      ts: new Date().toISOString(),
    };
  }

  function validate(d) {
    const errs = [];
    if (!d.imie || d.imie.length < 2) errs.push({ field: 'imie', msg: 'Podaj imię.' });
    if (!d.nazwisko || d.nazwisko.length < 2) errs.push({ field: 'nazwisko', msg: 'Podaj nazwisko.' });
    if (!madaEmailRe().test(d.email)) errs.push({ field: 'email', msg: 'Podaj prawidłowy adres e-mail.' });
    if (!d.telefon || d.telefon.replace(/\D/g, '').length < 9) errs.push({ field: 'telefon', msg: 'Podaj numer telefonu.' });
    if (!d.adres) errs.push({ field: 'adres', msg: 'Podaj adres korespondencyjny.' });
    if (!d.forma) errs.push({ field: 'forma', msg: 'Wybierz formę adopcji.' });
    if (d.forma === 'czasowa' && (!d.od || !d.do)) errs.push({ field: 'od', msg: 'Wskaż okres trwania (od - do).' });
    if (!d.metoda) errs.push({ field: 'metoda', msg: 'Wybierz sposób przekazywania wsparcia.' });
    if (d.metoda === 'przelew' && !d.czestotliwosc) errs.push({ field: 'czestotliwosc', msg: 'Wybierz częstotliwość wpłat.' });
    if (d.metoda === 'payu' && !d.zgoda_cykl) errs.push({ field: 'zgoda_cykl', msg: 'Zaznacz zgodę na cykliczne obciążanie karty.' });
    if (!d.zgoda_regulamin) errs.push({ field: 'zgoda_regulamin', msg: 'Wymagana zgoda na regulamin.' });
    if (!d.zgoda_wizerunek) errs.push({ field: 'zgoda_wizerunek', msg: 'Wymagana zgoda na oświadczenie o wizerunku.' });
    if (!d.zgoda_rodo) errs.push({ field: 'zgoda_rodo', msg: 'Wymagana zgoda na przetwarzanie danych.' });
    return errs;
  }

  function showErrors(form, errors) {
    errors.forEach(err => {
      if (!err.field) {
        const top = form.querySelector('.am-toperror');
        if (top) { top.textContent = t(err.msg); top.style.display = ''; }
        return;
      }
      const el = form.querySelector('[name="' + err.field + '"]');
      if (!el) return;
      const wrap = el.closest('.am-field') || el.parentElement;
      el.classList.add('invalid');
      const span = document.createElement('div');
      span.className = 'field-error';
      span.textContent = t(err.msg);
      wrap.appendChild(span);
    });
  }

  function renderModalHtml() {
    return `
      <div class="am-box" role="dialog" aria-modal="true" aria-labelledby="am-title">
        <button type="button" class="am-close" aria-label="Zamknij formularz">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M6 18L18 6"/></svg>
        </button>

        <form novalidate>
          <header class="am-head">
            <span class="am-eyebrow">Program Adopcja Serca</span>
            <h2 id="am-title">Zostań rodzicem adopcyjnym</h2>
            <p>Wypełnij poniższy formularz - odezwiemy się do Ciebie z dalszymi krokami. Otrzymasz informację o dziecku objętym Twoim wsparciem. Wszystkie pola są wymagane.</p>
          </header>

          <fieldset class="am-fieldset am-dzieci-set">
            <legend>Liczba dzieci, które chcesz wesprzeć</legend>
            <div class="dar-stepper">
              <button type="button" class="dar-step-btn" id="am-minus" aria-label="Mniej dzieci">−</button>
              <div class="dar-step-val"><span id="am-dzieci">1</span></div>
              <button type="button" class="dar-step-btn" id="am-plus" aria-label="Więcej dzieci">+</button>
              <div class="dar-step-calc" id="am-calc">1 × 70 <span class="i18n-word">zł</span> = <strong>70 <span class="i18n-word">zł/mies.</span></strong></div>
            </div>
          </fieldset>

          <div class="am-toperror" style="display:none;" role="alert"></div>

          <div class="am-grid">
            <label class="am-field">
              <span class="am-label">Imię</span>
              <input type="text" name="imie" autocomplete="given-name" required />
            </label>
            <label class="am-field">
              <span class="am-label">Nazwisko</span>
              <input type="text" name="nazwisko" autocomplete="family-name" required />
            </label>
            <label class="am-field">
              <span class="am-label">E-mail</span>
              <input type="email" name="email" autocomplete="email" required />
            </label>
            <label class="am-field">
              <span class="am-label">Numer telefonu</span>
              <input type="tel" name="telefon" autocomplete="tel" required />
            </label>
            <label class="am-field am-field-full">
              <span class="am-label">Adres korespondencyjny</span>
              <input type="text" name="adres" autocomplete="street-address" required />
            </label>
          </div>

          <fieldset class="am-fieldset">
            <legend>Forma adopcji</legend>
            <label class="am-radio">
              <input type="radio" name="forma" value="nieokreslony" />
              <span>Na czas nieokreślony</span>
            </label>
            <label class="am-radio">
              <input type="radio" name="forma" value="czasowa" />
              <span>Czasowa (min. 1 rok)</span>
            </label>
            <div class="am-okres" style="display:none;">
              <label class="am-field">
                <span class="am-label">Od</span>
                <input type="date" name="od" />
              </label>
              <label class="am-field">
                <span class="am-label">Do</span>
                <input type="date" name="do" />
              </label>
            </div>
          </fieldset>

          <fieldset class="am-fieldset am-metoda-set">
            <legend>Jak chcesz przekazywać wsparcie?</legend>
            <label class="am-method">
              <input type="radio" name="metoda" value="payu" />
              <span class="am-method-card">
                <span class="am-method-top">
                  <strong>Automatyczna płatność cykliczna</strong>
                  <span class="am-method-badge">PayU - najwygodniej</span>
                </span>
                <span class="am-method-desc">Podpinasz kartę raz - <strong>70&nbsp;zł co miesiąc</strong> pobierane automatycznie. Możesz anulować w każdej chwili.</span>
              </span>
            </label>
            <label class="am-method">
              <input type="radio" name="metoda" value="przelew" />
              <span class="am-method-card">
                <span class="am-method-top">
                  <strong>Przelew tradycyjny / zlecenie stałe</strong>
                </span>
                <span class="am-method-desc">Samodzielnie ustawiasz przelew w swoim banku. Po zgłoszeniu wyślemy Ci dane do przelewu i tytuł wpłaty.</span>
              </span>
            </label>

            <div class="am-czestotliwosc" style="display:none;">
              <span class="am-label">Częstotliwość wpłat (przelew)</span>
              <div class="am-czest-opts">
                <label class="am-radio">
                  <input type="radio" name="czestotliwosc" value="miesiecznie" />
                  <span>Miesięcznie - <span class="am-freq" data-base="70">70</span>&nbsp;zł</span>
                </label>
                <label class="am-radio">
                  <input type="radio" name="czestotliwosc" value="kwartalnie" />
                  <span>Kwartalnie - <span class="am-freq" data-base="210">210</span>&nbsp;zł</span>
                </label>
                <label class="am-radio">
                  <input type="radio" name="czestotliwosc" value="rocznie" />
                  <span>Rocznie - <span class="am-freq" data-base="840">840</span>&nbsp;zł</span>
                </label>
              </div>
            </div>
          </fieldset>

          <div class="am-card-wrap" style="display:none;">
            <span class="am-label">Dane karty (płatność cykliczna)</span>
            <div id="am-card" class="dar-card-form"></div>
            <p class="am-card-loading" id="am-card-loading">Ładowanie bezpiecznego formularza karty…</p>
            <p class="am-note">Dane karty wpisujesz w bezpiecznym formularzu PayU. Zapisujemy wyłącznie token - nie mamy dostępu do numeru karty.</p>
            <div class="dar-pay-methods"><a class="dar-pay-logo" href="https://poland.payu.com" target="_blank" rel="noopener" translate="no">PayU</a></div>
          </div>

          <div class="am-consents">
            <label class="am-check am-field am-cykl-consent" style="display:none;">
              <input type="checkbox" name="zgoda_cykl" value="1" />
              <span>Wyrażam zgodę na cykliczne (comiesięczne) obciążanie mojej karty kwotą <strong class="am-cykl-amount">70 zł</strong> na rzecz Fundacji Misja MADA (Adopcja Serca), minimum przez 12 miesięcy. Mogę zrezygnować w każdej chwili (link w mailu).</span>
            </label>
            <label class="am-check am-field">
              <input type="checkbox" name="zgoda_regulamin" value="1" />
              <span>Akceptuję <a href="regulamin-adopcja-serca.html" target="_blank" rel="noopener">regulamin</a> programu Adopcja Serca.</span>
            </label>
            <label class="am-check am-field">
              <input type="checkbox" name="zgoda_wizerunek" value="1" />
              <span>Akceptuję <a href="oswiadczenie-o-wizerunku.html" target="_blank" rel="noopener">oświadczenie o&nbsp;wykorzystaniu wizerunku dziecka</a>.</span>
            </label>
            <label class="am-check am-field">
              <input type="checkbox" name="zgoda_rodo" value="1" />
              <span>Wyrażam zgodę na przetwarzanie moich danych osobowych przez Fundację Misja MADA zgodnie z <a href="polityka-prywatnosci.html" target="_blank" rel="noopener">Polityką prywatności</a>.</span>
            </label>
            <label class="am-check am-field">
              <input type="checkbox" name="newsletter" value="1" />
              <span>Chcę też otrzymywać newsletter Fundacji Misja MADA (dobrowolne, możesz zrezygnować w każdej chwili).</span>
            </label>
          </div>

          <div class="am-actions">
            <button type="submit" class="btn btn-gold">Wyślij zgłoszenie →</button>
          </div>
        </form>

        <div class="am-success" style="display:none;" role="status">
          <div class="am-success-ic" aria-hidden="true">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22 6 12 13 2 6"/></svg>
          </div>
          <h2>Sprawdź swoją skrzynkę pocztową!</h2>
          <p>Na podany adres <strong class="am-success-email"></strong> wysłaliśmy link do potwierdzenia zgłoszenia. Kliknij w link, a my zajmiemy się resztą.</p>
          <p style="font-size: 13px; color: var(--brown); opacity: .65; margin: -8px 0 22px;">Bez kliknięcia w link zgłoszenie nie zostanie przekazane do fundacji. Sprawdź także folder <em>spam</em>, jeśli wiadomość się nie pojawi w ciągu kilku minut.</p>

          <p style="font-family: var(--font-head); font-style: italic; color: var(--brown); margin-top: 4px;">Super, że jesteś z nami i chcesz pomóc dzieciom na Madagaskarze ❤︎</p>
          <button type="button" class="btn btn-primary am-close-success">Zamknij</button>
        </div>
      </div>
    `;
  }

  // (Zamknięcie z ekranu sukcesu obsługiwane w init() przez close() - z przywróceniem fokusu.)

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
