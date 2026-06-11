/* ═══════════════════════════════════════════════════════════════
   Mobile hamburger + drawer - wstrzykiwane do każdej strony
   ═══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  function init() {
    const nav = document.querySelector('nav.main-nav .nav-inner');
    if (!nav || nav.querySelector('.nav-burger')) return;

    // Pobierz dane z istniejącej nawigacji
    const links = nav.querySelector('.nav-links');
    if (!links) return;
    const cta = nav.querySelector('.nav-cta');
    const ctaHref = cta ? cta.getAttribute('href') : '#wesprzyj';
    const ctaText = cta ? cta.textContent.trim() : 'Wesprzyj nas';

    // Utwórz przycisk hamburger
    const burger = document.createElement('button');
    burger.type = 'button';
    burger.className = 'nav-burger';
    burger.setAttribute('aria-label', 'Otwórz menu');
    burger.setAttribute('aria-expanded', 'false');
    burger.setAttribute('aria-controls', 'nav-drawer');
    burger.innerHTML = `
      <svg class="icon-open" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <line x1="3" y1="7" x2="21" y2="7"/>
        <line x1="3" y1="13" x2="21" y2="13"/>
        <line x1="3" y1="19" x2="21" y2="19"/>
      </svg>
      <svg class="icon-close" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <line x1="6" y1="6" x2="18" y2="18"/>
        <line x1="6" y1="18" x2="18" y2="6"/>
      </svg>
    `;
    nav.appendChild(burger);

    // Sklonuj logo z górnej nawigacji (do nagłówka szuflady)
    const logoImg = nav.querySelector('.nav-logo img');
    const drawerLogoSrc = logoImg ? logoImg.getAttribute('src') : '';

    // Skopiuj linki z .nav-links (zachowując "active")
    const linksHtml = Array.from(links.querySelectorAll('a'))
      .map(a => {
        const cls = a.classList.contains('active') ? ' class="active"' : '';
        return `<li><a href="${a.getAttribute('href')}"${cls}>${a.textContent}</a></li>`;
      })
      .join('');

    // Drawer
    const drawer = document.createElement('div');
    drawer.className = 'nav-drawer';
    drawer.id = 'nav-drawer';
    drawer.setAttribute('aria-hidden', 'true');
    drawer.innerHTML = `
      <div class="nav-drawer-inner" role="dialog" aria-modal="true" aria-label="Menu mobilne">
        <div class="nav-drawer-head">
          <img src="${drawerLogoSrc}" alt="Fundacja Misja MADA" />
          <button type="button" class="nav-drawer-close" aria-label="Zamknij menu">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="6" y1="18" x2="18" y2="6"/></svg>
          </button>
        </div>
        <ul>${linksHtml}</ul>
        <div class="drawer-actions">
          <a href="${ctaHref}" class="btn btn-primary">${ctaText}</a>
          <div class="drawer-socials">
            <button type="button" class="drawer-search" aria-label="Szukaj na stronie">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
            </button>
            <a href="https://www.facebook.com/MisjaMADA" target="_blank" rel="noopener" aria-label="Facebook Fundacji">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14 13.5h2.5l1-4H14v-2c0-1.03 0-2 2-2h1.5V2.14c-.326-.043-1.557-.14-2.857-.14C11.928 2 10 3.657 10 6.7v2.8H7v4h3V22h4v-8.5z"/></svg>
            </a>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(drawer);

    // Wire up otwierania/zamykania
    function open() {
      drawer.classList.add('is-open');
      drawer.setAttribute('aria-hidden', 'false');
      burger.setAttribute('aria-expanded', 'true');
      burger.setAttribute('aria-label', 'Zamknij menu');
      document.body.classList.add('drawer-open');
      // focus pierwszy link
      const first = drawer.querySelector('a, button');
      if (first) setTimeout(() => first.focus(), 100);
    }
    function close() {
      drawer.classList.remove('is-open');
      drawer.setAttribute('aria-hidden', 'true');
      burger.setAttribute('aria-expanded', 'false');
      burger.setAttribute('aria-label', 'Otwórz menu');
      document.body.classList.remove('drawer-open');
      burger.focus();
    }

    burger.addEventListener('click', () => {
      if (drawer.classList.contains('is-open')) close();
      else open();
    });
    drawer.querySelector('.nav-drawer-close').addEventListener('click', close);
    drawer.addEventListener('click', e => { if (e.target === drawer) close(); });
    document.addEventListener('keydown', e => {
      if (drawer.classList.contains('is-open') && e.key === 'Escape') close();
    });
    // Klik w link zamyka szufladę
    drawer.querySelectorAll('ul a').forEach(a => a.addEventListener('click', () => setTimeout(close, 50)));

    // Wyszukiwarka w szufladzie - uruchom istniejący overlay
    const drawerSearch = drawer.querySelector('.drawer-search');
    if (drawerSearch) {
      drawerSearch.addEventListener('click', () => {
        close();
        setTimeout(() => {
          const navSearch = document.querySelector('.nav-search');
          if (navSearch) navSearch.click();
        }, 250);
      });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
