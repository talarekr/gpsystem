# Jarek w zwykłym katalogu GPSwiss.fr

## Przyczyna i rozwiązanie

Baza: `main` `ef123de86dcf5f7a36aa9d4847a7f6f7420807a8`, po merge PR #1487, #1488 i #1489. Raport operatora `0d7fe356-8234-4fc7-ac77-df1ebbcf347c` podaje 1518 utworzonych ekspozycji. To informacja ze zgłoszenia, nie wynik odczytu produkcyjnej bazy w tej sesji.

Przyczyna osobnego boxa była w kodzie: home i katalog dołączały `storefront.fr.jarek-listing`, kategoria wybierała wyłącznie `Part`, a Jarek miał oddzielne szablony karty i produktu. Po tej zmianie:

- Home nie pobiera Jarka i nie renderuje osobnego bloku.
- Aktywne `JarekFrProduct` są przypisane **tylko w odczycie FR** do istniejącej kategorii `uklad-napedowy/skrzynie-biegow-i-inne-elementy/manualna-6-biegowa-skrzynia-biegow`. Nie ma migracji, nowej kategorii, zmiany `JarekGearbox.category_id`, tworzenia `Part` ani ponownego apply publikacji.
- `FrenchMixedCatalog` łączy identyfikatory zwykłych części i ekspozycji Jarka przez SQL `UNION ALL`. Katalog, docelowa kategoria i jej przodkowie mają jeden grid, wspólną liczbę wyników i jedną paginację po 60 pozycji (`page`). Zdjęcia i tłumaczenia produktów są ładowane tylko dla bieżącej strony.
- Adapter `JarekCatalogProduct` jest zwykłym obiektem widoku, **nie modelem Part**. Oba źródła korzystają z `storefront.partials.product-card` i `storefront.parts.show`; dlatego klasy CSS, proporcje obrazów, galeria, miniatury, lightbox, panel ceny i przycisk koszyka są wspólne. Trzy nieużywane szablony Jarka zostały usunięte.
- Obrazy adaptera mają ten sam interfejs `listingUrl/productUrl/publicUrl`, alt i kolejność co obrazy zwykłych części. Dotychczasowe `displayImageUrls()` preferuje istniejące lokalne pliki `storage/app/public/jarek-gearboxes/{offer}/NN.jpg`; dopiero bez lokalnych plików używa zapisanych URL. Nie pobieramy i nie przetwarzamy zewnętrznych obrazów w requestach. Przy braku lokalnych plików przeglądarka może pobrać zapisany zewnętrzny URL, tak jak dotychczas.
- Zachowano `/boites-jarek/{id}`, adres POST do koszyka i adresy produktów w sitemapie. Kolekcja `/boites-jarek` przekierowuje 301 do normalnej kategorii, zachowując query; stare `jarek_page` jest mapowane na `page`. Nie powstaje osobny sklep ani nowy publiczny layout.
- Breadcrumbs Jarka używają istniejących kategorii i gotowych nazw FR: Transmission → Boîtes de vitesses et accessoires → Boîte manuelle à 6 rapports, jeśli takie tłumaczenia zapisano. Nie wpisujemy tych nazw na stałe w kodzie.

Kategoria dostępna tylko dzięki Jarkowi nie deklaruje fikcyjnego hreflang PL do strony 404. Dla kategorii ze zwykłymi częściami lub istniejącą licznością Woo dotychczasowe alternatywy pozostają. Sitemapę kategorii zasila to samo drzewo FR.

Drzewo FR uwzględnia liczbę aktywnych ekspozycji w tej kategorii, także kiedy nie ma w niej zwykłych części. Buduje strukturę z bieżących danych, poza trwałym cache PL; następnie ładuje tłumaczenia. W przeciwnym razie cache rozgrzany dla PL mógłby pominąć gałąź zawierającą wyłącznie Jarka. PL zachowuje dotychczasowy cache `storefront.category_tree.v2` i nie uwzględnia ekspozycji Jarka.

## Cena, filtry, koszyk i ograniczenia danych

Cena publiczna Jarka nadal pochodzi wyłącznie z `JarekFrPresentation`: źródłowe PLN × 1,25 (zaokrąglenie do 2 miejsc), podzielone przez kurs NBP EUR/PLN (ponownie 2 miejsca). Dostawa 130 EUR, wirtualna ilość 1. Widok pokazuje EUR i `Frais de livraison : 130,00 EUR`. Nie zapisujemy ceny ani ilości do źródła.

Sortowanie cen i granice cen w **FR** są teraz wspólne i porównują wyświetlaną cenę EUR, bez dostawy. Projekcja SQL odtwarza dotychczasowy priorytet zwykłych części: własna cena EUR → jednoznaczna aktywna lokalna cena eBay FR w EUR → PLN/NBP przy odpowiedniej polityce. Konflikt lokalnych cen blokuje ich użycie; bez dostępnego kursu ceny wymagające przeliczenia są niedostępne. Ceny niedostępne trafiają na koniec sortowania. To odczyt istniejących lokalnych listingów, bez wywoływania eBay. Sam `FrenchPriceResolver` i ceny źródłowe pozostają bez zmian. Filtry i sortowanie PL pozostają bez zmian. Sortowanie nazw FR korzysta z niepustych `translated/reviewed` nazw, z fallbackiem źródłowym.

Filtrowanie po producencie/modelu/vehicle_model wyklucza Jarka: nie mamy wiarygodnego, ujednoliconego mapowania tych pól w źródle dostawcy. Nie zgadujemy wartości z tytułu. Wyszukiwanie Jarka nadal korzysta z dotychczasowego `JarekFrCatalog` (tytuł źródłowy/gotowy FR, q i part_number); adapter nie dodaje własnego mapowania numerów OEM ani parametrów dostawcy.

Koszyk, checkout, Stripe FR, webhook i settlement nie są zmieniane. Wspólny widok wysyła przycisk Jarka do dotychczasowego endpointu `storefront.jarek.add`, zwykłe części do dotychczasowego endpointu Part. Nadal jedna pozycja / jedna sztuka, bez PayU w FR. Istniejący test checkoutu obejmuje Stripe z mockiem, idempotencję webhooka, brak zmniejszenia stocku Jarka i brak zdarzeń synchronizacji marketplace. Wirtualna ilość nie jest licznikiem fizycznego stocku dostawcy.

Brak widocznej kategorii o dokładnej ścieżce lub ukryty przodek jest blokerem integracji z kategorią. Kod nie tworzy kategorii i nie omija flag widoczności. Istniejące bezpośrednie adresy aktywnych produktów nadal działają; katalog nie wymyśla zastępczej kategorii.

## Diagnoza polskich nazw i etykiet

Na badanym main statyczne etykiety filtrów/menu/paginacji są już poprawione przez #1488: `Fabricant`, `Modèle`, `Prix minimum`, `Prix maximum`, `Tous`, `Filtrer`, `Effacer les filtres`. Publiczne widoki menu, hero, breadcrumbs i metadata używają `PartCategory.storefront*ForLocale('fr')`. Źródłem są `category_translations`, a publiczne statusy to `translated/reviewed`; pola puste mają indywidualny fallback.

Testy potwierdzają, że gotowe FR tłumaczenie jest używane również po rozgrzaniu cache przez PL i po zmianie tłumaczenia. Nie odtworzono błędu ignorowania gotowego tłumaczenia. Narzędzie katalogu `/admin/tools/storefront/fr-translations`, sekcja **Produkty i kategorie GPSwiss.fr**, obejmuje wszystkie `PartCategory`, w tym trzy poziomy wskazanej ścieżki i pola `name/description`. Runner nie został zmieniony ani uruchomiony. Tekst o cenie brutto/najniższej cenie jest już wykluczony z HTML/JS FR przez #1488; wspólny widok Jarka dziedziczy ten warunek. PL zachowuje tekst.

**Nie było dostępu do produkcyjnej bazy ani informacji o faktycznie wdrożonym SHA.** Próba odczytu publicznego home i docelowej kategorii przez dostępne narzędzie WWW zakończyła się komunikatem, że adres nie jest dostępny przez to narzędzie. To nie dowód awarii strony. Nie możemy rozstrzygnąć, czy konkretne produkcyjne nazwy PL wynikają z braku rekordów, pustych pól, złych statusów lub starszego deployu. Nie twierdzimy, że produkcyjne tłumaczenia istnieją albo że ich brakuje.

### Po osobnym deployu — diagnoza operatora

1. Zweryfikować faktycznie wdrożony commit, pliki Blade/lang z #1488 i z tej poprawki; merge PR sam w sobie nie potwierdza deployu. W przypadku rozbieżności operator sprawdza cache widoków/OPcache/CDN według procedury wdrożenia. Codex nie wykonuje deployu ani czyszczenia produkcyjnego cache.
2. Read-only SELECT poniżej pokazuje właściwe ID kategorii, nazwę FR, status oraz przyczynę fallbacku. Sprawdzić ID i pełną ścieżkę, nie tylko identyczną polską nazwę w innym rekordzie.
3. Przy `ready_fr_name` aktualny widok powinien użyć FR; sprawdzić host/locale, wdrożony kod i ID. Nie ma potrzeby czyścić trwałego cache tłumaczeń w tym odczycie FR.
4. Przy `missing_translation`, `nonpublic_status` lub `empty_fr_name` fallback PL jest skutkiem danych. Operator sprawdza preview istniejącego narzędzia tłumaczeń katalogu. Ewentualny zatwierdzony start/review odbywa się osobno, poza Codexem i zakresem tego PR; nie naprawiać tego zmianą nazwy źródłowej ani masowym ustawieniem statusów.
5. Dla Jarka sprawdzić widoczność kategorii i wszystkich przodków, liczbę aktywnych ekspozycji i ich źródeł spełniających publiczne warunki. **Nie wykonywać ponownie publikacji 1518 produktów**, żeby naprawić sam listing/layout.

```sql
SELECT c.id, c.parent_id, c.source_system, c.is_visible,
       c.name, c.full_slug_path, t.name AS fr_name,
       t.description AS fr_description, t.status AS fr_status,
       CASE WHEN t.id IS NULL THEN 'missing_translation'
            WHEN t.status IS NULL OR t.status NOT IN ('translated','reviewed') THEN 'nonpublic_status'
            WHEN NULLIF(TRIM(t.name),'') IS NULL THEN 'empty_fr_name'
            ELSE 'ready_fr_name' END AS name_resolution
FROM part_categories c
LEFT JOIN category_translations t ON t.category_id = c.id AND t.locale = 'fr'
WHERE c.full_slug_path IN (
  'uklad-napedowy',
  'uklad-napedowy/skrzynie-biegow-i-inne-elementy',
  'uklad-napedowy/skrzynie-biegow-i-inne-elementy/manualna-6-biegowa-skrzynia-biegow'
)
OR c.name LIKE '%Części nadwozia%' OR c.name LIKE '%Drzwi i inne elementy%'
OR c.name LIKE '%Elementy przedniej części%' OR c.name LIKE '%Oświetlenie%'
OR c.name LIKE '%Kompletne silniki%' OR c.name LIKE '%Silniki i osprzęt%'
ORDER BY c.id;

SELECT COUNT(*) AS active_eligible_exposures
FROM jarek_fr_products f JOIN jarek_gearboxes g ON g.id = f.jarek_gearbox_id
WHERE f.is_active = 1 AND g.currency = 'PLN' AND g.price > 0 AND g.title <> '';
```

## Zmienione pliki

- `app/Http/Controllers/Storefront/CatalogController.php`
- `app/Http/Controllers/Storefront/CategoryController.php`
- `app/Http/Controllers/Storefront/Concerns/BuildsStorefrontQueries.php`
- `app/Http/Controllers/Storefront/HomeController.php`
- `app/Http/Controllers/Storefront/JarekFrProductController.php`
- `app/Services/Storefront/CategoryTreeService.php`
- `app/Services/Storefront/StorefrontSeo.php` — brak fikcyjnego hreflang PL dla gałęzi dostępnej wyłącznie dzięki Jarkowi
- `app/Services/Storefront/FrenchMixedCatalog.php` — nowy
- `app/Services/Storefront/JarekCatalogProduct.php` — nowy adapter
- `app/Services/Storefront/JarekCatalogImage.php` — nowy adapter zdjęć
- `resources/views/storefront/catalog/_content.blade.php`
- `resources/views/storefront/home.blade.php`
- `resources/views/storefront/partials/product-card.blade.php`
- `resources/views/storefront/partials/seo.blade.php` — przekazuje do istniejącego serwisu wyłącznie prawdziwy Part; canonical Jarka nadal wynika z jego trasy
- `resources/views/storefront/parts/show.blade.php`
- `resources/views/storefront/fr/jarek-card.blade.php` — usunięty
- `resources/views/storefront/fr/jarek-listing.blade.php` — usunięty
- `resources/views/storefront/fr/jarek-product.blade.php` — usunięty
- `tests/Feature/JarekUnifiedFrenchCatalogTest.php` — nowy
- `tests/Feature/JarekFrPublicationAndCheckoutTest.php` — fixture istniejącej kategorii i oczekiwane przekierowanie starej kolekcji
- `docs/jarek-fr-unified-catalog.md`

## Weryfikacja

PHP 8.4.26 / Laravel 11.57.0, SQLite w pamięci, blokada nieprzewidzianych HTTP. Nowy zestaw obejmuje wspólną kategorię/grid/paginację, izolację PL, home, wspólny widok/galerię/koszyk, zdjęcia lokalne, fallback/gotowe FR nazwy, wyszukiwanie, sortowanie i granice EUR, lokalne ceny eBay/conflict oraz brak kategorii. Nie wykonano Google API, marketplace calls, produkcyjnych zapisów ani deployu.

Zestaw regresji z blokadą nieprzewidzianych HTTP: **320 testów, 3803 asercje — PASS** (przed dodatkowym testem hreflang gałęzi dostępnej tylko na FR). Wyłączono 14 przypadków wcześniej potwierdzonych jako błędy bazowego main: 10 `StorefrontCatalogSearchTest`, 2 `StorefrontProductBreadcrumbTest`, 1 `StorefrontCategorySidebarTest` i test CSRF `JarekGearboxEbayBulkPricePreviewTest`. Nie przedstawiamy całego nieprzefiltrowanego suite jako zielonego.

Po dopracowaniu hreflang: **37 testów, 466 asercji — PASS** (integracja Jarka + UI FR + istniejąca publikacja/Stripe/stock) oraz **10 testów SEO, 229 asercji — PASS**. Dodatkowe asercje sprawdzają brak hreflang PL przy kategorii dostępnej tylko dzięki Jarkowi, zachowanie alternatyw dla kategorii ze zwykłą częścią i obecność docelowej kategorii w sitemapie FR.
