# Skrzynie Jarka — tłumaczenia FR w istniejącym narzędziu

## Zakres i przyczyna zmiany

Dotychczasowy `FrenchCatalogTranslationService` i runner obsługiwały wyłącznie Part/PartCategory. `StorefrontTranslation` jest bazową klasą wspólnych statusów, a nie uniwersalną tabelą polimorficzną: zapis odbywa się w `part_translations` i `category_translations`.

Rozszerzenie używa tego samego silnika, browser runnera, retry, lease i recovery. Dodaje minimalną tabelę `jarek_gearbox_translations` oraz model potomny `StorefrontTranslation`, relację JarekGearbox i scope `jarek_gearboxes` w istniejącej tabeli runów. Nie dodaje osobnego panelu, workera, komendy shell ani drugiego silnika tłumaczeń.

## Instrukcja operatorska

Po osobnym wdrożeniu kodu i migracji wejdź jako owner_admin lub manager na:

https://gpswiss.pl/admin/tools/storefront/fr-translations

Na tej samej stronie są dwie sekcje:

- **Produkty i kategorie GPSwiss.fr** — dotychczasowy scope `catalog`.
- **Skrzynie Jarka — tłumaczenia FR** — scope `jarek_gearboxes`.

W sekcji Jarka:

1. Kliknij **Sprawdź / Dry-run**. Zobaczysz total, eligible, pola, szacowane znaki, translated/reviewed, missing, failed, needs_update, skipped, przykłady i powody pominięcia. Liczniki obejmują Jarka, bez produktów/kategorii. Liczba 1518 pochodzi z przekazanego wcześniejszego preview; narzędzie zawsze odczytuje bieżącą liczbę z bazy, bez hardkodowania.
2. Sprawdź gotowość Google i flagę FR. Wejście na stronę, odczyt statusu i dry-run nie wywołują Google.
3. Wpisz dokładnie `TRANSLATE-JAREK-GEARBOXES-FR` i kliknij **Start tłumaczeń Jarka**. Token głównego katalogu nie uruchamia Jarka. Preview jest przypisane do konta i scope, ważne godzinę, jednorazowe.
4. **Zostaw stronę otwartą.** Jarek zawsze działa w trybie browser, nawet jeśli główny katalog ustawiono na queue. Jeden tick przetwarza najwyżej jeden rekord; rekord może mieć tytuł i opis, więc Google może otrzymać kilka requestów/segmentów.
5. Po przerwaniu/odświeżeniu/ponownym logowaniu sprawdź status i kliknij **Wznów**. Zachowuje run_id, kursory, liczniki i pending attempt. Nie klikaj ponownie Start, kiedy istnieje running/paused/failed/stopped_on_error.
6. **Pauza** działa w najbliższym bezpiecznym punkcie. **Stop** kończy run; stopped nie jest wznawiany. Nowy run wymaga nowego dry-run.
7. Sprawdź last success, last error oraz przykłady błędów. Przy aktywnym lease poczekaj na jego wygaśnięcie; nie kasuj locków/cache, aby przyspieszyć wznowienie.

Start/ticki wywołują płatne API Google. Nie uruchomiono ich na produkcji w ramach tego PR. Status i dry-run służą operatorowi do świadomej decyzji.

## Źródła, idempotencja i kody techniczne

Tłumaczone pola: `title` i publiczny tekst `description`. Opis pochodzi najpierw z `plain_description`, a następnie z tekstowych sekcji JSON Allegro albo tekstu/HTML. Znaczniki są usuwane, odstępy między blokami zachowane. JSON klucze, obrazy i parametry nie są polami tłumaczenia.

Nie tłumaczymy cen, waluty, stocku, identyfikatorów ofert ani osobnego SKU. W tytule/opisie chronione są tokeny z cyframi (numery części, OEM, VIN, kody), znane symbole skrzyń, krótkie wielkoliterowe symbole oraz wartości parametrów oznaczonych jako kod/numer/VIN/OEM/symbol. Przed API są zastępowane znacznikami. Wynik musi zachować każdy znacznik dokładnie raz; brak, duplikacja lub zmiana daje `technical_code_changed` i status failed. Wadliwy tekst nie jest zapisywany jako translated. Pola zawierające tylko rozpoznane kody/liczby są kopiowane bez Google.

Maskowanie nie zastępuje kontroli językowej: po tłumaczeniu operator powinien sprawdzić tytuły i warunki zwrotu starej skrzyni przed przyszłą publikacją.

`source_hash` obejmuje wybrany tytuł i znormalizowany opis. Zmiana ceny/stocku nie zmienia hash treści. Zmiana treści jest widoczna jako needs_update; zmiana podczas wywołania API nie zapisuje starego wyniku jako gotowego. Reviewed jest zawsze chronione. Aktualne translated/reviewed, istniejące failed i needs_update są pomijane według dotychczasowej polityki; UI nie umożliwia include_failed/include_needs_update. Retry 429/5xx dotyczy wyłącznie konkretnej próby bieżącego runu. Uszkodzony kod nie jest automatycznie ponawiany.

## Checkpointy i separacja scope

`storefront_fr_translation_runs.scope` ma default `catalog`, więc istniejące runy zachowują przypisanie. Jarek ma osobne cache preview per actor, pointer runu, mutation lock i processing lease. Per-record lock używa typu `jarek_gearboxes` i ID. Główne i Jarek runy mogą istnieć jednocześnie; ich ID, liczniki, pause/resume/stop i cache nie kolidują.

Checkpoint zapisuje się w bazie przed wywołaniem providera. Utrata cache/sesji nie usuwa runu. Start nie zastępuje aktywnego runu. Recovery rozpoznaje zapisany sukces i nalicza go raz; niepotwierdzona próba queued zostaje failed/interrupted_attempt zamiast ponownego naliczania kosztu. Ponowne wejście na stronę nie uruchamia ticków automatycznie — trzeba kliknąć Wznów.

Dry-run zapisuje jedynie techniczny raport w osobnym cache, zgodnie z obecnym mechanizmem. Jeśli cache preview zniknie przed Start, trzeba wykonać nowy dry-run. Trwały run po Start jest niezależny od tego raportu.

## Endpoints

Wszystkie mają istniejące auth/admin/host/CSRF restrictions; dostęp z publicznego hosta FR jest blokowany.

Prefix: `/admin/tools/storefront/fr-translations/jarek-gearboxes`

- GET `/status`
- POST `/dry-run`, `/start`, `/process-next-batch`, `/pause`, `/resume`, `/stop`

Scope jest stałą trasy, nie parametrem kontrolowanym przez klienta. Nie ma nowego endpointu panelu ani publikacji/apply.

## Wdrożenie i ograniczenia zakresu

Wymagana nowa migracja `2026_10_14_000001_add_jarek_gearbox_fr_translations.php`: tabela tłumaczeń Jarka i kolumna scope w runach. Przed migracją przyciski Jarka nie pozwalają na start; istniejący scope katalogu zachowuje kompatybilność. Nie wykonano migracji na produkcji ani deployu. Rollback jest blokowany, jeśli istnieją checkpointy Jarka, aby po usunięciu scope nie zostały uznane za runy katalogu.

Zapisy runtime dotyczą tylko dedykowanych tłumaczeń, technicznych runów/cache i istniejących logów silnika. Źródłowy JarekGearbox, Part, ceny, stock, dostawa, checkout, Stripe, PayU i marketplace nie są zmieniane. Nie ma publikacji ani tworzenia Part.

**Publikacja Jarka na gpswiss.fr jest osobnym etapem po tłumaczeniach.** Blokery FR-only, stocku, ceny +25%, dostawy i kursu NBP nie wpływają na eligibility tekstów w tym narzędziu.

## Walidacja PR

PHP 8.4.26, Laravel 11.57.0, SQLite in-memory. 145 testów / 1850 asercji przeszło: 14 nowych testów Jarka, istniejące testy silnika, narzędzia, browser runnera i durable recovery oraz regresje katalogu/checkoutu FR, Stripe, PayU i preview Jarka. Google był atrapą Http, bez rzeczywistych wywołań. Sprawdzono również składnię JavaScript i unikalność ID kontrolek obu sekcji. Nie uruchomiono produkcyjnego tłumaczenia ani migracji produkcyjnej.
