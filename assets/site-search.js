/* ═══════════════════════════════════════════════════════════════
   Fundacja Misja MADA - wyszukiwarka witryny + ujednolicony nav
   ───────────────────────────────────────────────────────────────
   • Klik na lupkę  → otwiera modal z polem szukania
   • Cmd/Ctrl + K   → ten sam skrót co lupka
   • Esc            → zamyka modal
   • Enter          → przechodzi do podświetlonego wyniku
   • Indeks zawiera ręcznie wybrane wpisy z każdej podstrony
   ═══════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  // ────────── Indeks (ręcznie wyselekcjonowane sekcje) ──────────
  const INDEX = [
    // STRONA GŁÓWNA
    { page: 'Strona główna',  url: 'strona-glowna.html',                       title: 'Fundacja Misja MADA',                  body: 'Miłość, Akceptacja bliźniego, Dobroczynność, Adoracja Boga. Służymy drugiemu człowiekowi przez pomoc misyjną, dzieła miłosierdzia i nadzieję płynącą z Ewangelii. Wspieramy Siostry Małe Misjonarki Miłosierdzia (Siostry Orionistki) posługujące dzieciom na Madagaskarze.' },
    { page: 'Strona główna',  url: 'strona-glowna.html#misja',                 title: 'Nasza misja na Madagaskarze',          body: 'Jesteśmy małżeństwem misjonarzy świeckich. Wspieramy Siostry Małe Misjonarki Miłosierdzia w ich codziennej posłudze najuboższym. 130+ dzieci w Adopcji Serca, 300+ posiłków wydawanych każdego dnia.' },
    { page: 'Strona główna',  url: 'strona-glowna.html#misja',                 title: 'Założyciele',                          body: 'Małżeństwo misjonarzy świeckich, których droga rozpoczęła się od Adopcji Serca dwójki dzieci z Madagaskaru. Łącząc codzienną pracę zawodową z misją, budują pomost solidarności między Polską a Czerwoną Wyspą.' },
    { page: 'Strona główna',  url: 'strona-glowna.html#co-robimy',             title: 'Adopcja Serca - 70 zł miesięcznie',    body: 'Tyle wystarcza, by zmienić życie jednego dziecka. Adopcja Serca to długoterminowe wsparcie konkretnego dziecka z Madagaskaru - pokrywa edukację, codzienny ciepły posiłek, mundurek szkolny i podstawową opiekę medyczną.' },
    { page: 'Strona główna',  url: 'strona-glowna.html#obszary',               title: 'Lista obszarów działań',               body: 'Działania misyjne w Polsce, Wsparcie dzieci, Edukacja i nauka, Walka z głodem - cztery filary codziennej pracy fundacji.' },
    { page: 'Strona główna',  url: 'strona-glowna.html#footer-konta',          title: 'Konta bankowe - PLN, EUR, GBP',        body: 'Numery kont PLN 70 1090 1056 0000 0001 5832 5871. Odbiorca: Fundacja Misja MADA, ul. Szosa Chełmińska 271A, 87-100 Toruń. Tytuł przelewu: Darowizna na cele statutowe.' },

    // CO ROBIMY
    { page: 'Co robimy?',     url: 'co-robimy.html#adopcja',                   title: 'Adopcja Serca',                        body: 'Długoterminowa pomoc dzieciom żyjącym w ubogich krajach misyjnych. Regularne wsparcie finansowe i duchowe - symboliczne objęcie wsparciem konkretnego dziecka z Madagaskaru. 130+ dzieci w programie. Co obejmuje miesięczne wsparcie: opłaty szkolne, wyprawka, wyżywienie, opieka i zdrowie, prezenty świąteczne.' },
    { page: 'Co robimy?',     url: 'co-robimy.html#atelier',                   title: 'Atelier Nadziei',                      body: 'Pracownia krawiecka przy Centrum Edukacyjnym w Itaosy, prowadzona przez Siostry Małe Misjonarki Miłosierdzia. Nauka praktycznego zawodu i wsparcie samodzielności ekonomicznej najuboższych rodzin. Powstające produkty trafiają na pobliskie targi.' },
    { page: 'Co robimy?',     url: 'co-robimy.html#centrum',                   title: 'Centrum Edukacyjne w Itaosy',            body: 'Centrum Edukacyjne św. Alojzego Orione w Itaosy - placówka edukacyjna prowadzona przez Siostry Małe Misjonarki Miłosierdzia (Siostry Orionistki) od 3 stycznia 1989 roku. Dla 275 dzieci z najuboższych rodzin Madagaskaru. Świetlica z francuskim systemem edukacji, codzienny ciepły posiłek.' },
    { page: 'Co robimy?',     url: 'co-robimy.html#wolontariat',               title: 'Wolontariat',                          body: 'Wolontariat lokalny w Polsce - kiermasze, prelekcje, akcje. Wolontariat misyjny na Madagaskar we współpracy z Fundacją Salvatti - półroczny kurs przygotowawczy: formacja duchowa, merytoryczna, praktyczna.' },

    // O NAS
    { page: 'O nas',          url: 'o-nas.html',                               title: 'O Fundacji Misja MADA',                body: 'Polska fundacja niosąca pomoc misyjną i nadzieję płynącą z Ewangelii. Wspieramy Siostry Małe Misjonarki Miłosierdzia (Siostry Orionistki) na Madagaskarze. KRS: 0001099359. NIP: 9562392375. REGON: 528347054. Siedziba: ul. Szosa Chełmińska 271A, 87-100 Toruń.' },
    { page: 'O nas',          url: 'o-nas.html#dokumenty',                     title: 'Statut, sprawozdania, dokumenty',      body: 'Statut Fundacji Misja MADA, sprawozdania roczne, polityka prywatności, RODO.' },
    { page: 'Polityka prywatności', url: 'polityka-prywatnosci.html',            title: 'Polityka prywatności',                 body: 'Zasady przetwarzania danych osobowych RODO. Administrator: Fundacja Misja MADA. Pliki cookies, prawa użytkowników: dostęp, sprostowanie, usunięcie, ograniczenie, przenoszenie, sprzeciw, cofnięcie zgody, skarga do Prezesa UODO. Newsletter, darowizny, formularze kontaktowe, wolontariusze.' },

    // WYDARZENIA
    { page: 'Wydarzenia',     url: 'wydarzenia.html',                          title: 'Wydarzenia i kiermasze',               body: 'Wydarzenia, kiermasze, relacje z misji. Bądź na bieżąco z życiem fundacji. Kiermasze wielkanocne i bożonarodzeniowe, prelekcje w szkołach, dziennik wyjazdu misyjnego do Itaosy.' },
  ];

  // ────────── Markup ──────────
  function buildOverlay() {
    if (document.getElementById('site-search-overlay')) return;
    const overlay = document.createElement('div');
    overlay.id = 'site-search-overlay';
    overlay.className = 'site-search-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Wyszukiwanie na stronie');
    overlay.innerHTML = `
      <div class="site-search-box">
        <div class="site-search-input-row">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7"/>
            <path d="M21 21l-4.3-4.3"/>
          </svg>
          <input type="search" class="site-search-input" placeholder="Szukaj na stronie…" autocomplete="off" autocorrect="off" spellcheck="false" />
        </div>
        <div class="site-search-results" id="site-search-results" role="region" aria-live="polite" aria-atomic="false" aria-label="Wyniki wyszukiwania">
          <div class="ss-empty">Wpisz frazę, np. „adopcja", „wolontariat", „70 zł", „Atelier".</div>
        </div>
        <div role="status" aria-live="polite" id="ss-announcer" class="sr-only"></div>
        <div class="site-search-footer">
          <span><kbd>↑</kbd><kbd>↓</kbd> nawigacja · <kbd>Enter</kbd> otwórz · <kbd>Esc</kbd> zamknij</span>
          <span>Misja MADA</span>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);
    return overlay;
  }

  // ────────── Logika ──────────
  function escapeHtml(s) { return s.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function escapeReg(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

  function snippet(text, q) {
    const idx = text.toLowerCase().indexOf(q.toLowerCase());
    if (idx === -1) return escapeHtml(text.slice(0, 140) + (text.length > 140 ? '…' : ''));
    const start = Math.max(0, idx - 35);
    const end = Math.min(text.length, idx + q.length + 90);
    const before = (start > 0 ? '…' : '') + text.slice(start, idx);
    const match = text.slice(idx, idx + q.length);
    const after = text.slice(idx + q.length, end) + (end < text.length ? '…' : '');
    return escapeHtml(before) + '<mark>' + escapeHtml(match) + '</mark>' + escapeHtml(after);
  }

  function search(q) {
    if (!q || q.trim().length < 2) return [];
    const needle = q.trim().toLowerCase();
    const tokens = needle.split(/\s+/).filter(Boolean);
    return INDEX
      .map(entry => {
        const hay = (entry.title + ' ' + entry.body + ' ' + entry.page).toLowerCase();
        let score = 0;
        tokens.forEach(t => {
          if (entry.title.toLowerCase().includes(t)) score += 4;
          if (entry.body.toLowerCase().includes(t)) score += 1;
          if (entry.page.toLowerCase().includes(t)) score += 1;
        });
        return { entry, score, hay };
      })
      .filter(r => r.score > 0)
      .sort((a, b) => b.score - a.score)
      .slice(0, 8)
      .map(r => r.entry);
  }

  function render(results, q) {
    const box = document.getElementById('site-search-results');
    const announcer = document.getElementById('ss-announcer');
    if (!q || q.trim().length < 2) {
      box.innerHTML = '<div class="ss-empty">Wpisz frazę, np. „adopcja", „wolontariat", „70 zł", „Atelier".</div>';
      if (announcer) announcer.textContent = '';
      return;
    }
    if (!results.length) {
      box.innerHTML = '<div class="ss-empty">Nic nie znaleziono dla „' + escapeHtml(q) + '".</div>';
      if (announcer) announcer.textContent = 'Brak wyników dla zapytania ' + q;
      return;
    }
    if (announcer) {
      announcer.textContent = 'Znaleziono ' + results.length + ' ' +
        (results.length === 1 ? 'wynik' : (results.length < 5 ? 'wyniki' : 'wyników')) +
        ' dla zapytania ' + q;
    }
    box.innerHTML = results.map((r, i) => `
      <a class="ss-item${i === 0 ? ' is-active' : ''}" href="${r.url}" data-idx="${i}">
        <div class="ss-page">${escapeHtml(r.page)}</div>
        <div class="ss-title">${snippet(r.title, q)}</div>
        <div class="ss-snippet">${snippet(r.body, q)}</div>
      </a>
    `).join('');
  }

  function activeIndex() {
    const items = document.querySelectorAll('.ss-item');
    for (let i = 0; i < items.length; i++) if (items[i].classList.contains('is-active')) return i;
    return -1;
  }
  function setActive(idx) {
    const items = document.querySelectorAll('.ss-item');
    if (!items.length) return;
    const clamped = (idx + items.length) % items.length;
    items.forEach((el, i) => el.classList.toggle('is-active', i === clamped));
    const active = items[clamped];
    if (active && active.scrollIntoViewIfNeeded) active.scrollIntoViewIfNeeded();
    else if (active) active.scrollIntoView({ block: 'nearest' });
  }

  function openSearch() {
    const overlay = buildOverlay();
    overlay.classList.add('is-open');
    document.documentElement.style.overflow = 'hidden';
    const input = overlay.querySelector('.site-search-input');
    setTimeout(() => input.focus(), 50);
  }
  function closeSearch() {
    const overlay = document.getElementById('site-search-overlay');
    if (!overlay) return;
    overlay.classList.remove('is-open');
    document.documentElement.style.overflow = '';
    const input = overlay.querySelector('.site-search-input');
    if (input) input.value = '';
    render([], '');
  }

  // ────────── Event wiring (po DOMContentLoaded) ──────────
  function init() {
    // bind otwarcie z każdego elementu .nav-search
    document.querySelectorAll('.nav-search').forEach(btn => {
      btn.addEventListener('click', e => {
        e.preventDefault();
        openSearch();
      });
    });

    // Cmd/Ctrl + K
    document.addEventListener('keydown', e => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        openSearch();
        return;
      }
      const overlay = document.getElementById('site-search-overlay');
      if (!overlay || !overlay.classList.contains('is-open')) return;
      if (e.key === 'Escape') { closeSearch(); return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive(activeIndex() + 1); return; }
      if (e.key === 'ArrowUp')   { e.preventDefault(); setActive(activeIndex() - 1); return; }
      if (e.key === 'Enter') {
        const active = document.querySelector('.ss-item.is-active');
        if (active) {
          e.preventDefault();
          const href = active.getAttribute('href');
          window.location.assign(href);
        }
      }
    });

    // Delegacja input + click na overlay
    document.addEventListener('input', e => {
      if (!e.target.classList || !e.target.classList.contains('site-search-input')) return;
      const q = e.target.value;
      render(search(q), q);
    });
    document.addEventListener('click', e => {
      const overlay = document.getElementById('site-search-overlay');
      if (!overlay || !overlay.classList.contains('is-open')) return;
      if (e.target === overlay) closeSearch();
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
