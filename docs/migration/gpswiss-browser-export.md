# Eksport GPSwiss → Wystawczesc na hostingu bez shella

## Obsługiwany sposób pracy

Panel `/admin/tools/gpswiss-migration-export` samodzielnie wykonuje cały eksport v3 przez krótkie POST-y i odczyty statusu. Nie uruchamia `artisan`, procesów, kolejki, supervisora, crona ani komend powłoki. Opcjonalny starszy eksport CLI v2 pozostaje dostępny dla innych środowisk; nie może przetworzyć próby panelowej.

Źródłowe części, zdjęcia, samochody, zamówienia, stany, ceny i integracje pozostają bez zmian. Zapis dotyczy tylko prywatnych plików technicznych i eksportowych. Nie ma wywołań Allegro/eBay/Ovoko, importu, publikacji ani wiadomości do klientów. Pliki zawierają dane zamówień i klientów, więc pobieranie wymaga zalogowanego OwnerAdmin oraz zgodności właściciela runu. Odpowiedzi są `private, no-store`, bez publicznych linków.

## Istniejący run po #1511

Zgłoszony run: `8de6e27c-ad7c-470b-830b-b9ecee9446ba`, poprzednia próba `7bd47b3f-7600-4dd1-aaa3-5c3d4b595c87`, stan `snapshot_pending / worker_launch_unavailable`.

Po wdrożeniu:

1. Zaloguj się jako ten sam właściciel i otwórz panel. Discovery odnajduje ostatni nieukończony run również bez `#run=...`.
2. Dla starej próby zaznacz potwierdzenie i kliknij **Ponów snapshot w trybie panelowym**. Zachowujemy ten sam `run_id`; powstaje nowy identyfikator próby i własne granice danych. Gotowy stary snapshot albo aktywna blokada uniemożliwiają taką konwersję.
3. Panel wykonuje kolejne `snapshot_tick`, następnie partie zdjęć i archiwów. Nie twórz nowego eksportu jako obejścia.
4. **Pauza snapshotu** zapisuje pauzę na serwerze; **Stop lokalnej pętli** kończy wysyłanie kolejnych żądań. Obie akcje zachowują checkpoint. Po reloadzie użyj **Wznów istniejący eksport**.
5. Po 419/401 zaloguj się ponownie, odśwież stronę i użyj Wznów. CSRF pozostaje włączone. Utrata odpowiedzi lub chwilowy błąd sieci prowadzi najpierw do odczytu statusu tego samego runu, z ograniczonym backoff, bez automatycznego ponawiania Start/Retry.
6. Po ukończeniu pobierz manifesty oraz wszystkie wskazane ZIP-y próbki i pełnego eksportu. Sprawdź SHA-256 pobranych plików oraz raporty braków i powiązań.

Przygotowanie tego PR nie uruchamia powyższych operacji produkcyjnych.

## Rzeczywista spójność: zamrożony eksport logiczny

MySQL/Laravel nie udostępniają tutaj identyfikatora pozwalającego przenosić otwartą transakcję REPEATABLE READ pomiędzy niezależnymi żądaniami HTTP. Sam `max_id` ani filtr dat nie rekonstruuje poprzednich wersji zmienionych/usuniętych rekordów. V3 nie deklaruje snapshotu transakcyjnego ani danych z jednego globalnego momentu.

Każdy zbiór zapisuje schemat, klucz, górną granicę, moment ustalenia granicy, licznik, kursor i sumy kontrolne. Rekordy są wersjami zaobserwowanymi przy pierwszym odczycie. Niezmienne porcje JSON/CSV są zatwierdzane przed aktualizacją checkpointu. Nowe numeryczne ID ponad ustaloną granicą nie wchodzą do zbioru.

Cutoff czasu bazy i filtr `created_at` są dodatkowym ograniczeniem selekcji. Sekundowa precyzja dat, wartości NULL, daty ustawione wstecz i tekstowe klucze bez dat utworzenia nie pozwalają zagwarantować wykluczenia każdego rekordu powstałego po kliknięciu Start. Granice są ustalane osobno dla zbiorów. Te ograniczenia są zapisane w manifeście; nie przedstawiamy ich jako historycznego snapshotu globalnego.

Po odczycie następuje drugi przebieg porównujący pełne wyeksportowane wartości, liczbę rekordów, klucze i sumy porcji. Nie opiera się wyłącznie na `updated_at` — zmiany Query Builderem bez aktualizacji tej daty również są wykrywane. Dla datasetów strict zmiana/usunięcie, dryf schematu albo rozbieżność sum zatrzymuje próbę z bezpiecznym powodem. Porcje datasetów strict nie są zastępowane świeżymi danymi. Obecnie dopuszczone datasety mutable to `marketplace_listings` i `parts`: obowiązuje kontrakt z `dataset_consistency_overrides` i [audytu recovery](https://github.com/talarekr/gpsystem/blob/main/docs/migration/gpswiss-mutable-listings.md), z wersjonowanymi shardami i ograniczonym reconciliation. Recovery gotowych części zachowuje stare poprawne shardy; szczegóły i audyt pozostałych datasetów: [parts](https://github.com/talarekr/gpsystem/blob/main/docs/migration/gpswiss-mutable-parts.md). Świadomy Retry ustala nowe granice pod tym samym runem i zachowuje poprzednie pliki próby.

Relacje część–samochód są wyprowadzone z zamrożonych części. Klienci i tożsamości kont są ograniczeni referencjami w zakresie zamrożonych źródłowych zamówień/ofert/zwrotów. Osobny audyt relacji czyta tylko zamrożone pliki. Brakujące referencje, np. historyczna pozycja do usuniętej części, trafiają do `relation-issues.jsonl`; pozycja zachowuje nazwę, SKU i pozostałe dane.

Zgodne dwa przebiegi nie wykluczają zmian i przywróceń pomiędzy obserwacjami ani nie dowodzą globalnej zgodności czasowej kwot zamówień i pozycji. Dlatego manifest zachowuje `cutover_ready=false`. Ścisły snapshot z jednego momentu wymaga osobno uzgodnionego zatrzymania zapisów lub kopii bazy/filesystemu. Eksporter nie zatrzymuje integracji.

## Etapy i granice pracy HTTP

- Metadane/granice: jeden zbiór na tick; wymagany rzeczywisty bezpieczny unikalny klucz. Brak takiego klucza jest jawnym błędem, bez niestabilnego stronicowania OFFSET.
- Dane i weryfikacja: do 5 rekordów i docelowo 192 KiB porcji. Pojedynczy surowy rekord powyżej 256 KiB zatrzymuje próbę jako `snapshot_row_too_large_for_http`; nie jest pomijany ani obcinany. Taki przypadek wymaga rozszerzenia strumieniowania pola, nie ponawiania w ciemno.
- Zbiory obejmują części, samochody, relacje, kategorie, lokalizacje, parametry/tłumaczenia, zamówienia i pozycje, przechowywane płatności/zwroty/przesyłki/notatki oraz prywatne profile i referencje marketplace. Nieobecne opcjonalne tabele są zinwentaryzowane. Pola sekretów/auth/kart są pomijane według wspólnej polityki, bez eksportowania credentials kont.
- Audyt referencji: jedna porcja źródłowa i maksymalnie 20 sprawdzeń FK na tick, bez SQL do żywej bazy.
- Dopiero po zakończeniu i weryfikacji danych powstaje inwentarz zadań zdjęć, po maksymalnie 20 rekordów na tick.
- Zdjęcia: odczyt/kopia/weryfikacja typowo po 512 KiB, do czterech kroków i około 1,5 s budżetu na żądanie. Serializowalny kontekst SHA-256 pozwala rozłożyć również końcowe sprawdzenie całego pliku między ticki. Bez ponownego odczytu całej galerii ani jednego 100-MiB przebiegu. Dotychczasowy limit oryginału 100 MiB pozostaje jawnie raportowany; nigdy nie podmieniamy go miniaturą.
- Próbka: do pięciu rzeczywistych części z różnymi przypadkami, następnie projekcja powiązanych danych po jednej porcji; zdjęcia wykorzystują już zweryfikowane kopie.
- Archiwa: jedna mała część ZIP na tick, do 8 MiB danych i 20 wpisów łącznie z mapą; bez kompresji oryginałów. Osobne checkpointy/manifesty próbki i pełnego eksportu.

Budżety ograniczają pracę aplikacji. Opóźnienie pojedynczego zapytania, storage albo zatrzymanie procesu przez hosting nadal może przerwać request; checkpoint umożliwia odtworzenie. Nie deklarujemy gwarancji czasu rzeczywistego dla zewnętrznego I/O. Utrata odpowiedzi nie cofa zatwierdzonego kursora i nie tworzy nowego UUID.

## Format v3

Próbka i pełny eksport mają ten sam format relacyjnych porcji CSV UTF-8 z BOM oraz JSON. Typy, kolumny, pominięcia i relacje zawiera `fields.generated.json` lub `fields.json` próbki. `statuses.json`, osobne `sold-part-ids.csv` i `parts-to-list-ids.csv` opisują rzeczywiste reguły panelu: `status=sold` oraz `needs_listing=true`, bez wnioskowania ze stanu zero lub braku linku.

ID/SKU/kody należy odczytywać jako tekst. CSV używa średnika, `\N` oznacza NULL, zagnieżdżone wartości są JSON, wiodący backslash jest escapowany, a tekst mogący uruchomić formułę ma prefiks apostrofu. Daty pozostają zapisane jak w źródle, bez zgadywania strefy.

`migration-manifest.json` zawiera granice, liczniki, kontrolę spójności i odwołania do porcji. `photo-manifest.csv` / `.jsonl` zawiera ID części/zdjęcia, oryginalną nazwę, ścieżkę paczki, kolejność, zdjęcie główne, rozmiar, SHA-256 oraz status. `missing-originals.jsonl` wykazuje braki/zmiany/nieczytelne pliki. Nazwa jest zachowana pod `images/ID_CZĘŚCI/image-ID_ZDJĘCIA/NAZWA`, co eliminuje kolizje.

`archive-manifest.json` oraz `sample-archive-manifest.json` wymieniają ZIP-y i ich sumy. Każdy ZIP zawiera `__gpswiss_volume_manifest.json`. Plik większy niż budżet wolumenu jest bezstratnie podzielony: mapa podaje docelową ścieżkę, rozmiar, offsety, SHA fragmentów oraz SHA całego pliku. Odtwarzanie wymaga zebrania fragmentów tego samego `artifact_index` w kolejności offsetów, zapisania pod `archive_path` i sprawdzenia całego SHA-256. Dotyczy to również dużych oryginałów; nie są rekompresowane ani zastępowane miniaturami. Żaden etap eksportera na GPSwiss nie wymaga shella. Narzędzie docelowe musi rozumieć mapę wolumenów; to PR eksportera, bez uruchamiania importu.

Allegro zachowuje dane pomocnicze/historyczne, bez aktywnych powiązań po imporcie. ID/URL/status/rynek/konto eBay i Ovoko pozostają zachowane jako dane do świadomego przyjęcia przez system docelowy. Każdy manifest wyłącza automatyczny import, publikację i synchronizację.

## Ponawianie zdjęć

**Ponów nieudane kopie zdjęć** wymaga świadomego potwierdzenia. Dotyczy przejściowych `COPY_FAILED`, `UNREADABLE_ORIGINAL`, `INSUFFICIENT_DISK_SPACE`, z identycznym zamrożonym źródłem. Nie odświeża automatycznie fingerprintu dla `SOURCE_CHANGED` ani nie pobiera `MISSING_ORIGINAL` z internetu.

Retry tworzy osobną generację kopii w tym samym runie i tej samej próbie snapshotu DB. Poprawne pliki i ich receipt pozostają. Tylko nieudane zadania przechodzą ograniczone stronicowanie oraz ponowną weryfikację. Kanoniczny manifest uwzględnia najnowszy wynik danej pary część–zdjęcie, bez duplikowania wierszy. Poprzednie paczki są zachowane w prywatnym `package-history`, a nowe manifesty/ZIP-y powstają z tych samych zamrożonych danych. Nie wykonuje się ponownego odczytu bazy.

Procedura późniejszego zatrzymania integracji pozostaje w `gpswiss-export-and-sync-stop.md`; jest dokumentacją do osobnej decyzji, a nie akcją wykonywaną przez eksport.
