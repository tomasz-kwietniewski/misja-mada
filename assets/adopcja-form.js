/* ═══════════════════════════════════════════════════════════════
   Formularz "Zostań rodzicem adopcyjnym" - modal + walidacja + POST
   ═══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

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

    function open(e) {
      if (e) e.preventDefault();
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('drawer-open');
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
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('drawer-open');
    }

    triggers.forEach(t => t.addEventListener('click', open));
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
        if (v && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) {
          emailInput.classList.add('invalid');
          const span = document.createElement('div');
          span.className = 'field-error';
          span.textContent = 'Podaj prawidłowy adres e-mail (np. jan@przykład.pl).';
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
    metodaRadios.forEach(r => r.addEventListener('change', () => {
      const val = form.querySelector('input[name="metoda"]:checked').value;
      czestWrap.style.display = val === 'przelew' ? '' : 'none';
      submitBtnEl.textContent = val === 'payu' ? 'Przejdź do płatności PayU →' : 'Wyślij zgłoszenie →';
    }));

    // Submit handler
    form.addEventListener('submit', async e => {
      e.preventDefault();
      // clear prior errors
      form.querySelectorAll('.field-error').forEach(el => el.remove());
      form.querySelectorAll('.invalid').forEach(el => el.classList.remove('invalid'));

      const data = collectData(form);
      const errors = validate(data);
      if (errors.length) {
        showErrors(form, errors);
        const firstBad = form.querySelector('.invalid');
        if (firstBad) firstBad.focus();
        return;
      }

      const submitBtn = form.querySelector('button[type="submit"]');
      submitBtn.disabled = true;
      submitBtn.textContent = data.metoda === 'payu' ? 'Łączę z PayU…' : 'Wysyłam…';

      // ŚCIEŻKA A - automatyczna płatność cykliczna PayU
      if (data.metoda === 'payu') {
        const PAYU = window.MADA_PAYU_URL || '';
        const payload = {
          type: 'adopcja',
          recurring: true,
          amount: 70,
          currency: 'PLN',
          goal: 'adopcja',
          goalLabel: 'Adopcja Serca - 70 zł/mies.',
          imie: data.imie, nazwisko: data.nazwisko, email: data.email,
          telefon: data.telefon, adres: data.adres,
          forma: data.formaLabel, okres: data.okres,
        };
        if (!PAYU) {
          showErrors(form, [{ field: null, msg: 'Bramka płatności jest w trakcie konfiguracji. Wybierz na razie „Przelew tradycyjny” lub spróbuj ponownie wkrótce.' }]);
          submitBtn.disabled = false; submitBtn.textContent = 'Przejdź do płatności PayU →';
          return;
        }
        try {
          const res = await fetch(PAYU, { method: 'POST', headers: { 'Content-Type': 'text/plain;charset=utf-8' }, body: JSON.stringify(payload) });
          const json = await res.json();
          if (json && json.redirectUri) { window.location.href = json.redirectUri; return; }
          throw new Error('brak redirectUri');
        } catch (err2) {
          showErrors(form, [{ field: null, msg: 'Nie udało się połączyć z bramką PayU. Spróbuj ponownie lub wybierz przelew tradycyjny.' }]);
          submitBtn.disabled = false; submitBtn.textContent = 'Przejdź do płatności PayU →';
          return;
        }
      }

      // ŚCIEŻKA B - przelew tradycyjny (zgłoszenie do fundacji, double opt-in)
      try {
        if (SUBMIT_URL) {
          await fetch(SUBMIT_URL, {
            method: 'POST',
            mode: 'no-cors',
            headers: { 'Content-Type': 'text/plain;charset=utf-8' },
            body: JSON.stringify(data),
          });
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
        submitBtn.textContent = data.metoda === 'payu' ? 'Przejdź do płatności PayU →' : 'Wyślij zgłoszenie →';
      }
    });
  }

  function collectData(form) {
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
      forma: forma,
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
      ts: new Date().toISOString(),
    };
  }

  function validate(d) {
    const errs = [];
    if (!d.imie || d.imie.length < 2) errs.push({ field: 'imie', msg: 'Podaj imię.' });
    if (!d.nazwisko || d.nazwisko.length < 2) errs.push({ field: 'nazwisko', msg: 'Podaj nazwisko.' });
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.email)) errs.push({ field: 'email', msg: 'Podaj prawidłowy adres e-mail.' });
    if (!d.telefon || d.telefon.replace(/\D/g, '').length < 9) errs.push({ field: 'telefon', msg: 'Podaj numer telefonu.' });
    if (!d.adres) errs.push({ field: 'adres', msg: 'Podaj adres korespondencyjny.' });
    if (!d.forma) errs.push({ field: 'forma', msg: 'Wybierz formę adopcji.' });
    if (d.forma === 'czasowa' && (!d.okres || d.okres.trim() === '-')) errs.push({ field: 'od', msg: 'Wskaż okres trwania (od - do).' });
    if (!d.metoda) errs.push({ field: 'metoda', msg: 'Wybierz sposób przekazywania wsparcia.' });
    if (d.metoda === 'przelew' && !d.czestotliwosc) errs.push({ field: 'czestotliwosc', msg: 'Wybierz częstotliwość wpłat.' });
    if (!d.zgoda_regulamin) errs.push({ field: 'zgoda_regulamin', msg: 'Wymagana zgoda na regulamin.' });
    if (!d.zgoda_wizerunek) errs.push({ field: 'zgoda_wizerunek', msg: 'Wymagana zgoda na oświadczenie o wizerunku.' });
    if (!d.zgoda_rodo) errs.push({ field: 'zgoda_rodo', msg: 'Wymagana zgoda na przetwarzanie danych.' });
    return errs;
  }

  function showErrors(form, errors) {
    errors.forEach(err => {
      if (!err.field) {
        const top = form.querySelector('.am-toperror');
        if (top) { top.textContent = err.msg; top.style.display = ''; }
        return;
      }
      const el = form.querySelector('[name="' + err.field + '"]');
      if (!el) return;
      const wrap = el.closest('.am-field') || el.parentElement;
      el.classList.add('invalid');
      const span = document.createElement('div');
      span.className = 'field-error';
      span.textContent = err.msg;
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
                  <span class="am-method-badge">PayU · najwygodniej</span>
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
                  <span>Miesięcznie · 70&nbsp;zł</span>
                </label>
                <label class="am-radio">
                  <input type="radio" name="czestotliwosc" value="kwartalnie" />
                  <span>Kwartalnie · 210&nbsp;zł</span>
                </label>
                <label class="am-radio">
                  <input type="radio" name="czestotliwosc" value="rocznie" />
                  <span>Rocznie · 840&nbsp;zł</span>
                </label>
              </div>
            </div>
          </fieldset>

          <div class="am-consents">
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

          <div class="am-bank">
            <span class="am-bank-label">Dane do przelewu (zlecenie stałe)</span>
            <div class="am-bank-row"><span>Odbiorca</span><strong>Fundacja Misja MADA</strong></div>
            <div class="am-bank-row"><span>Konto&nbsp;PLN</span><strong>70 1090 1056 0000 0001 5832 5871</strong></div>
            <div class="am-bank-row"><span>Tytuł</span><strong>Adopcja Serca - [Imię i nazwisko]</strong></div>
            <p class="am-bank-note">Kwota: 70&nbsp;zł miesięcznie (lub 210&nbsp;zł kwartalnie / 840&nbsp;zł rocznie). Pełne dane wyślemy również mailem po potwierdzeniu.</p>
          </div>
          <p style="font-family: var(--font-head); font-style: italic; color: var(--brown); margin-top: 4px;">Super, że jesteś z nami i chcesz pomóc dzieciom na Madagaskarze ❤︎</p>
          <button type="button" class="btn btn-primary am-close-success">Zamknij</button>
        </div>
      </div>
    `;
  }

  // Wire success close (delegated after init)
  document.addEventListener('click', e => {
    if (e.target.matches('.am-close-success')) {
      const modal = document.getElementById('adopcja-modal');
      if (modal) {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('drawer-open');
      }
    }
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
