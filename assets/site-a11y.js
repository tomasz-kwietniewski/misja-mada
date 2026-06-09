/* ═══════════════════════════════════════════════════════════════
   A11Y enhancements
   • aria-controls / aria-expanded na rozwijanych komponentach
   • focus trap w modalach (drawer, search, lightbox)
   ═══════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  function init() {
    // ── Wesprzyj-nas - powiąż ws-card z ws-panel przez ID + aria ──
    document.querySelectorAll('.ws-grid').forEach(grid => {
      const cards = grid.querySelectorAll('.ws-card');
      cards.forEach(card => {
        const target = card.dataset.target;
        if (!target) return;
        const panel = document.querySelector('.ws-panel[data-id="' + target + '"]');
        if (!panel) return;
        const panelId = 'ws-panel-' + target;
        panel.id = panelId;
        card.setAttribute('aria-controls', panelId);
        const cardId = 'ws-tab-' + target;
        card.id = cardId;
        panel.setAttribute('aria-labelledby', cardId);
      });
    });

    // ── upcoming-toggle: aria-expanded śledzi data-state ──
    const upcomingToggle = document.getElementById('upcoming-toggle');
    if (upcomingToggle) {
      upcomingToggle.setAttribute('role', 'button');
      const extras = document.querySelectorAll('.upcoming-extra');
      const grpId = 'upcoming-extras-group';
      // zawiń ekstrasy w grupę dla aria-controls? proste rozwiązanie: pierwszej nadać id
      if (extras.length && !extras[0].id) extras[0].id = grpId;
      upcomingToggle.setAttribute('aria-expanded', 'false');
      if (extras.length) upcomingToggle.setAttribute('aria-controls', grpId);
      // Zsynchronizuj aria-expanded po kliknięciu (po istniejącym handlerze)
      upcomingToggle.addEventListener('click', () => {
        // Wait a tick for data-state to flip in existing handler
        setTimeout(() => {
          upcomingToggle.setAttribute('aria-expanded',
            upcomingToggle.dataset.state === 'open' ? 'true' : 'false');
        }, 0);
      });
    }

    // ── Focus trap helper ──
    function trapFocus(container) {
      const focusable = container.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      );
      if (!focusable.length) return null;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      function handler(e) {
        if (e.key !== 'Tab') return;
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      }
      container.addEventListener('keydown', handler);
      return () => container.removeEventListener('keydown', handler);
    }

    // ── Obserwuj otwieranie/zamykanie modali i włączaj/wyłączaj trap ──
    function watchModal(selector, openClass) {
      const target = document.querySelector(selector);
      if (!target) return;
      let cleanup = null;
      const observer = new MutationObserver(() => {
        if (target.classList.contains(openClass)) {
          if (!cleanup) cleanup = trapFocus(target);
        } else if (cleanup) {
          cleanup();
          cleanup = null;
        }
      });
      observer.observe(target, { attributes: true, attributeFilter: ['class'] });
    }

    watchModal('#nav-drawer', 'is-open');
    watchModal('#site-search-overlay', 'is-open');
    watchModal('#mada-lightbox', 'is-open');
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
