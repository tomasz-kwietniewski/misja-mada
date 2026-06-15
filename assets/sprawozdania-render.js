/* ═══════════════════════════════════════════════════════════════
   Sprawozdania - render z window.MADA_SPRAWOZDANIA (sprawozdania.js.php).
   Dwa konteksty (skrypt sam wykrywa, co jest na stronie):
     • Podstrona sprawozdania.html: listy lat w #spraw-finansowe / #spraw-merytoryczne.
     • O NAS: kafle [data-sprawozdania] - wpis „Najnowsze: <rok>".
   Ładować PO sprawozdania.js.php, PRZED i18n.js.
  ═══════════════════════════════════════════════════════════════ */
(function () {
  var data = window.MADA_SPRAWOZDANIA || { finansowe: [], merytoryczne: [] };
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);});}
  function sorted(list){ return (list||[]).slice().sort(function(a,b){return (b.year||0)-(a.year||0);}); }
  function latestYear(list){ var s=sorted(list); return s.length ? s[0].year : ''; }

  // ── Podstrona: listy lat z wyraźnymi przyciskami pobierania ───
  function renderList(slotId, list, typeLabel) {
    var slot = document.getElementById(slotId);
    if (!slot) return;
    var items = sorted(list);
    if (!items.length) {
      slot.innerHTML = '<p class="spraw-empty">Wkrótce pojawią się tutaj dokumenty do pobrania.</p>';
      return;
    }
    slot.innerHTML = items.map(function (it) {
      return '<div class="spraw-row">' +
        '<div class="spraw-row-info">' +
          '<span class="spraw-year">' + esc(it.year) + '</span>' +
          '<span class="spraw-title">' + esc(it.title || typeLabel) + '</span>' +
        '</div>' +
        '<a class="btn btn-primary spraw-dl" href="/' + esc(it.file) + '" target="_blank" rel="noopener">Pobierz PDF za rok <span class="spraw-dl-year">' + esc(it.year) + '</span></a>' +
      '</div>';
    }).join('');
  }
  renderList('spraw-finansowe', data.finansowe, 'Sprawozdanie finansowe');
  renderList('spraw-merytoryczne', data.merytoryczne, 'Sprawozdanie z działalności');

  // ── O NAS: kafle - najnowszy rok w .doc-meta ──────────────────
  var tiles = document.querySelectorAll('[data-sprawozdania]');
  tiles.forEach(function (tile) {
    var type = tile.getAttribute('data-sprawozdania');
    var y = latestYear(data[type]);
    var metaEl = tile.querySelector('.doc-meta');
    // Rok w osobnym <span>, żeby etykieta „Najnowsze:" była stabilnym kluczem i18n
    // (tłumaczona), a rok zostawał. Patrz technika z przyciskiem „Pobierz PDF za rok".
    if (metaEl && y) metaEl.innerHTML = 'Najnowsze: <span class="doc-meta-year">' + y + '</span>';
  });
})();
