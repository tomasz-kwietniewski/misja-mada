/* ═══════════════════════════════════════════════════════════════
   Formularz darowizny PayU - Fundacja Misja MADA
   ───────────────────────────────────────────────────────────────
   Wieloetapowy modal:
     KROK 1 - kwota (10/20/50/100/inna), waluta (PLN/EUR),
              typ (jednorazowo/co miesiąc), cel (4 opcje)
     KROK 2 - dane osobowe + zgody → wysyłka do backendu PayU

   SPECJALNA LOGIKA - cel „Adopcja Serca":
     • kwota = liczba dzieci × stawka (PLN: 70 zł, EUR: 18 €),
       zawsze wielokrotność stawki - selektor liczby dzieci,
     • brak pola „inna kwota",
     • typ wpłaty zablokowany na „co miesiąc" (min. 12 miesięcy).

   KONFIGURACJA: ustaw window.MADA_PAYU_URL na adres backendu.
   Gdy puste - formularz pokazuje komunikat „wkrótce".
  ═══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  // Backend PayU (płatność jednorazowa) - ścieżka WZGLĘDNA, by działała
  // niezależnie od domeny wejścia (misjamada.pl oraz www.misjamada.pl) - bez CORS.
  window.MADA_PAYU_URL = '/payu/create-order.php';
  // Backend płatności cyklicznej (Secure Form -> recurring FIRST).
  window.MADA_RECURRING_URL = '/payu/recurring-first.php';

  // Ładuje moduł Secure Form (assets/secure-form.js) na żądanie - raz.
  function loadSecureFormLib() {
    if (window.MadaSecureForm) return Promise.resolve();
    return new Promise(function (res, rej) {
      var s = document.createElement('script');
      s.src = '/assets/secure-form.js'; s.async = true;
      s.onload = function () { res(); };
      s.onerror = function () { rej(new Error('Nie udało się załadować modułu płatności cyklicznej.')); };
      document.head.appendChild(s);
    });
  }

  const CELE = {
    statutowe: 'Działania statutowe Fundacji Misja MADA',
    adopcja: 'Adopcja Serca (tylko wpłaty cykliczne)',
    centrum: 'Rozbudowa Centrum Edukacyjnego w Itaosy',
    atelier: 'Wsparcie Atelier Nadziei',
  };
  const KWOTY = [10, 20, 50, 100];
  // Stawka Adopcji Serca za jedno dziecko (miesięcznie)
  const STAWKA = { PLN: 70, EUR: 18 };

  let state = {
    kwota: 50,
    inna: '',
    waluta: 'PLN',
    typ: 'jednorazowo',
    cel: 'statutowe',
    dzieci: 1,        // liczba adoptowanych dzieci (tylko cel = adopcja)
  };

  const isAdopcja = () => state.cel === 'adopcja';
  // Wyliczona kwota: dla adopcji = liczba dzieci × stawka, inaczej wybór/inna
  function kwotaAktualna() {
    if (isAdopcja()) return STAWKA[state.waluta] * state.dzieci;
    return state.inna ? parseFloat(state.inna) : state.kwota;
  }

  function init() {
    const triggers = Array.from(document.querySelectorAll('[data-darowizna-open]'));
    document.querySelectorAll('.ws-panel[data-id="darowizna"] a').forEach(a => {
      if (/p\u0142atno\u015b\u0107\s+online/i.test(a.textContent)) {
        a.removeAttribute('href');
        a.style.cursor = 'pointer';
        triggers.push(a);
      }
    });
    if (!triggers.length) return;

    if (!document.getElementById('darowizna-modal')) {
      const modal = document.createElement('div');
      modal.className = 'darowizna-modal';
      modal.id = 'darowizna-modal';
      modal.setAttribute('aria-hidden', 'true');
      document.body.appendChild(modal);
    }
    const modal = document.getElementById('darowizna-modal');

    function open(e) {
      if (e) e.preventDefault();
      var preCel = (e && e.currentTarget && e.currentTarget.getAttribute('data-cel')) || 'statutowe';
      if (!CELE[preCel]) preCel = 'statutowe';
      state = {
        kwota: 50, inna: '', waluta: 'PLN',
        typ: (preCel === 'adopcja' ? 'miesiecznie' : 'jednorazowo'),
        cel: preCel, dzieci: 1,
      };
      renderStep1();
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('drawer-open');
    }
    function close() {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('drawer-open');
    }
    window.__darowiznaClose = close;

    triggers.forEach(t => t.addEventListener('click', open));
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    document.addEventListener('keydown', e => {
      if (modal.classList.contains('is-open') && e.key === 'Escape') close();
    });

    // ─── KROK 1 ───
    function renderStep1() {
      const cur = state.waluta;
      const sym = cur === 'PLN' ? 'zł' : '€';
      const adopcja = isAdopcja();

      // Sekcja kwoty - inna dla adopcji (selektor liczby dzieci)
      const kwotaSekcja = adopcja ? `
          <div class="dar-field">
            <label class="dar-label">Liczba dzieci</label>
            <div class="dar-stepper">
              <button type="button" class="dar-step-btn" id="dar-minus" aria-label="Mniej dzieci">−</button>
              <div class="dar-step-val"><span id="dar-dzieci">${state.dzieci}</span></div>
              <button type="button" class="dar-step-btn" id="dar-plus" aria-label="Więcej dzieci">+</button>
              <div class="dar-step-calc">${state.dzieci} × ${STAWKA[cur]} ${sym} = <strong>${STAWKA[cur]*state.dzieci} ${sym}/mies.</strong></div>
            </div>
          </div>` : `
          <div class="dar-field">
            <label class="dar-label">Określ kwotę</label>
            <div class="dar-amounts">
              ${KWOTY.map(k => `<button type="button" class="dar-amt ${state.kwota===k&&!state.inna?'is-active':''}" data-amt="${k}">${k} ${sym}</button>`).join('')}
              <div class="dar-amt-other ${state.inna?'is-active':''}">
                <input type="number" min="1" step="1" inputmode="numeric" placeholder="Inna kwota" value="${state.inna||''}" id="dar-inna" />
                <span>${sym}</span>
              </div>
            </div>
          </div>`;

      // Sekcja typu - zablokowana dla adopcji
      const typSekcja = adopcja ? `
          <div class="dar-field">
            <label class="dar-label">Typ wpłaty</label>
            <div class="dar-seg is-locked" aria-disabled="true">
              <button type="button" class="is-active" disabled>Co miesiąc</button>
            </div>
          </div>` : `
          <div class="dar-field">
            <label class="dar-label">Typ wpłaty</label>
            <div class="dar-seg" data-group="typ">
              <button type="button" class="${state.typ==='jednorazowo'?'is-active':''}" data-val="jednorazowo">Jednorazowo</button>
              <button type="button" class="${state.typ==='miesiecznie'?'is-active':''}" data-val="miesiecznie">Co miesiąc</button>
            </div>
          </div>`;

      modal.innerHTML = `
        <div class="dar-box" role="dialog" aria-modal="true" aria-labelledby="dar-title">
          <button type="button" class="dar-close" aria-label="Zamknij" onclick="window.__darowiznaClose()">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M6 18L18 6"/></svg>
          </button>
          <div class="dar-steps"><span class="dar-step is-active">1. Darowizna</span><span class="dar-step">2. Dane i płatność</span></div>
          <span class="dar-eyebrow">Wesprzyj nas</span>
          <h2 id="dar-title">Przekaż darowiznę</h2>

          <div class="dar-field">
            <label class="dar-label">Waluta</label>
            <div class="dar-seg" data-group="waluta">
              <button type="button" class="${cur==='PLN'?'is-active':''}" data-val="PLN">PLN</button>
              <button type="button" class="${cur==='EUR'?'is-active':''}" data-val="EUR">EUR</button>
            </div>
          </div>

          ${kwotaSekcja}
          ${typSekcja}

          <div class="dar-field">
            <label class="dar-label">Wybierz cel</label>
            <div class="dar-cele">
              ${Object.entries(CELE).map(([k,v]) => `
                <label class="dar-cel ${state.cel===k?'is-active':''}">
                  <input type="radio" name="cel" value="${k}" ${state.cel===k?'checked':''} />
                  <span>${v}</span>
                </label>`).join('')}
            </div>
            <p class="dar-note" id="dar-adopcja-note" style="${adopcja?'':'display:none'}">
              Adopcja Serca to wsparcie długoterminowe - dostępne wyłącznie jako wpłata cykliczna (co miesiąc i minimum przez 1 rok). Jest to niezwykle istotne dla przewidywalności, stabilności i możliwości zaplanowania całego roku szkolnego.
            </p>
          </div>

          <div class="dar-actions">
            <button type="button" class="btn btn-gold" id="dar-next">Dalej → dane i płatność</button>
          </div>
          <p class="dar-secure">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
            Płatność obsługiwana bezpiecznie przez PayU - BLIK, karta, przelew online.
          </p>
        </div>`;
      wireStep1();
    }

    function wireStep1() {
      // waluta + typ segmenty (typ tylko gdy nie-adopcja)
      modal.querySelectorAll('.dar-seg[data-group]').forEach(seg => {
        const group = seg.dataset.group;
        seg.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
          state[group] = b.dataset.val;
          if (group === 'waluta') renderStep1();
          else { seg.querySelectorAll('button').forEach(x=>x.classList.remove('is-active')); b.classList.add('is-active'); }
        }));
      });

      // Selektor liczby dzieci (adopcja)
      const dziSpan = modal.querySelector('#dar-dzieci');
      const calc = modal.querySelector('.dar-step-calc');
      function refreshDzieci() {
        if (dziSpan) dziSpan.textContent = state.dzieci;
        if (calc) {
          const sym = state.waluta === 'PLN' ? 'zł' : '€';
          calc.innerHTML = `${state.dzieci} × ${STAWKA[state.waluta]} ${sym} = <strong>${STAWKA[state.waluta]*state.dzieci} ${sym}/mies.</strong>`;
        }
      }
      const minus = modal.querySelector('#dar-minus');
      const plus = modal.querySelector('#dar-plus');
      if (minus) minus.addEventListener('click', () => { if (state.dzieci > 1) { state.dzieci--; refreshDzieci(); } });
      if (plus) plus.addEventListener('click', () => { if (state.dzieci < 20) { state.dzieci++; refreshDzieci(); } });

      // kwoty (tylko nie-adopcja)
      modal.querySelectorAll('.dar-amt').forEach(b => b.addEventListener('click', () => {
        state.kwota = parseInt(b.dataset.amt, 10); state.inna = '';
        modal.querySelectorAll('.dar-amt').forEach(x=>x.classList.remove('is-active'));
        b.classList.add('is-active');
        modal.querySelector('.dar-amt-other').classList.remove('is-active');
        const innaInput = modal.querySelector('#dar-inna'); if (innaInput) innaInput.value = '';
      }));
      const inna = modal.querySelector('#dar-inna');
      if (inna) inna.addEventListener('input', () => {
        state.inna = inna.value;
        if (inna.value) {
          state.kwota = 0;
          modal.querySelectorAll('.dar-amt').forEach(x=>x.classList.remove('is-active'));
          modal.querySelector('.dar-amt-other').classList.add('is-active');
        }
      });

      // cele - zmiana celu przerenderowuje krok 1 (zmienia UI kwoty/typu)
      modal.querySelectorAll('input[name="cel"]').forEach(r => r.addEventListener('change', () => {
        const prev = state.cel;
        state.cel = r.value;
        if (state.cel === 'adopcja') { state.typ = 'miesiecznie'; state.dzieci = state.dzieci || 1; }
        // przerenderuj gdy przechodzimy DO/Z adopcji (zmienia się układ pól)
        if ((prev === 'adopcja') !== (state.cel === 'adopcja')) { renderStep1(); return; }
        modal.querySelectorAll('.dar-cel').forEach(c => c.classList.toggle('is-active', c.contains(r)));
      }));

      modal.querySelector('#dar-next').addEventListener('click', () => {
        const amt = kwotaAktualna();
        if (!amt || amt < 1) { alert('Podaj prawidłową kwotę darowizny.'); return; }
        renderStep2();
      });
    }

    // ─── KROK 2 ───
    function renderStep2() {
      const amt = kwotaAktualna();
      const curSym = state.waluta === 'PLN' ? 'zł' : '€';
      const adopcja = isAdopcja();
      const typLabel = (state.typ === 'miesiecznie' || adopcja) ? 'co miesiąc' : 'jednorazowo';
      const celLabel = adopcja
        ? `${CELE.adopcja} · ${state.dzieci} ${state.dzieci === 1 ? 'dziecko' : 'dzieci'}`
        : CELE[state.cel];
      const recurring = (state.typ === 'miesiecznie' || adopcja);

      // Metoda płatności: dla cyklicznych - Secure Form (pola karty na stronie); inaczej info o bramce.
      const payMethodBlock = recurring ? `
            <div class="dar-field">
              <label class="dar-label">Dane karty (płatność cykliczna)</label>
              <div id="dar-card" class="dar-card-form"></div>
              <p class="dar-card-loading" id="dar-card-loading">Ładowanie bezpiecznego formularza karty…</p>
              <p class="dar-note">Dane karty wpisujesz w bezpiecznym formularzu PayU (osadzonym tu w ramce). Zapisujemy wyłącznie token - nie mamy dostępu do numeru karty.</p>
            </div>` : `
            <div class="dar-field">
              <label class="dar-label">Metoda płatności</label>
              <div class="dar-pay-methods">
                <span class="dar-pay"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg> Karta</span>
                <span class="dar-pay">BLIK</span>
                <span class="dar-pay">Przelew online</span>
                <span class="dar-pay-logo">PayU</span>
              </div>
              <p class="dar-note">Konkretną metodę wybierzesz w bezpiecznym oknie PayU po kliknięciu „Przekaż".</p>
            </div>`;

      // Zgoda na cykliczność - WYMÓG PayU (niedomniemana, konkretna, z możliwością rezygnacji).
      const recurringConsent = recurring ? `
              <label class="dar-check"><input type="checkbox" name="zgoda_cykl" required /> <span>Wyrażam zgodę na cykliczne (comiesięczne) obciążanie mojej karty kwotą <strong>${amt} ${curSym}</strong> na rzecz Fundacji Misja MADA (${celLabel})${adopcja ? ', minimum przez 12 miesięcy' : ''}. Mogę zrezygnować w każdej chwili (link w mailu).</span></label>` : '';

      modal.innerHTML = `
        <div class="dar-box" role="dialog" aria-modal="true" aria-labelledby="dar-title2">
          <button type="button" class="dar-close" aria-label="Zamknij" onclick="window.__darowiznaClose()">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M6 18L18 6"/></svg>
          </button>
          <div class="dar-steps"><span class="dar-step is-done">1. Darowizna</span><span class="dar-step is-active">2. Dane i płatność</span></div>

          <div class="dar-summary">
            <div>
              <span class="dar-summary-amt">${amt} ${curSym}</span>
              <span class="dar-summary-typ">${typLabel}</span>
            </div>
            <div class="dar-summary-cel">${celLabel}</div>
            <button type="button" class="dar-edit" id="dar-back">← zmień</button>
          </div>

          <form id="dar-form" novalidate>
            <div class="dar-grid">
              <label class="dar-field"><span class="dar-label">Imię</span><input type="text" name="imie" autocomplete="given-name" required /></label>
              <label class="dar-field"><span class="dar-label">Nazwisko</span><input type="text" name="nazwisko" autocomplete="family-name" required /></label>
              <label class="dar-field dar-full"><span class="dar-label">Adres e-mail</span><input type="email" name="email" autocomplete="email" required /></label>
            </div>

            ${payMethodBlock}

            <div class="dar-consents">
              <label class="dar-check"><input type="checkbox" name="zgoda_regulamin" required /> <span>Akceptuję <a href="regulamin-serwisu.html" target="_blank" rel="noopener">Regulamin Serwisu</a> oraz <a href="polityka-prywatnosci.html" target="_blank" rel="noopener">Politykę prywatności</a>.</span></label>
              <label class="dar-check"><input type="checkbox" name="zgoda_dane" required /> <span>Wyrażam zgodę na przetwarzanie moich danych osobowych w celu realizacji darowizny.</span></label>
              ${recurringConsent}
            </div>

            <div class="dar-err" id="dar-err" style="display:none;" role="alert"></div>

            <div class="dar-actions">
              <button type="submit" class="btn btn-gold" id="dar-submit">Przekaż ${amt} ${curSym} →</button>
            </div>
            <p class="dar-secure">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
              Po kliknięciu zostaniesz przekierowany do bezpiecznej bramki PayU.
            </p>
          </form>
        </div>`;
      modal.querySelector('#dar-back').addEventListener('click', renderStep1);
      modal.querySelector('#dar-form').addEventListener('submit', submit);

      // Dla płatności cyklicznej: załaduj i osadź formularz karty (Secure Form).
      if (recurring) {
        const loadingEl = modal.querySelector('#dar-card-loading');
        loadSecureFormLib()
          .then(() => window.MadaSecureForm.mount('dar-card'))
          .then(() => { if (loadingEl) loadingEl.style.display = 'none'; })
          .catch(err => {
            if (loadingEl) loadingEl.style.display = 'none';
            showErr((err && err.message) ? err.message : 'Nie udało się załadować formularza karty. Odśwież stronę lub spróbuj później.');
          });
      }
    }

    async function submit(e) {
      e.preventDefault();
      const form = e.target;
      const err = modal.querySelector('#dar-err');
      err.style.display = 'none';
      const fd = new FormData(form);
      const amt = kwotaAktualna();

      if (!fd.get('imie') || !fd.get('nazwisko')) { return showErr('Podaj imię i nazwisko.'); }
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test((fd.get('email')||'').toString().trim())) { return showErr('Podaj prawidłowy adres e-mail.'); }
      if (!fd.get('zgoda_regulamin') || !fd.get('zgoda_dane')) { return showErr('Wymagana akceptacja regulaminu i zgody na dane.'); }

      const payload = {
        type: isAdopcja() ? 'adopcja-online' : 'darowizna',
        amount: amt,
        currency: state.waluta,
        recurring: isAdopcja() ? true : (state.typ === 'miesiecznie'),
        goal: state.cel,
        goalLabel: CELE[state.cel],
        dzieci: isAdopcja() ? state.dzieci : undefined,
        imie: fd.get('imie').toString().trim(),
        nazwisko: fd.get('nazwisko').toString().trim(),
        email: fd.get('email').toString().trim(),
      };

      if (state.waluta !== 'PLN') {
        return showErr('Płatność online dostępna na razie tylko w PLN. Wybierz PLN albo skorzystaj z przelewu tradycyjnego (konto EUR w stopce).');
      }
      // Płatność cykliczna - Secure Form (tokenizacja karty + recurring FIRST).
      if (payload.recurring) {
        if (!fd.get('zgoda_cykl')) { return showErr('Zaznacz zgodę na cykliczne obciążanie karty.'); }
        return submitRecurring(payload);
      }

      const URL = window.MADA_PAYU_URL || '';
      const submitBtn = modal.querySelector('#dar-submit');
      submitBtn.disabled = true; submitBtn.textContent = 'Łączę z PayU…';

      if (!URL) {
        showErr('Bramka płatności jest w trakcie konfiguracji. Prosimy o wpłatę tradycyjnym przelewem (dane w stopce) lub spróbuj ponownie wkrótce.');
        submitBtn.disabled = false; submitBtn.textContent = 'Przekaż →';
        return;
      }
      try {
        const res = await fetch(URL, {
          method: 'POST',
          headers: { 'Content-Type': 'text/plain;charset=utf-8' },
          body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (data && data.redirectUri) {
          window.location.href = data.redirectUri;
        } else {
          throw new Error(data && data.error ? data.error : 'Brak redirectUri');
        }
      } catch (e2) {
        showErr('Nie udało się połączyć z bramką płatności. Spróbuj ponownie lub napisz na kontakt@misjamada.pl.');
        submitBtn.disabled = false; submitBtn.textContent = 'Przekaż →';
      }
    }
    // Wysyłka płatności cyklicznej: tokenizacja karty (Secure Form) -> recurring-first.php.
    async function submitRecurring(payload) {
      const submitBtn = modal.querySelector('#dar-submit');
      const origText = submitBtn ? submitBtn.textContent : '';
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Przetwarzam kartę…'; }
      try {
        const token = await window.MadaSecureForm.tokenize();
        const body = Object.assign({}, payload, { token: token, consent: true });
        const url = window.MADA_RECURRING_URL || '/payu/recurring-first.php';
        const res = await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'text/plain;charset=utf-8' },
          body: JSON.stringify(body),
        });
        const data = await res.json();
        if (data && data.redirectUri) { window.location.href = data.redirectUri; return; }
        if (data && data.status === 'active') { window.location.href = 'dziekujemy.html'; return; }
        throw new Error((data && data.error) ? data.error : 'Nie udało się rozpocząć płatności cyklicznej.');
      } catch (e) {
        showErr((e && e.message) ? e.message : 'Nie udało się przetworzyć karty. Spróbuj ponownie.');
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = origText; }
      }
    }

    function showErr(msg) {
      const err = modal.querySelector('#dar-err');
      if (err) { err.textContent = msg; err.style.display = ''; }
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
