# Zbiorcze CSV z zamrożonego DATA ONLY

W istniejącym panelu `/admin/tools/gpswiss-migration-export` wybierz właściwy run i naciśnij **Przygotuj zbiorcze CSV** w sekcji DATA ONLY. Przycisk wznawia własny checkpoint także po reloadzie, błędzie sieci i utraconej odpowiedzi. **Zatrzymaj lokalną pętlę CSV** zatrzymuje kolejne żądania bez zmiany snapshotu i procesu zdjęć. Po zakończeniu sześć prywatnych linków pokazuje liczbę wierszy danych (bez nagłówka), rozmiar i SHA-256. Pierwszych pięć to pliki migracyjne, szósty to raport problemów zdjęć.

| Plik | Źródło i reguła |
| --- | --- |
| `czesci.csv` | wszystkie wiersze `parts`, wszystkie kolumny zamrożonego `csv_columns` |
| `sprzedane.csv` | te same pełne kolumny; wyłącznie `status === "sold"` |
| `do_wystawienia.csv` | te same pełne kolumny; zapisany `needs_listing=true`, także jego reprezentacja SQL `1` / `"1"`; bez wnioskowania ze stocku/ofert |
| `moje_samochody.csv` | wszystkie wiersze i kolumny `cars`, także auta bez części |
| `zdjecia_czesci.csv` | `part_images` oraz `parts.code_photo_path` / zapisany `main_photo_path` |
| `zdjecia_czesci_issues.csv` | `orphan_image` i `missing_source_path`, ze źródłowymi metadanymi |

Źródłem schematu jest ten sam zamrożony indeks, z którego powstaje `fields.generated.json`. Nieznana kolumna w shardzie blokuje eksport zamiast cichego pominięcia. Brak wartości jest zapisywany jako `\N`; literalny początkowy backslash jest podwajany. CSV ma UTF-8 BOM, separator `;`, JSON w komórkach i ochronę formuł identyczną z eksporterem shardów (apostrof przed `=`, `+`, `-`, `@`, również po białych znakach). ID, SKU i kody zachowują dokładne wartości tekstowe. W arkuszu **importuj te kolumny jako tekst**; zwykłe automatyczne otwarcie CSV może usunąć wiodące zera.

Zdjęcia są łączone po dokładnej parze tekstowej `(part_id, source_path)`. Alias main/primary albo code tego samego pliku nie tworzy kolejnego wiersza. `roles`, `image_ids` i `source_metadata` zachowują pozostałe zapisane role, identyfikatory i pełne metadane. Galeria ma pierwszeństwo przy wyborze pojedynczego ID, nazwy i kolejności przed aliasem main/code; primary jest zachowywane, jeśli zapisano je w którymkolwiek aliasie. `original_name` pochodzi z zapisanego pola nazwy, a przy jego braku z ostatniego segmentu zapisanej ścieżki. MIME i rozszerzenie pozostają NULL, jeśli ich nie zapisano. Kolejność należy odtwarzać według `part_id` i `sort_order`, a nie fizycznej pozycji wiersza CSV (plik jest składany z deterministycznych kubełków). Osierocone/bezścieżkowe wpisy trafiają do raportu, nie do poprawnych relacji. Brak oryginału na dysku nie blokuje metadanych ani nie jest tu oceniany.

## Izolacja i wznowienie

Serwis odczytuje wyłącznie prywatne pliki tożsamości właściciela/runu, `data-only/<attempt>/parent-index.json` i jego zapisany SHA, a następnie JSON-y wskazane w `logical-snapshot/<attempt>/datasets/{parts,cars,part_images}`. Sprawdza checksumy, liczniki i containment ścieżek. Nie otwiera wolumenów ZIP ani fizycznych zdjęć. Nie wywołuje snapshotu, weryfikacji żywego źródła, reconciliation, audytu relacji, API ani SQL tabel biznesowych. Uwierzytelnienie HTTP nadal może odczytać użytkownika/uprawnienia.

Własne pliki zapisuje w `consolidated-csv/<attempt>/`: zamrożona kopia indeksu, checkpoint, CSV i techniczne pliki indeksowania zdjęć. Run, attempt, shard źródłowy, istniejący DATA ONLY i photo pipeline pozostają nietknięte. Serwis nie zmienia gwarancji spójności pierwotnego snapshotu ani zaakceptowanego driftu.

Tick przetwarza najwyżej 50 wierszy jednego sharda z budżetem pętli 1,5 s. Jeden shard JSON lub kubełek metadanych zdjęć ma limit 16 MiB; przekroczenie blokuje operację jawnie. W pamięci pozostaje indeks i jedna partia, nie wszystkie produkty. Wyniki są dopisywane do pojedynczych finalnych CSV. Blokada plikowa zapobiega równoległym pętlom; zatwierdzone offsety pozwalają obciąć niezacommitowany append po awarii bez duplikacji. SHA jest liczony przy dopisywaniu i przechowywany w checkpointach.

Ponowne kliknięcie po zakończeniu sprawdza gotowe pliki w partiach po 256 KiB. Poprawne pliki nie są przepisywane; odtwarzane są tylko brakujące/uszkodzone wyniki, ponownie z zamrożonych shardów. Robocze metadane mogą być odtworzone przy naprawie CSV zdjęć. GET statusu i pobranie nie naprawiają ani nie generują plików; download kontroluje SHA i blokuje uszkodzoną zawartość.

GET/POST: `/admin/tools/gpswiss-migration-export/<run>/consolidated-csv`, z `parent_attempt_id`; POST ma `action=start|tick`, GET status albo `download=<dokładna nazwa CSV>`. Dostęp: OwnerAdmin i właściciel runu; odpowiedzi `private, no-store`. Gotowe linki są odczytywane z serwera po reloadzie. Brak nowego snapshotu/attemptu, business writes, provider calls, publikacji i importu. Nie uruchamiać generowania produkcyjnego w ramach wdrażania PR.

Fixture testowy: 5 części, 1 sprzedana, 2 do wystawienia, 3 samochody, 5 unikalnych zdjęć oraz 3 problemy zdjęć. Są to dane syntetyczne testów, nie liczniki produkcji.
