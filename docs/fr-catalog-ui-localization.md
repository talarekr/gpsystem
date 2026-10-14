# GPSwiss.fr — menu, kategorie, filtry i karta produktu

## Diagnoza

Bazą jest aktualny `main` `dc28a4dd8cc8c5d69e99b664906051ecfa49df27`, zawierający PR #1486 i #1487. Poprawka jest osobnym PR i dotyczy wyłącznie publicznego UI.

Potwierdzone przyczyny w kodzie:

- `storefront/partials/filters.blade.php` zawierał literalne „Filtry”, „Producent”, „Model”, „Wszyscy”, „Wszystkie”, „Filtruj”. Tłumaczenia etykiet cen i czyszczenia już istniały w `lang/fr/storefront.php`, więc panel mieszał języki.
- Mega menu miało literalne „Wybrana kategoria”, „Zobacz wszystkie”, „Zobacz wszystkie w kategorii” i polski empty state. Sidebar miał „Kategoria”, „Podkategorie” oraz polską etykietę ARIA.
- Domyślna paginacja Laravel zawierała m.in. angielskie „Showing”, „to”, „of”, „results” i nieprzetłumaczone klucze `pagination.*`. FR dostaje osobny widok paginacji; PL zachowuje dotychczasowy widok.
- Karta produktu bezwarunkowo renderowała `storefront.gross_price_note`. Na FR ukrywa ją warunek hosta; nie zastępujemy tekstu innym. Ten sam nieużywany wpis został usunięty z francuskiego słownika JavaScript, żeby komunikatu nie było również w źródle HTML. PL pozostaje bez zmian.

**Nazwy kategorii na tym main już używają istniejących tłumaczeń.** Nie znaleziono bezpośredniego odczytu `PartCategory.name` zamiast gotowego tłumaczenia w mega menu, sidebarze, nagłówku lub breadcrumbs. Nie dodajemy drugiego mechanizmu tłumaczenia nazw ani nie nadpisujemy ich stałym słownikiem.

Ścieżka odczytu:

1. `ResolveStorefrontHost` ustawia `storefront_locale=fr` dla gpswiss.fr; cookie/session PL nie może tego zastąpić.
2. `AppServiceProvider` przekazuje `storefrontContentLocale` do widoków.
3. `CategoryTreeService` buduje drzewo z publicznych kategorii Woo/bez source_system, a na FR ładuje relację `category_translations` poza wspólnym cache bazowego drzewa. Nie cache’uje gotowych tłumaczeń w trwałym cache drzewa.
4. Menu, sidebar, hero i breadcrumbs używają `storefrontPublicNameForLocale('fr')`. Kontrolery używają lokalizowanych nazw/opisów także w meta title/description.
5. `HasStorefrontTranslations` akceptuje niepuste pole tłumaczenia wyłącznie w statusie `translated`/`reviewed`. W przeciwnym razie używa źródła. Opis i nazwa mają osobne fallbacki.
6. `FrenchCatalogSearch` szuka po gotowych nazwach produktów/kategorii FR, zachowując fallback i wyszukiwanie numerów OEM. Filtr kategorii obsługuje FR nazwę oraz dotychczasowy slug. Wyszukiwanie PL nie jest zmieniane.

Nowe testy potwierdzają tłumaczenia korzenia, dziecka i wnuka, hero, breadcrumbs oraz metadata dla obu publicznych statusów. Potwierdzają również nowo dodane/zmienione tłumaczenia po rozgrzaniu cache przez PL, bez czyszczenia cache. Nie stwierdzono odtwarzalnego błędu odczytu istniejącego gotowego tłumaczenia na tym main.

## Stan produkcyjnej bazy — granica ustaleń

W tej sesji nie ma połączenia z produkcyjną bazą GPSwiss. Nie sprawdzono produkcyjnych rekordów ani ich liczby/statusów. Nie można na podstawie samych screenów rozstrzygnąć, czy pokazane nazwy wynikają z braku gotowych tłumaczeń właściwych ID kategorii, pustego pola, statusu niepublicznego czy starszego wdrożenia. Nie deklarujemy, że produkcyjne tłumaczenia istnieją albo że ich brakuje.

Poniższy **SELECT**, do osobnego wykonania przez operatora z dostępem read-only, pokazuje właściwe ID i powód fallbacku dla przykładów ze zgłoszenia. Nie wymaga zmian danych:

```sql
SELECT c.id, c.parent_id, c.source_system, c.name, c.full_slug_path,
       t.name AS fr_name, t.status AS fr_status,
       CASE
         WHEN t.id IS NULL THEN 'missing_translation'
         WHEN t.status NOT IN ('translated', 'reviewed') THEN 'nonpublic_status'
         WHEN NULLIF(TRIM(t.name), '') IS NULL THEN 'empty_fr_name'
         ELSE 'ready_fr_name'
       END AS name_resolution
FROM part_categories c
LEFT JOIN category_translations t ON t.category_id = c.id AND t.locale = 'fr'
WHERE c.name LIKE '%Silniki i osprzęt%'
   OR c.name LIKE '%Kompletne silniki%'
   OR c.name LIKE '%Części nadwozia%'
   OR c.name LIKE '%Drzwi i inne elementy%'
   OR c.name LIKE '%Elementy przedniej części%'
   OR c.name LIKE '%Oświetlenie%'
ORDER BY c.id;
```

Gdy wynik jest `ready_fr_name`, bieżący main powinien pokazać `fr_name` w menu i widokach. Sprawdzić zgodność ID/ścieżki widocznej kategorii i faktycznie wdrożonego kodu. Gdy brak gotowego niepustego pola, źródłowy fallback jest przewidzianym zachowaniem.

Dotychczasowy preview/runner tłumaczeń katalogu obejmuje **wszystkie PartCategory**, w tym rekordy Woo używane przez publiczne drzewo, oraz pola `name` i `description`. Test dry-run sprawdza objęcie wszystkich trzech poziomów drzewa bez połączenia Google i bez tworzenia tłumaczeń. Przy statusach failed/needs_update obecna polityka runnera może pomijać rekordy — ten PR jej nie zmienia i nie uruchamia tłumaczeń. Operator może sprawdzić raport istniejącej sekcji **Produkty i kategorie GPSwiss.fr** w `/admin/tools/storefront/fr-translations`.

## Zakres zmian

Nowe etykiety wybierają FR dla hosta FR i identyczne wcześniejsze polskie teksty dla pozostałych hostów; zachowujemy je również na PL z francuskim cookie/session. Filtry używają spójnie **Fabricant**, **Modèle**, **Tous**, **Filtrer**. `Prix minimum`, `Prix maximum`, `Effacer les filtres` już istniały i pozostają używane. Parametry filtrów, wartości producentów/modeli, URL-e, slugi, sortowanie i logika cen pozostają bez zmian.

Nie zmieniono modeli, kontrolerów, cache, odczytu wyszukiwania, runnerów, płatności, checkoutu, stocku, marketplace, cen ani dostaw. Nie ma migracji ani produkcyjnych zapisów. Nie wykonano deployu, Google API ani operacji Allegro/eBay/Ovoko.

Lista plików:

- `lang/fr/storefront.php`
- `lang/pl/storefront.php` — nowe klucze zawierają identyczne dotychczasowe teksty PL
- `resources/views/layouts/storefront.blade.php`
- `resources/views/storefront/partials/category-menu.blade.php`
- `resources/views/storefront/partials/category-sidebar.blade.php`
- `resources/views/storefront/partials/category-breadcrumbs.blade.php`
- `resources/views/storefront/partials/breadcrumbs.blade.php`
- `resources/views/storefront/partials/filters.blade.php`
- `resources/views/storefront/parts/show.blade.php`
- `resources/views/storefront/categories/show.blade.php` — wyłącznie wybór paginacji FR
- `resources/views/storefront/catalog/_content.blade.php` — wyłącznie wybór paginacji FR
- `resources/views/storefront/fr/jarek-listing.blade.php` — wyłącznie wybór paginacji FR
- `resources/views/storefront/fr/pagination.blade.php` — nowy widok tylko FR
- `tests/Feature/FrenchCatalogUiLabelsTest.php`
- `docs/fr-catalog-ui-localization.md`

## Weryfikacja

PHP 8.4.26 / Laravel 11.57.0; SQLite w pamięci. Nowe testy blokują nieprzewidziane HTTP i nie uruchamiają Google. Zestaw UI, istniejących tłumaczeń, wyszukiwania, SEO/hreflang/sitemap, katalogu FR, prezentacji cen, Stripe FR, PayU PL/panelu i publikacji Jarka: **84 testy, 1208 asercji — PASS**.
