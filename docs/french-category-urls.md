# Francuskie URL-e kategorii GPSwiss.fr

## Audyt

Na main po #1492/#1493 `category_translations` miało name/description/status i metadane tłumaczenia, bez sluga. Nazwy menu/hero/breadcrumbs już mogły być FR, lecz `CategoryTreeService::url`, CategoryController lookup, StorefrontSeo i FrenchSitemapController nadal korzystały z PL `PartCategory.slug/full_slug_path`. To wyjaśnia francuski tytuł na polskim URL-u niezależnie od istnienia gotowego tłumaczenia.

Bez odczytu produkcyjnej bazy nie potwierdzono, które konkretne ID mają brak/pustą/złą treść FR. Wymagane są status translated/reviewed i niepuste name. Nie dodano zgadywanych tłumaczeń ani mapy polskich słów na francuskie.

## Wspólna polityka URL

`FrenchCategoryUrls` + `CategoryTreeService::publicPath/localizedUrl` odpowiadają za linki, routing, canonical, hreflang i sitemap. Techniczny prefix istniejącej routy `/kategoria-produktu/` pozostaje; segmenty kategorii po nim są lokalizowane.

Każdy znany root/parent/leaf z gotowym FR name dostaje `Str::slug(name)` i stały sufiks `-{category_id}`. Slash w nazwie jest separatorem słów, nie dodatkowym poziomem drzewa. ID rozwiązuje kolizje jednakowych nazw/transliteracji bez kolejności losowej, providerów, zgadywania języka i zmian adresu wskutek dodania kolejnej kategorii.

Dla podanego przykładu nowy wzorzec jest:

```text
https://gpswiss.fr/kategoria-produktu/portes-et-autres-elements-{root_id}/portes-laterales-coulissantes-et-autres-composants-{parent_id}/portes-laterales-coulissantes-{leaf_id}
```

To wzorzec, nie wymyślone produkcyjne ID. Faktyczny URL pokaże diagnostyka/menu po wdrożeniu.

- FR title/breadcrumb/menu zachowują pełną nazwę bez ID; ID znajduje się tylko w slugu.
- PL nadal używa swoich dotychczasowych slugów/ścieżek.
- Brak, nonpublic lub puste tłumaczenie daje stary segment. Pusta transliteracja ma osobny powód `empty_fr_slug`.
- Brak rekordu przodka przy dłuższym legacy full_slug_path zachowuje nieznany PL prefix jako fallback; diagnostyka pokazuje `unresolved_ancestor_segments`, zamiast wymyślać kategorię/tłumaczenie.
- FR routing nie zgaduje kategorii na podstawie ostatniego segmentu błędnej ścieżki. Jednoznaczny legacy path/slug/history alias ma 301 do bieżącego canonical. Wieloznaczny legacy path ma 404; aktualne FR adresy z ID pozostają rozróżnialne.
- 301 zachowuje query filters, sortowanie i paginację. Canonical/robots nadal stosują istniejącą politykę filtered/noindex i paginacji.

## Trwałe slugi, zmiany nazw i migracja

Nowa migracja `2026_10_14_000005_add_french_category_slugs.php` dodaje nullable `slug`, `slug_name_hash`, `slug_history` do category_translations oraz krótki unique indeks `(locale, slug)`. Jest odporna na ponowienie i częściowy schema; sprawdza kolumny/indeksy, a kolizję samej nazwy indeksu z innym kształtem rozwiązuje sufiksem. Down zachowuje metadata/aliasy.

Migracja partiami 200 wylicza metadata wyłącznie dla już gotowych FR name. Nie tłumaczy niczego, nie wywołuje Google ani marketplace, nie zmienia source PartCategory, treści/statusu tłumaczeń, timestamps, cen lub stocku.

CategoryTranslation przy zapisie gotowego FR name generuje slug w tym samym zapisie. Zmiana name zmienia canonical i dopisuje poprzedni slug do `slug_history`; stare FR ścieżki, także po zmianie nazwy przodka, mają 301 do nowej. Zmiana samego opisu nie zmienia sluga. Niedokończone tłumaczenie nie staje się publicznym FR name.

Publiczny GET niczego nie zapisuje. Gdy metadata jeszcze nie istnieje lub nie pasuje do name, resolver bezpiecznie wylicza bieżący slug w pamięci (`derived_fr_slug`). Trwałe aliasy wymagają migracji i normalnego zapisu tłumaczenia; nie pomijać migracji w deployu. Bez kompletu kolumn hook nie próbuje ich zapisywać.

FR filter kategorii przyjmuje dotychczasowy source slug/name, gotowy FR name, bieżący/historyczny FR slug i jednoznaczny FR path. Zwykłe produkty i Jarek używają tej samej kategorii manualnej. Nie zmieniono kryteriów wyszukiwania nazw produktów, polityki cen eBay.de/NBP, dostawy lub checkoutu.

## SEO i katalog

- Mega menu (desktop/mobile), sidebar, subcategories, product/category breadcrumbs używają jednego URL resolvera.
- Curated home/header links, w tym manualne Boîtes de vitesses, rozwiązują dotychczasowe ścieżki jako selektory kategorii i emitują FR canonical.
- `/boites-jarek` przekierowuje bezpośrednio do FR manualnej kategorii; adresy kart produktów Jarka i zwykłych produktów pozostają bez zmian.
- Canonical FR ma FR path; hreflang FR/PL wskazuje różne odpowiednie ścieżki/domeny, x-default PL.
- Nie powstaje fałszywy PL alternate dla kategorii wyłącznie FR/Jarek albo bez poprawnej źródłowej ścieżki PL.
- FR sitemap pages.xml publikuje bieżące canonical i pomija legacy/history URL-e, ukryte/niepubliczne i nieadresowalne źródłowe rekordy. Product/Jarek sitemap logic pozostaje.
- FR tree odczytuje translations poza trwałym PL cache. Kolejny request widzi zapisane FR zmiany; narzędzie nie uruchamia masowego flush.

## Operator po merge/deploy

1. Ponowić zwykły deploy browserowy z migracjami (najpierw fix #1493). Nowa migracja URL automatycznie wyliczy slugi z istniejących ready FR nazw, bez Google; nie potrzeba ręcznego SQL/shella/backfill apply.
2. Otworzyć `/admin/tools/storefront/fr-translations/category-diagnostics`. Nowe kolumny: fr_slug, fr_slug_reason, fr_public_path/url, path_has_fallback, slug_history_count, unresolved_ancestor_segments. `?format=csv` obejmuje wszystkie ID; `?missing_only=1` obejmuje brak nazw i fallbacki URL, również odziedziczone od przodków.
3. Kliknąć publiczny FR URL z diagnostyki/menu. Stary adres Drzwi z zadania powinien dać 301 do niego, jeśli rozpoznanie jest jednoznaczne. Nowy adres 200, francuski title/breadcrumb, canonical FR, PL alternate z dotychczasowym PL path.
4. Sprawdzić `/sitemaps/pages.xml` na FR, menu (obie kolumny/mobile), home Boîtes de vitesses, manualną kategorię i breadcrumbs Jarka/normalnego produktu.
5. Dla fallbacków sprawdzić dokładny root/parent/leaf ID i treść/status FR. Istniejące tłumaczenia kategorii obsługuje narzędzie `/admin/tools/storefront/fr-translations`; provider apply/tłumaczenie danych to osobna decyzja operatora. Reviewed jest chronione, nie jest masowo nadpisywane.
6. Odczytać istniejący `/admin/tools/storefront/fr-translations/price-audit?run=1` z #1492 dla obu zgłoszonych produktów; uruchomić potrzebny preview/prepare obrazów w `jarek-local-images`. Ten PR nie zmienia ich cen/runnera. Bez dostępu do produkcyjnej bazy nie podaje rzeczywistych wyników/ID/counts.

Nie uruchomiono deployu, produkcyjnego backfillu/tłumaczeń, providerów lub marketplace w Codexie. Testy korzystają z lokalnej bazy i atrap HTTP. Osobny #1494 naprawia ciągłe tłumaczenie Jarka i bezpieczny retry w istniejącym runie.
