# Zbiorcze CSV z zamrożonego DATA ONLY

W istniejącym panelu `/admin/tools/gpswiss-migration-export` wybierz właściwy run i naciśnij **Przygotuj zbiorcze CSV** w sekcji DATA ONLY. Przycisk wznawia własny checkpoint także po reloadzie, błędzie sieci i utraconej odpowiedzi. **Zatrzymaj lokalną pętlę CSV** zatrzymuje kolejne żądania bez zmiany snapshotu i procesu zdjęć. Po zakończeniu osiem prywatnych linków pokazuje liczbę wierszy danych (bez nagłówka), rozmiar i SHA-256. Pięć dotychczasowych CSV pozostaje dostępnych; dochodzą pełny CSV listingów oraz jego raport problemów. Osobny raport problemów zdjęć jest nadal dostępny.

| Plik | Źródło i reguła |
| --- | --- |
| `czesci.csv` | wszystkie wiersze `parts`, wszystkie kolumny zamrożonego `csv_columns` |
| `sprzedane.csv` | te same pełne kolumny; wyłącznie `status === "sold"` |
| `do_wystawienia.csv` | te same pełne kolumny; zapisany `needs_listing=true`, także jego reprezentacja SQL `1` / `"1"`; bez wnioskowania ze stocku/ofert |
| `moje_samochody.csv` | wszystkie wiersze i kolumny `cars`, także auta bez części |
| `zdjecia_czesci.csv` | `part_images` oraz `parts.code_photo_path` / zapisany `main_photo_path` |
| `marketplace_listingi_czesci.csv` | wszystkie poprawnie przypisane listingi z zamrożonego `marketplace_listings`, pełne kolumny źródłowe i aliasy migracyjne |
| `marketplace_listingi_czesci_issues.csv` | osierocone listingi (pełny wiersz w JSON), braki URL/ID i części z wieloma listingami |
| `zdjecia_czesci_issues.csv` | `orphan_image` i `missing_source_path`, ze źródłowymi metadanymi |

Źródłem schematu jest ten sam zamrożony indeks, z którego powstaje `fields.generated.json`. Nieznana kolumna w shardzie blokuje eksport zamiast cichego pominięcia. Brak wartości jest zapisywany jako `\N`; literalny początkowy backslash jest podwajany. CSV ma UTF-8 BOM, separator `;`, JSON w komórkach i ochronę formuł identyczną z eksporterem shardów (apostrof przed `=`, `+`, `-`, `@`, również po białych znakach). ID, SKU i kody zachowują dokładne wartości tekstowe. W arkuszu **importuj te kolumny jako tekst**; zwykłe automatyczne otwarcie CSV może usunąć wiodące zera.

Zdjęcia są łączone po dokładnej parze tekstowej `(part_id, source_path)`. Alias main/primary albo code tego samego pliku nie tworzy kolejnego wiersza. `roles`, `image_ids` i `source_metadata` zachowują pozostałe zapisane role, identyfikatory i pełne metadane. Galeria ma pierwszeństwo przy wyborze pojedynczego ID, nazwy i kolejności przed aliasem main/code; primary jest zachowywane, jeśli zapisano je w którymkolwiek aliasie. `original_name` pochodzi z zapisanego pola nazwy, a przy jego braku z ostatniego segmentu zapisanej ścieżki. MIME i rozszerzenie pozostają NULL, jeśli ich nie zapisano. Kolejność należy odtwarzać według `part_id` i `sort_order`, a nie fizycznej pozycji wiersza CSV (plik jest składany z deterministycznych kubełków). Osierocone/bezścieżkowe wpisy trafiają do raportu, nie do poprawnych relacji. Brak oryginału na dysku nie blokuje metadanych ani nie jest tu oceniany.

## Izolacja i wznowienie

Serwis odczytuje wyłącznie prywatne pliki tożsamości właściciela/runu, `data-only/<attempt>/parent-index.json` i jego zapisany SHA, a następnie JSON-y wskazane w `logical-snapshot/<attempt>/datasets/{parts,cars,part_images,marketplace_listings}`. Sprawdza checksumy, liczniki i containment ścieżek. Nie otwiera wolumenów ZIP ani fizycznych zdjęć. Nie wywołuje snapshotu, weryfikacji żywego źródła, reconciliation, audytu relacji, API ani SQL tabel biznesowych. Uwierzytelnienie HTTP nadal może odczytać użytkownika/uprawnienia.

Własne pliki zapisuje w `consolidated-csv/<attempt>/`: zamrożona kopia indeksu, checkpoint, CSV i techniczne pliki indeksowania zdjęć. Run, attempt, shard źródłowy, istniejący DATA ONLY i photo pipeline pozostają nietknięte. Serwis nie zmienia gwarancji spójności pierwotnego snapshotu ani zaakceptowanego driftu.

Tick przetwarza najwyżej 50 wierszy jednego sharda z budżetem pętli 1,5 s. Jeden shard JSON lub kubełek metadanych zdjęć ma limit 16 MiB; przekroczenie blokuje operację jawnie. W pamięci pozostaje indeks i jedna partia, nie wszystkie produkty. Wyniki są dopisywane do pojedynczych finalnych CSV. Blokada plikowa zapobiega równoległym pętlom; zatwierdzone offsety pozwalają obciąć niezacommitowany append po awarii bez duplikacji. SHA jest liczony przy dopisywaniu i przechowywany w checkpointach.

Ponowne kliknięcie po zakończeniu sprawdza gotowe pliki w partiach po 256 KiB. Poprawne pliki nie są przepisywane; odtwarzane są tylko brakujące/uszkodzone wyniki, ponownie z zamrożonych shardów. Robocze metadane mogą być odtworzone przy naprawie CSV zdjęć. GET statusu i pobranie nie naprawiają ani nie generują plików; download kontroluje SHA i blokuje uszkodzoną zawartość.

GET/POST: `/admin/tools/gpswiss-migration-export/<run>/consolidated-csv`, z `parent_attempt_id`; POST ma `action=start|tick`, GET status albo `download=<dokładna nazwa CSV>`. Dostęp: OwnerAdmin i właściciel runu; odpowiedzi `private, no-store`. Gotowe linki są odczytywane z serwera po reloadzie. Brak nowego snapshotu/attemptu, business writes, provider calls, publikacji i importu. Nie uruchamiać generowania produkcyjnego w ramach wdrażania PR.

Fixture testowy: 5 części, 1 sprzedana, 2 do wystawienia, 3 samochody, 5 unikalnych zdjęć oraz 3 problemy zdjęć. Są to dane syntetyczne testów, nie liczniki produkcji.


## Powiązania marketplace (CSV schema_version=2)

Audyt repozytorium: `MarketplaceListing` i migracje `2026_06_20_120000_create_marketplace_tables` oraz `2026_06_22_000001_add_allegro_active_tracking_to_marketplace_listings` definiują:

```
id, marketplace, marketplace_account_id, part_id,
external_offer_id, external_listing_id, external_inventory_id,
sku, title, price, quantity, currency, status, url, raw_payload,
sync_status, match_status, match_confidence, match_reason,
last_synced_at, last_seen_at, last_api_status, not_seen_in_active_api_at,
last_error, created_at, updated_at
```

Snapshot dodaje `migration_binding_policy` oraz `automatic_activation_allowed=false`. Faktyczny schemat produkcyjnego sharda jest dynamiczny: pełny `csv_columns` z zamrożonego indeksu jest źródłem prawdy. Kod nie ma osobnych natywnych kolumn `provider`, `account_code`, `market`, `country`, `public_url` ani `UUID` w tej tabeli. `raw_payload` może przechowywać dodatkowe informacje; zachowujemy je w całości, bez zgadywania kluczy lub wyciągania ID innego rodzaju. Brak dostępu do produkcyjnego storage nie pozwala potwierdzić schematu ani liczników konkretnego runu na podstawie repozytorium.

Model nie ma unikalności `(part_id, marketplace)`; wiele listingów jednej części jest poprawne. Istnieją usługi wybierające oferty w zależności od celu (np. audyt eBay), ale nie ma uniwersalnego primary listingu dla migracji. W nowych kolumnach części przyjmujemy wyłącznie **dokładnie jeden** wiersz na dostawcę eBay/Ovoko, łącznie ze wszystkimi kontami, rynkami i statusami. Przy większej liczbie wszystkie skróty tego dostawcy są NULL, nawet jeśli tylko jeden listing ma status active. Wszelkie powiązania pozostają w pełnym CSV.

Każdy z prefiksów `ebay_` i `ovoko_` ma kolumny `listing_id`, `external_offer_id`, `url`, `status`, `account`, `marketplace_account_id`, `market`, `sku`. `listing_id` oznacza **external_listing_id**, bez fallbacku do offer ID. `account` oznacza zapisany account_code, a przy jego braku zapisany marketplace_account_id. `url` pochodzi wyłącznie z zapisanej niepustej wartości `url` albo `public_url`, nigdy z ID lub raw_payload. `market` i account_code pozostają NULL, jeśli nie są polami w frozen row. Nazwa kanału (np. ebay_de) pozostaje osobno w `marketplace`; nie zmieniamy jej w country/market. Nie czytamy kont ani konfiguracji z DB; nawet osobny frozen dataset kont nie jest potrzebny do tych powiązań, ponieważ zachowujemy marketplace_account_id.

Pełny CSV zawiera wszystkie oryginalne kolumny (w tym raw_payload, ceny, stany, statusy synchronizacji i śledzenia), oraz `listing_local_id=id`, `public_url` i `relationship_mode`. Dla eBay/Ovoko `active_reference` oznacza **tożsamość do ewentualnej jawnej adopcji**, nie deklarację aktywnej oferty i nie zgodę na automatyczny import/sync. Oryginalny status pozostaje, także ended/unlinked. Allegro i nieznani dostawcy mają `historical_reference`. Każdy wiersz ma `automatic_activation_allowed=false`; oryginalny migration_binding_policy jest zachowany. Jeśli wcześniejsze pola części kolidują ze skrótami, ich wartości zachowuje dodatkowy JSON `frozen_parts_marketplace_values`.

`listing_audit` w statusie i panelu pokazuje: dokładne source_columns, total, exported, orphan_listings, missing_url, missing_external_id, listings_by_marketplace, listings_by_provider, parts_multiple_by_marketplace i parts_multiple_by_provider. Liczniki listingów obejmują także orphan; liczniki części z wieloma listingami obejmują wyłącznie istniejące części. Brak external ID oznacza brak wszystkich trzech pól external_listing_id/external_offer_id/external_inventory_id. Każdy orphan trafia z pełnymi danymi do issues; nie wchodzi do poprawnych powiązań. Liczba issues może przekroczyć liczbę wadliwych listingów (kilka powodów). Multiple_listings to jedno zgłoszenie na część/dostawcę z listą lokalnych ID.

Przed joinem małe ticki indeksują wyłącznie frozen parts ID, następnie frozen listingi, potem budują skróty podczas eksportu części. Jest to własny indeks plikowy, a nie SQL i nie ponowny snapshot. Limit pojedynczego lookupu to 10000 listingów części (jawny blocker przy przekroczeniu). Przerwane zapisy i liczniki są odtwarzane z checkpointu; lookup listingów nadpisuje deterministyczny plik per lokalne ID i nie zwiększa liczników przy retry.

Po wdrożeniu ponownie naciśnij **Przygotuj zbiorcze CSV** dla istniejącego runu. Upgrade v1→v2 odbudowuje trzy zmienione pliki części i dodaje dwa CSV listingów. Gotowe poprawne CSV samochodów i zdjęć pozostają nietknięte; SHA są sprawdzane w małych partiach. Nie zmienia run/attempt ani frozen indeksu, shardów, DATA ONLY, ZIP-ów czy photo pipeline. Pobranie/status starej wersji nie rozpoczyna upgrade; panel pokazuje `csv_schema_upgrade_required`.

Fixture rozszerzenia: 9 listingów źródłowych: eBay 5, Ovoko 3, Allegro 1; 8 poprawnych powiązań i 1 orphan. Jedna część ma wiele listingów eBay; 3 listingi bez URL, 1 bez external ID; 6 wierszy issues. Są to liczniki testowe, nie dane produkcji.
