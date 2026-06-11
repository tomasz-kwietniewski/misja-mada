# Fundacja Misja MADA — kompletny serwis (handoff)

Statyczny serwis (HTML + CSS + vanilla JS) gotowy do (a) wrzucenia na serwer
testowy oraz (b) dalszej pracy w Claude Code nad integracjami backendowymi.
To jest **pełna, samowystarczalna paczka** — zawiera wszystkie strony, style,
skrypty, zdjęcia, logo i dokumenty PDF. Nic spoza tego folderu nie jest potrzebne.

## Jak uruchomić (test)
1. Wgraj CAŁĄ zawartość tego folderu na serwer (FTP / panel hostingu).
2. Wejdź na adres — `index.html` przekieruje na „index.html".
3. `robots.txt` blokuje indeksowanie. **Przed produkcją usuń `robots.txt`.**

> Wymaga internetu: mapa Madagaskaru (D3 + Natural Earth) i fonty Google ładują się z CDN.

## Struktura (uporządkowana)
```
/ (root)
├── index.html        — strona główna
├── co-robimy.html            — projekty (Adopcja Serca, Atelier, Centrum Edukacyjne, Wolontariat)
├── o-nas.html                — fundacja, założyciele, obszary działań, dokumenty, partnerzy
├── wydarzenia.html           — wyróżnione + nadchodzące + ostatnie archiwalne
├── wydarzenie.html           — szablon pojedynczego wpisu (czyta ?id=, dane z events.js.php)
├── archiwum-wydarzen.html    — szachownica z filtrami (rok/kategoria) + paginacja (9/stronę)
├── kontakt.html              — formularz kontaktowy + dane + newsletter
├── polityka-prywatnosci.html
├── regulamin-serwisu.html
├── regulamin-adopcja-serca.html
├── oswiadczenie-o-wizerunku.html
├── newsletter.html           — szablon e-mail (do wklejenia w MailerLite jako Custom HTML)
├── index.html / robots.txt   — przekierowanie + blokada indeksowania (test)
├── assets/                   — CSS, JS, dane, skrypt backendu, mapa SVG
│   ├── site.css
│   ├── site-nav.js, site-a11y.js, site-search.js
│   ├── wydarzenia-render.js, archiwum-render.js, wydarzenie-render.js — render wydarzeń z danych
│   ├── adopcja-form.js        — formularz „Zostań rodzicem adopcyjnym" (2 ścieżki: PayU / przelew)
│   ├── darowizna.js           — formularz darowizny PayU (kwota/waluta/typ/cel + dane)
│   ├── newsletter.js          — modal newslettera (do podpięcia MailerLite)
│   ├── google-apps-script.gs  — gotowy backend Apps Script (Adopcja + Kontakt)
│   └── madagaskar.svg         — statyczny fallback mapy
└── media/                    — WSZYSTKIE zdjęcia, logo i PDF-y (płaska, czysta struktura)
    ├── logo-kolor.png, logo-biale.png
    ├── <zdjęcia wydarzeń>: antoni-*, siedlce-*, londyn-*, grodzisk-*, bierzmowanie-*, klodzko-*, lezajsk-2026.jpg
    ├── <zdjęcia stron>: 20251107_103213.jpg, My_*_web.jpg, Atelier_Nadziei_*, Centrum_Edukacyjne.jpg, Wolontariat_*, Madagaskar_1/2/3.jpg, Adopcja_Serca_chlopiec.jpg
    ├── partner-*.png
    └── Statut_*.pdf, Sprawozdanie_*.pdf
```
> Uwaga porządkowa: wcześniejsza długa ścieżka logo i foldery `uploads/output`,
> `uploads/fixed` zostały skonsolidowane do jednego folderu **`media/`**.

## Design tokens (`assets/site.css`, `:root`)
| Token | Wartość | Rola |
|---|---|---|
| `--brown` | `#422918` | nagłówki, tekst, ciemne sekcje, przyciski |
| `--brownDk` | `#2a1a0e` | stopka, hover |
| `--gold` | `#c99d66` | akcent: eyebrows, ikony, CTA, podkreślenia |
| `--cream` | `#faf5ee` | główne tło |
| `--pinkBeige` | `#efe4dc` | tła kart |
| `--rule` | `rgba(66,41,24,.12)` | linie/obramowania |
| `--font-head` | Libre Caslon Text (serif) | nagłówki |
| `--font-body` | Plus Jakarta Sans | tekst |

## DO DOKOŃCZENIA backendowo (główny cel pracy w Claude Code)

### 0. Funkcje frontu już zaimplementowane (kontekst)
- **Wersja PL/EN/FR** — przełącznik w menu (wyśrodkowane menu, narzędzia po prawej), silnik `assets/i18n.js` (wielojęzyczny) + słowniki `assets/i18n-dict.js` (EN) i `assets/i18n-dict-fr.js` (FR), klucz = tekst PL. Polski jest podstawą; brak wpisu = fallback do PL.
- **Galeria Madagaskaru** — przycisk na stronie głównej otwiera modal-siatkę (`media/galeria-01..32.jpg`) z lightboxem; pierwsze 3 zdjęcia to też kafelki w sekcji Madagaskar.
- **Darowizna z preselekcją celu** — przyciski „Wesprzyj Atelier" / „Wesprzyj rozbudowę" (Co robimy) otwierają formularz darowizny z ustawionym celem (`data-cel="atelier"` / `"centrum"` → `darowizna.js`).
- **Darowizna - cel „Adopcja Serca"**: specjalna logika w `darowizna.js` - zamiast pola kwoty pojawia się selektor LICZBY DZIECI (każde = 70 zł PLN / 18 € EUR, kwota zawsze wielokrotność), typ zablokowany na „co miesiąc" (min. 12 mies.), bez pola „inna kwota". Payload: `{ type:'adopcja-online', recurring:true, amount, currency, dzieci:N, goal:'adopcja', imie, nazwisko, email }`.
- **Responsywność mobilna** — szuflada (hamburger) z ujednoliconymi ikonami (lupka + FB + PL/EN/FR), zredukowane paddingi kart na ekranach ≤560px.
- **Zasady redakcyjne** (patrz `CLAUDE.md`): bez długich myślników, „Msza Święta" wielką literą, pełna nazwa „Siostry Małe Misjonarki Miłosierdzia (Siostry Orionistki)", „Centrum Edukacyjne".

### 1. Płatności PayU — `assets/darowizna.js` + `assets/adopcja-form.js`
UI gotowe. Oba wysyłają `POST` na `window.MADA_PAYU_URL` i oczekują `{ redirectUri }`.
Trzeba dopisać backend serwer-do-serwera (klucz `client_secret` NIE może być w przeglądarce):
OAuth PayU → OrderCreate → zwrot `redirectUri`. Dla wpłat cyklicznych (Adopcja 70 zł/mies.,
darowizna „co miesiąc") — PayU recurring/tokenizacja. Ustaw `window.MADA_PAYU_URL`.
- **Darowizna**: payload `{ amount, currency, recurring, goal, goalLabel, imie, nazwisko, email }`. Dla celu „Adopcja Serca" online: `{ type:'adopcja-online', recurring:true, amount, currency, dzieci:N, goal:'adopcja', ... }` (kwota = N × 70 zł / N × 18 €, zawsze cykliczna).
- **Adopcja → PayU**: `{ type:'adopcja', recurring:true, amount:70, currency:'PLN', goal:'adopcja', imie, nazwisko, email, telefon, adres, forma, okres }`.
- **Adopcja → przelew**: idzie do Apps Script (double opt-in), ekran sukcesu pokazuje dane do przelewu.

### 2. Formularze Adopcja + Kontakt — `assets/google-apps-script.gs`
Gotowy skrypt (double opt-in dla Adopcji, osobny arkusz dla Kontaktu). Instrukcja w
`DEPLOY-FORMULARZ.md`. Ustaw `window.MADA_SUBMIT_URL`. E-mail fundacji: **kontakt@misjamada.pl**.
- **Kontakt** (`kontakt.html`): pola **imię, nazwisko, e-mail, temat, treść** + zgoda RODO.
  Payload: `{ type:'kontakt', imie, nazwisko, email, temat, tresc }`. Apps Script (`handleKontakt`)
  wysyła e-mail do fundacji z `Reply-To` = e-mail nadawcy (bez double opt-in).

### 3. Newsletter — `assets/newsletter.js`
Modal gotowy; podpiąć embed/API MailerLite (plan darmowy).

### 4. CMS wydarzeń (panel PHP)
Panel pod `/panel/` (logowanie: konta w `panel/secret/users.php`). Redaktorzy dodają/edytują/usuwają
wydarzenia oraz galerię (zdjęcia w `uploads/wydarzenia/`, filmy jako linki YouTube/Facebook).
- **Źródło prawdy:** `data/wydarzenia/<id>.json` (poza repo i deployem; chronione `data/.htaccess`).
- **Endpoint:** `events.js.php` czyta JSON-y i emituje `window.MADA_EVENTS` + dosypuje tłumaczenia
  do słowników i18n. Front (index, wydarzenia, wydarzenie, archiwum) renderuje się z tego.
- **Status z daty:** `dateISO` w przyszłości → „nadchodzące", w przeszłości → „archiwum" (automatycznie).
- **Wyróżnione:** ręczna flaga `featured` (maks. 1) albo fallback na najbliższe nadchodzące.
- **Tłumaczenia EN/FR:** DeepL API Free przy zapisie (`panel/secret/deepl-config.php`) + glosariusz terminów.
- Sekrety i dane (`panel/secret/`, `data/`, `uploads/`) są poza repo i wykluczone z deployu (rsync `--delete`).

## Dane fundacji (zachować)
- Fundacja Misja MADA · ul. Szosa Chełmińska 271A, 87-100 Toruń
- kontakt@misjamada.pl · tel. 604 181 301 / 690 623 252 · domena docelowa: misjamada.pl
- KRS 0001099359 · NIP 9562392375 · REGON 528347054
- Konta: PLN 70 1090 1056 0000 0001 5832 5871 · EUR 49 1090 1056 0000 0001 6067 9663 · GBP 34 1090 1056 0000 0001 6645 4246
- Facebook: facebook.com/MisjaMADA
- Partnerzy: Pallotyńska Fundacja Misyjna Salvatti, Stowarzyszenie MISEVI Polska, Siostry Małe Misjonarki Miłosierdzia (Orionistki)

## Podgląd
Zrzuty kluczowych stron w folderze `screenshots/`.
