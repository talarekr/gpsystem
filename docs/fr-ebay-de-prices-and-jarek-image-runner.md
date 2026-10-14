# GPSwiss.fr: ceny eBay.de, diagnostyka kategorii i przygotowanie zdjęć Jarka

Repozytorium: `talarekr/gpsystem`. Baza zmian: `main` po PR #1491 (`a069cf6ccfc8611764d2c8d9f138fae218ec9298`). Nie wykonano deployu, produkcyjnych zapisów ani wywołań marketplace/Google. Testy używają lokalnej bazy, plików fixture i atrap HTTP.

## Ustalenia i granice audytu

**Ceny zwykłych produktów:** poprzedni `FrenchPriceResolver` preferował `parts.price` w EUR, następnie lokalny listing eBay FR, a dla PLN przeliczenie NBP. Nie wybierał eBay DE. SQL sortowania/filtrowania powielał ten błędny wybór, więc samo poprawienie karty nie wystarczało. `Part.ebay_price` jest polem oczekiwanej ceny PLN z narzutem; nie wolno odczytywać go jako gotowej ceny EUR z eBay.de.

**Zdjęcia Jarka:** PR #1491 podłączył ten sam procesor pikseli, ranking zdjęć, warianty i wspólne komponenty karty/galerii, ale brakujące warianty trzeba przygotować. Poprzedni panel wykonywał pojedynczą partię z ręcznym kursorem; nie prowadził operatora przez cały zakres ani nie miał trwałego checkpointu. Deploy kodu sam nie przygotowuje zdjęć.

**Kategorie:** w aktualnym `main` lewa kolumna mega menu, środek, podkategorie, nagłówki i breadcrumbs już odczytują tłumaczenia FR. Drzewo FR ładuje relacje `translations` z bazy poza cache PL i obejmuje także kategorię z ekspozycjami Jarka. Nie znaleziono osobnego odczytu `PartCategory.name` dla lewej kolumny. Nie dodano słownika zgadywanych tłumaczeń w kodzie.

Nie ma dostępu do produkcyjnej bazy w tej sesji. Nie potwierdzono liczby produktów, konkretnych production ID, zawartości ich listingów ani brakujących rekordów tłumaczeń. Nie można stwierdzić jako faktu, że przyczyną polskich kategorii na produkcji jest brak danych. Możliwe są: brak rekordu FR, niepubliczny status, puste `name`, tłumaczenie nadal identyczne ze źródłem, tłumaczenie innego ID o tej samej ścieżce lub nieaktualna wdrożona wersja. Poniższe raporty odróżniają te sytuacje.

## Polityka ceny zwykłych Part

1. Jednoznaczna, aktywna lokalna oferta eBay DE, EUR, cena 0.01–999999.99.
2. Zatwierdzona cena `parts.price` w EUR, jeśli nie ma prawidłowej DE.
3. PLN / EURPLN NBP jako ostatni fallback, tylko w polityce `existing_eur_nbp`.

Rozpoznanie DE: jawne `marketplace=ebay_de` bez sprzecznego konta FR; albo `marketplace=ebay` z kontem `code=ebay_de`; albo nieprzypisany legacy eBay z URL rzeczywiście pod `ebay.de`. Nie zgadujemy rynku dla nieznanego konta/URL. Cena eBay FR nie jest fallbackiem.

Oferta musi mieć dodatnią quantity i status active/published/listed/live. Jawne stale/not-seen/ended/not-found oraz unmatched/ambiguous/conflict/unlinked są wykluczone. Samo `part_id` nie usuwa sprzecznego `match_status=unmatched`; jeśli raport to wykryje, wymaga to osobnego sprawdzenia powiązania źródłowego. Nie wprowadzono arbitralnego limitu wieku ostatniej synchronizacji. Lokalne dane mogą być starsze od live eBay — ten PR niczego nie synchronizuje.

Kilka ofert DE z taką samą ceną jest jednoznaczne. Różne prawidłowe ceny DE blokują cenę/zakup (`ambiguous_ebay_listing`), bez wybierania minimum, najnowszej lub ceny Part. Ta sama polityka odpowiada za publiczne ceny, globalne sortowanie, granice EUR i checkout/kwotę Stripe.

Źródłowe Part/MarketplaceListing nie są zapisywane. Dostawa nadal korzysta z dotychczasowego mapowania FR 55/70/130 EUR. Cena Jarka nadal korzysta z osobnej polityki PLN × 1.25 / NBP i dostawy 130 EUR. Nie zmieniono integracji Stripe/PayU/webhooków ani źródłowego stocku.

## Globalny raport cen: operator po wdrożeniu

Zaloguj się jako owner/admin lub manager na gpswiss.pl:

- `/admin/tools/storefront/fr-translations/price-audit`: panel, bez automatycznego skanu.
- `?run=1`: pełny raport, sumy, top 50 różnic i dwa wskazane produkty.
- `?format=json`: pełne podsumowanie i przykłady.
- `?format=csv`: wszystkie storefront-visible Part, strumieniowane partiami 200.

Raport zawiera ID, SKU, numer części, slug, cenę źródłową, źródło/cenę FR, lokalne DE listing ID/cenę/walutę, różnicę kwotową i procentową oraz reason code. JSON obejmuje lokalne statusy, daty synchronizacji i wyłącznie wybrane pola cenowe snapshotów. Nie udostępnia credentiali ani całego raw payload. Dodatkowe kolumny `previous_policy_*` symulują starą politykę, żeby pokazać poprzednie rozbieżności po poprawieniu resolvera; to nie historyczny pomiar live strony.

Raport obejmuje zwykłe Part; Jarek pozostaje osobnym źródłem. Nie pobiera NBP ani live eBay, korzysta wyłącznie z istniejącego cache NBP. Bez ważnego cache PLN fallback w raporcie jest niedostępny. Brak rekordu produktu daje `part_not_found` z pustym ID, bez wymyślania danych.

Dwa slugi sprawdzane oddzielnie:

- `audi-rs3-8y-dpf-katalizator-filtr-czastek-stalych-8s0131701f-8s0131765`, zgłoszona przez użytkownika cena DE **1476.35 EUR**.
- `a2904905000`, zgłoszona cena DE **3487.00 EUR**.

Te wartości są oznaczone jako zgłoszone, nie odczytane live. Testy tworzą jawne fixture tych cen i potwierdzają wybór oraz checkout pierwszego produktu: 1476.35 + 55 = 1531.35 EUR. Ostateczne wartości i ID produkcyjne trzeba odczytać z raportu po wdrożeniu, a następnie porównać z produktem/koszykiem. Nie używać tego raportu do automatycznego apply cen.

## Trwały runner zdjęć Jarka

W procedurze wdrożenia tego PR uruchom migrację `2026_10_14_000004_create_jarek_image_runner_checkpoints.php`. Tworzy wyłącznie techniczne tabele `storefront_jarek_image_runs` i `storefront_jarek_image_items`. Nie przepisuje danych Jarek/Part/PartImage. Bez migracji panel jawnie zgłasza brak schema i nie zaczyna pracy. Migracji produkcyjnej nie uruchomiono w Codexie.

Panel owner/admin: `/admin/tools/storefront/fr-translations/jarek-local-images` (link z narzędzi FR).

1. Kliknij **Sprawdź zdjęcia Jarka**. Podgląd automatycznie obejmuje aktywne ekspozycje, po maksymalnie 10 produktów na request. Nie generuje obrazów; zapisuje tylko techniczny raport/checkpoint. Widać aktywne/sprawdzone produkty, gotowe/brakujące/błędne obrazy, pominięte produkty bez lokalnych zdjęć i sample błędów.
2. Po zakończeniu podglądu wpisz `PREPARE-JAREK-FR-LOCAL-IMAGES` i kliknij **Przygotuj wszystkie brakujące warianty**. Maksymalnie 2 obrazy na request, automatyczne kolejne partie. Nie trzeba wpisywać ID ani numeru zdjęcia.
3. **Pauza**, **Wznów**, **Stop** mają trwały stan. Otwarcie strony tylko odczytuje ostatni checkpoint; po reloadzie/awarii kliknij Wznów. Pozostaw stronę otwartą podczas aktywnego przebiegu.
4. Progress pokazuje processed/generated/skipped/failed/remaining, aktualny produkt, ostatni sukces i błąd. W części advanced kursor jest tylko do odczytu. Gotowe, błędne i pominięte pozycje preview są już terminalnymi pozycjami postępu; processed liczy obrazy/pozycje, nie produkty.
5. Błąd jednego obrazu jest raportowany i pozostały zakres kontynuuje. Wynik końcowy z błędami jest oznaczony jawnie. Po naprawie przyczyny wykonaj nowy podgląd; istniejące gotowe warianty nie są generowane ponownie.

Checkpoint i wyniki znajdują się w DB, więc utrata sesji przeglądarki/cache nie zeruje postępu. Każdy przebieg należy do operatora; inne konto nie steruje jego run_id. W trakcie pracy lease pojedynczego obrazu trwa 120 sekund. Po awarii i przed jego wygaśnięciem Wznów może chwilowo nie zwiększać licznika. Po wygaśnięciu gotowy manifest odzyskuje ukończoną pracę; brak manifestu oznacza `interrupted_image_attempt` i przejście dalej, bez zapętlenia fatalnego GD. Pauza/Stop pozwalają dokończyć już rozpoczęty obraz, potem nie podejmują kolejnego.

Wyłącznie istniejące pliki `jarek-gearboxes/.../01.jpg`–`60.jpg`. Bez HTTP/downloadów, zapisów modeli źródłowych i publikowania produktów. Nie tworzy Part ani PartImage; procesor otrzymuje wyłącznie niezapisany deskryptor obrazu. Oryginały nie są nadpisywane. Publiczne karty i galeria używają gotowych wariantów zwykłego procesora po ich przygotowaniu.

## Kategorie FR: dokładne ID, fallback i istniejący runner tłumaczeń

Diagnostyka: `/admin/tools/storefront/fr-translations/category-diagnostics`; CSV `?format=csv`; tylko fallbacki `?missing_only=1`; filtr `?q=uklad-napedowy` lub `?q=nadwozia`.

Raport wymusza FR drzewo także na admin hoście PL. Dla każdego root/parent/leaf pokazuje ID, parent_id, poziom, source_system, visibility, pełną ścieżkę, FR name/status, faktyczny publiczny tekst, przyczynę fallbacku, gotowość opisu, obecność w publicznym FR drzewie, ID rozwiązane z URL i liczbę rekordów o tej samej ścieżce. Flaga identycznego tłumaczenia i źródła wymaga sprawdzenia treści; nie zgaduje języka.

- `missing_translation_table`: brak schema.
- `missing_translation`: brak `category_translations` dla **tego category_id**, locale `fr`.
- `nonpublic_status`: rekord jest, ale nie ma statusu translated/reviewed.
- `empty_fr_name`: publiczny status, pusta nazwa.
- `ready_fr_name`: istniejący FR name używany przez UI. Jeżeli nadal jest PL, sprawdzić treść tego rekordu, nie dodać fallbacku w kodzie.

Fallback do nazwy źródłowej przy pierwszych czterech przyczynach jest obecnym, prawidłowym zachowaniem. FR nazwa z gotowego rekordu zawsze ma pierwszeństwo. Status reviewed jest chroniony przez istniejące narzędzie; pusty lub polski reviewed wymaga osobnej redakcji danych i nie zostanie automatycznie nadpisany.

Istniejące narzędzie tłumaczeń kategorii obejmuje `PartCategory` na wszystkich poziomach, nie tylko liście. Na `/admin/tools/storefront/fr-translations` wykonaj **Dry-run** standardowego katalogu, sprawdź listę/sumy kategorii, następnie świadomy **Start** z tokenem `TRANSLATE-ALL-GPSWISS-FR-CATALOG`; **Wznów** kontynuuje istniejący run. Ten browser runner obejmuje katalog/produkty razem z kategoriami — nie przedstawiać go jako category-only. Kod runnera tłumaczeń nie został zmieniony.

Gdy potrzebny jest zakres wyłącznie kategorii, istniejąca komenda produkcyjna daje kontrolę zakresu. Najpierw operator uruchamia odczyt:

```sh
php artisan storefront:fr-translations-translate-all --dry-run --type=categories --only-missing --include-needs-update --include-failed
```

Dopiero po sprawdzeniu diagnostyki/dry-run i osobnej zgodzie na produkcyjne tłumaczenia:

```sh
php artisan storefront:fr-translations-translate-all --apply --type=categories --only-missing --include-needs-update --include-failed --chunk=20 --confirm=TRANSLATE-ALL-GPSWISS-FR-CATALOG
```

Apply korzysta z istniejącej konfiguracji Google i zapisuje tłumaczenia; **nie został uruchomiony w tej sesji**. Powtórne uruchomienie pomija gotowe pasujące i reviewed. Rekordy reviewed nie są naprawiane tym poleceniem.

Cache: FR drzewo nie korzysta z trwałego PL cache i odczytuje translations ponownie w kolejnym request. Po zapisaniu tłumaczeń ponownie otwórz publiczną stronę i diagnostykę. Przy nadal starym HTML sprawdź rzeczywiście wdrożony SHA, zwykłą procedurę cache widoków/OPcache oraz ewentualny CDN; nie uruchamiaj automatycznego masowego flush w tym narzędziu.

## Sprawdzenie po wdrożeniu

- FR mega menu: lewa kolumna i środek mają ten sam FR tekst dla tego samego ID; root/parent/leaf zgodne z CSV diagnostyki. PL nadal pokazuje PL.
- FR manualna kategoria: `uklad-napedowy/skrzynie-biegow-i-inne-elementy/manualna-6-biegowa-skrzynia-biegow`: nagłówek, breadcrumbs, podkategorie i opis zgodne z gotowymi FR rekordami. Home „Boîtes de vitesses” nadal wskazuje tę kategorię, Jarek nadal w zwykłym gridzie.
- Globalny raport cen oraz dwie wskazane karty: DE EUR zgodne z ceną widoczną FR, filtrowaniem i koszykiem; dostawa według dotychczasowej kategorii. Rozbieżne oferty DE muszą pokazać brak ceny/zakupu, nie przypadkową kwotę.
- Preview zdjęć, Start, Pauza, reload, Wznów: liczniki zachowane, bez ręcznego kursora. Sprawdzić gotowe miniatury i duże zdjęcie Jarka; stare remote-only źródła mają jawny skipped.
- Publiczne JAREK-... nadal ukryte, cena/dostawa Jarka niezmieniona, hreflang/sitemap i FR/PL checkout bez regresji. Nie wykonywać płatnego zamówienia ani provider sync do samej kontroli wyglądu.

## Pliki odpowiedzialne za przepływy

Ceny: `EbayDeLocalPrice`, `FrenchPriceResolver`, `FrenchMixedCatalog`, `FrenchPriceAudit`, `FrenchPriceAuditController`, `config/storefront-fr-checkout.php`, widok admin audytu i route. Istniejące `FrenchCheckoutService`/`StripeFrCheckout` pobierają tę samą cenę przez resolver; ich kod nie jest zmieniany.

Zdjęcia: `JarekImagePresentation`, `JarekImageRunner`, `JarekFrImagesController`, migracja checkpointów, widok `admin/jarek-gearboxes/fr-images.blade.php`, limiter w `AppServiceProvider` i route. Wspólny `PartImagePresentationService`/product-card/gallery pozostaje bez zmiany algorytmu.

Kategorie: `CategoryTreeService::forLocale`, `FrenchCategoryDiagnostics`, kontroler/widok diagnostyki, linki narzędzi FR. Istniejące `PartCategory` metody tłumaczeń, `storefront/partials/category-menu.blade.php`, category show/breadcrumbs oraz runner tłumaczeń zostały sprawdzone bez dopisywania tłumaczeń w kodzie.
