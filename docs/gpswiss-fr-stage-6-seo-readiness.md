# GPSwiss.fr — Etap 6: SEO, wyszukiwanie i gotowość

## Audyt przed zmianą

- Layout miał `lang` zależny od locale oraz canonical równy `url()->current()`. Nie było hreflang, normalizacji hosta HTTPS/aliasów produktu/kategorii ani meta robots.
- Katalog miał polski title/description także na domenie FR. Produkt/kategoria czytały istniejące gotowe tłumaczenia, z fallbackiem do źródła. Pusta treść produktu mogła dawać pustą meta description.
- W repo nie ma publicznego robots.txt, sitemap ani konfiguracji rewrite `.htaccess`. Istniejący rewrite działa na właściwych publicznych trasach; w tym etapie nie zmieniamy konfiguracji serwera.
- `SearchController` tylko przekierowuje `/szukaj` na `/czesci`. `Part::searchStorefront` szuka tokenów wyłącznie w nazwie źródłowej, z osobną ścieżką numerów części. Tłumaczenia nie brały udziału w wyszukiwaniu.
- Strony zamówienia/konta miały częściowo nagłówki prywatności, ale checkout/auth/cart wymagały spójnego noindex/no-store, a layout nie miał meta robots.
- CartService i domyślny wynik CartController zawierały polskie komunikaty sesji. FR karty produktu obiecywały darmową dostawę następnego dnia, sprzeczną z wyceną FR. Maintenance był polski; domyślne strony błędów nie gwarantowały języka FR. Rejestracja pokazywała opcjonalny polski identyfikator NIP.

## Wprowadzone SEO

Publiczne home, katalog, kategorie, produkty, kontakt, CGV i privacy:

- FR zachowuje `lang=fr`, title/description FR i canonical HTTPS na `gpswiss.fr`.
- Produkt po aliasie numeric ID kanonizuje do slugu (jeśli istnieje); kategoria do publicznej ścieżki. Parametry tracking/signature nie trafiają do canonical.
- Whitelist rzeczywistych odpowiedników ma alternates `pl`, `fr` i `x-default` na PL. Każdy zestaw wskazuje obie canonical wersje. PL otrzymuje odwrotne hreflang i normalizację canonical dla tych publicznych stron; UI, ceny i logika PL nie zmieniają się. Pozostałe wybrane języki PL są wariantami sesji, bez odrębnych publicznych URL: nie generujemy dla nich fałszywych alternates.
- Nie ma hreflang na stronach filtrowanych, prywatnych, błędach ani technicznych. Pagination zachowuje `?page=N` w canonical i alternates. Filtry/sortowanie mają FR `noindex, follow`, canonical bazowej strony bez filtrów.
- FR cart/checkout/auth/reset/account/order/return mają meta i nagłówek `noindex, noarchive`, `private, no-store`, `no-referrer`. To obejmuje legacy aliasy PL na domenie FR. Signed access nadal autoryzuje odczyt; SEO nie zastępuje podpisu.
- Wersja www przekierowuje przez istniejący kod na apex. Certyfikat i zachowanie vhost/reverse proxy trzeba zweryfikować po deployu; kod nie instaluje SSL ani domeny.

### Sitemap i robots

`https://gpswiss.fr/sitemap.xml` to indeks:

- `/sitemaps/pages.xml`: home, katalog, kontakt, CGV, privacy i publiczne kategorie z tego samego CategoryTreeService, który obsługuje listingi. Ścieżki bez slugu lub rozwiązujące się do innej kategorii są pomijane; nie generujemy fikcyjnych tras po category ID. Sprawdzenie kolumny widoczności w resolverze jest zapamiętane w obrębie instancji, aby sprawdzanie listy nie wykonywało zapytań schema dla każdego porównania.
- `/sitemaps/products-N.xml`: po 1000 produktów, ten sam `storefrontVisible` co frontend, canonical slugi/ID, lastmod z updated_at. Sprzedane/niedostępne produkty nie trafiają do sitemap. Są to odczyty lokalnej bazy, bez API i bez komend/schedulera. Indeks odzwierciedla bieżącą liczbę widocznych produktów; między pobraniami może ona się zmienić.
- Bez koszyka, checkoutu, konta, signed URLs, login/reset, admina i technicznych ścieżek. Endpointy istnieją wyłącznie na FR; nie dodajemy sitemap/robots ani statycznych plików zmieniających PL.
- Kategorie zachowują istniejący publiczny cache drzewa (do 10 minut). Nie czyścimy cache ani checkpointów. Widoczność używa istniejącej polityki aplikacji, która nie wymaga `is_visible_storefront=true`; nie zmieniamy tej polityki w SEO.

`https://gpswiss.fr/robots.txt` reklamuje indeks FR i blokuje ścieżki prywatne/techniczne oraz wyszukiwanie/sortowanie. Nie blokuje bazowego katalogu, produktów, kategorii ani sitemap. Robots nie jest kontrolą dostępu. Przy aktywnym maintenance robots działa, sitemap/publiczny sklep odpowiada 503; nie publikuj sklepu w maintenance.

Statyczny robots.txt w produkcyjnym DocumentRoot lub reguły serwera/CDN mogą wyprzedzić routing Laravel. Repo nie pozwala potwierdzić ich stanu: po deployu sprawdź faktyczną odpowiedź przez przeglądarkę. Nie kopiuj FR robots globalnie na PL.

## Wyszukiwanie i teksty FR

- FR `q` szuka w publicznych (`translated`/`reviewed`) nazwach produktu i kategorii, wymagając wszystkich tokenów. Źródłowa nazwa danego obiektu jest fallbackiem wyłącznie przy braku niepustej gotowej nazwy FR. Failed/needs_update/queued/missing nie są wyszukiwane jako tłumaczenia.
- Filtr `category` akceptuje istniejący slug i nazwę FR. OEM/part_number zachowują dotychczasową ścieżkę. Pozostałe filtry/sortowanie działają jak wcześniej; np. sort po name nadal korzysta z kolumny źródłowej, nie zmieniamy sortowania w tym PR.
- PL pozostaje na dotychczasowym searchStorefront, także gdy klient PL wybierze język FR. Nie zmieniamy globalnego modelu Part, wyszukiwania admina ani marketplace.
- Katalog ma metadane FR; komunikaty dodania/aktualizacji/niedostępności koszyka są po francusku tylko dla FR. Uzupełniono francuskie komunikaty walidacji używane przez formularze.
- FR karta produktu informuje, że dostawa do Francji jest wyliczana w koszyku. Usunięto z FR obietnicę darmowej dostawy następnego dnia i godzinę graniczną; nie zmieniono stawek ani resolvera.
- Home FR nie sugeruje lokalizacji firmy w Polsce. Rejestracja FR nie pokazuje opcjonalnego pola NIP; backend, konto i walidacja PL pozostają bez zmian.
- Publiczne błędy HTML FR 403/404/419/500/503 i maintenance mają samodzielny widok francuski, kontakt FR i noindex. Widok nie wymaga bazy/katalogu i nie pokazuje szczegółów wyjątku. Obsługa JSON, webhooków, admina i operacyjnych tras nie jest zastępowana tym widokiem.

### Pozostałe miejsca wymagające kontroli treści

| Obszar | Stan / ograniczenie |
| --- | --- |
| Nazwy/opisy produktu/kategorii, condition_notes | Gotowe tłumaczenia są używane. Brakujące, failed i needs_update mogą pokazać polski fallback; runner i zapisane dane nie były zmieniane. Poczekać na zakończenie i sprawdzić reprezentatywne produkty. |
| Dane techniczne samochodu/OEM/SKU, historyczne etykiety i alt_text zdjęć | Źródłowe dane mogą zawierać PL. Nie tłumaczymy ani nie przepisujemy danych katalogu w tym PR. |
| Ceny publicznego katalogu/karty produktu | Publiczny FR używa tego samego FrenchPriceResolver co checkout: parts.price w EUR albo jednoznaczna lokalna cena eBay FR EUR po part_id. Brak ceny daje Prix indisponible, bez fallbacku PLN i z zatwierdzonym fallbackiem NBP (patrz gpswiss-fr-nbp-pricing.md). Brak ceny lub potwierdzonej dostawy blokuje przycisk oraz POST dodania do koszyka FR. Sprawdzić aktualność źródłowych danych EUR; wyświetlanie nie aktualizuje cen ani ofert. |
| Reset hasła / Google login | Formularze mają etykiety FR, ale frameworkowy mail resetu, domena linku, nadawca i integracja Google wymagają osobnego potwierdzenia. Nie wykonywano Google ani wysyłki resetu. Konta współdzielą istniejący system users. Nie są automatycznie przypinane gościnne zamówienia po e-mailu. |
| Account/order preview/Stripe return | Etykiety statusów FR i signed dostęp z Etapu 5. Brak maili ręcznej zmiany statusu i nowego flow zwrotów. |
| Maile Order FR | Francuskie widoki, dane FR, idempotentny rejestr. Faktyczna dostarczalność zależy od SMTP; `MAIL_MAILER=log` nie dostarcza wiadomości. |
| API info, admin i narzędzia | Nie są publicznymi landingami FR. Mogą mieć PL/EN; nie są w sitemap, robots blokuje techniczne ścieżki. Nie zmieniamy panelu/integracji w tym etapie. |
| CGV/privacy | Francuska tożsamość i adres. Nadal placeholder mediatora, hosting oraz finalna lista cookies/dostawców z Etapu 4. Nie uzupełniamy nieznanych danych fikcyjną treścią. |

## Gotowość: co potwierdza kod, co nadal blokuje live

Gotowe w kodzie i testach: host/locale FR, publiczne trasy, katalog/tłumaczenia/fallbacki, search FR, SEO/sitemap/robots, dane prawne FR, zabezpieczenie prywatnych stron, jedna sztuka/jedna pozycja, ceny EUR z zatwierdzonym fallbackiem NBP (patrz gpswiss-fr-nbp-pricing.md), mapowanie dostawy FR 55/70/130, izolacja PayU PL, Stripe session i zweryfikowany/idempotentny webhook, snapshot Order FR, maile i signed link, historia realnych Order.

To jest audyt repo, nie potwierdzenie aktywnej konfiguracji/danych produkcji. Nadal warunki przed live:

1. Zakończone tłumaczenia oraz przegląd fallbacków/failed/needs_update. Nie uruchamiaj nowego runu w ramach wdrożenia SEO.
2. Zatwierdzone/kompletne dokumenty prawne: mediator, hosting, faktyczne cookies/dostawcy i zachowanie consent. Zewnętrzne fonty Google/asset services już występują w layoutach; zweryfikować ich rolę przy inwentaryzacji, bez wywoływania API.
3. Rzeczywista kompletność i aktualność cen EUR oraz mapowań dostawy dla sprzedawanych produktów. Braki blokują dodanie do koszyka i checkout FR; karty i checkout korzystają z tego samego źródła EUR.
4. Autoryzowany SMTP dla contact@gpswiss.fr, SPF/DKIM i kontrola dostarczalności/odbicia. Niepewne sending/uncertain z Etapu 5 nie są automatycznie ponawiane.
5. Domena/SSL/www, poprawny rewrite wszystkich tras, faktyczne robots/sitemap bez statycznego nadpisania, brak maintenance i APP_DEBUG w produkcji. Cookies sesji muszą działać na gpswiss.fr (nie mogą być przypięte wyłącznie do gpswiss.pl), a kart/session ma przetrwać reload i powrót z Stripe. Sprawdzić HTTPS/Secure/SameSite przez istniejącą konfigurację; nie zmieniamy .env.
6. Udany end-to-end Stripe TEST, poprawne dane sprzedawcy/VAT/konta Stripe, webhook właściwego trybu, monitoring błędów i obsługi stock conflict/refund. Tryb testowy nie realizuje sprzedaży/stocku; nie przełączać na live podczas tego testu.

## Instrukcja po deployu — operator korzysta z przeglądarki

Standardowy deploy samego kodu tego etapu, bez nowych migracji/workerów i bez ręcznych komend operatora. Zachować APP_KEY, cache/checkpointy i dotychczasową konfigurację płatności. Ten PR nie został wdrożony z Codexa.

1. Sprawdź `https://gpswiss.fr/` oraz www/apex po HTTPS; www ma 301 do apex. Sprawdź też trwałość koszyka i logowania po reload (cookie sesji FR). Nie wystarczy działający home: otwórz `/czesci`, kategorię, produkt, `/panier`, `/commande`, `/kontakt`, legal.
2. W źródle HTML sprawdź lang=fr, FR title/description/canonical i trzy hreflang na publicznych odpowiednikach. Otwórz każdy wskazany URL PL/FR, także przykładową kategorię i produkt. Strony prywatne/filtrowane nie mają hreflang; prywatne mają noindex/noarchive, w Network private/no-store/no-referrer.
3. Otwórz robots.txt i sitemap.xml. W indeksie otwórz pages.xml oraz products-1.xml i ostatni products-N.xml. URL-e są HTTPS FR; brak prywatnych ścieżek/podpisów. Po potwierdzeniu treści i zakończeniu tłumaczeń zgłoś sitemap w Search Console dla FR (nie robimy zgłoszenia w tym PR).
4. Wyszukaj tytuł FR, nazwę kategorii FR i numer OEM. Sprawdź wynik i dostępność produktu. Na PL sprawdź dotychczasowy tytuł PL/OEM oraz wygląd/metadane/koszyk; nie wykonuj realnej płatności PayU w tym audycie.
5. Sprawdź błędny URL FR (francuskie 404) i dostęp do order po numeric ID bez podpisu (403 bez danych). Nie testuj awarii produkcji ani nie wymuszaj wyjątku 500.
6. Zweryfikuj widoczne copy, dostawę, ceny/waluty, kontakty i fallbacki dla kilku produktów/kategorii. Sprawdź konto w zwykłym i prywatnym oknie; bez podpisu/autoryzacji nie wolno ujawnić danych.

## Finalny scenariusz sprzedaży TEST — po zakończeniu tłumaczeń

1. Stripe pozostaje `STRIPE_FR_MODE=test`, klucze test i poprawny webhook. Nie zmieniaj cen/mapowań/stocku, aby wymusić test. Wybierz istniejący produkt dostępny publicznie z zatwierdzoną ceną EUR i mapowaniem FR.
2. Produkt → panier: jedna pozycja/ilość 1. Sprawdź nazwę FR, cenę EUR, dostawę 55/70/130 z właściwego mapowania i total.
3. Commande: pełny adres FR, CGV, brak pobrania/odbioru/NIP. Brak EUR/dostawy lub >1 sztuka musi dać kontrolowaną blokadę, bez zamówienia i provider call.
4. Rozpocznij Stripe Checkout: jeden Order FR/locale fr/EUR/stripe i jeden awaiting mail. Powtórzony submit tej samej próby nie tworzy niezależnego zamówienia/maila.
5. Karta TEST 4242 4242 4242 4242, przyszła data, dowolny CVC. Return nie potwierdza płatności sam; poprawny webhook zmienia paid. W Dashboard sprawdź delivery 200; resend eventu nie powtarza paid maila ani realizacji.
6. W adminie: źródło GPSwiss.fr, Stripe, EUR, poprawny snapshot, paid i test on_hold. Ilość/status produktu nie zmieniają się w test mode; nie wysyłaj towaru.
7. Skrzynka klienta: nadawca/dane FR, mail paid po webhooku, podpisany link FR. Otwórz link bez logowania: aktualny status po francusku. Zmieniony podpis/sam ID nie ujawnia danych.
8. Dla zakupu zalogowanego: historia na `/moje-konto` zawiera właściwy Order FR, bez PL/cudzych/ShopEvent. Gość używa mailowego signed linku.
9. Osobna sesja testowa przerwana w Stripe: powrót nie anuluje Order; można wznowić tę samą sesję. Po rzeczywistym expired webhooku jeden mail expired, bez paid. Nie wywołujemy tych zdarzeń z Codexa.

## Pliki/odpowiedzialności i testy

- `StorefrontSeo`, partial `storefront/partials/seo`, layout: canonical/hreflang/meta robots.
- `FrenchStorefrontSeoHeaders`, bootstrap exception response, `FrontendMaintenanceMode`, widok FR error: nagłówki prywatności i język błędów/maintenance.
- `FrenchSitemapController`, routes/web.php, sitemap-index/sitemap-urls: sitemap i robots FR.
- `FrenchCatalogSearch`, `BuildsStorefrontQueries`: FR wyszukiwanie i filtr kategorii; Part::searchStorefront PL bez zmian.
- CatalogController/PartController: metadane FR; CartService/CartController: wyłącznie komunikaty FR; home/product-card/register: copy FR bez zmian stawek/cen.
- lang/fr/validation.php: brakujące tłumaczenia walidacji.
- FrenchStorefrontSeoTest/FrenchStorefrontSearchTest: SEO, XML/robots, izolacja PL, prywatne strony, wyszukiwanie i teksty.

Testy wykonują wyłącznie lokalne operacje na SQLite, Http::preventStrayRequests i Mail::fake. Bez Google, realnych płatności/maili, produkcyjnych zapisów, deployu ani zmian marketplace/stocku/runnera. Liczby końcowego zestawu są zapisane w PR.

Dodatkowe sprawdzenie StorefrontCategorySidebarTest ujawniło dwa wcześniejsze błędy: oczekiwanie selected w opcji kategorii głównej (linia 67) oraz brak pełnej nazwy kategorii w całym HTML (linia 198, podczas gdy występuje w metadanych). Te same dwa błędy odtworzono na czystym origin/main po #1479. Nie zmieniano testów ani zachowania sidebaru w celu ich ukrycia. Pozostają osobnym zadaniem bazowym.

## Poprawka publicznych cen FR po Etapie 6

Home/listingi/kategorie/powiązane karty oraz strona produktu używają FrenchProductPresentation z niezmienionymi FrenchPriceResolver i FrenchDeliveryQuoteService. EUR produktu ma pierwszeństwo; fallbackiem może być wyłącznie jednoznaczna aktywna lokalna oferta eBay FR w EUR, po part_id. Po zatwierdzonej zmianie biznesowej PLN może być przeliczane przez wspólny moduł NBP; szczegóły i limity w gpswiss-fr-nbp-pricing.md. PLN nie jest wyświetlane ani podpisywane jako EUR bez przeliczenia. Brak lub sprzeczność daje Prix indisponible. Brak/blokada mapowania dostawy daje Livraison en France indisponible pour cet article. Nie deklarujemy darmowej dostawy ani gotowości wysyłki przed 13:30. Słownik JS FR pomija historyczne komunikaty PL o dostawie/0 PLN; słownik PL pozostaje bez zmian.

Zmiana nie wymaga migracji ani workera. Po zwykłym deployu sprawdź produkt z EUR, produkt z lokalną ceną eBay FR EUR i produkt bez EUR; sprawdź też dostawę bez mapowania. Nie twórz nowego runu tłumaczeń ani nie czyść checkpointów. Publiczne filtry/sort po cenie nadal opierają się na kolumnie źródłowej Part, co wymaga oddzielnego etapu dla pełnej zgodności filtrowania/sortowania EUR; ten PR nie zmienia zapytań cenowych, cenników ani stawek.

Aktualizacja po #1481: nowa zatwierdzona polityka existing_eur_nbp używa NBP jako ostatniego fallbacku dla PLN; neutralny komunikat to Frais de livraison calculés au panier. Szczegóły: [gpswiss-fr-nbp-pricing.md](gpswiss-fr-nbp-pricing.md).
