# Nowe produkty GPSwiss — audyt PL/FR i zgłoszenia tłumaczeń

Baza audytu: main `9ffb37101e9eb33d4c800823f9033acefbec9b6c` (po #1486, #1487 i #1488). To audyt kodu i testów; w tej sesji nie było dostępu do produkcyjnej bazy ani konfiguracji serwera. Nie wykonano produkcyjnych zapisów, deployu, Google/marketplace API ani rzeczywistych płatności.

## Audyt przed poprawką

| Pytanie | Obecne zachowanie na main |
| --- | --- |
| Produkt widoczny w PL → widoczny w FR? | Tak dla zwykłego Part, bez osobnej ręcznej widoczności FR. Katalog, karta i sitemap korzystają ze wspólnego scope. Wyniki wyszukiwania mogą różnić się dla tego samego zapytania, ponieważ FR szuka po gotowych nazwach FR. |
| Dokładnie te same kryteria widoczności? | Tak: status=ready, needs_listing=false, needs_review=false lub NULL, price>0, stock quantity>0. Sam checkbox is_visible_storefront nie jest kryterium tego scope. Zaznaczony checkbox przy draft nie publikuje produktu na żadnym sklepie; nie zmieniamy tej istniejącej polityki. |
| Nowy Part trafia do tłumaczeń? | Tak, istniejący preview skanuje wszystkie Part i rozpoznaje brak wiersza FR jako missing. Nie ma automatycznego zgłoszenia/wykonania po zapisie. |
| Automatyczne tłumaczenie? | Nie. Brak observera/created/updated/saved wywołującego tłumaczenia. Google uruchamia dopiero zatwierdzony istniejący runner. |
| Zmiana source fields → needs_update? | Source hash wykrywa ją, ale wcześniej oznaczenie nie następowało od razu przy zapisie. Translator mógł oznaczyć stan dopiero przy przetwarzaniu. Reviewed jest chronione. |
| Masowy import i koszt Google? | Model Part nie uruchamia Google, więc sam zapis/import nie generuje kosztu tłumaczeń. Dotychczasowy zatwierdzony pełny runner jest osobną operacją. |
| Cena EUR od razu? | Tak: dodatnia parts.price(EUR), następnie jednoznaczna aktywna lokalna cena eBay FR EUR, następnie dodatnia cena PLN / NBP. Bez narzutu +25% dla zwykłych Part. |
| Stary NBP? | Resolver odrzucał brak/niepoprawny/przyszły/starszy niż 7 dni kurs, lecz rateFor mogło zwrócić stary, nadal niewygasły cache bez odświeżenia. To poprawiamy przez istniejące currentEurPln. |
| Brak NBP / dostawy? | Blokuje cenę/zakup FR, nie wspólną widoczność produktu. PL pozostaje niezależne od NBP FR. Dostawa to obecne mapowania 55/70/130 EUR; nie ma nowej stawki domyślnej. |
| Fallback treści? | Tak: pola translated/reviewed mają pierwszeństwo; bez gotowego niepustego pola działa źródło. Pending/queued/needs_update nie jest gotową treścią. |
| Sitemap FR? | Produkty pojawiają się na dynamicznym sitemap po spełnieniu wspólnej widoczności. Tłumaczenie, NBP i mapowanie dostawy nie warunkują wpisu. |
| Zdjęcia? | PL/FR czytają te same Part.images i URL-e/wersje prezentacyjne; nie ma drugiego importu/aktywacji zdjęć dla FR. Zdjęcie musi zostać dodane/zsynchronizowane przez istniejący panel. |
| Kategorie? | Gotowe category_translations są używane, bez nich działa fallback. Dodatkowo cache drzewa (600 s) mógł ukrywać pierwszą część w dotychczas pustej kategorii i powodować przejściowe 404 jej strony. |

Pliki odczytu: Part::scopeStorefrontVisible, BuildsStorefrontQueries, CatalogController, CategoryController, PartController, FrenchSitemapController, FrenchCatalogSearch, PartCategory, CategoryTreeService, HasStorefrontTranslations, FrenchPriceResolver, FrenchDeliveryQuoteService, FrenchProductPresentation, FrenchCheckoutService i StripeFrCheckout. Kryteria visibility, wyszukiwanie, checkout, Stripe webhook/settlement, PayU, stock oraz marketplace pozostają bez zmian. Jarek gearbox zachowuje odrębną ekspozycję FR z poprzedniego PR — ten mechanizm obejmuje tylko zwykłe Part.

## Wybrany bezpieczny wariant

Automatyczne jest **zgłoszenie i wykrycie zmiany**, a nie płatne Google wywołane przy każdym zapisie. To wariant a/b/c dopuszczony w wymaganiach: produkt od razu dostępny według wspólnych kryteriów, z fallbackiem, a operator zatwierdza ograniczoną partię nowych/zmienionych produktów. Nie musi pilnować każdego produktu osobno. Pełne autonomiczne tłumaczenie po zapisie nie jest włączone, aby import nie uruchomił tysięcy kosztownych żądań.

- Observer Part created/updated rejestruje lokalne zgłoszenie w `storefront_fr_product_translation_requests` (jeden wiersz na Part). Reaguje na name, short_description, description, condition_notes. Nie dispatchuje jobów i nie kontaktuje się z Google.
- Źródłowe pola zmienione w translated powodują natychmiastowe needs_update. Reviewed zachowuje treść, status i hash; zgłoszenie ma needs_review i jest wykluczone z automatycznej partii. Zatwierdzona stara treść pozostaje widoczna według obecnej polityki do świadomej kontroli.
- Queued jest własnością istniejącego translatora; observer nie zmienia jego claimu. Translator wykrywa zmianę przed zatwierdzeniem wyniku. Nowy runner nie przejmuje cudzych queued.
- Zmiany ceny/stocku bez source fields nie tworzą nowego zadania tłumaczenia. Wspólny cache kategorii jest unieważniany po utworzeniu produktu oraz zmianie kategorii/kryteriów widoczności. Nie zmienia to danych produktu ani samych kryteriów.
- Błąd opcjonalnego zgłoszenia nie zrywa zapisu produktu. Log zawiera `fr_product_translation_registration_failed`, Part ID i typ wyjątku, bez źródłowej treści/sekretów. Brak migracji blokuje nową sekcję, lecz katalog/fallback i zapis produktu nadal działają.
- Nowy browser runner używa istniejącego FrenchCatalogTranslationService i trwałego RunStore ze scope=new_products. Oryginalne scope catalog/jarek i ich checkpointy/JS nie są zastępowane. Korzysta z tego samego locka na produkt co istniejący translator.
- Każdy zatwierdzony run jest ograniczony do max 100 produktów i max 50 000 znaków źródła. Konfiguracja może tylko obniżyć twarde limity. Jeden produkt na tick; brak automatycznego uruchamiania następnej partii. Tick co ok. 6 s po odpowiedzi; osobny limiter 15 żądań/min nie zużywa wspólnego limitu starych runnerów.
- Start wymaga własnego dry-run (godzina ważności), tokena i niezmienionego manifestu source hash. Reviewed nigdy nie trafia do selekcji. Snapshot, liczniki, wyniki i claim in-flight są trwałe przed API. Dodatkowy expected_source_hash jest sprawdzany wewnątrz blokady translatora przed claimem/API.
- Błąd zatrzymuje run. Nie ma automatycznego ponawiania Google ani backoffu w mini-runnerze. Failed można włączyć wyłącznie do nowego świadomie zatwierdzonego dry-run. Niepewna przerwana próba jest raportowana jako interrupted_attempt zamiast ponownego obciążenia. Trwały gotowy wynik po przerwanym checkpointcie jest liczony raz, bez API.
- Pause/Stop nie cofa rozpoczętego żądania providera; jego wynik może się zapisać, ale nie uruchamia następnego produktu. Po odświeżeniu strony wymagane jest Wznów, żeby przeglądarka kontynuowała ticki.

## Cena i dostawa

FrenchPriceResolver używa teraz istniejącego NbpExchangeRateService.currentEurPln: poprawny cache jest używany, pusty/stary/niepoprawny odświeżany zwykłą ścieżką NBP. Ponowna walidacja w resolverze nadal odrzuca błędny kurs. Karta/listing/koszyk/checkout/Stripe czytają ten sam resolver. Nie zapisujemy EUR do Part, nie zmieniamy źródłowej ceny ani pól marketplace i nie nakładamy narzutu eBay.

NBP może być pobierane podczas odczytu ceny FR; nie jest pobierane przez observer zgłoszeń. Brak kursu daje controlled unavailable i blokuje zakup, ale dodatnia cena źródłowa/stock nadal pozwalają pokazać produkt. Konfliktujące aktywne ceny eBay FR EUR lub waluta inna niż EUR/PLN również wymagają wyjaśnienia źródła ceny. Dostawa bez mapowania blokuje FR zakup; zachowujemy dotychczasowe mapowania.

## Instrukcja operatora

1. Wdrożyć zatwierdzony PR z migracją `2026_10_14_000003_create_fr_product_translation_requests.php` po wcześniejszych migracjach tłumaczeń/trwałych runów. Migracja nie uruchamia Google, nie publikuje ani nie zmienia Part. Nie backfilluje całego starego katalogu.
2. Dodać produkt normalnie w panelu. Sprawdzić wspólne kryteria widoczności — draft/needs_listing/needs_review/brak dodatniej ceny lub stocku nie pojawi się w żadnym katalogu, niezależnie od checkboxa.
3. Widoczny Part pojawi się w PL i FR, również w sitemap. Na FR od razu działa EUR resolver, współdzielone zdjęcia i treść fallback. Tłumaczenie nie jest wymogiem publikacji; NBP i FR delivery muszą być poprawne do zakupu.
4. Otworzyć `/admin/tools/storefront/fr-translations` na PL jako owner_admin/manager. Sekcja **Nowe/zmienione produkty — tłumaczenia FR** pokazuje pending, waiting_visibility, current, reviewed_protected, failed, queued i limity. Nie należy uruchamiać pełnego katalogu tylko po to, aby przetłumaczyć kilka nowych Part.
5. Wykonać Dry-run; sprawdzić wybrane ID/SKU, source hash, liczbę znaków, deferred/over_budget, błędy i przykłady blokad. Wpisać `TRANSLATE-NEW-CHANGED-GPSWISS-FR`, kliknąć Start i pozostawić stronę otwartą. Zatwierdzony tick na produkcji wykonuje rzeczywiste Google API przez istniejący provider (jego historyczna konfiguracja mode=dry_run nie oznacza braku połączeń w runnerze tłumaczeń). W Codexie użyto tylko mocków.
6. Gotowe FR pojawia się przy następnym odczycie. Po kolejnym source update translated dostaje needs_update i wraca do kolejnej partii; reviewed jest chronione i wymaga świadomej kontroli, bez przycisku obejścia w tym runnerze.
7. Przy błędzie Google: sprawdzić Part ID i kod w last_error/raporcie. Usunąć przyczynę konfiguracji/danych. Można Wznów, aby przejść do pozostałych produktów — wcześniej nieudany produkt nie jest ponawiany. Aby ponowić failed, zatrzymać stary run, zaznaczyć włączenie failed, zrobić nowy dry-run i świadomie zatwierdzić nową partię.
8. Przy interrupted_attempt sprawdzić, czy gotowy wynik już istnieje. Niepewne queued pozostawić oryginalnemu runowi, który posiadał claim; nowa sekcja nie przejmuje go. Po utracie cache checkpoint nadal jest w DB. Wznów wymaga ponownego kliknięcia po odświeżeniu strony.
9. Przy needs_review sprawdzić zatwierdzoną treść i zmianę źródła poza automatycznym runem. To celowa blokada, nie błąd Google. Nazwa/opis kategorii korzysta nadal z sekcji całego katalogu, z własnym approved/fallback.
10. Przy NBP: produkt nadal ma być widoczny, cena/zakup FR może być unavailable. Sprawdzić dostępność NBP/cache/datę/tabelę; zwykła ścieżka spróbuje odświeżyć kurs przy następnym odczycie. Nie wstawiać ręcznego kursu ani nie zmieniać źródłowej ceny. PL działa dalej. Brak mapowania FR dostawy naprawiać w istniejącym narzędziu mapowań, bez zmiany globalnych stawek.

### Importy i naprawa zgłoszeń

Eloquent create/update/save uruchamiają observer. Query Builder insert/update/upsert, saveQuietly i withoutEvents go omijają. Nie zmieniamy integracji/importerów marketplace. Dla importerów bez zdarzeń można jawnie wywołać lokalne FrenchProductTranslationRequests.register(Part) w zatwierdzonej integracji albo użyć w przeglądarce **Naprawa zgłoszenia / import bez zdarzeń Eloquent → Ponownie zgłoś ID**. Ta akcja jest idempotentna, nie zmienia Part, nie nadpisuje reviewed i nie robi API; po niej wymagany nowy dry-run. Obsługuje też naprawę błędu opcjonalnego zgłoszenia, bez shella/workerów.

Mini-runner obejmuje zgłoszone Part. Stare niezgłoszone produkty pozostają w istniejącym pełnym katalogu tłumaczeń. Import SQL nie rejestruje automatycznie nowych ID, a jego własne unieważnianie cache pozostaje odpowiedzialnością importera. Nie obiecujemy pełnej automatyzacji wszystkich surowych importów bez zmian ich kodu.

Konfiguracja: istniejące GPSWISS_FR_TRANSLATIONS_ENABLED i Google Translate readiness. Nowe opcjonalne GPSWISS_FR_NEW_PRODUCTS_MAX_RECORDS / GPSWISS_FR_NEW_PRODUCTS_MAX_CHARACTERS domyślnie 100 / 50000, twarde maxima takie same. Publiczny GET i zapis produktu nie startują Google. Rollback usuwa tylko zgłoszenia; nie cofa treści FR, Part ani runów. Przed rollbackiem zatrzymać nowy run.

## Pliki PR

Nowe: migration `2026_10_14_000003_create_fr_product_translation_requests.php`, `FrenchProductTranslationRequest.php`, `PartFrenchTranslationObserver.php`, `FrenchProductTranslationRequests.php`, `FrenchNewProductsTranslationRunner.php`, `FrenchNewProductsTranslationController.php`, `_fr-new-products.blade.php`, `FrenchNewProductsLifecycleTest.php`, ten dokument.

Integracja: `AppServiceProvider.php`, `FrenchCatalogTranslationRunStore.php`, `FrenchCatalogTranslationService.php` (opcjonalny hash guard), `FrenchPriceResolver.php` (current NBP), `FrenchCatalogTranslationController.php`, `config/storefront-translations.php`, `resources/views/admin/tools/storefront/fr-translations.blade.php`, `routes/web.php`, `tests/Feature/FrenchNbpPriceFallbackTest.php` (mock aktualnej metody NBP).

Nie zmieniono Part model/scope, checkoutu, Stripe, PayU, dostaw ani marketplace. Nowy observer zapisuje wyłącznie metadane tłumaczeń i unieważnia wspólny cache kategorii.

## Weryfikacja

PHP 8.4.26 / Laravel 11.57.0, SQLite w pamięci, mocki Google/NBP/Stripe/poczty i dodatkowa blokada nieprzewidzianych HTTP podczas regresji. Nie wykonano rzeczywistych provider calls.

- Nowy lifecycle: **21 testów, 191 asercji — PASS**.
- Końcowe regresje po wyłączeniu wyłącznie przypadków odtworzonych jako błędne na main: **309 testów, 3674 asercje — PASS**.
- Bazowy main bez tej implementacji i nowej migracji: 302 testy, 14 failures, 0 errors. Rozszerzony zestaw przed ostatnim testem naprawy zgłoszenia: 322 testy, dokładnie te same 14 failures, 0 errors. Nie stwierdzono dodatkowych regresji.
- Znane błędy: CSRF w JarekGearboxEbayBulkPricePreviewTest, 10 przypadków StorefrontCatalogSearchTest, etykiety w StorefrontCategorySidebarTest i dwa przypadki StorefrontProductBreadcrumbTest. Nie są poprawiane w tym PR.
- Zestaw obejmuje dotychczasowy browser/queue runner, checkpoint recovery, izolację katalog/Jarek, UI tłumaczeń i admin restrictions, wspólną widoczność, fallback/kategorie/zdjęcia/sitemap, NBP, lokalne eBay FR EUR, ceny w Stripe, brak mapowania dostawy, checkout FR, PayU PL oraz brak marketplace writes.
- `git diff --check` poprawny. PHP sformatowany Pint; bez masowego formatowania routes/web.php. `node --check` sprawdza JavaScript nowej sekcji po zastąpieniu wyrażeń Blade wartościami fixture.
