/* ═══════════════════════════════════════════════════════════════
   Fundacja Misja MADA - wyszukiwarka witryny + ujednolicony nav
   ───────────────────────────────────────────────────────────────
   • Klik na lupkę  → otwiera modal z polem szukania
   • Cmd/Ctrl + K   → ten sam skrót co lupka
   • Esc            → zamyka modal
   • Enter          → przechodzi do podświetlonego wyniku
   • Indeks zawiera ręcznie wybrane wpisy z każdej podstrony
   ───────────────────────────────────────────────────────────────
   JĘZYKI: każdy wpis ma warianty `en` i `fr` obok bazowego polskiego.
   Nie wystarczy tu zwykły słownik i18n (podmiana tekstu w DOM), bo:
     • wyszukiwarka DOPASOWUJE zapytanie do treści indeksu - gdyby indeks
       został polski, wpisanie „adoption" w wersji EN nie znalazłoby nic,
     • wyniki i tak przechodzą przez snippet(), który wstawia <mark> wokół
       trafienia i rozbija węzeł tekstowy - słownik by go nie dopasował.
   Dodając wpis, uzupełnij wszystkie trzy języki. Pilnuje tego bramka
   tests/i18n-coverage.js (czyta tę tablicę wprost).
   ═══════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  // ────────── Indeks (ręcznie wyselekcjonowane sekcje) ──────────
  const INDEX = [
    // STRONA GŁÓWNA
    {
      url: 'index.html',
      page: 'Strona główna', title: 'Fundacja Misja MADA',
      body: 'Miłość, Akceptacja bliźniego, Dobroczynność, Adoracja Boga. Służymy drugiemu człowiekowi przez pomoc misyjną, dzieła miłosierdzia i nadzieję płynącą z Ewangelii. Wspieramy Siostry Małe Misjonarki Miłosierdzia (Siostry Orionistki) posługujące dzieciom na Madagaskarze.',
      en: { page: 'Home', title: 'Misja MADA Foundation',
            body: 'Love, Acceptance of others, Charity, Adoration of God. We serve others through missionary aid, works of mercy and the hope that flows from the Gospel. We support the Little Missionary Sisters of Charity (Orionine Sisters) who serve children in Madagascar.' },
      fr: { page: 'Accueil', title: 'Fondation Misja MADA',
            body: "Amour, Acceptation du prochain, Charité, Adoration de Dieu. Nous servons notre prochain par l'aide missionnaire, les œuvres de miséricorde et l'espérance de l'Évangile. Nous soutenons les Petites Sœurs Missionnaires de la Charité (Sœurs Orionines) au service des enfants à Madagascar." }
    },
    {
      url: 'index.html#misja',
      page: 'Strona główna', title: 'Nasza misja na Madagaskarze',
      body: 'Jesteśmy małżeństwem misjonarzy świeckich. Wspieramy Siostry Małe Misjonarki Miłosierdzia w ich codziennej posłudze najuboższym. 130+ dzieci w Adopcji Serca, 300+ posiłków wydawanych każdego dnia.',
      en: { page: 'Home', title: 'Our mission in Madagascar',
            body: 'We are a married couple of lay missionaries. We support the Little Missionary Sisters of Charity in their daily service to the poorest. 130+ children in Heart Adoption, 300+ meals served every day.' },
      fr: { page: 'Accueil', title: 'Notre mission à Madagascar',
            body: "Nous sommes un couple de missionnaires laïcs. Nous soutenons les Petites Sœurs Missionnaires de la Charité dans leur service quotidien auprès des plus pauvres. Plus de 130 enfants en Adoption de Cœur, plus de 300 repas servis chaque jour." }
    },
    {
      url: 'index.html#misja',
      page: 'Strona główna', title: 'Założyciele',
      body: 'Małżeństwo misjonarzy świeckich, których droga rozpoczęła się od Adopcji Serca dwójki dzieci z Madagaskaru. Łącząc codzienną pracę zawodową z misją, budują pomost solidarności między Polską a Czerwoną Wyspą.',
      en: { page: 'Home', title: 'Founders',
            body: 'A married couple of lay missionaries whose path began with the Heart Adoption of two children from Madagascar. Combining their daily jobs with the mission, they build a bridge of solidarity between Poland and the Red Island.' },
      fr: { page: 'Accueil', title: 'Fondateurs',
            body: "Un couple de missionnaires laïcs dont le chemin a commencé par l'Adoption de Cœur de deux enfants de Madagascar. Conciliant leur travail quotidien et la mission, ils bâtissent un pont de solidarité entre la Pologne et l'Île Rouge." }
    },
    {
      url: 'index.html#co-robimy',
      page: 'Strona główna', title: 'Adopcja Serca - 70 zł miesięcznie',
      body: 'Tyle wystarcza, by zmienić życie jednego dziecka. Adopcja Serca to długoterminowe wsparcie konkretnego dziecka z Madagaskaru - pokrywa edukację, codzienny ciepły posiłek, mundurek szkolny i podstawową opiekę medyczną.',
      en: { page: 'Home', title: 'Heart Adoption - 70 PLN per month',
            body: 'That is enough to change the life of one child. Heart Adoption is long-term support for a specific child from Madagascar - it covers education, a warm meal every day, a school uniform and basic medical care.' },
      fr: { page: 'Accueil', title: 'Adoption de Cœur - 70 PLN par mois',
            body: "Cela suffit à changer la vie d'un enfant. L'Adoption de Cœur est un soutien à long terme d'un enfant précis de Madagascar - elle couvre la scolarité, un repas chaud quotidien, l'uniforme scolaire et les soins médicaux de base." }
    },
    {
      url: 'index.html#obszary',
      page: 'Strona główna', title: 'Lista obszarów działań',
      body: 'Działania misyjne w Polsce, Wsparcie dzieci, Edukacja i nauka, Walka z głodem - cztery filary codziennej pracy fundacji.',
      en: { page: 'Home', title: 'List of areas of activity',
            body: 'Missionary work in Poland, Support for children, Education and learning, Fighting hunger - the four pillars of the foundation\'s daily work.' },
      fr: { page: 'Accueil', title: "Liste des domaines d'action",
            body: "Action missionnaire en Pologne, Soutien aux enfants, Éducation et apprentissage, Lutte contre la faim - les quatre piliers du travail quotidien de la fondation." }
    },
    {
      url: 'index.html#footer-konta',
      page: 'Strona główna', title: 'Konta bankowe - PLN, EUR, GBP',
      body: 'Numery kont PLN 70 1090 1056 0000 0001 5832 5871. Odbiorca: Fundacja Misja MADA, ul. Szosa Chełmińska 271A, 87-100 Toruń. Tytuł przelewu: Darowizna na cele statutowe.',
      en: { page: 'Home', title: 'Bank accounts - PLN, EUR, GBP',
            body: 'Account numbers PLN 70 1090 1056 0000 0001 5832 5871. Beneficiary: Fundacja Misja MADA, ul. Szosa Chełmińska 271A, 87-100 Toruń. Payment title: Donation for statutory purposes.' },
      fr: { page: 'Accueil', title: 'Comptes bancaires - PLN, EUR, GBP',
            body: "Numéros de compte PLN 70 1090 1056 0000 0001 5832 5871. Bénéficiaire : Fundacja Misja MADA, ul. Szosa Chełmińska 271A, 87-100 Toruń. Libellé du virement : Don aux fins statutaires." }
    },

    // CO ROBIMY
    {
      url: 'co-robimy.html#adopcja',
      page: 'Co robimy?', title: 'Adopcja Serca',
      body: 'Długoterminowa pomoc dzieciom żyjącym w ubogich krajach misyjnych. Regularne wsparcie finansowe i duchowe - symboliczne objęcie wsparciem konkretnego dziecka z Madagaskaru. 130+ dzieci w programie. Co obejmuje miesięczne wsparcie: opłaty szkolne, wyprawka, wyżywienie, opieka i zdrowie, prezenty świąteczne.',
      en: { page: 'What we do', title: 'Heart Adoption',
            body: 'Long-term help for children living in poor mission countries. Regular financial and spiritual support - symbolically taking a specific child from Madagascar into your care. 130+ children in the programme. What the monthly support covers: school fees, school supplies, food, care and health, holiday gifts.' },
      fr: { page: 'Ce que nous faisons', title: 'Adoption de Cœur',
            body: "Aide à long terme aux enfants des pays de mission pauvres. Soutien financier et spirituel régulier - la prise en charge symbolique d'un enfant précis de Madagascar. Plus de 130 enfants dans le programme. Ce que couvre le soutien mensuel : frais de scolarité, fournitures scolaires, nourriture, soins et santé, cadeaux de fêtes." }
    },
    {
      url: 'co-robimy.html#atelier',
      page: 'Co robimy?', title: 'Atelier Nadziei',
      body: 'Pracownia krawiecka przy Centrum Edukacyjnym w Itaosy, prowadzona przez Siostry Małe Misjonarki Miłosierdzia. Nauka praktycznego zawodu i wsparcie samodzielności ekonomicznej najuboższych rodzin. Powstające produkty trafiają na pobliskie targi.',
      en: { page: 'What we do', title: 'Atelier of Hope',
            body: 'A sewing workshop at the Educational Centre in Itaosy, run by the Little Missionary Sisters of Charity. Learning a practical trade and supporting the economic independence of the poorest families. The products made there go to nearby markets.' },
      fr: { page: 'Ce que nous faisons', title: "Atelier de l'Espoir",
            body: "Un atelier de couture auprès du Centre d'Éducation à Itaosy, tenu par les Petites Sœurs Missionnaires de la Charité. Apprentissage d'un métier concret et soutien à l'autonomie économique des familles les plus pauvres. Les produits fabriqués sont vendus sur les marchés voisins." }
    },
    {
      url: 'co-robimy.html#centrum',
      page: 'Co robimy?', title: 'Centrum Edukacyjne w Itaosy',
      body: 'Centrum Edukacyjne św. Alojzego Orione w Itaosy - placówka edukacyjna prowadzona przez Siostry Małe Misjonarki Miłosierdzia (Siostry Orionistki) od 3 stycznia 1989 roku. Dla 275 dzieci z najuboższych rodzin Madagaskaru. Świetlica z francuskim systemem edukacji, codzienny ciepły posiłek.',
      en: { page: 'What we do', title: 'Educational Centre in Itaosy',
            body: 'St Aloysius Orione Educational Centre in Itaosy - a school run by the Little Missionary Sisters of Charity (Orionine Sisters) since 3 January 1989. For 275 children from the poorest families in Madagascar. A common room with the French education system and a warm meal every day.' },
      fr: { page: 'Ce que nous faisons', title: "Centre d'Éducation à Itaosy",
            body: "Le Centre d'Éducation Saint-Louis Orione à Itaosy - un établissement tenu par les Petites Sœurs Missionnaires de la Charité (Sœurs Orionines) depuis le 3 janvier 1989. Pour 275 enfants des familles les plus pauvres de Madagascar. Une garderie suivant le système éducatif français et un repas chaud quotidien." }
    },
    {
      url: 'co-robimy.html#wolontariat',
      page: 'Co robimy?', title: 'Wolontariat',
      body: 'Wolontariat lokalny w Polsce - kiermasze, prelekcje, akcje. Wolontariat misyjny na Madagaskar we współpracy z Fundacją Salvatti - półroczny kurs przygotowawczy: formacja duchowa, merytoryczna, praktyczna.',
      en: { page: 'What we do', title: 'Volunteering',
            body: 'Local volunteering in Poland - charity fairs, talks, campaigns. Mission volunteering in Madagascar together with the Salvatti Foundation - a six-month preparatory course: spiritual, subject-matter and practical formation.' },
      fr: { page: 'Ce que nous faisons', title: 'Bénévolat',
            body: "Bénévolat local en Pologne - ventes de charité, conférences, actions. Volontariat missionnaire à Madagascar en coopération avec la Fondation Salvatti - une formation préparatoire de six mois : spirituelle, théorique et pratique." }
    },

    // O NAS
    {
      url: 'o-nas.html',
      page: 'O nas', title: 'O Fundacji Misja MADA',
      body: 'Polska fundacja niosąca pomoc misyjną i nadzieję płynącą z Ewangelii. Wspieramy Siostry Małe Misjonarki Miłosierdzia (Siostry Orionistki) na Madagaskarze. KRS: 0001099359. NIP: 9562392375. REGON: 528347054. Siedziba: ul. Szosa Chełmińska 271A, 87-100 Toruń.',
      en: { page: 'About us', title: 'About the Misja MADA Foundation',
            body: 'A Polish foundation bringing missionary aid and the hope that flows from the Gospel. We support the Little Missionary Sisters of Charity (Orionine Sisters) in Madagascar. KRS: 0001099359. NIP: 9562392375. REGON: 528347054. Registered office: ul. Szosa Chełmińska 271A, 87-100 Toruń.' },
      fr: { page: 'À propos de nous', title: 'À propos de la Fondation Misja MADA',
            body: "Une fondation polonaise qui apporte l'aide missionnaire et l'espérance de l'Évangile. Nous soutenons les Petites Sœurs Missionnaires de la Charité (Sœurs Orionines) à Madagascar. KRS : 0001099359. NIP : 9562392375. REGON : 528347054. Siège : ul. Szosa Chełmińska 271A, 87-100 Toruń." }
    },
    {
      url: 'o-nas.html#dokumenty',
      page: 'O nas', title: 'Statut, sprawozdania, dokumenty',
      body: 'Statut Fundacji Misja MADA, sprawozdania roczne, polityka prywatności, RODO.',
      en: { page: 'About us', title: 'Statute, reports, documents',
            body: 'Statute of the Misja MADA Foundation, annual reports, privacy policy, GDPR.' },
      fr: { page: 'À propos de nous', title: 'Statuts, rapports, documents',
            body: "Statuts de la Fondation Misja MADA, rapports annuels, politique de confidentialité, RGPD." }
    },
    {
      url: 'polityka-prywatnosci.html',
      page: 'Polityka prywatności', title: 'Polityka prywatności',
      body: 'Zasady przetwarzania danych osobowych RODO. Administrator: Fundacja Misja MADA. Pliki cookies, prawa użytkowników: dostęp, sprostowanie, usunięcie, ograniczenie, przenoszenie, sprzeciw, cofnięcie zgody, skarga do Prezesa UODO. Newsletter, darowizny, formularze kontaktowe, wolontariusze.',
      en: { page: 'Privacy policy', title: 'Privacy policy',
            body: 'Rules for processing personal data under the GDPR. Controller: Misja MADA Foundation. Cookies, user rights: access, rectification, erasure, restriction, portability, objection, withdrawal of consent, complaint to the President of the Personal Data Protection Office. Newsletter, donations, contact forms, volunteers.' },
      fr: { page: 'Politique de confidentialité', title: 'Politique de confidentialité',
            body: "Règles de traitement des données personnelles selon le RGPD. Responsable du traitement : Fondation Misja MADA. Cookies, droits des utilisateurs : accès, rectification, effacement, limitation, portabilité, opposition, retrait du consentement, réclamation auprès du Président de l'Office de protection des données personnelles. Newsletter, dons, formulaires de contact, bénévoles." }
    },

    // WYDARZENIA
    {
      url: 'wydarzenia.html',
      page: 'Wydarzenia', title: 'Wydarzenia i kiermasze',
      body: 'Wydarzenia, kiermasze, relacje z misji. Bądź na bieżąco z życiem fundacji. Kiermasze wielkanocne i bożonarodzeniowe, prelekcje w szkołach, dziennik wyjazdu misyjnego do Itaosy.',
      en: { page: 'Events', title: 'Events and charity fairs',
            body: 'Events, charity fairs, reports from the mission. Keep up with the life of the foundation. Easter and Christmas fairs, talks in schools, a diary from the mission trip to Itaosy.' },
      fr: { page: 'Événements', title: 'Événements et ventes de charité',
            body: "Événements, ventes de charité, comptes rendus de la mission. Suivez la vie de la fondation. Ventes de Pâques et de Noël, conférences dans les écoles, journal du voyage missionnaire à Itaosy." }
    },
  ];

  // Zwraca wpis w bieżącym języku. Wywoływane przy każdym szukaniu, więc zmiana
  // języka przy otwartym oknie działa od razu. Brak wariantu = zostaje polski
  // (ten sam bezpieczny fallback co w słowniku i18n).
  function localize(entry) {
    const lang = (window.MadaI18n && window.MadaI18n.lang()) || 'pl';
    const t = entry[lang];
    if (!t) return entry;
    return {
      url: entry.url,
      page: t.page || entry.page,
      title: t.title || entry.title,
      body: t.body || entry.body
    };
  }

  // Cudzysłów wg języka - polskie „…" w zdaniu angielskim czy francuskim wygląda
  // jak niedokończone tłumaczenie (a takie właśnie usterki zgłaszali testerzy).
  function quote(s) {
    const lang = (window.MadaI18n && window.MadaI18n.lang()) || 'pl';
    if (lang === 'en') return '“' + s + '”';
    if (lang === 'fr') return '« ' + s + ' »';
    return '„' + s + '”';
  }

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
      .map(localize)
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
      // Zdanie sklejane z zapytaniem użytkownika - gotowy węzeł jest za każdym razem
      // inny, więc słownik by go nie dopasował. Tłumaczymy część stałą przez t().
      var tf = (window.MadaI18n && window.MadaI18n.t) || function (s) { return s; };
      box.innerHTML = '<div class="ss-empty">' + escapeHtml(tf('Nic nie znaleziono dla')) +
        ' ' + quote(escapeHtml(q)) + '.</div>';
      if (announcer) announcer.textContent = tf('Brak wyników dla zapytania') + ' ' + q;
      return;
    }
    if (announcer) {
      // Komunikat dla czytników ekranu. Sklejamy go ze zmiennej (liczba + fraza), więc
      // gotowy węzeł jest za każdym razem inny - podmiana w DOM po słowniku by go nie
      // złapała. Tłumaczymy więc części składowe przez MadaI18n.t() przed złożeniem.
      // Zostaje textContent (nie innerHTML): q pochodzi od użytkownika.
      var t = (window.MadaI18n && window.MadaI18n.t) || function (s) { return s; };
      announcer.textContent = t('Znaleziono') + ' ' + results.length + ' ' +
        t(results.length === 1 ? 'wynik' : (results.length < 5 ? 'wyniki' : 'wyników')) +
        ' ' + t('dla zapytania') + ' ' + q;
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
