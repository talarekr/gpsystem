# GPSwiss.fr — zdjęcia Jarka, SKU, home i diagnostyka kategorii

## 1. Audyt root cause

Baza: `talarekr/gpsystem`, `main` `8b0e7374ba9b72de0a69dc07cdfe62c232c13457`, po merge #1490. Praca dotyczy tylko gpsystem.

**Techniczne kody:** wspólne `product-card`, product page i koszyk FR renderowały SKU adaptera `JAREK-{offer_id}` jako publiczną referencję. To identyfikator techniczny, nie numer OEM. Widoczne referencje są teraz pomijane dla źródła Jarek, a identyfikator pozostaje w wewnętrznych danych koszyka/zamówienia. Zwykłe produkty i PL zachowują referencje. Przegląd publicznych widoków nie wykazał innych wyświetleń SKU Jarka; podsumowania checkoutu używają nazwy produktu.

**Zdjęcia:** #1490 udostępnił wspólny szablon/CSS/galerię, ale adapter nadal zwracał oryginalny JPEG zarówno z `listingUrl()`, jak i `productUrl()`. Zwykłe `PartImage` korzystają z wariantów `parts/photos/presentation/listing` i `product`: wykrywają obiekt, ograniczają marginesy, kadrują i renderują canvas. Biały margines w oryginale Jarka pozostawał więc wewnątrz takiego samego wrappera `object-fit: contain`, przez co sam obiekt wyglądał na mały. To brakujący etap poprzedniej poprawki, a nie oddzielny CSS karty.

**Home:** kontroler i link „Boîtes de vitesses” wskazywały kategorię automatycznej skrzyni. FR korzysta teraz z docelowej manualnej 6-biegowej kategorii; sekcja pobiera zwykłe produkty i ekspozycje Jarka do tego samego standardowego karuzelowego grida, maksymalnie 8 pozycji. Nie ma oddzielnej sekcji Jarka. PL zachowuje automatyczną skrzynię i dotychczasowy wybór.

**Polskie kategorie:** aktualny main już odczytuje gotowe niepuste `category_translations.name`, status `translated/reviewed`, locale `fr`. Dotyczy to mega menu, sidebaru/podkategorii, breadcrumbs, hero, nagłówka i meta title/description. Drzewo FR jest budowane z bieżących danych, a relacje tłumaczeń ładowane poza trwałym cache PL. Testy potwierdzają użycie gotowego FR również po cache PL i zmianie tłumaczenia. Nie odtworzono ignorowania gotowych FR nazw w kodzie.

## 2. Co zostało poprawione

- Ukryto linię technicznej referencji Jarka na listingu, product page i w koszyku FR. SKU nie jest usuwane ze źródeł, sesji ani zamówień.
- Dodano lokalne przygotowanie zdjęć przez **ten sam `PartImagePresentationService`**, bez zmiany jego algorytmu i bez tworzenia rekordów Part/PartImage. W pamięci tworzony jest tylko deskryptor obrazu, przekazywany do istniejącego procesora. Listing ma canvas **522×336**, produkt **900×675**.
- Normalne produkty i adapter Jarka używają wspólnego `ListingImageSelector`. Dotychczasowy algorytm rankingu zdjęć z Part został przeniesiony bez zmiany reguł: score → primary → sort_order → id. Jarek korzysta z score wyliczonego przez zwykły procesor. Nie wybiera zawsze pierwszego zdjęcia, jeśli inne ma lepszy przygotowany wariant.
- Galeria Jarka nadal jest dokładnie tym samym widokiem `storefront.parts.show`: duże zdjęcie używa wariantu product, miniatury wariantu listing, nawigacja i lightbox tego samego JS. CSS wrappera, aspect ratio, contain i padding pozostają wspólne i niezmienione.
- Publiczne requesty **wyłącznie odczytują** gotowy manifest i warianty. Nie generują cropów, nie pobierają zdjęć i nie zapisują plików. Bez gotowego wariantu zostaje oryginalny URL. Przy braku lokalnego oryginału także zostaje wcześniejszy zapisany URL; nowy procesor nie pobiera go z sieci.
- Przygotowanie jest osobnym narzędziem ownera w panelu, z podglądem bez zapisów i jawnie potwierdzonym POST. Partie są ograniczone do dwóch zdjęć i dziesięciu ekspozycji; jest kursor, zatrzymanie i wznowienie od kursora. Warianty z aktualnym manifestem są pomijane. Zmiana mtime/rozmiaru źródła albo wersji procesora unieważnia manifest; ponowne przygotowanie nadaje aktualnym plikom nowe URL-e. Oryginały nie są nadpisywane ani usuwane.
- FR home i link sekcji używają `uklad-napedowy/skrzynie-biegow-i-inne-elementy/manualna-6-biegowa-skrzynia-biegow`.
- Dodano read-only diagnostykę bieżącej bazy kategorii: ID, parent, source, widoczność, pełna ścieżka, źródłowa nazwa, nazwa FR, status, powód fallbacku i gotowość opisu. Link jest na dotychczasowej stronie narzędzia FR. Runner tłumaczeń nie jest zmieniony ani uruchomiony.

Cena Jarka PLN ×1,25 → EUR/NBP, dostawa 130 EUR, qty 1, koszyk/checkout/Stripe/PayU i webhook pozostają bez zmian. Nie ma migracji ani ponownej publikacji Jarka.

## 3. Kod a dane produkcyjne

W tej sesji **nie ma odczytu produkcyjnej bazy GPSwiss ani informacji o faktycznie wdrożonym SHA**. Środowisko nie ma skonfigurowanych poświadczeń ani połączenia do tej bazy. Próby odczytu wskazanych publicznych stron i panelu przez narzędzie WWW zwróciły brak dostępności adresów w narzędziu; to nie dowód awarii serwisu. Nie można rzetelnie podać, których produkcyjnych ID tłumaczenia nie istnieją, ani potwierdzić przyczyny dla konkretnego screena.

Nowa diagnostyka pokaże tę informację operatorowi bez Google i bez zapisów:

`https://gpswiss.pl/admin/tools/storefront/fr-translations/category-diagnostics`

Wyszukaj `uklad-napedowy`, następnie `Części nadwozia`, `Drzwi`, `Oświetlenie`, `Kompletne silniki` lub pełną ścieżkę. Wynik jest odczytem bieżącej bazy, nie cache drzewa.

| Wynik | Co dokładnie nie jest gotowe | Zachowanie frontu |
|---|---|---|
| `ready_fr_name` | Nic dla nazwy: niepuste name + translated/reviewed | Nazwa FR. Jeśli na stronie nadal PL, sprawdzić właściwe ID/ścieżkę i faktyczny deploy/cache HTML |
| `missing_translation` | Brak rekordu category_translations dla tego ID i locale=fr | Źródłowy fallback PL jest prawidłowy |
| `nonpublic_status` | Rekord istnieje, ale status nie jest translated/reviewed | Źródłowy fallback; raport pokazuje rzeczywisty status |
| `empty_fr_name` | Rekord jest ready, ale name jest puste/białe | Źródłowy fallback dla nazwy; opis rozstrzygany osobno |
| `missing_translation_table` | Brak samej tabeli | Źródłowy fallback i bloker istniejących migracji tłumaczeń |

Nie dodajemy stałego słownika nazw do Blade ani nie nadpisujemy nazw źródłowych, żeby ukryć braki danych. Gotowa nazwa FR ma zawsze pierwszeństwo; fallback jest przewidziany tylko bez gotowego niepustego pola.

## 4. Co uruchomić po osobnym wdrożeniu

### Zdjęcia — bez shella i bez Google

1. Owner otwiera `https://gpswiss.pl/admin/tools/storefront/fr-translations/jarek-local-images` (także link na dotychczasowej stronie FR).
2. Przy kursorze `0 / 0` klika **Podgląd następnej partii**. `would_prepare` oznacza istniejący lokalny plik bez gotowego wariantu. Podgląd i samo otwarcie strony niczego nie zapisują.
3. Wpisuje `PREPARE-JAREK-FR-LOCAL-IMAGES` i klika **Przygotuj lokalne warianty od kursora**. Strona wysyła małe POST-y co około 3 sekundy, aż do końca, zatrzymania lub błędu. Zapisywane są tylko JPG pochodne, manifesty i log procesora, bez DB/stock/cen/marketplace. Wymagane GD i zapisywalny public disk oraz dotychczasowy katalog public_html mirror używany przez zwykły procesor.
4. Po zamknięciu strony nowe partie nie ruszają. Przy ponownym otwarciu wpisać ostatni kursor (źródłowe JarekGearbox ID i numer zdjęcia) lub zacząć od `0 / 0`; aktualne warianty zostaną pominięte. Przy błędzie źródła/procesora po jego rozwiązaniu zacząć od zera, żeby objąć ponownie wcześniej pominięte obrazy.
5. `no_local_images` to brak lokalnych oryginałów, nie błąd CSS. Nowe narzędzie ich nie pobiera. Operator powinien sprawdzić istniejący podgląd `/admin/tools/jarek-gearboxes/image-import-preview` lub `localize-images-dry-run` i ustalić osobny import lokalnych oryginałów istniejącym narzędziem; taki import/pobieranie nie jest częścią tej sesji ani nowego przygotowania cropów. `unreadable_or_too_large` wymaga sprawdzenia pliku (limit 16 mln pikseli); `gd_unavailable` konfiguracji serwera. Błędy nie zapisują gotowego manifestu. `public_mirror_unavailable` oznacza nieudane udostępnienie wariantu w dotychczasowym public_html mirror; naprawić uprawnienia/mapowanie storage i ponowić przygotowanie.

Bez wykonania kroku przygotowania istniejących lokalnych zdjęć sam deploy kodu **nie usunie marginesów zapisanych w oryginale**. Fallback obrazu pozostaje funkcjonalny, ale korzysta z oryginału.

### Kategorie — istniejące narzędzie tłumaczeń

1. Najpierw otworzyć diagnostykę i spisać ID/ścieżkę/status właściwych rekordów. Dla docelowej ścieżki sprawdzić także oba przodki.
2. Dla brakujących/missing tłumaczeń otworzyć `https://gpswiss.pl/admin/tools/storefront/fr-translations` i wybrać **Produkty i kategorie GPSwiss.fr**, nie sekcję **Skrzynie Jarka**. Ta druga tłumaczy tylko tytuły/opisy JarekGearbox, nie PartCategory.
3. Kliknąć **Sprawdź / Dry-run**, sprawdzić eligible dla kategorii i szacowane znaki. Ten podgląd nie uruchamia Google. Panelowy zakres obejmuje też zwykłe produkty, więc sprawdzić cały raport przed startem.
4. Jeżeli zatwierdzono ten zakres i skonfigurowano flagę/providera: wpisać `TRANSLATE-ALL-GPSWISS-FR-CATALOG`, kliknąć **Przetłumacz cały katalog** i pozostawić stronę otwartą. To osobna czynność operatora z rzeczywistym Google API/kosztem oraz zapisem tłumaczeń. Codex jej nie wykonuje. Jeśli run już trwa/przerwany, użyć Wznów istniejącego runu, nie nowego Start.
5. Obecny panelowy runner pomija failed/needs_update oraz chroni reviewed. Jeśli diagnostyka pokazuje takie rekordy, samo kliknięcie standardowego Start ich nie naprawi. Zweryfikować powód błędu i użyć zatwierdzonego trybu ponowienia istniejącego CLI albo ręcznej weryfikacji. Pusty reviewed wymaga poprawienia zatwierdzonej treści w procesie review; nie masowego ustawienia statusów ani nadpisania reviewed.

Jeśli operator ma dostęp do istniejącego CLI, jest też **zakres wyłącznie kategorii**, bez zmiany runnera:

```bash
# Sam podgląd, bez API i zapisów:
php artisan storefront:fr-translations-translate-all --type=categories --only-missing --dry-run

# Osobny apply operatora, po zatwierdzeniu powyższego zakresu/kosztu:
php artisan storefront:fr-translations-translate-all --type=categories --only-missing --apply --confirm=TRANSLATE-ALL-GPSWISS-FR-CATALOG
```

Dla świadomie zatwierdzonego ponowienia failed/needs_update użyć istniejących `--include-failed --include-needs-update`: najpierw dry-run, potem osobny apply z tymi samymi opcjami i wymaganym tokenem. Reviewed pozostaje chronione. CLI nie jest nowo dodawane ani uruchamiane w tej sesji; komendy podano jako instrukcję operatora.

## 5. Co sprawdzić po wdrożeniu

- Faktyczny commit wdrożenia, nie tylko merge PR. Jeżeli stary HTML/Blade nadal pokazuje techniczne referencje albo stary link, sprawdzić standardowy cache widoków/OPcache/CDN w procedurze wdrożenia.
- FR home: „Boîtes de vitesses” → wskazana manualna 6-biegowa kategoria, zwykła karuzela, brak osobnego boxa Jarka. PL home nadal → automatyczne skrzynie.
- FR katalog i kategoria: bez widocznej linii `Référence de la pièce JAREK-...`; zwykłe części nadal pokazują swoje referencje.
- Po przygotowaniu wariantów: `img src` Jarka na listingu zawiera `parts/photos/presentation/listing/jarek-...`; duże zdjęcie `product/jarek-...`; miniatury/lightbox i nawigacja działają. Wspólny wrapper/CSS jest bez zmian. Porównać kilka realnych zdjęć poziomych i pionowych ze zwykłymi produktami.
- Breadcrumbs, nagłówek/hero, megamenu i podkategorie używają FR nazw dla rekordów `ready_fr_name`. Pozostałe rekordy spisać z diagnostyki i uzupełnić zgodnie z faktycznym statusem. Po zakończeniu tłumaczeń ponownie sprawdzić diagnostykę oraz stronę kategorii.
- Koszyk FR: brak widocznego technicznego SKU, cena w EUR, dostawa 130 EUR, jedna pozycja/sztuka, dotychczasowy Stripe. SKU pozostaje wewnętrznie. Nie wykonywać testowej płatności live w ramach tej weryfikacji UI.
- Jarek nadal tylko FR; ceny źródłowe/stock/marketplace, globalne stawki dostaw i płatności niezmienione.

## Pliki

- `app/Http/Controllers/Storefront/HomeController.php`
- `app/Http/Controllers/Admin/FrenchCategoryDiagnosticsController.php` — nowy read-only odczyt
- `app/Http/Controllers/Admin/JarekGearboxes/JarekFrImagesController.php` — nowe lokalne przygotowanie, owner-only
- `app/Models/Part.php` — delegacja istniejącego rankingu zdjęć do wspólnego selektora
- `app/Services/Images/ListingImageSelector.php` — nowy, niezmienione reguły rankingu
- `app/Services/Images/JarekImagePresentation.php` — nowy, odczyt manifestów i osobne przygotowanie lokalnych plików
- `app/Services/Images/PartImagePresentationService.php` — tylko udostępnienie stałej wersji; algorytm bez zmian
- `app/Services/Storefront/JarekCatalogImage.php`
- `app/Services/Storefront/JarekCatalogProduct.php`
- `app/Services/Storefront/FrenchMixedCatalog.php` — opcjonalny limit strony dla standardowej sekcji home
- `resources/views/storefront/partials/product-card.blade.php`
- `resources/views/storefront/parts/show.blade.php`
- `resources/views/storefront/fr/cart.blade.php`
- `resources/views/storefront/home.blade.php`
- `resources/views/admin/tools/storefront/fr-translations.blade.php` — wyłącznie linki do narzędzi
- `resources/views/admin/tools/storefront/fr-category-diagnostics.blade.php` — nowy
- `resources/views/admin/jarek-gearboxes/fr-images.blade.php` — nowy
- `routes/web.php` — dwa chronione endpointy
- `tests/Feature/FrenchCatalogPolishTest.php` — nowy
- `tests/Feature/JarekUnifiedFrenchCatalogTest.php` — aktualne oczekiwanie standardowej sekcji gearbox home
- `docs/fr-catalog-images-categories-followup.md`

## Testy i zakres wykonania

PHP 8.4.26 / Laravel 11.57.0, SQLite w pamięci, HTTP blokowane lub mockowane. **334 testy, 3935 asercji — PASS**, w tym **14 nowych testów / 124 asercje**. Nowe testy obejmują ukrycie SKU z zachowaniem wewnętrznego identyfikatora, link home/PL, limit wspólnej karuzeli, identyczne piksele i canvasy zwykłego procesora, ranking zdjęć, brak generowania w requestach publicznych/preview, lokalne warianty/galerię, kursor/idempotencję, unieważnienie manifestu, brak lokalnych/czytelnych plików, role owner/manager, potwierdzenie POST i odczyt brakujących/pustych/niepublicznych tłumaczeń bez mutacji. Istniejące testy obejmują menu/hero/meta/breadcrumbs/podkategorie, tłumaczenia po cache PL, Stripe/webhook bez stock write Jarka, PayU PL, ceny i SEO/sitemap/hreflang.

Z zestawu regresji wyłączono 14 wcześniej potwierdzonych błędów bazowego main: 10 przypadków StorefrontCatalogSearchTest, 2 StorefrontProductBreadcrumbTest, 1 StorefrontCategorySidebarTest i test CSRF JarekGearboxEbayBulkPricePreviewTest. Nie deklarujemy całego nieprzefiltrowanego suite jako zielonego.

Pint dla dodanych/zmienionych klas, `node --check` skryptu panelu zdjęć, kompilacja Blade przez `view:cache` i `git diff --check` przechodzą. Nie formatowano masowo routes ani Part. Nie wykonano deployu, produkcyjnych zapisów, Google API, marketplace calls ani rzeczywistych płatności. Lokalne testy przygotowały wyłącznie syntetyczne zdjęcia w testowym storage.
