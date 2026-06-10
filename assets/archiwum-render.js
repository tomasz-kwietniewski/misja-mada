/* ═══════════════════════════════════════════════════════════════
   Strona „Archiwum wydarzeń" - render z window.MADA_EVENTS.
   Buduje sekcje lat + kafle z danych, generuje chipy lat, a potem
   uruchamia filtry (rok + kategoria) i paginację. Wygląd 1:1.
   Ładować PO events.js.php, PRZED i18n.js.
   ═══════════════════════════════════════════════════════════════ */
(function () {
  var all = window.MADA_EVENTS || [];
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);});}
  var PL_M = ['stycznia','lutego','marca','kwietnia','maja','czerwca','lipca','sierpnia','września','października','listopada','grudnia'];
  function plDate(iso){ if(!iso) return ''; var d=new Date(iso+'T00:00:00'); return d.getDate()+' '+PL_M[d.getMonth()]+' '+d.getFullYear(); }
  function firstImage(ev){ var m=(ev.media||[]).filter(function(x){return (x.type||'image')==='image';}); return m[0]||null; }
  function plural(n){ return n===1 ? 'wydarzenie' : ((n%10>=2 && n%10<=4 && (n%100<10||n%100>=20)) ? 'wydarzenia' : 'wydarzeń'); }

  // tylko archiwum, malejąco po dacie
  var archive = all.filter(function (e) { return e.status === 'archiwum'; });
  archive.sort(function (a, b) { return (b.dateISO||'').localeCompare(a.dateISO||''); });

  // grupowanie po roku (malejąco)
  var byYear = {};
  archive.forEach(function (e) { (byYear[e.year] = byYear[e.year] || []).push(e); });
  var years = Object.keys(byYear).sort(function (a, b) { return b.localeCompare(a); });

  // ── Budowa sekcji lat + kafli ─────────────────────────────────
  var rootEl = document.getElementById('archive-root');
  if (rootEl) {
    rootEl.innerHTML = years.map(function (y) {
      var cards = byYear[y].map(function (e) {
        var img = firstImage(e);
        return '<a href="wydarzenie.html?id=' + esc(e.id) + '" class="archive-card" data-year="' + esc(e.year) + '" data-cat="' + esc(e.category) + '">' +
          '<div class="photo">' + (img ? '<img src="' + esc(img.src) + '" alt="' + esc(img.alt||'') + '" />' : '') + '<span class="year-tag">' + esc(e.year) + '</span></div>' +
          '<div class="body">' +
            '<div class="meta">' + esc(plDate(e.dateISO)) + ' <span class="dot"></span> ' + esc(e.categoryLabel||'') + '</div>' +
            '<h3>' + esc(e.title||'') + '</h3>' +
            '<p>' + esc(e.lead||'') + '</p>' +
            '<span class="read">Czytaj relację</span>' +
          '</div></a>';
      }).join('');
      return '<div class="year-section"><div class="year-head"><h2>' + esc(y) + '</h2>' +
        '<span class="count">' + byYear[y].length + ' ' + plural(byYear[y].length) + '</span></div>' +
        '<div class="archive-grid">' + cards + '</div></div>';
    }).join('');
  }

  // ── Chipy lat (dynamiczne) ────────────────────────────────────
  var yearGroup = document.getElementById('year-group');
  if (yearGroup) {
    var chips = '<span class="group-label">Rok</span>';
    chips += '<button class="chip active" data-year="all">Wszystkie</button>';
    years.forEach(function (y) { chips += '<button class="chip" data-year="' + esc(y) + '">' + esc(y) + '</button>'; });
    yearGroup.innerHTML = chips;
  }

  // licznik łączny
  var visibleCount = document.getElementById('visible-count');
  if (visibleCount) visibleCount.textContent = archive.length;

  // ── Filtry (rok + kategoria) + paginacja (auto-hide gdy 1 strona) ─
  var yearChips = document.querySelectorAll('.archive-toolbar [data-year]');
  var catChips  = document.querySelectorAll('.archive-toolbar [data-cat]');
  var items = Array.prototype.slice.call(document.querySelectorAll('.archive-card'));
  var yearSections = document.querySelectorAll('.year-section');
  var pager = document.getElementById('archive-pagination');
  var PAGE_SIZE = 9;
  var activeYear = 'all', activeCat = 'all', page = 1;

  function matched() {
    return items.filter(function (it) {
      return (activeYear === 'all' || it.dataset.year === activeYear) &&
             (activeCat  === 'all' || it.dataset.cat  === activeCat);
    });
  }
  function applyFilter() {
    var list = matched();
    var totalPages = Math.max(1, Math.ceil(list.length / PAGE_SIZE));
    if (page > totalPages) page = totalPages;
    var start = (page - 1) * PAGE_SIZE, end = start + PAGE_SIZE;
    items.forEach(function (it) { it.style.display = 'none'; });
    list.forEach(function (it, i) { it.style.display = (i >= start && i < end) ? '' : 'none'; });
    yearSections.forEach(function (sec) {
      var hasVisible = Array.prototype.slice.call(sec.querySelectorAll('.archive-card')).some(function (c) { return c.style.display !== 'none'; });
      sec.style.display = hasVisible ? '' : 'none';
    });
    if (visibleCount) visibleCount.textContent = list.length;
    renderPager(totalPages);
  }
  function renderPager(totalPages) {
    if (!pager) return;
    if (totalPages <= 1) { pager.setAttribute('hidden', ''); pager.innerHTML = ''; return; }
    pager.removeAttribute('hidden');
    var html = '<a href="#" class="page-btn" data-go="prev"' + (page === 1 ? ' disabled aria-disabled="true"' : '') + '>← Poprzednia</a>';
    for (var p = 1; p <= totalPages; p++) { html += '<a href="#" class="page-btn' + (p === page ? ' active' : '') + '" data-go="' + p + '">' + p + '</a>'; }
    html += '<a href="#" class="page-btn" data-go="next"' + (page === totalPages ? ' disabled aria-disabled="true"' : '') + '>Następna →</a>';
    pager.innerHTML = html;
    pager.querySelectorAll('.page-btn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        if (btn.hasAttribute('disabled')) return;
        var go = btn.dataset.go;
        if (go === 'prev') page = Math.max(1, page - 1);
        else if (go === 'next') page = Math.min(totalPages, page + 1);
        else page = parseInt(go, 10);
        applyFilter();
        var grid = document.querySelector('.archive-grid');
        if (grid) window.scrollTo({ top: grid.getBoundingClientRect().top + window.pageYOffset - 120, behavior: 'smooth' });
      });
    });
  }
  yearChips.forEach(function (c) { c.addEventListener('click', function () {
    yearChips.forEach(function (x) { x.classList.remove('active'); }); c.classList.add('active');
    activeYear = c.dataset.year; page = 1; applyFilter();
  }); });
  catChips.forEach(function (c) { c.addEventListener('click', function () {
    catChips.forEach(function (x) { x.classList.remove('active'); }); c.classList.add('active');
    activeCat = c.dataset.cat; page = 1; applyFilter();
  }); });

  applyFilter();
})();
