# Parts: reconciliation ukończonego eksportu bez restartu

## Przyczyna i granica dowodu

Zgłoszony run `8de6e27c-ad7c-470b-830b-b9ecee9446ba`, attempt `9cb33c92-3f1e-4db0-b08d-052715aa5f4c`: export79957/79957, snapshot_failed, parts, cursor8926, snapshot_row_updated_after_cutoff. Globalny `cutoff_database_time` pochodzi z początku całej próby. Legacy `parts` w #1517 pozostało strict, więc `page()` w verification odrzucało wiersz z updated_at późniejszym od cutoff. Pełne przeczytanie rekordów nie oznacza ukończenia verification/finalizacji.

Normalne ścieżki mutacji:

- `MarketplaceOrdersImportService` po zapisaniu prawdziwej pozycji zamówienia wywołuje `PartAvailabilityEventService::sold()`. Ten kod zapisuje Part przez Eloquent: status=sold, quantity=0, needs_listing=false, widoczność, sale_source i sold_at. Restore zmienia je w drugą stronę.
- `LocalSaleController` oraz `LocalSaleEndMarketplacesService::apply()` zapisują sprzedaż lokalną i Part. Zmiany dostępności/operatora w PartResource również używają save/update.
- `OvokoStockSyncRunProcessor` aktualizuje quantity/status/widoczność. `OvokoListingUrlBackfillService` potrafi zapisać ovoko_price/walutę. Stripe webhook aktualizuje stock/status i jawnie updated_at przez Query Builder.
- `PreparePartMarketplaceListingService::markLocallyListed()` zapisuje status/needs_listing/widoczność. `MarketplaceListingReadinessService` zapisuje review_metadata; edycja parametrów, opisów, magazynu i zdjęcia kodu też może zmieniać Part.
- `Part::saving` przelicza ceny Allegro/eBay i przy zmianie car_id wypełnia vehicle_snapshot. Nawet zapis dostępności może więc zmienić więcej niż updated_at. Eksporter nie używa save/update modeli.

To potwierdza mechanizm normalnej mutacji; nie dowodzi konkretnego zamówienia ani ID produkcyjnego sprawcy. Brak dostępu do produkcyjnej bazy/checkpointu/logów. Cursor8926 jest ostatnim kluczem eksportu, nie identyfikacją wadliwego wiersza verification. #1517 zapisywało `source_conflict` także dla części: jeżeli jest, prywatne **Diagnozuj konflikt datasetu** pokazuje zapisane ID/daty jako recorded_at_failure. Jeśli go brak, odczyt używa verify_cursor i bezpiecznej bieżącej strony rekordów, oznaczonej jako aktualna obserwacja, nie historyczny dowód. Bez raw_payload, opisów klientów, tokenów, message/stack. Z samego updated_at nie odtwarzamy poprzednich pól ani źródła zapisu.

## Czy można uniknąć pełnego re-eksportu parts?

Tak: stare shardy zawierają CAŁE oczyszczone wiersze, SHA-256 JSON/CSV, liczbę wierszy i cursor_end. Brak osobnej per-row fingerprint map nie oznacza braku before-image: można odtworzyć ID/hash wiersza z niezmiennego JSON. Pełny hash sharda pozwala zachować jego pliki, jeśli wiersze się nie zmieniły. Nie trzeba kasować/odłączać całego datasetu ani jeszcze raz zapisywać wszystkich produktów.

Dla parts recovery wymaga ukończonego exportu tego datasetu (processed=total) i faila w verification. Inny/półkompletny legacy przypadek jest blokowany jako parts_reconciliation_requires_complete_export; nie zgadujemy ani nie przełączamy po cichu na pełny restart.

## Wspólny mechanizm i zakres pracy

`MutableDatasetSnapshot` jest uogólnionym mechanizmem #1517, a nie jego kopią. Whitelista: parts i marketplace_listings; reason recovery nadal wyłącznie snapshot_row_updated_after_cutoff. Checksum/chain corruption, schema/source/attempt mismatch i inne reason nie uprawniają do recovery.

1. Świadome Wznów sprawdza własność, aktualny run/attempt, źródło, schemat i reason pod istniejącym flock.
2. Ten sam run i **ten sam attempt** dostają zapisany intent strategii `adopt_complete_shards_then_reconcile` dla parts. Ponowne kliknięcie/utrata odpowiedzi nie tworzą nowego intentu ani całego snapshotu.
3. Bounded preflight sprawdza do 5 starych shardów na request: SHA JSON/CSV, checksum chain i zatwierdzoną liczbę rekordów. Corruption nie jest resetowana. Stary descriptor pozostaje w prywatnej historii.
4. Nadaje parts jawne generation/boundary metadata, zachowując WSZYSTKIE shardy, export cursor, processed/total i max PK. Resetuje tylko stan verification tego datasetu. Bez reexport_dataset i bez ponownego eksportu 79957 rekordów. Nowa granica opisuje model obserwacji wartości; membership recovery stanowią już zapisane ID ze starych shardów.
5. Jeden bounded pass porównuje capture z aktualnymi rekordami dla zapisanych ID. Ten odczyt wszystkich shardów części jest konieczny, aby odkryć dirty set (również zmiany bez updated_at). **Porównanie całości nie oznacza ponownego eksportowania całości.** Zgodne JSON/CSV pozostają pod tymi samymi ścieżkami i SHA, zmienione dostają content-addressed rewizję. Stary plik nie jest nadpisywany. Raport obejmuje unikalne dirty rows/shards i reuse liczony w obrębie reconciliation.
6. Dalej czytamy wyłącznie dirty shardy; limit trzech rund (pierwsza pełna + dwie dirty-only). Ciągła mutacja daje snapshot_mutable_dataset_unstable, nie nieskończone resetowanie. Usunięte zapisane ID mają tombstone. Nowe części >max oraz nieobecne w complete capture nie są dopisywane.
7. Po parts kontynuowany jest verification pozostałych gotowych datasetów i istniejący pipeline zdjęć/próbki/paczki. Generacja listingów i jej historia nie są resetowane; listingi mogą normalnie uzgodnić zmianę wynikającą z tej samej sprzedaży.

Nowe porcje mają limit ≤5 rekordów i dotychczasowy byte/row budget. Legacy shardy z początkowej wersji v3 mogły mieć do 25 rekordów: ich verification nadal obejmuje najwyżej jeden taki shard w ticku (hard cap 25), bez przepakowania wszystkich starych plików. Diagnostyka records_limit pokazuje rzeczywisty limit. Pojedynczy za duży rekord pozostaje zablokowany. Przeglądarka otrzymuje wyłącznie status, nie dane produktów. Compact GET pozostaje odczytem małego operational state.

Globalny wyeksportowany licznik pozostaje jawnie dostępny jako `exported_records` (np. 79957), obok `verification_processed`. Licznik fazy verification może zacząć od początku części; nie oznacza utraty wyeksportowanych danych. Manifest przechowuje strategię, starszą granicę, generację, kontrakt, dirty/reuse i SHA; format nadal v3 i cutover_ready=false.

## Kontrakt consistency i pochodne

Ten sam `bounded_current_rows_reconciled_observations_v1`: bieżące oczyszczone wartości, freeze zakresu/membership, pełny bounded verification, dirty-only dalsze rundy, per-row hashes/updated_at w nowych shardach. Każdy finalny wiersz jest zaobserwowany bez zmiany dwa razy; nie jest to as-of, historyczna baza ani globalny stan na koniec eksportu. Stabilne shardy nie są ponownie skanowane po późniejszej mutacji. Identyczność treści po zmianie i powrocie między odczytami nie jest historią zdarzeń.

Sold/to-list counters i pliki grup powstają z kanonicznych części według status=sold i needs_listing; nie według stocku/linków. Zależne `part_car_relations` są wyliczane WYŁĄCZNIE z kanonicznych parts, nie z osobnej żywej tabeli. Jeśli car_id/obecność wiersza się zmieni, aktualizowana jest tylko niezbędna pochodna partia; zgodne pochodne pliki pozostają. Ich stare SHA/chain są sprawdzane. Nie rozszerza to mutable read na cars ani inne niezależne dane. Brak nowego samochodu poza zamrożonym zakresem zostanie opisany przez istniejący relation audit.

## Audyt pozostałych datasetów: wyłącznie kod, bez rozszerzania whitelisty

A = istniejące wpisy effectively immutable w zbadanych writerach lub deterministyczna pochodna; strict może pozostać. B = normalne zapisy istniejących rekordów potwierdzone w kodzie. C = niepewne, szczególnie opcjonalne/legacy tabele bez wykrytego writera; brak tabeli na produkcji nie jest tu potwierdzony. To audyt repo, nie inwentaryzacja produkcyjnego snapshotu/triggerów czy procesów zewnętrznych. A dotyczy niezmienności istniejących wpisów: append-only nie gwarantuje niezmiennej liczby rekordów przy backdating, NULL created_at albo dopisywaniu klucza wewnątrz zamrożonego zakresu.

| Dataset | Grupa | Dowód / ryzyko weryfikacji |
|---|---|---|
| parts, marketplace_listings | B | Sprzedaż, status/stock/sync/readiness; implementowana wspólna whitelista. |
| part_car_relations | A, pochodna | Nie ma niezależnego DB writera. Wyprowadzana z canonical parts; ta zależność jest uzgadniana w tym PR. |
| stripe_fr_events | A | StripeFrWebhook insertuje receipt zdarzenia po przetworzeniu; nie znaleziono updatera istniejącego receipt. Nowe ID poza granicą nie wchodzą. |
| shop_events | A | LocalSaleController tworzy zdarzenie; zbadane Dashboard/ShopEvents/CustomerAccount odczytują. Brak znalezionego updatera; to nie gwarancja wobec zewnętrznych zapisów. |
| local_sales | A, warunkowo | Zbadana ścieżka LocalSaleController tworzy zapis sprzedaży. Brak znalezionego updatera; korekty/legacy writer poza tym kodem wymagają ponownego audytu. |
| orders | B, wysokie ryzyko | MarketplaceOrdersImportService::upsertOrder, fulfillment/status sync, Stripe webhook oraz panel Orders zmieniają istniejące statusy, kwoty, meta/imported_at i updated_at. |
| order_items | B, wysokie ryzyko | MarketplaceOrdersImportService::upsertItem zapisuje ponownie sku/nazwę/part_id/cenę/ilość/raw_payload istniejącej pozycji. |
| shipments | B | ShipmentLabelService i DhlShipmentService zapisują status, tracking, wynik i naprawę etykiety. |
| stripe_fr_attempts | B | StripeFrCheckout oraz StripeFrWebhook aktualizują session/status/updated_at. |
| cars | B | CarResource, OvokoDonorCarImport MODE_UPDATE_EXISTING i mapping/status tools używają fill/save. |
| part_images, car_images | B | Galerie panelu/importy: upload, kolejność, primary/path, usuwanie i kaskada po usunięciu rodzica. Usunięcie Part może spowodować osobny strict konflikt part_images. |
| part_categories, storage_locations | B, zwykle ręczne | Resources i importer Woo mogą tworzyć/edytować nazwy, przypisania/status; do not assume immutable katalog. |
| allegro_parameter_selections, marketplace_category_mappings | B | Edycja parametrów/mapowania w panelu oraz importy mogą aktualizować zapisane wartości. |
| part_translations, category_translations | B | Translatory/storefront publication mogą aktualizować istniejące tłumaczenia/publikację. |
| ovoko_car_dictionary_entries | B | OvokoCarDictionaryService::storeRows robi updateOrCreate, także raw_payload/synced_at; cykliczny sync może zmienić dataset bez ręcznej edycji. |
| customers (projekcja users) | B | CustomerAccountController aktualizuje profil. Hasła/auth są pominięte, ale nazwa/adres mogą się zmienić. |
| marketplace_account_identities | B, zwykle ręczne | Projekcja omija credentials i timestampy, więc sam refresh OAuth nie jest mutacją eksportowanej projekcji; zmiana code/name/status/mode/base_url już jest. |
| customer_returns | C | Controller tworzy zwrot, model dopuszcza status. W tym audycie brak potwierdzonego updatera; nie zakładamy niezmienności procesu obsługi zwrotów. |
| order_status_history, order_status_histories, order_status_changes, order_histories | C | Opcjonalne nazwy historyczne w eksporcie; bez potwierdzonego writera i obecności nie deklarujemy append-only. |
| payments, order_payments, refunds, order_refunds | C | Opcjonalne legacy tabele; biznesowo mogą się zmieniać, ale brak zidentyfikowanego konkretnego writera dla tych nazw. |
| returns, order_returns, return_items | C | Opcjonalne legacy relacje zwrotów, bez wystarczającego dowodu niezmienności. |
| shipment_packages, order_notes, order_discounts, order_taxes, order_addresses | C | Opcjonalne zbiory zależne, brak wystarczającej wiedzy o produkcyjnych writerach. |

**Tak, kolejny strict konflikt na orders/order_items/shipments/cars/tłumaczeniach lub słownikach jest możliwy.** Ten PR nie wyłącza ich guardów i nie obiecuje finalizacji każdego produkcyjnego snapshotu przy dowolnej mutacji aplikacji. Dla niezmienionych zbiorów verification idzie dalej normalnie; każda dodatkowa whitelista wymaga osobnego kontraktu i audytu zależności. Nie wyłączamy sprzedaży/integracji i nie blokujemy globalnie tabel.

## Testy i obsługa

Fixture technicznego stanu odtwarza run/attempt, parts cursor8926 i UI79957/79957. Duży licznik jest jawnie projekcją testową; poprawność end-to-end jest osobno badana na małych rzeczywistych rekordach fixture. Sprawdzamy identyczność wcześniej zapisanych plików i niezależnych descriptorów, tylko jeden dirty Part/shard, reuse pozostałych, stare 25-row shardy, brak zmian business DB/provider calls, schema/attempt/source/corruption, limit rund, tombstone/car_id i canonical derived relacje.

Test prawdziwego `PartAvailabilityEventService::sold()` wykonuje normalne Eloquent zapisy Part/listingu na lokalnych fixture i mockowany GET źródłowej sprzedaży Allegro; następnie eksporter nie robi żadnego DML ani dodatkowego HTTP, a cały pipeline dochodzi do completed. Browser: reload, double Resume, utrata odpowiedzi Resume/ticka, network, pause/reload/resume, KICK, 419 i inne blocked reason. Nowy eksport/attempt nie powstają.

Po deployu: odświeżyć panel, opcjonalnie odczytać **Diagnozuj konflikt datasetu**, następnie **Wznów istniejący eksport**. Widoczne: recoverable_mutable_dataset_conflict, dataset=parts, reconciliation_started, snapshot_running, dirty rows/shards i verification. Nie używać Ponów snapshot. Nie uruchomiono recovery na produkcji; dane źródłowe/integracje pozostają bez zmian.

## Recovery intent zapisany przed publikacją stanu runu (#1518)

Późniejszy dostarczony status identyfikuje Part **2262**, `updated_at=2026-11-06 19:20:03`, stary database cutoff `2026-11-06 15:17:19.925197`. Nie ustala zmienionych pól ani sprawcy zapisu. `last_tick.started_at=1794006808.398781` pochodzi sprzed intentu recovery z `2026-11-07T09:33:57+00:00`. To historyczny błąd strict verification, nie dowód nowego wywołania cutoff guarda w preflight.

W kodzie guard jest w `BrowserMigrationSnapshot::page()`, wywoływanym przez strict export/verify. `recoverDatasetBoundary()` nie wywołuje `page()`: preflight czyta zapisane artefakty i opis schematu. Nie usuwamy ani nie omijamy guarda dla strict datasetów.

Odtworzona luka jest między `requestDatasetRecovery()` (atomowy zapis logicznego intentu i pochodnej projekcji) a `BrowserMigrationExport::resume()` (publikacja `snapshot_running` w state.json). Przerwanie pomiędzy nimi pozostawia poprawny intent generation=1, ale stary root=snapshot_failed. Projekcja nadpisuje widoczny last_error na null, a dotychczasowy warunek recovery wymagał błędu timestamp. Dlatego GET 200 pokazywał jednocześnie last_error=null, blocked_reason=stary guard i resume_allowed=false. Bez logów hostingu nie dowodzimy przyczyny przerwania żądania (timeout/pamięć/proces); usuwamy odtworzoną blokadę i dodatkowe pełne odczytanie indeksu między zapisem intentu a publikacją root.

Wznów i KICK rozpoznają istniejący intent; backend pod flock ponownie sprawdza attempt/source/schema, whitelistę, dataset/strategię, obie generacje, boundary i offset preflight. Naprawialny stary failed state nie tworzy kolejnego intentu. Zachowujemy run, attempt, generation **1**, boundary **a42db372-cf85-4f46-99e7-ccacce2bc6c3**, dotychczasowe pliki oraz zatwierdzony offset/chain. Dotyczy także projekcji #1518 bez nowego pola snapshot_stage. Inne błędy root lub canonical checkpointu nadal blokują recovery; to nie jest ogólne wznowienie dowolnego snapshot_failed.

Artifact integrity preflight sprawdza do pięciu shardów: ścieżkę z właściwym attempt/dataset, obecność, SHA JSON/CSV, rozmiar, format JSON/listę wierszy/ID, nagłówek i liczbę pól/wierszy CSV, zgodne ID obu plików, końcowy chain i sumę rekordów. Złe pliki nie stają się dirty. Nowa diagnostyka `last_tick.action=artifact_preflight` pokazuje preflight_shard_before/after, artifacts_checked i generation, przy records_attempted=0. Schema/identity mismatch oraz checksum/format/row-count/size corruption pozostają twardymi blokadami.

Dopiero po adopcji reconciliation porównuje before-image z aktualnym oczyszczonym Part. Nowszy updated_at nie jest tu blockerem, a różnica daje dirty row/shard. Test Part2262 zmienia quantity/status/needs_listing, zachowuje stare shardy podczas preflight, przepisuje tylko jeden dirty shard i przechodzi bounded verification. Fixture 1296 prawdziwych shardów testowych zachowuje 1295 niezmienionych; globalne 79957/79957 jest jawną projekcją liczników, a nie kopią produkcyjnych produktów. Osobny mały fixture przechodzi cały pipeline do completed. Testy obejmują przerwę między intent/root, powtórne Resume, częściowy preflight, KICK, uszkodzenia oraz Chromium reload/double-click/lost-response/network/419. Eksporter nie wykonuje DML ani HTTP providerów; produkcyjne recovery nie było uruchamiane.

Po deployu dla podanego stanu: odświeżyć panel i kliknąć **Wznów istniejący eksport**. Preflight zaczyna od zapisanego 0/1296 (lub późniejszego checkpointu), następnie reconciliation/verification i dotychczasowy pipeline. Nie wybierać Ponów snapshot.
