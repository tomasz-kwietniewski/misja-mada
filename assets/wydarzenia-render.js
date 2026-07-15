/* ═══════════════════════════════════════════════════════════════
   Strona „Wydarzenia" - render z window.MADA_EVENTS (events.js.php).
   Sekcje: wyróżnione (duży kafel) + nadchodzące (mniejsze) + teaser
   archiwum (3 najnowsze). Ładować PO events.js.php, PRZED i18n.js.
   Wygląd 1:1 z dotychczasowym (te same klasy CSS).
   ═══════════════════════════════════════════════════════════════ */
(function () {
  var all = window.MADA_EVENTS || [];
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);});}

  var PL_M_FULL = ['stycznia','lutego','marca','kwietnia','maja','czerwca','lipca','sierpnia','września','października','listopada','grudnia'];
  var PL_M_ABBR = ['STY','LUT','MAR','KWI','MAJ','CZE','LIP','SIE','WRZ','PAŹ','LIS','GRU'];
  function isoDate(iso){ return iso ? new Date(iso + 'T00:00:00') : null; }
  // Nazwa miesiąca w osobnym elemencie - i18n podmienia ją ze słownika (12 kluczy),
  // zamiast wymagać osobnego klucza na każdą datę. Kolejność „dzień miesiąc rok"
  // jest wspólna dla PL/EN/FR. Zwraca HTML - dane tylko z Date i naszej tablicy.
  function plDateHTML(iso){
    var d=isoDate(iso);
    return d ? (d.getDate()+' <span class="i18n-month">'+PL_M_FULL[d.getMonth()]+'</span> '+d.getFullYear()) : '';
  }
  function firstImage(ev){ var m=(ev.media||[]).filter(function(x){return (x.type||'image')==='image';}); return m[0]||null; }

  var icoCal = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>';
  var icoClock = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
  var icoPin = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
  var icoClock14 = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
  var icoPin14 = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';

  // ── Podział na nadchodzące / archiwum ─────────────────────────
  var upcoming = all.filter(function (e) { return e.status === 'nadchodzace'; });
  var archive  = all.filter(function (e) { return e.status === 'archiwum'; });
  upcoming.sort(function (a, b) { return (a.dateISO||'').localeCompare(b.dateISO||''); });
  archive.sort(function (a, b) { return (b.dateISO||'').localeCompare(a.dateISO||''); });

  // ── Wyróżnione: ręczne (featured) albo fallback na najbliższe ──
  var featured = null;
  for (var i = 0; i < upcoming.length; i++) { if (upcoming[i].featured) { featured = upcoming[i]; break; } }
  if (!featured && upcoming.length) featured = upcoming[0];

  // ── FEATURED (duży kafel) ──────────────────────────────────────
  var featSection = document.getElementById('featured-section');
  var featSlot = document.getElementById('featured-slot');
  if (featSlot) {
    if (featured) {
      var img = firstImage(featured);
      var h = '<article class="featured">';
      h += '<div class="photo">' + (img ? '<img src="' + esc(img.src) + '" alt="' + esc(img.alt||'') + '" />' : '') + '</div>';
      h += '<div class="text">';
      h += '<span class="badge">★ Nadchodzące - wyróżnione</span>';
      h += '<h2><a href="wydarzenie.html?id=' + esc(featured.id) + '">' + esc(featured.title) + '</a></h2>';
      h += '<div class="feat-info">';
      h += '<div class="feat-info-row">' + icoCal + '<span><b>Kiedy?</b> ' + esc(featured.dateLabel) + '</span></div>';
      if (featured.masze) h += '<div class="feat-info-row">' + icoClock + '<span>' + esc(featured.masze) + '</span></div>';
      h += '<div class="feat-info-row">' + icoPin + '<span><b>Gdzie?</b> ' + esc(featured.place) + '</span></div>';
      h += '</div>';
      if (featured.lead) h += '<p>' + esc(featured.lead) + '</p>';
      h += '<a href="wydarzenie.html?id=' + esc(featured.id) + '" class="more">Czytaj więcej →</a>';
      h += '</div></article>';
      featSlot.innerHTML = h;
      if (featSection) featSection.removeAttribute('hidden');
    } else if (featSection) {
      featSection.setAttribute('hidden', '');
    }
  }

  // ── NADCHODZĄCE (mniejsze kafle, bez wyróżnionego) ────────────
  var rest = upcoming.filter(function (e) { return !featured || e.id !== featured.id; });
  var section = document.getElementById('upcoming-section');
  var grid = document.getElementById('upcoming-grid');
  if (section && grid) {
    if (!rest.length) {
      section.setAttribute('hidden', '');
    } else {
      var INITIAL = 3;
      function upCard(e, extra) {
        var d = isoDate(e.dateISO);
        var day = d ? String(d.getDate()).padStart(2, '0') : '';
        // skrot miesiaca w osobnym elemencie (tlumaczony ze slownika), rok obok jako liczba.
        // Twarda spacja zostaje - trzyma "PAZ 2026" w jednej linii.
        var my = d ? ('<span class="i18n-month">' + PL_M_ABBR[d.getMonth()] + '</span> ' + d.getFullYear()) : '';
        return '<a class="upcoming-card' + (extra ? ' upcoming-extra' : '') + '" href="wydarzenie.html?id=' + esc(e.id) + '"' + (extra ? ' hidden' : '') + '>' +
          '<div class="upcoming-date"><span class="day">' + day + '</span><span class="my">' + my + '</span></div>' +
          '<div class="upcoming-time">' + icoClock14 + (e.dateLabel ? esc(e.dateLabel) : '') + '</div>' +
          '<h3>' + esc(e.title || '') + '</h3>' +
          '<div class="where">' + icoPin14 + esc(e.place || '') + '</div>' +
        '</a>';
      }
      grid.innerHTML = rest.map(function (e, i) { return upCard(e, i >= INITIAL); }).join('');
      section.removeAttribute('hidden');

      var extras = grid.querySelectorAll('.upcoming-extra');
      var toggles = document.querySelectorAll('.upcoming-toggle');
      if (extras.length) {
        toggles.forEach(function (t) { t.removeAttribute('hidden'); if (t.parentElement.classList.contains('upcoming-toggle-mobile-wrap')) t.parentElement.removeAttribute('hidden'); });
        var open = false;
        toggles.forEach(function (t) {
          t.addEventListener('click', function (ev) {
            ev.preventDefault();
            open = !open;
            extras.forEach(function (c) { if (open) c.removeAttribute('hidden'); else c.setAttribute('hidden', ''); });
            toggles.forEach(function (x) { x.textContent = open ? 'Pokaż mniej ←' : 'Zobacz kolejne →'; });
          });
        });
      }
    }
  }

  // ── ARCHIWUM - teaser (3 najnowsze) ───────────────────────────
  var teaser = document.getElementById('archive-teaser');
  if (teaser) {
    var items = archive.slice(0, 3);
    if (!items.length) {
      teaser.innerHTML = '<p style="color:var(--brown);opacity:.6;">Wkrótce pojawią się tutaj relacje z naszych wydarzeń.</p>';
    } else {
      teaser.innerHTML = items.map(function (e) {
        var img = firstImage(e);
        return '<a href="wydarzenie.html?id=' + esc(e.id) + '" class="event-card">' +
          '<div class="photo">' + (img ? '<img src="' + esc(img.src) + '" alt="' + esc(img.alt||'') + '" />' : '') + '</div>' +
          '<div class="body">' +
            '<div class="meta">' + plDateHTML(e.dateISO) + ' · ' + esc(e.categoryLabel || '') + '</div>' +
            '<h3>' + esc(e.title || '') + '</h3>' +
            '<p>' + esc(e.lead || '') + '</p>' +
            '<span class="read">Czytaj więcej →</span>' +
          '</div></a>';
      }).join('');
    }
  }
})();
