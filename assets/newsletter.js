/* ═══════════════════════════════════════════════════════════════
   Newsletter modal - zapisy do MailerLite
   ───────────────────────────────────────────────────────────────
   KONFIGURACJA:
   • Po założeniu konta na mailerlite.com i utworzeniu grupy
     (np. "Newsletter Misja MADA"), wygeneruj embed form HTML
     (Subscribers → Forms → Create form → Embedded form).
   • MailerLite da Ci ~80 linii HTML/JS. Wklej je w plik
     `assets/mailerlite-embed.html` (utworzymy go gdy będzie
     gotowy). Modal automatycznie wstawi treść do iframe.
   • Tymczasowo: modal pokazuje własny formularz, który wysyła
     POST do Google Apps Script (type=newsletter) → zapis
     do osobnego arkusza "Newsletter".
  ═══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  // Backend zapisu na newsletter (MailerLite) - endpoint na własnej domenie.
  window.MADA_NEWSLETTER_URL = 'https://misjamada.pl/newsletter/subscribe.php';

  function init() {
    const triggers = document.querySelectorAll('[data-newsletter-open]');
    if (!triggers.length) return;

    if (!document.getElementById('newsletter-modal')) {
      const modal = document.createElement('div');
      modal.className = 'newsletter-modal';
      modal.id = 'newsletter-modal';
      modal.setAttribute('aria-hidden', 'true');
      modal.innerHTML = renderHtml();
      document.body.appendChild(modal);
    }

    const modal = document.getElementById('newsletter-modal');
    const form = modal.querySelector('form');
    const closeBtn = modal.querySelector('.nm-close');
    const successPane = modal.querySelector('.nm-success');

    function open(e) {
      if (e) e.preventDefault();
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('drawer-open');
      form.style.display = '';
      successPane.style.display = 'none';
      form.reset();
      form.querySelectorAll('.field-error').forEach(e => e.remove());
      form.querySelectorAll('.invalid').forEach(el => el.classList.remove('invalid'));
      setTimeout(() => {
        const first = form.querySelector('input');
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

    form.addEventListener('submit', async e => {
      e.preventDefault();
      form.querySelectorAll('.field-error').forEach(el => el.remove());
      form.querySelectorAll('.invalid').forEach(el => el.classList.remove('invalid'));

      const imie = form.querySelector('input[name="imie"]');
      const email = form.querySelector('input[name="email"]');
      const rodo = form.querySelector('input[name="zgoda_rodo"]');

      let ok = true;
      if (!imie.value.trim() || imie.value.trim().length < 2) {
        showError(imie, 'Podaj imię.'); ok = false;
      }
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
        showError(email, 'Podaj prawidłowy adres e-mail.'); ok = false;
      }
      if (!rodo.checked) {
        showError(rodo, 'Wymagana zgoda na otrzymywanie newslettera.'); ok = false;
      }
      if (!ok) { form.querySelector('.invalid')?.focus(); return; }

      const submitBtn = form.querySelector('button[type="submit"]');
      submitBtn.disabled = true;
      submitBtn.textContent = 'Zapisuję…';

      const payload = {
        imie: imie.value.trim(),
        email: email.value.trim(),
        zgoda_rodo: !!rodo.checked,
      };
      const NEWSLETTER_URL = window.MADA_NEWSLETTER_URL || '';

      try {
        if (!NEWSLETTER_URL) throw new Error('no-endpoint');
        const res = await fetch(NEWSLETTER_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) {
          throw new Error(data && data.error ? data.error : 'Wystąpił błąd. Spróbuj ponownie.');
        }
        form.style.display = 'none';
        successPane.style.display = '';
      } catch (err) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Zapisz się →';
        showError(email, (err && err.message && err.message !== 'no-endpoint') ? err.message : 'Wystąpił błąd. Spróbuj ponownie.');
      }
    });
  }

  function showError(field, msg) {
    field.classList.add('invalid');
    const wrap = field.closest('label, .am-field, .am-check') || field.parentElement;
    const e = document.createElement('div');
    e.className = 'field-error';
    e.textContent = msg;
    wrap.appendChild(e);
  }

  function renderHtml() {
    return `
      <div class="nm-box" role="dialog" aria-modal="true" aria-labelledby="nm-title">
        <button type="button" class="nm-close" aria-label="Zamknij">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M6 18L18 6"/></svg>
        </button>
        <form novalidate>
          <div class="nm-head">
            <div class="nm-icon" aria-hidden="true">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                <polyline points="22 6 12 13 2 6"/>
              </svg>
            </div>
            <span class="am-eyebrow">Bądź na bieżąco</span>
            <h2 id="nm-title">Zapisz się na newsletter</h2>
            <p>Raz w miesiącu wysyłamy krótką relację z naszych działań w Polsce i&nbsp;na&nbsp;Madagaskarze. Bez spamu.</p>
          </div>

          <label class="am-field">
            <span class="am-label">Imię</span>
            <input type="text" name="imie" autocomplete="given-name" required />
          </label>
          <label class="am-field">
            <span class="am-label">Adres e-mail</span>
            <input type="email" name="email" autocomplete="email" required />
          </label>

          <div class="am-consents" style="margin-top: 18px;">
            <label class="am-check">
              <input type="checkbox" name="zgoda_rodo" />
              <span>Wyrażam zgodę na otrzymywanie newslettera od Fundacji Misja MADA zgodnie z <a href="polityka-prywatnosci.html" target="_blank" rel="noopener">Polityką prywatności</a>. Mogę wypisać się w&nbsp;każdej chwili.</span>
            </label>
          </div>

          <div class="am-actions" style="margin-top: 6px;">
            <button type="submit" class="btn btn-gold">Zapisz się →</button>
          </div>
        </form>

        <div class="nm-success" style="display:none;" role="status">
          <div class="nm-icon" aria-hidden="true" style="background: var(--gold); color: var(--brownDk);">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          </div>
          <h2>Dziękujemy!</h2>
          <p>Twój adres e-mail został zapisany. Wkrótce dostaniesz od&nbsp;nas pierwszą wiadomość.</p>
          <button type="button" class="btn btn-primary" onclick="document.getElementById('newsletter-modal').classList.remove('is-open'); document.body.classList.remove('drawer-open');">Zamknij</button>
        </div>
      </div>
    `;
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
