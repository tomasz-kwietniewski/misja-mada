/* ═══════════════════════════════════════════════════════════════
   DANE WYDARZEŃ - Fundacja Misja MADA
   ───────────────────────────────────────────────────────────────
   Pracownicy fundacji dodają nowe wydarzenia tutaj. Każdy wpis:
     id        - unikalny identyfikator (slug, bez spacji/polskich znaków)
     status    - 'nadchodzace' | 'archiwum'
     featured  - true dla wyróżnionego (max 1 nadchodzące)
     title     - tytuł wydarzenia
     dateLabel - data do wyświetlenia (np. "26 lipca 2026")
     dateISO   - data sortowania "YYYY-MM-DD"
     year      - rok (do filtrów archiwum)
     category  - 'misja' | 'kiermasz' | 'szkola' | 'fundacja'
     categoryLabel - etykieta kategorii
     place     - miejsce
     masze     - (opcjonalnie) godziny Mszy Świętych
     lead      - krótki opis (na kafelek)
     body      - tablica akapitów (pełny opis)
     photos    - tablica { src, alt, caption }
     summary   - (opcjonalnie) podsumowanie akcji (kwota itp.)
  ═══════════════════════════════════════════════════════════════ */
window.MADA_EVENTS = [
  {
    id: 'lezajsk-2026',
    status: 'nadchodzace',
    featured: true,
    title: '„Razem dla Misji" - spotkanie misyjne z Fundacją Misja MADA w Leżajsku',
    dateLabel: 'Niedziela, 26 lipca 2026',
    dateISO: '2026-07-26',
    year: '2026',
    category: 'misja',
    categoryLabel: 'Spotkanie misyjne',
    place: 'Parafia Św. Trójcy w Leżajsku, Rynek 35, Leżajsk',
    masze: 'Msze Święte o godz.: 7:00, 8:30, 10:00, 11:30, 16:00, 18:00',
    lead: 'Czas pełen świadectwa, inspirujących historii oraz spotkania z misjami prowadzonymi na Madagaskarze. Po Mszach Świętych - misyjny kiermasz.',
    body: [
      'Zapraszamy na kolejne spotkanie misyjne z Fundacją Misja MADA, które odbędzie się w Parafii Św. Trójcy w Leżajsku.',
      'To będzie czas pełen świadectwa, inspirujących historii oraz spotkania z misjami prowadzonymi na Madagaskarze. Podczas Mszy Świętych podzielimy się naszym doświadczeniem pracy misyjnej, opowiemy o codziennym życiu mieszkańców Madagaskaru oraz o tym, jak realna pomoc zmienia życie najbardziej potrzebujących dzieci oraz ich rodzin.',
      'W trakcie wydarzenia przybliżymy również ideę programu Adopcja Serca - wyjątkowej inicjatywy, dzięki której można objąć wsparciem konkretne dziecko, pomagając mu w edukacji, dostępie do posiłków oraz lepszej przyszłości.',
      'Po Mszach Świętych zapraszamy także na misyjny kiermasz, podczas którego będzie można wesprzeć działania Fundacji i poznać bliżej naszą działalność. Przygotowaliśmy dla Was produkty i rękodzieło prosto z Madagaskaru, a przede wszystkim - przestrzeń do spotkania i wspólnego działania na rzecz potrzebujących.',
      'Wierzymy, że razem możemy stać się częścią wielkiej misji! Serdecznie zapraszamy!',
    ],
    photos: [
      { src: 'media/lezajsk-2026.jpg', alt: 'Misyjny kiermasz - rękodzieło z Madagaskaru' },
    ],
  },

  {
    id: 'wieczory-sw-antoniego-2026',
    status: 'archiwum',
    title: '„Wieczory u św. Antoniego" - misyjne świadectwo z Madagaskaru',
    dateLabel: 'Wtorek, 19 maja 2026, godz. 18:45',
    dateISO: '2026-05-19',
    year: '2026',
    category: 'misja',
    categoryLabel: 'Świadectwo misyjne',
    place: 'Parafia Św. Antoniego w Toruniu, ul. Św. Antoniego 4, Toruń',
    lead: 'Gościem wieczoru był Radosław Grodzki, Prezes Fundacji Misja MADA, ze świadectwem „Eucharystia na Madagaskarze".',
    body: [
      'We wtorkowy wieczór w Parafii św. Antoniego w Toruniu odbyło się kolejne spotkanie z cyklu „Wieczory u św. Antoniego".',
      'Gościem wieczoru był Radosław Grodzki, Prezes Fundacji Misja MADA. Radek podzielił się swoim doświadczeniem misyjnym w temacie „Eucharystia na Madagaskarze - świadectwo o żywej wspólnocie". Spotkanie stało się okazją do poznania codziennego życia misjonarzy, wyzwań związanych z posługą misyjną oraz niezwykłej wiary i otwartości lokalnych wspólnot. Były opowieści o życiu Kościoła na Madagaskarze, znaczeniu Eucharystii dla lokalnych wspólnot, pracy misyjnej pośród najbardziej potrzebujących dzieci i rodzin, trudnościach związanych z ubóstwem i edukacją oraz radości płynącej ze służby drugiemu człowiekowi.',
      'Podczas spotkania wspomniano również o działalności Zgromadzenia Sióstr Małych Misjonarek Miłosierdzia (Sióstr Orionistek), które prowadzą na Madagaskarze placówki medyczne, edukacyjne i duszpasterskie.',
      'Spotkanie było również wspaniałą okazją, by przybliżyć działalność naszej Fundacji Misja MADA, która wspiera posługę i działania misjonarzy na Madagaskarze. Na spotkaniu zgromadzili się liczni parafianie oraz goście zainteresowani tematyką misyjną i życiem Kościoła na Madagaskarze.',
      'Serdecznie dziękujemy Parafii św. Antoniego w Toruniu za zaproszenie, a wszystkim uczestnikom spotkania za obecność, zaangażowanie i wspólną refleksję nad misyjnym wymiarem naszego Kościoła.',
    ],
    photos: [
      { src: 'media/antoni-a.jpg', alt: 'Prezes Fundacji Misja MADA podczas wieczoru', caption: 'fot. K. Bilska' },
      { src: 'media/antoni-c.jpg', alt: 'Prelekcja - Radosław Grodzki', caption: 'fot. K. Bilska' },
      { src: 'media/antoni-d.jpg', alt: 'Prezentacja „Eucharystia na Madagaskarze”', caption: 'fot. K. Bilska' },
      { src: 'media/antoni-b.jpg', alt: 'Uczestnicy spotkania', caption: 'fot. K. Bilska' },
      { src: 'media/antoni-f.jpg', alt: 'Zgromadzeni parafianie', caption: 'fot. K. Bilska' },
      { src: 'media/antoni-g.jpg', alt: 'Wspólne słuchanie świadectwa', caption: 'fot. K. Bilska' },
      { src: 'media/antoni-e.jpg', alt: 'Malgaskie rękodzieło na stoisku', caption: 'fot. K. Bilska' },
      { src: 'media/antoni-h.jpg', alt: 'Podziękowania i wręczenie książki', caption: 'fot. K. Bilska' },
    ],
  },

  {
    id: 'siedlce-2026',
    status: 'archiwum',
    title: 'Spotkanie misyjne z Fundacją Misja MADA w Siedlcach',
    dateLabel: 'Niedziela, 26 kwietnia 2026',
    dateISO: '2026-04-26',
    year: '2026',
    category: 'misja',
    categoryLabel: 'Akcja misyjna',
    place: 'Katedra w Siedlcach - Parafia pw. Niepokalanego Poczęcia NMP',
    masze: 'Msze Święte o godz.: 6:30, 8:00, 10:00, 12:00, 16:00, 18:00, 20:00',
    lead: 'Opowieści o misjach na każdej Mszy Św., prelekcja z pokazem zdjęć i filmów oraz zbiórka na Centrum Edukacyjne.',
    body: [
      'Fundacja Misja MADA znowu działa!',
      'W niedzielę 26 kwietnia odbyła się kolejna wspaniała akcja misyjna w Katedrze pw. Niepokalanego Poczęcia NMP w Siedlcach.',
      'Opowieści o misjach na każdej Mszy Św., prelekcja dla Parafian z pokazem zdjęć i filmów z naszych wyjazdów misyjnych oraz zbiórka na Centrum Edukacyjne, prowadzone przez Siostry Małe Misjonarki Miłosierdzia na Madagaskarze - jednym słowem to była piękna i owocna Niedziela.',
      'Kwota, którą ofiarowaliście na misje, to 13 403,32 zł! Dodatkowo kilkanaście osób zadeklarowało wsparcie w naszym Programie Adopcja Serca.',
      'Dziękujemy Wszystkim Parafianom za otwarte i dobre serca, za modlitwę, za każdy gest wsparcia i każdą nawet najmniejszą wpłatę!',
    ],
    summary: { label: 'Zebrano na misje', value: '13 403,32 zł' },
    photos: [
      { src: 'media/siedlce-5.jpg', alt: 'Prezentacja o Madagaskarze w Katedrze w Siedlcach' },
      { src: 'media/siedlce-4.jpg', alt: 'Prezentacja - mapa trasy misyjnej' },
      { src: 'media/siedlce-2.jpg', alt: 'Prelekcja w Katedrze w Siedlcach' },
      { src: 'media/siedlce-3.jpg', alt: 'Świadectwo misyjne przy ołtarzu' },
      { src: 'media/siedlce-6.jpg', alt: 'Pokaz Centrum Edukacyjnego' },
      { src: 'media/siedlce-1.jpg', alt: 'Stoisko z malgaskim rękodziełem' },
      { src: 'media/siedlce-7.jpg', alt: 'Rękodzieło z Madagaskaru' },
      { src: 'media/siedlce-8.jpg', alt: 'Pamiątki i instrumenty z Madagaskaru' },
    ],
  },

  {
    id: 'londyn-2026',
    status: 'archiwum',
    title: 'Akcja Wielkopostna - pierwsza zagraniczna akcja misyjna Fundacji',
    dateLabel: 'Niedziela, 22 marca 2026',
    dateISO: '2026-03-22',
    year: '2026',
    category: 'misja',
    categoryLabel: 'Akcja zagraniczna',
    place: 'Duszpasterstwo Polskie, St Ignatius Jesuit Parish, Stamford Hill, Londyn, Wielka Brytania',
    lead: 'Pierwsza zagraniczna akcja misyjna Fundacji - zbiórka na rozbudowę Centrum Edukacyjnego wśród Polonii w Londynie.',
    body: [
      'W tym roku, w czasie Wielkiego Postu, w Parafii St Ignatius Jesuit Parish w Stamford Hill w Londynie była prowadzona zbiórka na Centrum Edukacyjne na Madagaskarze, prowadzone przez Siostry Małe Misjonarki Miłosierdzia, i uczęszczające tam dzieci z Madagaskaru.',
      'Główny cel? Rozbudowa za małego już budynku, tak by jak najwięcej dzieci mogło korzystać ze wsparcia Sióstr, a następnie zagospodarowanie przestrzeni, tak by stworzyć kilka sal tematycznych i dać dzieciom możliwość nauki oraz rozwoju.',
      'Przez cały Wielki Post nagrywaliśmy materiały o Madagaskarze, które trafiały do parafian. Aż w końcu reprezentacja Fundacji wybrała się, by dać świadectwo na miejscu.',
      'Dlaczego warto być Misjonarzem? Jak zaczęła się nasza historia? I czy bycie Misjonarzem wiąże się tylko z wyjazdem w dalekie kraje? Tymi i innymi opowieściami podzieliliśmy się z Polonią w Londynie, która była obecna na Mszach Świętych w Parafii, przeznaczonych dla Polaków.',
      'Kwota, którą ofiarowaliście na misje, wyniosła łącznie ok. 13 530,00 zł! Dodatkowo kilka osób zadeklarowało wsparcie w naszym Programie Adopcja Serca.',
      'To był dobry, owocny i niezapomniany czas! Dziękujemy za wszystkie spotkania, rozmowy i wspólnie przeżyte chwile. Już teraz z nadzieją i z radością oczekujemy kolejnej podróży misyjnej za granicę!',
    ],
    summary: { label: 'Zebrano na misje', value: '≈ 13 530,00 zł' },
    photos: [
      { src: 'media/londyn-6.jpg', alt: 'Prelekcja w St Ignatius Parish, Londyn' },
      { src: 'media/londyn-1.jpg', alt: 'Dzieci podczas Mszy Św. dla rodzin' },
      { src: 'media/londyn-2.jpg', alt: 'Świadectwo misyjne przy ołtarzu' },
      { src: 'media/londyn-3.jpg', alt: 'Radosław Grodzki dzieli się świadectwem' },
      { src: 'media/londyn-4.jpg', alt: 'Spotkanie z dziećmi' },
      { src: 'media/londyn-5.jpg', alt: 'Rozmowa z najmłodszymi' },
      { src: 'media/londyn-7.jpg', alt: 'Wspólnota podczas Mszy Świętej' },
      { src: 'media/londyn-9.jpg', alt: 'Prelekcja dla Polonii w Londynie' },
    ],
  },

  {
    id: 'grodzisk-prelekcja-2026',
    status: 'archiwum',
    title: '„Zakochaj się w misjach" - prelekcja misyjna w Grodzisku Mazowieckim',
    dateLabel: 'Niedziela, 15 lutego 2026',
    dateISO: '2026-02-15',
    year: '2026',
    category: 'misja',
    categoryLabel: 'Prelekcja',
    place: 'Parafia Św. Anny w Grodzisku Mazowieckim',
    lead: 'Wspólnie z inną rodziną misyjną opowiadaliśmy o misjach - od Kazachstanu po Madagaskar.',
    body: [
      'W niedzielę 15 lutego, z inną rodziną misyjną opowiadaliśmy o misjach w naszej Parafii św. Anny w Grodzisku Mazowieckim.',
      'Ola i Krzysio opowiadali o swoim wyjeździe misyjnym do Kazachstanu, o tym jak Duch Święty ich prowadził, po co pojechali na misje i jak ich to ubogaciło. To co było mega inspirujące, to fakt, że wyjechali na misje z trójką dzieci na pokładzie.',
      'My zaś pokazaliśmy naszą drogę misyjną, jak to się wszystko zaczęło, dlaczego wciąż i bez ustanku działamy, i czemu to dla nas takie ważne.',
      'Przede wszystkim był to dla nas piękny wspólny czas w naszej parafii, z ludźmi którym temat misji jest bliski, którzy chcą ten temat zgłębiać i się angażować. To był też czas poznania niesamowitej i fantastycznej rodzinki misyjnej, która jest dla nas niezwykłą inspiracją, a z którą znajomość na pewno przerodzi się w coś trwalszego.',
      'No i w końcu był to czas, gdzie jak zwykle mogliśmy liczyć na naszych wspaniałych przyjaciół, którzy są i nas wspierali. To była naprawdę niezwykła niedziela! Dziękujemy, że byliście z nami!',
    ],
    photos: [
      { src: 'media/grodzisk-1.jpg', alt: 'Asia i Radek opowiadają o misjach na Madagaskarze' },
      { src: 'media/grodzisk-2.jpg', alt: 'Rodzina misyjna o wyjeździe do Kazachstanu' },
      { src: 'media/grodzisk-8.jpg', alt: 'Wspólne zdjęcie rodzin misyjnych' },
      { src: 'media/grodzisk-9.jpg', alt: 'Pamiątkowe zdjęcie z prelekcji' },
      { src: 'media/grodzisk-3.jpg', alt: 'Malgaskie instrumenty i rękodzieło' },
      { src: 'media/grodzisk-7.jpg', alt: 'Stoisko z pamiątkami z Madagaskaru' },
    ],
  },

  {
    id: 'grodzisk-bierzmowanie-2026',
    status: 'archiwum',
    title: 'Spotkanie z młodzieżą przed Sakramentem Bierzmowania',
    dateLabel: 'Sobota, 7 lutego 2026',
    dateISO: '2026-02-07',
    year: '2026',
    category: 'szkola',
    categoryLabel: 'Spotkanie z młodzieżą',
    place: 'Parafia Św. Anny w Grodzisku Mazowieckim',
    lead: 'Spotkanie dla młodzieży przygotowującej się do Bierzmowania - o Darach Ducha Świętego i misjach.',
    body: [
      'W sobotę poprowadziliśmy spotkanie dla młodzieży, która przygotowuje się do Sakramentu Bierzmowania w Parafii św. Anny w Grodzisku Mazowieckim.',
      'Czym są Dary Ducha Świętego? Jak je odnajdywać w swoim życiu? I co właściwie mają do tego misje? To tematy przewodnie naszego spotkania.',
      'W dzisiejszych czasach, gdy wiele osób, a w szczególności młodzi, nie chce mieć nic wspólnego z kościołem, a bycie wierzącym raczej bywa powodem do wstydu niż do dumy i radości, tym bardziej cieszymy się, że lokalna młodzież zdecydowała się przystąpić do Sakramentu Bierzmowania.',
      'I może na razie nie są jeszcze tego w pełni świadomi, może czasem „wysłani" przez rodziców, a czasem „bo koledzy też szli", ale mimo wszystko są w kościele i próbują żyć wiarą. Na tyle na ile potrafią i to w czasach, które pod wieloma względami nie są wcale dla młodych łatwe. Tym bardziej cieszy nas to bardzo!',
      'Drodzy Młodzi, i za to Wasze świadectwo ogromnie Wam dziękujemy! Dobrze było być dzisiaj razem z Wami!',
    ],
    photos: [
      { src: 'media/bierzmowanie-1.jpg', alt: 'Spotkanie z młodzieżą przed Bierzmowaniem' },
      { src: 'media/bierzmowanie-2.jpg', alt: 'Prezentacja o dzieciach z Madagaskaru' },
      { src: 'media/bierzmowanie-3.jpg', alt: 'Opowieść o Centrum Edukacyjnym w Itaosy' },
      { src: 'media/bierzmowanie-4.jpg', alt: 'Młodzież słucha świadectwa misyjnego' },
      { src: 'media/bierzmowanie-5.jpg', alt: 'Prezentacja projektu rozbudowy kościoła' },
    ],
  },

  {
    id: 'klodzko-2026',
    status: 'archiwum',
    title: 'Akcja misyjna w Kłodzku',
    dateLabel: 'Niedziela, 4 stycznia 2026',
    dateISO: '2026-01-04',
    year: '2026',
    category: 'misja',
    categoryLabel: 'Akcja misyjna',
    place: 'Parafia pw. Wniebowzięcia NMP w Kłodzku, pl. Kościelny 9, Kłodzko',
    lead: 'Pierwsza akcja na rzecz Madagaskaru w tym roku - świadectwo na 7 Mszach Świętych i malgaskie rękodzieło.',
    body: [
      'Akcja misyjna w Kłodzku to nasza pierwsza akcja na rzecz Madagaskaru w tym roku.',
      'Uczestniczyliśmy w 7 Mszach Świętych i pod koniec każdej z nich, dawaliśmy świadectwo oraz opowiadaliśmy o naszych działaniach na Madagaskarze. Braliśmy również udział we Mszy Św. dla dzieci, uczestniczyliśmy czynnie w kazaniu i opowiadaliśmy dzieciom o misjach, co było dla nas wyjątkowym doświadczeniem.',
      'Podczas naszych spotkań można było nabyć piękne malgaskie rękodzieło, które znikało jak świeże bułeczki albo nawet szybciej. Parafianie przyjęli nas tak życzliwie, że nie da się tego w pełni opisać słowami. Oczywiście miło jest usłyszeć, że to co robimy jest światu potrzebne, że wspaniale, że jesteśmy i takie działania prowadzimy, ale tak naprawdę jest to możliwe dzięki Wam, naszym Darczyńcom.',
      'Podczas naszej akcji misyjnej w Kłodzku zebraliśmy łącznie 11 262,00 zł + 70 euro. Cała kwota zostanie przekazana bezpośrednio na Centrum Edukacyjne Sióstr Małych Misjonarek Miłosierdzia. Ponadto, do naszego programu Adopcja Serca dołączyło kolejnych kilkanaście osób.',
      'Po raz kolejny przekonaliśmy się, że jest w Kościele miejsce, by działać, by wspierać się wzajemnie i by razem pomnażać DOBRO. I w końcu, widzimy ogromny sens w tym naszym działaniu. Jest to realna pomoc dla misji, ale też a może przede wszystkim - łączenie dwóch odmiennych światów, Polski i Madagaskaru. Gdzie jeden może obdarować drugiego czymś odmiennym i gdzie można nawzajem się ubogacać, w szerszym tego słowa znaczeniu.',
      'Z całego serca DZIĘKUJEMY!',
    ],
    summary: { label: 'Zebrano na misje', value: '11 262,00 zł + 70 €' },
    photos: [
      { src: 'media/klodzko-1.jpg', alt: 'Świadectwo misyjne w kościele w Kłodzku' },
      { src: 'media/klodzko-3.jpg', alt: 'Parafianie przy stoisku misyjnym' },
      { src: 'media/klodzko-4.jpg', alt: 'Malgaskie rękodzieło na kiermaszu' },
      { src: 'media/klodzko-5.jpg', alt: 'Kiermasz misyjny w Kłodzku' },
      { src: 'media/klodzko-2.jpg', alt: 'Założyciele fundacji przy stoisku' },
      { src: 'media/klodzko-6.jpg', alt: 'Rękodzieło z Madagaskaru' },
      { src: 'media/klodzko-7.jpg', alt: 'Materiały o Adopcji Serca i puszka na datki' },
    ],
  },
];
