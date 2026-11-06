# Recovery eksportera GPSwiss → WystawCzesc po #1509

## Ustalenia i granice diagnozy

Sprawdzono `main` po #1509 (`f045563b85c7910f833643469861f501a99a8ac4`). Nie odczytywano produkcyjnej bazy, sesji, logów ani prywatnego katalogu eksportów. Nie uruchamiano produkcyjnego eksportu ani importu.

W kodzie #1509 przeglądarka tworzyła UUID i zapisywała `#run=UUID` **przed** POST Start. Middleware CSRF może odrzucić ten POST statusem 419 przed wywołaniem kontrolera. Wówczas UUID istnieje tylko w przeglądarce, a późniejszy GET statusu prawidłowo zwraca 404: na serwerze nie powstał checkpoint. Test wymuszający rzeczywistą weryfikację CSRF odtwarza ten ciąg. Same dwa statusy HTTP nie dowodzą, że dokładnie to zdarzyło się na produkcji.

419 może wynikać z wygaśnięcia sesji, zmiany sesji/tokenu lub starej strony z nieaktualnym tokenem CSRF. `ReadOnlyPreviewSession` odczytuje sesję bez jej zapisywania i przedłużania; samo wykonywanie eksportu nie odnawia sesji. Poprawka zachowuje ochronę CSRF. Po 419/401 zatrzymuje lokalną pętlę i wymaga loginu oraz ponownego otwarcia strony z aktualnym tokenem.

Jeżeli wcześniej zapisano już partie eksportu, 419 nie usuwa checkpointu ani artefaktów. Późniejsze 404 wymaga rozróżnienia UUID, właściciela, routingu i magazynu plików. Bez dostępu do tych danych nie przypisujemy produkcji konkretnej przyczyny. `storage_root_fingerprint` pozwala porównać konfigurację katalogu między żądaniami bez ujawniania ścieżki. Nie wyszukujemy automatycznie poza skonfigurowanym prywatnym katalogiem.

## Operator po wdrożeniu

1. Zaloguj się ponownie jako ten sam właściciel eksportu i otwórz `/admin/tools/gpswiss-migration-export`.
2. Panel najpierw odczyta status UUID z adresu. Gdy UUID zginął lub nie istnieje, wyszuka ostatni niedokończony eksport tego właściciela na dysku.
3. Sprawdź znalezione `run_id`, stan, postęp, ostatni sukces i błąd. Kliknij **Wznów istniejący eksport**. Nie klikaj Start w celu odzyskania poprzedniego eksportu.
4. Wznowienie zaplanuje sprawdzenie SHA-256 zapisanych zdjęć w krótkich partiach (`phase=verification`) i będzie kontynuować ten sam checkpoint. Nie wykonuje nowego snapshotu. Ukończony eksport udostępnia zapisane paczki i nie uruchamia kolejnych partii.
5. Przy 419 panel pokaże `SESSION_EXPIRED`; ponów login i reload, następnie Wznów. Przy błędzie sieci najpierw odczytuje status tego samego UUID i stosuje ograniczony retry z backoff.

Jeżeli panel nie odnajdzie eksportu, zachowaj UUID oraz diagnostykę. Nie twórz zastępczego eksportu jako sposobu naprawy. Operator serwera powinien sprawdzić obecność prywatnego katalogu i checkpointu w tym samym skonfigurowanym storage. `checkpoint_missing` nie upoważnia eksportera do rekonstrukcji snapshotu z bieżącej bazy. Jeżeli Start został odrzucony przed kontrolerem, nie ma istniejącego eksportu, który można wznowić.

Nowe eksporty zapisują pomocniczy `owner.json`, pozwalający odnaleźć właściciela również po utracie checkpointu. Dla starszego eksportu z brakującym `state.json` nie da się bezpiecznie odgadnąć właściciela; taki katalog nie jest ujawniany przez discovery.

## Kontrakt HTTP

Wszystkie endpointy zachowują autoryzację OwnerAdmin, prywatne odpowiedzi i CSRF dla POST.

| Żądanie | Znaczenie |
| --- | --- |
| GET panel `?format=status` | Ostatni niedokończony eksport bieżącego właściciela; brak nowego eksportu i brak snapshotu |
| GET panel `/UUID` | Status wskazanego eksportu |
| POST panel `/UUID`, `action=resume` | Weryfikacja istniejących kopii, kolejka napraw uszkodzonych kopii, zachowany kursor |
| POST panel `/UUID`, `action=tick` | Kolejna ograniczona partia tego samego eksportu |

Status zawiera `run_id`, `state`, `phase`, `processed`, `remaining`, `current_item`, `last_success`, `last_error`, `resume_allowed`, `blocked_reason`, `owner_match`, `artifacts_present`, `busy` i bezpieczny fingerprint katalogu. Postęp dotyczy zadań zdjęć; kolejka napraw jest uwzględniona w `remaining`, a pozostała weryfikacja ma osobne `verification_remaining`. Dodatkowe pola poprzedniego kontraktu pozostają dostępne.

| reason_code / blocked_reason | Znaczenie |
| --- | --- |
| `SESSION_EXPIRED` | HTTP 419 lub sesja wymagająca ponownego logowania; stop lokalnej pętli |
| `run_not_found` | Brak katalogu wskazanego UUID w skonfigurowanym storage |
| `run_owner_mismatch` | Właściciel checkpointu jest inny; BLOCK, bez danych eksportu |
| `route_not_found` | Niepoprawna ścieżka endpointu eksportera |
| `artifact_not_found` | Brak wymaganego snapshotu, listy zadań lub gotowej paczki |
| `checkpoint_missing` | Katalog istnieje, ale nie ma `state.json`; bez ponownego snapshotu |
| `invalid_run_id` | Niepoprawny format UUID lub brak UUID dla operacji wymagającej go |
| `export_already_completed` | Paczki są ukończone; `resume_allowed=false` |
| `export_deleted` | Trwały znacznik usunięcia/stan deleted; nie wnioskujemy usunięcia z samego braku katalogu |
| `existing_export_unfinished` | Start nowego UUID zablokowany przez istniejący niedokończony eksport |
| `snapshot_in_progress_or_interrupted` | Snapshot trwa lub został przerwany; bez automatycznej rekonstrukcji |
| `unsupported_export_schema` | Starszy, niezgodny format checkpointu; bez prób przetworzenia jako v2 |
| `other` | Niesklasyfikowany błąd; bez treści wyjątków, SQL, ścieżek i rekordów |

## Zachowanie plików i bezpieczeństwo

- Blokada właściciela zapobiega równoległemu Start pod różnymi UUID. Blokada runu serializuje partie i wznowienie. Odpowiedź utracona przez klienta nie przerywa autoryzowanego checkpointu.
- Zamrożony snapshot i lista zadań pozostają źródłem eksportu. Resume nie odczytuje ponownie produktów ani zamówień.
- Poprawne kopie zdjęć są weryfikowane po rozmiarze i SHA-256. Dziennik potrafi odtworzyć pracę po zapisie pliku przed aktualizacją kursora; częściowa linia dziennika nie blokuje kolejnego wpisu. Naprawa kopii nie cofa kursora.
- Identyczne CSV/JSON nie są nadpisywane. Gotowe ZIP-y są ponownie wykorzystywane po weryfikacji manifestu, metadanych i SHA-256 zawartości. Nieukończone pliki `.tmp`, `.pending`, `.partial` nie trafiają do paczki.
- Brak lub uszkodzenie oryginału pozostaje jawnym wynikiem eksportu. Poprawka nie pobiera zamienników z marketplace.
- Zapis dotyczy wyłącznie prywatnych plików eksportu i checkpointu. `database_writes=false`, `source_file_writes=false`, `provider_calls=false`; bez importu, synchronizacji, publikacji, zmiany produktów, samochodów, zamówień, cen lub stanów.

Testy używają lokalnej bazy SQLite i plików testowych. Obejmują rzeczywisty 419 przed kontrolerem, odszukanie poprzedniego UUID po loginie, izolację właścicieli, blokadę nowego Start, zachowanie kursora, ponowne użycie plików po awarii i brak wywołań dostawców. Chromium sprawdza również reload bez hasha, podwójne Wznów, odzyskanie po utraconej odpowiedzi i zatrzymanie po 419.
