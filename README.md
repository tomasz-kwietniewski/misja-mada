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
│   ├── mail.php             maile transakcyjne (relay przez Gmail/Apps Script, fallback mail())
│   ├── sheet.php            zapis do arkusza Google + sync anulowania adopcji (przez Apps Script)
│   └── migrate.php          migracja bazy (CLI)
│
├── newsletter/             własny double opt-in + MailerLite (PHP)
│   ├── subscribe.php, confirm.php, lib.php, confirm-email.html
│
├── panel/                  panel CMS (PHP, logowanie + CSRF)
│   ├── index.php, login.php, auth.php, layout.php, lib.php, panel.css
│   ├── edit.php, save.php, delete.php, upload.php, media.php, categories.php
│   ├── translate.php, glossary.php    tłumaczenia DeepL + glosariusz
│   ├── sprawozdania.php, sprawozdania-upload.php, sprawozdania-delete.php
│   └── subskrypcje.php     podgląd subskrypcji + ręczne anulowanie
│
├── events.js.php, sprawozdania.js.php   endpointy emitujące dane CMS na front
├── media/                  zdjęcia, logo, PDF-y (płaska struktura)
├── tests/                  run.php (logika CMS), run-recurring.php (logika płatności)
└── .github/workflows/      deploy.yml (SEO Host), ci.yml (lint + testy)
```

Nie są w repo (żyją tylko na serwerze, poza deployem): katalogi `data/`, `uploads/`
oraz wszystkie `*/secret/` (patrz „Sekrety i dane").

---

## Wielojęzyczność (PL / EN / FR)

Polski jest oryginałem. `assets/i18n.js` po przełączeniu języka podmienia teksty w węzłach DOM
na podstawie słowników (`i18n-dict.js` = EN, `i18n-dict-fr.js` = FR), gdzie **kluczem jest
tekst polski**. Brak wpisu = tekst zostaje po polsku (bezpieczny fallback). Wybór języka
zapamiętywany w `localStorage`, wspólny dla podstron.

---

## Płatności PayU

POS produkcyjny **4432411**, sklep `misjamada.pl`. Wszystkie sekrety (OAuth `client_secret`,
klucz podpisu, dane bazy) są po stronie serwera w `payu/secret/` - nigdy w przeglądarce.

### Jednorazowe (live)
`payu/create-order.php`: walidacja -> OAuth -> OrderCreate -> zwrot `redirectUri`.
Karta wpisywana **na stronie PayU** (hosted redirect), 3DS automatyczne, zero danych karty
u nas. Status potwierdza `payu/notify.php` (weryfikacja podpisu).

### Cykliczne (etap 6, live)
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
   (ochrona przed podwójnym obciążeniem).
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

`assets/adopcja-form.js` i formularz kontaktowy wysyłają dane do backendu w Google Apps Script
(`assets/google-apps-script.gs`) - kontakt: mail do fundacji z `Reply-To`; adopcja przez przelew:
double opt-in + dane do przelewu na ekranie sukcesu. E-mail fundacji: `kontakt@misjamada.pl`.

## Dostarczalność e-maili (relay Gmail)

Poczta wychodząca z serwera przez PHP `mail()` bywała łapana jako spam. Dlatego maile
transakcyjne (potwierdzenia i powiadomienia subskrypcji, potwierdzenie zapisu na newsletter)
idą przez **relay w Apps Script** (`type=relay` -> `GmailApp`, uwierzytelniony Gmail
`kontakt@misjamada.pl`), a `mail()` zostaje jako **fallback**, gdyby relay był niedostępny.
Anulowanie subskrypcji adopcji dodatkowo aktualizuje wiersz w arkuszu Google na „anulowana"
i powiadamia fundację tym samym niezawodnym kanałem (`payu/sheet.php` -> Apps Script). Zależy
to od wgranego `assets/google-apps-script.gs` (Web App) i sekretu współdzielonego z PHP.

## Panel CMS

Pod `/panel/` (logowanie: konta imienne w `panel/secret/users.php`, sesje + CSRF + throttling).
Redaktorzy zarządzają dwoma typami treści:

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
- **Subskrypcje** - podgląd płatności cyklicznych + ręczne anulowanie.

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
php tests/run.php             # czysta logika panelu CMS (slug, walidacje, sprawozdania)
php tests/run-recurring.php   # czysta logika płatności cyklicznych (harmonogram, idempotencja,
                              # ekstrakcja tokena, decyzja o obciążeniu)
```

CI (`.github/workflows/ci.yml`) przy każdym pushu/PR: `php -l` na wszystkich `.php`,
`node --check` na `assets/*.js`, oraz oba runnery testów. Niezależne od deployu;
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
