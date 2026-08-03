# Fundacja Misja MADA - serwis misjamada.pl

Serwis internetowy Fundacji Misja MADA (pomoc dzieciom i rodzinom na Madagaskarze).
Strona jest **live na produkcji**: [https://misjamada.pl](https://misjamada.pl).

Front to statyczny HTML + CSS + vanilla JS (bez frameworków, bez build-stepu). Backend to
lekkie skrypty **PHP 8** na hostingu współdzielonym: płatności PayU (jednorazowe i cykliczne),
newsletter, panel CMS. Serwis jest trójjęzyczny (PL / EN / FR).

- **Domena kanoniczna:** `https://misjamada.pl` (bez `www`; `www` -> 301 na bez-www).
- **Hosting:** SEO Host (DirectAdmin, PHP 8, MySQL, cron, SSH).
- **Języki:** polski (podstawa) + angielski + francuski (tłumaczenie po stronie przeglądarki).

---

## Deploy (jak zmiany trafiają na produkcję)

Push do gałęzi **`main`** uruchamia GitHub Actions **`Deploy na SEO Host`**
(`.github/workflows/deploy.yml`), który przez `rsync` po SSH wgrywa pliki do `public_html`.
Klucz SSH jest w sekrecie repo `SSH_PRIVATE_KEY`.

- Deploy jest lustrzany (`rsync --delete`), ale **wyklucza** dane i sekrety, których nie
  wolno nadpisać ani skasować: `/data/`, `/uploads/`, `*/secret/`, oraz pliki wewnętrzne repo
  (`.git`, `.github`, `README.md`, `tests/`).
- Przy chwilowej blokadzie SSH (fail2ban SEO Hosta) workflow ponawia do 6 razy co 40 s.

> **GitHub Pages nie jest używany.** To serwis PHP - Pages (statyczny Jekyll) nie ma jak go
> zbudować, więc jego deploy w zakładce „Deployments" bywa czerwony. To **nie dotyczy
> produkcji** - naszą produkcję robi wyłącznie `Deploy na SEO Host`. Pages można wyłączyć
> (Settings -> Pages -> Source: None), żeby nie zaśmiecał historii deployów.

**Historia zmian na GitHubie:** commity na `main` (zakładka *Code* -> historia commitów)
oraz scalone Pull Requesty (zakładka *Pull requests* -> filtr *Merged*).

---

## Struktura repozytorium

```
/ (root)
├── index.html, o-nas.html, co-robimy.html, kontakt.html   strony treści
├── wydarzenia.html, wydarzenie.html, archiwum-wydarzen.html   wydarzenia (CMS)
├── sprawozdania.html                                       sprawozdania (CMS)
├── polityka-prywatnosci.html, regulamin-serwisu.html,
│   regulamin-adopcja-serca.html, oswiadczenie-o-wizerunku.html   dokumenty
├── newsletter.html, newsletter-zapisano.html              newsletter
├── dziekujemy.html, platnosc-nieudana.html                strony powrotu z płatności
├── 404.html                                               strona błędu
├── robots.txt, sitemap.xml, favicon*, apple-touch-icon.png   SEO / ikony
├── .htaccess                                              301 www->bez-www, blokady data//secret/
│
├── assets/                 CSS + JS (front)
│   ├── site.css            wspólny arkusz stylów (design tokens w :root)
│   ├── site-nav.js, site-a11y.js, site-search.js   nawigacja, dostępność, wyszukiwarka
│   ├── site-common.js      wspólne helpery formularzy (focus trap, e-mail, host, loader Secure Form)
│   ├── i18n.js             silnik tłumaczeń (podmiana węzłów tekstowych)
│   ├── i18n-dict.js        słownik PL->EN     (klucz = tekst PL)
│   ├── i18n-dict-fr.js     słownik PL->FR
│   ├── darowizna.js        modal darowizny (jednorazowo + „co miesiąc")
│   ├── secure-form.js      wrapper PayU Secure Form (tokenizacja karty MULTI)
│   ├── adopcja-form.js     formularz „Zostań rodzicem adopcyjnym"
│   ├── newsletter.js       modal newslettera
│   ├── wydarzenia-render.js, wydarzenie-render.js, archiwum-render.js,
│   │   sprawozdania-render.js   render treści CMS z danych
│   ├── google-apps-script.gs   backend formularzy + relay poczty (Apps Script) - do wgrania w Google
│   └── madagaskar.svg      statyczny fallback mapy
│
├── payu/                   backend płatności PayU (PHP)
│   ├── create-order.php    płatność JEDNORAZOWA (hosted redirect)
│   ├── secure-config.js.php config Secure Form dla frontu (tylko posId/env/sdkUrl)
│   ├── recurring-first.php  pierwsza płatność cykliczna (FIRST + 3DS, zapis tokena)
│   ├── cron-charge.php      scheduler kolejnych obciążeń (STANDARD) - uruchamiany cronem
│   ├── notify.php           notyfikacje serwer-do-serwera (weryfikacja podpisu)
│   ├── manage.php           link rezygnacji z subskrypcji (token + CSRF)
│   ├── db.php               warstwa MySQL (subskrypcje, obciążenia)
│   ├── recurring-lib.php    czysta logika (harmonogram, idempotencja, decyzje)
│   ├── lib.php              wspólne (OAuth, żądania do PayU, podpis)
│   ├── mail.php             maile transakcyjne (HTML) - relay przez Gmail/Apps Script, fallback mail()
│   ├── sheet.php            arkusz Google (Apps Script, shared secret): zapis + sync anulowania adopcji + newsletter add-verified
│   └── migrate.php          migracja bazy (CLI)
│
├── newsletter/             własny double opt-in + MailerLite (PHP)
│   ├── subscribe.php, confirm.php, lib.php, confirm-email.html
│   └── add-verified.php     dopisanie ZWERYFIKOWANEGO maila do MailerLite (z adopcji, shared secret)
│
├── adopcja/                moduł Adopcja Serca - backend (PHP + MySQL)
│   ├── db.php               schemat (adopt_*/fin_flows/panel_*) + CRUD + audyt + auto-rejestracja z karty
│   ├── lib.php              czysta logika: pokrycie wpłat, zaległości, parser okresów, sort po nazwisku
│   ├── zgloszenie.php       formularz przelewowy ze strony (double opt-in, KROK 1)
│   ├── potwierdz.php        potwierdzenie e-maila (KROK 2: darczyńca + adopcje pending, maile, lustro)
│   ├── xlsx.php             minimalny writer XLSX (eksport-backup, bez Composera)
│   └── migrate.php          migracja schematu modułu (CLI; idempotentna, także ALTER-y dossier)
│
├── panel/                  panel CMS (PHP, logowanie + CSRF + rate-limit IP)
│   ├── index.php, login.php, auth.php, layout.php, lib.php, panel.css
│   │                        layout = wspólna nawigacja modułów + pod-menu Adopcji Serca
│   ├── edit.php, save.php, delete.php, upload.php, media.php, categories.php
│   ├── translate.php, glossary.php    tłumaczenia DeepL + glosariusz
│   ├── sprawozdania.php, sprawozdania-upload.php, sprawozdania-delete.php
│   ├── subskrypcje.php     podgląd subskrypcji + ręczne anulowanie i wznawianie
│   ├── adopcje.php, darczyncy.php, darczynca.php, darczynca-edit.php,
│   │   dzieci.php, adopcja-edit.php, wplaty.php, zgloszenia.php   moduł Adopcja Serca (UI)
│   ├── finanse.php         rejestr przepływów misyjnych (fin_flows)
│   ├── eksport.php         wyjście awaryjne: XLSX/CSV w układzie arkuszy fundacji
│   └── import.php, import-lacz.php   jednorazowa migracja danych (do usunięcia po niej)
│
├── tools/                  narzędzia lokalne - POZA deployem
│   └── import/parse-adopcje.php   parser arkuszy fundacji -> JSON importu
│
├── events.js.php, sprawozdania.js.php   endpointy emitujące dane CMS na front
├── media/                  zdjęcia, logo, PDF-y (płaska struktura)
├── tests/                  run.php (CMS), run-recurring.php (płatności), run-adopcja.php (moduł adopcji)
└── .github/workflows/      deploy.yml (SEO Host), ci.yml (lint + testy)
```

Nie są w repo (żyją tylko na serwerze, poza deployem): katalogi `data/`, `uploads/`
(w tym `uploads/dzieci/` - zdjęcia do dossier podopiecznych)
oraz wszystkie `*/secret/` (patrz „Sekrety i dane").

---

## Wielojęzyczność (PL / EN / FR)

Polski jest oryginałem. `assets/i18n.js` po przełączeniu języka podmienia teksty w węzłach DOM
na podstawie słowników (`i18n-dict.js` = EN, `i18n-dict-fr.js` = FR), gdzie **kluczem jest
tekst polski**. Brak wpisu = tekst zostaje po polsku (bezpieczny fallback). Wybór języka
zapamiętywany w `localStorage`, wspólny dla podstron.

### Tłumacz Google jest wyłączony - i musi taki zostać

Każda podstrona ma w `<head>`:

```html
<meta name="google" content="notranslate" />
```

**Nie usuwać.** Bez tego Chrome u polskiego użytkownika wykrywał naszą wersję EN
(`<html lang="en">`) i tłumaczył ją **z powrotem na polski**, owijając teksty w `<font>`.
Nasz `MutationObserver` widział te nowe węzły i tłumaczył je PL->EN z powrotem. Gdzie słownik
miał trafienie, wygrywaliśmy my; gdzie nie miał - zostawał polski Google'a. Efekt: losowa
mieszanka języków (zgłoszenia fundacji z lipca 2026 - m.in. przełącznik pokazujący
„PL / PL / FR", bo Google tłumaczył etykietę „EN" na „PL"). Objaw zależał od ustawień
przeglądarki testera, więc u autora strony był nie do odtworzenia.
Przełącznik ma dodatkowo `translate="no"` - obrona w głąb.

### Jak dodać / poprawić tłumaczenie

1. Tekst wpisany wprost w HTML -> dopisz parę `"tekst PL": "tłumaczenie"` do
   `assets/i18n-dict.js` (EN) i `assets/i18n-dict-fr.js` (FR).
2. Tekst budowany w JS -> **rozbij zmienną część od słów**, żeby klucz był skończony.
   Data „19 maja 2026" nie może być kluczem (byłby jeden na każdy dzień) - dlatego renderery
   wstawiają sam miesiąc w `<span class="i18n-month">`, a tłumaczymy 12 nazw miesięcy.
   Ten sam wzorzec: `70 <span class="i18n-word">zł/mies.</span>`.
3. Gdy zdania nie da się rozbić (np. komunikat czytnika ekranu sklejany ze zmiennej) -
   użyj `window.MadaI18n.t('tekst PL')` **przed** wstawieniem do DOM.
4. Fraza NIE ma być tłumaczona (nazwa własna, kod, numer) -> dopisz ją do `ALLOW`
   w `tests/i18n-coverage.js`, a nie do słownika.

### Bramka CI: `node tests/i18n-coverage.js`

Brak tłumaczenia **nie wywala strony** - tekst po prostu zostaje po polsku. Błąd jest więc
cichy i wychodzi dopiero u użytkownika. Dlatego CI blokuje push, gdy jakakolwiek fraza nie ma
pary EN i FR. Skrypt jest bez zależności (czysty Node) i przechodzi stronę tak jak `i18n.js`
(węzeł po węźle, tylko `<body>`, z pominięciem `[translate="no"]`).

Zakres i świadome ograniczenia:

| źródło tekstu | pokrycie |
|---|---|
| HTML podstron (16 plików) | **wyczerpujące** - każdy tekst z literami musi być w słowniku albo w `ALLOW` |
| `.textContent = '...'` w JS | pełne (wzorzec parsuje się pewnie) |
| indeks wyszukiwarki (`site-search.js`) | **wyczerpujące** - tablica czytana wprost, każdy wpis musi mieć `en` i `fr` z `page`/`title`/`body` |
| domyślne kategorie wydarzeń (`panel/lib.php`) | sprawdzane wprost |
| HTML sklejany w JS (`'<span>Tekst</span>' + x`) | tylko teksty z polskimi znakami (regex nie zastąpi parsera JS) |
| treści wydarzeń z CMS | poza skryptem - tłumaczy je panel (DeepL + glosariusz), pole `i18n` wydarzenia |

`newsletter.html` jest pomijany celowo: to szablon e-maila do MailerLite, nie podstrona.

### Wyszukiwarka ma własny indeks językowy

`assets/site-search.js` NIE korzysta ze słownika i18n - każdy wpis indeksu ma warianty
`en` i `fr` obok polskiego. Powód: wyszukiwarka **dopasowuje zapytanie do treści indeksu**,
więc przy polskim indeksie wpisanie „adoption" w wersji EN nie znalazłoby nic. Do tego wyniki
przechodzą przez `snippet()`, który wstawia `<mark>` wokół trafienia i rozbija węzeł tekstowy -
podmiana po słowniku i tak by go nie dopasowała. Dodając wpis, uzupełnij wszystkie trzy języki.

### Dokumenty prawne

Regulaminy, polityka prywatności i oświadczenie o wizerunku mają notę
(`<p class="doc-binding">`), że **wiążąca jest wersja polska**, a tłumaczenia mają charakter
informacyjny. Dodając nowy dokument prawny, dołóż tę notę - jest tłumaczona jak każdy inny
tekst, więc wystarczy skopiować akapit.

### Cache

Skrypty i18n mają `?v=…` w URL, a `.htaccess` wymusza dla nich rewalidację
(`no-cache, must-revalidate`). Reguła obejmuje **też renderery i formularze** - stary renderer
z nowym słownikiem daje połowicznie przetłumaczoną stronę. Przy zmianie któregokolwiek
z tych plików (oraz `site.css`) podbij `?v=` w podstronach - to jeden wspólny token dla całej
witryny, obecnie `20260717`.

---

## Płatności PayU

POS produkcyjny **4432411**, sklep `misjamada.pl`. Wszystkie sekrety (OAuth `client_secret`,
klucz podpisu, dane bazy) są po stronie serwera w `payu/secret/` - nigdy w przeglądarce.
Umowa z PayU obejmuje **wyłącznie PLN** - dlatego EUR jest w modalu darowizny ukryte;
włączenie innej waluty wymaga najpierw aneksu do umowy.

### Jednorazowe (live)
`payu/create-order.php`: walidacja -> OAuth -> OrderCreate -> zwrot `redirectUri`.
Karta wpisywana **na stronie PayU** (hosted redirect), 3DS automatyczne, zero danych karty
u nas. Status potwierdza `payu/notify.php` (weryfikacja podpisu).

### Cykliczne (live)
Model recurring PayU z tokenizacją karty (Secure Form):

1. **Pobranie karty** - `assets/secure-form.js` renderuje Secure Form (SDK PayU w iframe)
   i tokenizuje kartę jako **MULTI**.
2. **Pierwsza płatność** - `payu/recurring-first.php` tworzy zamówienie `recurring=FIRST`
   z wymuszonym **3DS (challenge MANDATE)**. Token wielorazowy `TOKC_` i maska karty
   przychodzą w **synchronicznej odpowiedzi** i są zapisywane na subskrypcji.
3. **Aktywacja** - po notyfikacji `COMPLETED` (`payu/notify.php`) subskrypcja przechodzi
   w stan `active`, idzie mail powitalny, ustawiany jest termin kolejnego obciążenia.
4. **Kolejne obciążenia** - `payu/cron-charge.php` (cron, raz dziennie) obciąża token
   w trybie `recurring=STANDARD` (serwer-do-serwera, bez 3DS). Idempotencja przez
   `extOrderId` + tabela `charges`; ponowienia po odmowie: max 1x/dobę, do 3 prób.
   Przy nieznanym wyniku (timeout) subskrypcja jest wstrzymywana zamiast ponawiania
   (ochrona przed podwójnym obciążeniem). Cron przy okazji czyści porzucone tokeny,
   efemeryczne pliki `data/donation-pending` / `data/adopcja-card-pending` starsze niż 7 dni
   oraz przycina log notyfikacji (`data/payu-notifications.log`) do 12 miesięcy.
5. **Rezygnacja** - link z tokenem (`payu/manage.php`) w każdym mailu, oraz ręcznie
   w panelu (`panel/subskrypcje.php`).

> **Wymóg produkcyjny:** obciążenia STANDARD działają tylko, gdy na serwerze jest ustawiony
> **cron** na `payu/cron-charge.php` (codziennie ~05:00). Bez crona pierwsza płatność przejdzie,
> ale kolejne miesiące się nie naliczą.

Baza MySQL zakłada się sama przy pierwszym użyciu (`payu/db.php`, idempotentna migracja).

---

## Newsletter

`newsletter/` - własny double opt-in (zapis -> mail z potwierdzeniem -> dopisanie do listy),
zintegrowany z MailerLite. Modal na froncie: `assets/newsletter.js`.

## Formularze (kontakt, adopcja)

Backend formularzy to Google Apps Script (`assets/google-apps-script.gs`, wdrażany ręcznie w edytorze
Apps Script; PHP woła go serwer-do-serwera z shared secret). E-mail fundacji: `kontakt@misjamada.pl`.

- **Kontakt** - mail do fundacji z `Reply-To` na nadawcę.
  - **Temat z linku:** `kontakt.html?temat=partner-biznesowy#napisz` (też `partner-edukacyjny`,
    `wolontariat`) wstawia temat do pola „Temat" i przewija do formularza (`id="napisz"` na sekcji).
    Tak działają przyciski „Napisz do nas" / „Zaproś nas do szkoły" / „Zgłoś się do wolontariatu"
    w sekcji „Dołącz do nas" na `index`, `co-robimy`, `o-nas`, `wydarzenia`, `archiwum-wydarzen`.
    **Wcześniej były to linki `mailto:`, które u odbiorcy bez skonfigurowanego klienta poczty nie
    robiły NIC** - żadnego komunikatu, martwy przycisk (zgłoszenie z sierpnia 2026). Nie wracać
    do `mailto:` w roli głównego CTA.
  - Wartość parametru jest mapowana przez listę dozwolonych kluczy w `kontakt.html` - nieznana
    wartość zostawia pole puste, nic nie trafia z URL-a do DOM.
  - Temat wstawiamy przez `setAttribute('value', ...)`, **nie** przez `.value`. Powód: i18n tłumaczy
    atrybut `value` (patrz `ATTRS` w `assets/i18n.js`), więc temat sam przechodzi na EN/FR, a brak
    „dirty value flag" sprawia, że podmiana przy przełączaniu języka faktycznie zmienia to, co widać.
    Ustawienie `.value` cicho zepsułoby tłumaczenie tego pola.
- **Adopcja Serca** (`assets/adopcja-form.js`) - jeden pełny formularz z selektorem liczby dzieci
  (kwota = dzieci x 70 zł), dwie ścieżki wsparcia:
  - **Przelew** - double opt-in po stronie **PHP** (`adopcja/zgloszenie.php` -> mail „Potwierdź
    zgłoszenie" przez relay Gmail -> `adopcja/potwierdz.php`): po kliknięciu linku powstaje darczyńca
    i adopcja (status `pending`) w **MySQL modułu CMS**, darczyńca dostaje mail **powitalny** z danymi
    do przelewu (kwota, tytuł „Adopcja Serca Madagaskar - Imię Nazwisko", okres zlecenia dla formy
    czasowej), fundacja powiadomienie, a arkusz Google kopię wiersza (lustro, typ `adopcja-mirror`).
    Niepotwierdzone zgłoszenia wygasają po 7 dniach (cron).
  - **Karta (cyklicznie)** - Secure Form + `payu/recurring-first.php` (jak darowizna cykliczna); komplet
    danych adopcyjnych trafia też do arkusza (metoda `Karta PayU`, subskrypcja `Aktywna`), sama
    subskrypcja do panelu.
  - Dobrowolny **newsletter** - po weryfikacji maila (klik linku / płatność kartą) dopisanie do MailerLite
    przez `newsletter/add-verified.php` (bez drugiego double opt-in).
- **Wejście z darowizny** - w modalu „Wesprzyj nas" wybór celu „Adopcja Serca" + „Dalej" przełącza na
  pełny formularz adopcji z przeniesioną liczbą dzieci (jedno źródło danych).

### Arkusz „Darowizny" (Google Sheets)

Opłacone **jednorazowe** darowizny (`payu/notify.php` przy `COMPLETED`) oraz **cykliczne na cele inne niż
adopcja** (każda rata, idempotentnie) trafiają do zakładki „Darowizny" w arkuszu + powiadomienie fundacji.
Zapis przez `payu/sheet.php` -> Apps Script. Cykliczne adopcje mają własną zakładkę „Adopcja Serca".

### Arkusz „Adopcja Serca" - lustro danych

Od wdrożenia modułu CMS **źródłem prawdy jest MySQL (panel)**, a zakładka arkusza pozostaje
lustrem/backupem i znajomym widokiem dla fundacji. Układ zakładki bez zmian:
polskie nazwy kolumn, kolorowanie wierszy i osobna zakładka **„Instrukcja"** (legenda + opis kolumn).
Zapis odbywa się **po nazwach nagłówków**, więc kolumny wolno przestawiać; nie wolno zmieniać ich nazw.

- **Dwie osobne kolumny stanów** (świadomie rozdzielone porządki): „Weryfikacja e-mail"
  (`Oczekuje` -> `Potwierdzony`, przy karcie `Nie dotyczy`) dotyczy double opt-in, a „Status subskrypcji
  PayU" (`Aktywna` / `Anulowana`) dotyczy wyłącznie kart.
- **Metoda płatności** (`Przelew` / `Karta PayU`) - jawna kolumna zamiast wnioskowania z trzech innych.
- **Raty kartowe w arkuszu**: po każdej notyfikacji `COMPLETED` (`payu/notify.php` ->
  `mada_adopcja_charge_sheet`, typ `adopcja-charge`) wiersz darczyńcy dostaje datę ostatniej wpłaty
  i licznik opłaconych miesięcy z bazy (wartość absolutna, więc ponowiona notyfikacja jest nieszkodliwa).
- **Kolumny robocze fundacji** (`Przypisane dzieci`, `Wpłaty przelewowe - opłacone do`, `Notatki`) -
  system ich **nigdy** nie nadpisuje. Dwa procesy zostają świadomie ręczne: system nie widzi zaksięgowania
  przelewów (fundacja sprawdza wyciąg) i nie przypisuje dzieci ani nie wysyła maila o dziecku.

**Wdrożenie zmian w `assets/google-apps-script.gs` (KOLEJNOŚĆ MA ZNACZENIE):** najpierw wklej nową wersję
w edytorze Apps Script i zrób *Deploy -> Manage deployments -> Edit -> New version* (URL `/exec` musi
zostać ten sam), potem uruchom raz `setupArkuszAdopcja()` (nagłówki, migracja starych wartości, kolory,
Instrukcja - funkcja jest idempotentna), a dopiero na końcu wypchnij PHP na produkcję. Odwrotna kolejność
oznacza nowe PHP i stary skrypt; chroni przed tym strażnik nieznanych typów (zwraca `unknown-type`
zamiast tworzyć fałszywy wiersz i wysyłać darczyńcy mail).

## Wysyłka maili (relay Gmail, fallback `mail()`)

Wszystkie maile wychodzą uwierzytelnionym Gmailem `kontakt@misjamada.pl` - poczta słana
wprost z serwera przez PHP `mail()` bywała łapana jako spam, a reputacja Google i DKIM
załatwiają dostarczalność. Technicznie dwie drogi o wspólnym ujściu:

- **Apps Script bezpośrednio** (`GmailApp`) - maile formularzy: potwierdzenie i powitanie
  adopcji, wiadomości z formularza kontaktowego, powiadomienia fundacji.
- **PHP przez relay w Apps Script** (`payu/mail.php`, `type=relay` + shared secret) - maile
  transakcyjne płatności cyklicznych (powitalny, rata, nieudana płatność, wstrzymanie,
  anulowanie) oraz potwierdzenie zapisu na newsletter. Gdy relay jest niedostępny, wołający
  robi **fallback na PHP `mail()`** z envelope-from `-f`; DNS ma SPF, DKIM (selektor `x`)
  i DMARC (`p=quarantine`), więc fallback też przechodzi uwierzytelnienie.

Anulowanie subskrypcji adopcji dodatkowo ustawia w arkuszu „Status subskrypcji PayU" na „Anulowana"
i powiadamia fundację tym samym niezawodnym kanałem (`payu/sheet.php` -> Apps Script). Zależy
to od wgranego `assets/google-apps-script.gs` (Web App) i sekretu współdzielonego z PHP.
Uwierzytelniony SMTP pozostaje opcją docelową - świadomie odłożony, póki dzienny limit
Gmaila wystarcza.

## Panel CMS

Pod `/panel/` (logowanie: konta imienne w `panel/secret/users.php`, sesje + CSRF + throttling
sesyjny i per IP). Panel nazywa się **„Fundacja Misja MADA - Panel administracyjny"** i ma
wspólną górną nawigację modułów (`panel/layout.php`): **Wydarzenia · Sprawozdania ·
Subskrypcje · Adopcja Serca · Finanse** - aktywna zakładka wykrywana po nazwie skryptu.
Moduły:

- **Wydarzenia** - źródło prawdy `data/wydarzenia/<id>.json`; endpoint `events.js.php` emituje
  `window.MADA_EVENTS`; status (nadchodzące/archiwum) liczony z daty; zdjęcia do `uploads/`,
  filmy jako linki YouTube/Facebook; tłumaczenia EN/FR przez DeepL przy zapisie + glosariusz.
  Na **stronie głównej** sekcja wydarzeń (pod „Co robimy?", nad mapą Madagaskaru) dobiera formę
  automatycznie: gdy jest zaplanowane **nadchodzące** wydarzenie - duży blok „nadchodzące -
  wyróżnione" (wygląd jak na podstronie, klasa `.featured`); gdy są **tylko archiwalne** - siatka
  3 najnowszych relacji; gdy **brak** jakichkolwiek wydarzeń - sekcja się chowa. Wyróżnione =
  ręcznie oznaczone w panelu albo najbliższe nadchodzące.
- **Sprawozdania** - `data/sprawozdania.json`; PDF-y do `uploads/sprawozdania/`; render na
  `sprawozdania.html` i kaflach `o-nas.html`.
- **Subskrypcje** - podgląd płatności cyklicznych + ręczne anulowanie i **wznawianie** wstrzymanych
  (`paused` -> `active`, kolejne obciążenie zaplanowane na następny dzień). Anulowanie subskrypcji
  adopcyjnej zamyka też powiązane adopcje w module poniżej.

### Moduł „Adopcja Serca" (katalog `adopcja/` + strony `panel/`)

Pełne zarządzanie programem Adopcji Serca w MySQL (ta sama baza co PayU, `payu_db()`), zastępuje
ręczne arkusze „LISTA WSZYSTKICH DARCZYŃCÓW" i „PŁATNOŚCI":

- **Model** (`adopcja/db.php`, schemat idempotentny, CLI `php adopcja/migrate.php`): dzieci
  (`adopt_children`, klucz = Numer Dziecka), darczyńcy, adopcje (okres od-do, częstotliwość, metoda,
  powiązanie z subskrypcją PayU) i **wpłaty jako zdarzenia z zakresem miesięcy** (`period_from..period_to`) -
  z tego liczy się „opłacone do" i zaległości (`adopcja/lib.php`, czysta logika pod testami).
- **Strony panelu**: `adopcje.php` (dashboard: zalegają / wygasają), `darczyncy.php` + `darczynca.php`
  (karta z historią wpłat, szybką wpłatą i notatkami fundacji), `dzieci.php`, `wplaty.php`
  (**macierz miesięcy** jak w arkuszu - klik czerwonej komórki odnotowuje wpłatę), `zgloszenia.php`
  (zgłoszenia ze strony, przypisywanie dzieci), `finanse.php` (rejestr przepływów: zbiórki, wypłaty
  do Sióstr, wymiana walut), `eksport.php` (**wyjście awaryjne**: XLSX w układzie znanym fundacji
  + CSV), `import.php` + `import-lacz.php` (jednorazowa migracja - do usunięcia po jej domknięciu).
- **Ergonomia list** (wypracowana z fundacją): darczyńcy sortowani po **nazwisku**
  (`adopt_surname_key` - ostatni człon nazwy, tytuły „Ks." pomijane) z wyszukiwarką po nazwisku
  i e-mailu; klik w dowolne miejsce wiersza otwiera kartę darczyńcy / edycję dziecka
  (`<tr class="row-link" data-href>` obsłużone raz w `panel_footer()`); dzieci i darczyńcy
  edytowalni wprost z listy; formularz dodawania dziecka jest identyczny z formularzem edycji;
  macierz wpłat ma wybór okresu („rok RRRR" albo ostatnie 15 miesięcy) i suwak poziomy również
  **nad** tabelą.
- **Raty kartowe**: `payu/notify.php` przy `COMPLETED` dopisuje wpłatę do powiązanych adopcji
  (idempotentnie, kwota dzielona między dzieci); powiązanie subskrypcji w edycji adopcji robi
  **backfill** historycznych rat. Opłacona adopcja kartowa **sama zakłada darczyńcę i adopcje
  `pending`** (`adopt_ensure_from_card`, obie ścieżki: notify FIRST i sukces synchroniczny) -
  baza darczyńców rośnie automatycznie z obu dróg formularza; ręcznie dodaje się tylko dzieci.
- **Dossier dziecka** (wzór: PDF wysyłany darczyńcom): pola w Podopieczni -> Edytuj (pełne imię
  i nazwisko, data urodzenia, rodzice, liczba dzieci w rodzinie, opis sytuacji, zdjęcie do
  `uploads/dzieci/` z losową nazwą). Przy przypisywaniu dziecka pracownik może jednym
  checkboxem wysłać darczyńcy **mail-dossier** (szablon fundacji + opcjonalny osobisty dopisek).
  Skrót **„📧 Wyślij dossier"** przy adopcji na karcie darczyńcy wysyła ten sam mail bez wchodzenia
  w edycję (bez dopisku). Treść maila składa `adopcja/mail-dossier.php` - jedno źródło dla obu
  wejść, a każda wysyłka zostaje w dzienniku audytu (`adoption.childmail`).
  Osobnej flagi „materiały wysłane" **nie prowadzimy** (kolumna wycofana 2026-08-03): arkusz
  fundacji miał ją tylko dla grupy GR1, więc dla pozostałych grup pokazywała fałszywe „nie".
- **Przerwa i powrót darczyńcy**: „Zakończ" zamyka okres adopcji (miesiące po końcu nie liczą się
  jako zaległość), „Wznów" tworzy nowy okres - historia zostaje, przerwa nie generuje zaległości.
- **Migracja danych**: lokalny parser `tools/import/parse-adopcje.php` -> JSON -> `panel/import.php`
  (import idempotentny, w transakcji); niejednoznaczne wiersze rozwiązuje się ręcznie na
  `panel/import-lacz.php`. **Parser karmić PEŁNYM plikiem xlsx pobranym z Google Sheets**
  (`…/export?format=xlsx`) - eksport zakładek do HTML pomija kolumny ukryte w arkuszu, przez co
  opłacone miesiące wyglądają jak zaległości (przy pełnym xlsx dopasowanie wpłat urosło
  z 70 385 do 105 910 PLN). Tryb HTML pozostaje awaryjnym fallbackiem i ostrzega o tym w konsoli.
- **Rate-limit logowania per IP** (10 prób / 15 min, tabela `panel_login_attempts`) jako druga linia
  za throttlingiem sesyjnym + **audit log** zmian (`panel_audit_log`).

---

## Dokumenty prawne i prywatność

Polityka prywatności i oba regulaminy przeszły w lipcu 2026 audyt zgodności ze stanem
faktycznym strony oraz z umową zawartą z PayU (PR #39). Zasady, których pilnujemy:

- **Dokumenty opisują tylko to, co strona naprawdę robi.** Serwis nie ma narzędzi
  analitycznych ani marketingowych i nie ustawia własnych cookies u odwiedzających
  (jedynie `localStorage` z wyborem języka; cookie sesji wyłącznie w panelu CMS) - dlatego
  nie ma banera zgód i dokumenty tego nie obiecują. Jeśli kiedyś dojdzie analityka, nowe
  cookie albo nowa usługa zewnętrzna, **najpierw** zaktualizuj politykę prywatności
  i regulamin (plus daty aktualizacji i tłumaczenia EN/FR).
- **Odbiorcy danych wymienieni z nazwy** (PayU, Google, MailerLite, hosting, banki);
  transfer poza EOG opisany przez zabezpieczenia (EU-US Data Privacy Framework / SCC).
- **Badge „PayU" w modalach darowizn i adopcji jest linkiem do poland.payu.com - nie
  usuwać i nie zamieniać z powrotem na zwykły napis.** Umowa z PayU wymaga na stronie
  akceptanta znaku PayU połączonego z linkiem do strony PayU.
- Klauzula podatkowa w regulaminie opiera się na uldze z art. 26 ust. 1 pkt 9 ustawy o PIT.
  Fundacja **nie ma statusu OPP** - nie pisać, że ma (ani sugerować 1,5% podatku), dopóki
  go nie uzyska.
- Log notyfikacji PayU (`data/payu-notifications.log`) nie zapisuje e-maili darczyńców
  i jest przycinany do 12 miesięcy przez cron (dokumentacja transakcji na potrzeby
  ewentualnych reklamacji żyje w bazie MySQL, nie w logu).
- Wiążąca jest polska wersja dokumentów (nota `doc-binding` - patrz sekcja i18n wyżej).

---

## Rozwój lokalny

Wymagany PHP 8. Serwis to zwykłe pliki - wystarczy serwer PHP:

```bash
php -S 127.0.0.1:8099        # w katalogu repo, potem http://127.0.0.1:8099/index.html
```

Backend PayU/panel wymaga lokalnie plików `*/secret/` (config bazy, PayU, DeepL) - na czysto
front i logika renderują się bez nich (endpointy zwrócą błąd konfiguracji, co jest oczekiwane).

## Testy i CI

```bash
php tests/run.php               # czysta logika panelu CMS (slug, walidacje, sprawozdania)
php tests/run-recurring.php     # czysta logika płatności cyklicznych (harmonogram, idempotencja,
                                # ekstrakcja tokena, decyzja o obciążeniu)
php tests/run-sheet.php         # payloady PHP -> arkusz (adopcja-charge, adopcja-cancel)
node tests/run-apps-script.js   # logika Apps Script na atrapach Google API: double opt-in,
                                # raty i anulowanie kartowe, migracja arkusza i jej idempotencja
node tests/i18n-coverage.js     # pokrycie tłumaczeń EN/FR
```

Runner Apps Script istnieje, bo `assets/google-apps-script.gs` wdrażamy **ręcznie** (wklejenie
w edytorze), a jego funkcje ruszają produkcyjne dane fundacji - bez niego jedyną weryfikacją
byłoby uruchomienie na żywym arkuszu.

CI (`.github/workflows/ci.yml`) przy każdym pushu/PR: `php -l` na wszystkich `.php`,
`node --check` na `assets/*.js` oraz wszystkie runnery testów. Niezależne od deployu;
`tests/` nie trafia na produkcję.

## Sekrety i dane (poza repo)

Nigdy nie ma ich w repo ani w deployu; muszą istnieć na serwerze:

- `payu/secret/` - `db-config.php` (baza), config PayU (client_id/secret, klucz podpisu).
- `newsletter/secret/`, `panel/secret/` - konfiguracja newslettera; `users.php` (hasła redaktorek),
  `deepl-config.php` (klucz DeepL).
- `data/` - treść CMS (wydarzenia, sprawozdania). `uploads/` - wgrane zdjęcia i PDF-y.

Dostęp do `data/` i `*/secret/` z weba jest zablokowany (`.htaccess` w repo + na serwerze),
a pliki `*.log`/`*.sql` odmawiane. **Uwaga:** `data/` i `uploads/` żyją tylko na serwerze -
warto je okresowo backupować (DirectAdmin / backup hostingu).

---

## Dane fundacji

- **Fundacja Misja MADA**, ul. Szosa Chełmińska 271A, 87-100 Toruń
- `kontakt@misjamada.pl` · tel. 604 181 301 / 690 623 252 · [misjamada.pl](https://misjamada.pl)
- KRS 0001099359 · NIP 9562392375 · REGON 528347054
- Konta: PLN `70 1090 1056 0000 0001 5832 5871` · EUR `PL49 1090 1056 0000 0001 6067 9663`
  · GBP `PL34 1090 1056 0000 0001 6645 4246` (bank: Erste Bank Polska S.A., SWIFT WBKPPLPP)
- Facebook: facebook.com/MisjaMADA
- Partnerzy: Pallotyńska Fundacja Misyjna Salvatti, Stowarzyszenie MISEVI Polska,
  Siostry Małe Misjonarki Miłosierdzia (Siostry Orionistki)

## Konwencje redakcyjne

Bez długich myślników (tylko `-`), „Msza Święta" wielką literą, pełna nazwa
„Siostry Małe Misjonarki Miłosierdzia (Siostry Orionistki)", „Centrum Edukacyjne".
Autor witryny: [tomaszkwietniewski.pl](https://tomaszkwietniewski.pl).
