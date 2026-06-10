/* ═══════════════════════════════════════════════════════════════
   Widok pojedynczego wydarzenia - render z window.MADA_EVENTS
   (dane z events.js.php). Galeria: zdjęcia + filmy YouTube/Facebook
   w lightboxie. Ładować PO events.js.php, PRZED i18n.js.
   ═══════════════════════════════════════════════════════════════ */
(function () {
  var params = new URLSearchParams(location.search);
  var id = params.get('id');
  var root = document.getElementById('ev-root');
  if (!root) return;
  var ev = (window.MADA_EVENTS || []).find(function (e) { return e.id === id; });

  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);});}

  if (!ev) {
    root.innerHTML = '<div class="ev-notfound">' +
      '<h1>Nie znaleziono wydarzenia</h1>' +
      '<p>Wydarzenie mogło zostać usunięte lub link jest nieprawidłowy.</p>' +
      '<a href="wydarzenia.html" class="btn btn-primary">Wróć do wydarzeń</a></div>';
    document.title = 'Wydarzenie - Fundacja Misja MADA';
    return;
  }

  document.title = String(ev.title || '').replace(/[„""]/g, '') + ' - Fundacja Misja MADA';

  var media  = ev.media || [];
  var images = media.filter(function (m) { return (m.type || 'image') === 'image'; });
  var videos = media.filter(function (m) { return m.type === 'youtube' || m.type === 'facebook'; });
  var isUpcoming = ev.status === 'nadchodzace';

  var pin = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
  var cal = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>';
  var clock = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
  var play = '<svg class="play-ic" width="46" height="46" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="11" fill="rgba(20,12,6,.62)"/><path d="M10 8l6 4-6 4z" fill="#fff"/></svg>';

  var html = '';
  html += '<a href="wydarzenia.html" class="ev-back">← Wróć do wydarzeń</a>';
  html += '<div class="ev-cat">' + esc(ev.categoryLabel || '') + '</div>';
  html += '<h1 class="ev-title">' + esc(ev.title) + '</h1>';
  html += '<div class="ev-meta">';
  html += '<div class="ev-meta-row">' + cal + '<span><b>Kiedy?</b> ' + esc(ev.dateLabel) + '</span></div>';
  html += '<div class="ev-meta-row">' + pin + '<span><b>Gdzie?</b> ' + esc(ev.place) + '</span></div>';
  if (ev.masze) html += '<div class="ev-meta-row">' + clock + '<span>' + esc(ev.masze) + '</span></div>';
  html += '</div>';

  if (images[0]) {
    html += '<div class="ev-hero-img"><img src="' + esc(images[0].src) + '" alt="' + esc(images[0].alt || '') + '" /></div>';
  }

  html += '<div class="ev-body">';
  (ev.body || []).forEach(function (p) { html += '<p>' + esc(p) + '</p>'; });
  html += '</div>';

  if (ev.summary) {
    html += '<div class="ev-summary"><div class="ev-summary-val">' + esc(ev.summary.value) + '</div><div class="ev-summary-label">' + esc(ev.summary.label) + '</div></div>';
  }

  // Galeria: zdjęcia (dla archiwum) + filmy (zawsze, jeśli są)
  var galleryImages = isUpcoming ? [] : images;
  var galItems = [];
  galleryImages.forEach(function (im) { galItems.push({ kind: 'image', src: im.src, alt: im.alt || '', caption: im.caption || '' }); });
  videos.forEach(function (v) {
    if (v.type === 'youtube') {
      galItems.push({ kind: 'youtube', embed: 'https://www.youtube-nocookie.com/embed/' + esc(v.videoId), thumb: 'https://img.youtube.com/vi/' + esc(v.videoId) + '/hqdefault.jpg', alt: v.alt || 'Film na YouTube', caption: v.caption || '' });
    } else {
      galItems.push({ kind: 'facebook', embed: 'https://www.facebook.com/plugins/video.php?show_text=false&href=' + encodeURIComponent(v.url || ''), thumb: '', alt: v.alt || 'Film na Facebooku', caption: v.caption || '' });
    }
  });

  if (galItems.length) {
    html += '<div class="ev-gallery"><h2>Galeria</h2><div class="ev-gallery-grid">';
    galItems.forEach(function (it, i) {
      if (it.kind === 'image') {
        html += '<figure data-i="' + i + '" data-kind="image"><img src="' + esc(it.src) + '" alt="' + esc(it.alt) + '" />';
      } else {
        var bg = it.thumb ? ' style="background-image:url(\'' + esc(it.thumb) + '\')"' : '';
        html += '<figure data-i="' + i + '" data-kind="video" class="is-video"' + bg + '><span class="vid-thumb">' + play + '</span>';
      }
      if (it.caption) html += '<figcaption>' + esc(it.caption) + '</figcaption>';
      html += '</figure>';
    });
    html += '</div></div>';
  }

  html += '<div class="ev-cta-band">' +
    '<p>' + (isUpcoming ? 'Chcesz wziąć udział lub dowiedzieć się więcej?' : 'Chcesz wspierać nasze działania na Madagaskarze?') + '</p>' +
    '<a href="index.html#wesprzyj" class="btn btn-gold">Wesprzyj nas</a>' +
    '<a href="wydarzenia.html" class="btn btn-outline">Wszystkie wydarzenia</a></div>';

  root.innerHTML = html;

  // ── Lightbox (zdjęcia + filmy) ──────────────────────────────────
  var lb = document.getElementById('ev-lightbox');
  var lbImg = document.getElementById('ev-lightbox-img');
  if (!lb || !lbImg) return;
  var lbFrameWrap = document.getElementById('ev-lightbox-video');
  var figs = Array.prototype.slice.call(root.querySelectorAll('.ev-gallery figure'));
  var cur = 0;

  function show(i) {
    cur = (i + galItems.length) % galItems.length;
    var it = galItems[cur];
    if (it.kind === 'image') {
      if (lbFrameWrap) { lbFrameWrap.innerHTML = ''; lbFrameWrap.style.display = 'none'; }
      lbImg.style.display = '';
      lbImg.src = it.src; lbImg.alt = it.alt;
    } else {
      lbImg.style.display = 'none';
      if (lbFrameWrap) {
        lbFrameWrap.style.display = '';
        lbFrameWrap.innerHTML = '<iframe src="' + it.embed + '" title="' + esc(it.alt) + '" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen loading="lazy"></iframe>';
      }
    }
  }
  function open(i) { show(i); lb.classList.add('is-open'); lb.setAttribute('aria-hidden', 'false'); document.documentElement.style.overflow = 'hidden'; }
  function close() {
    lb.classList.remove('is-open'); lb.setAttribute('aria-hidden', 'true'); document.documentElement.style.overflow = '';
    if (lbFrameWrap) lbFrameWrap.innerHTML = '';  // zatrzymaj film
    lbImg.src = '';
  }
  figs.forEach(function (f) { f.addEventListener('click', function () { open(parseInt(f.getAttribute('data-i'), 10)); }); });
  lb.querySelector('.lb-close').addEventListener('click', close);
  lb.querySelector('.lb-arrow.prev').addEventListener('click', function (e) { e.stopPropagation(); show(cur - 1); });
  lb.querySelector('.lb-arrow.next').addEventListener('click', function (e) { e.stopPropagation(); show(cur + 1); });
  lb.addEventListener('click', function (e) { if (e.target === lb) close(); });
  document.addEventListener('keydown', function (e) {
    if (!lb.classList.contains('is-open')) return;
    if (e.key === 'Escape') close();
    else if (e.key === 'ArrowLeft') show(cur - 1);
    else if (e.key === 'ArrowRight') show(cur + 1);
  });
})();
