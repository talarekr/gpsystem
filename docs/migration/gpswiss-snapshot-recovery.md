# GPSwiss: przerwany snapshot po #1510

**Hosting bez shella:** panel v3 korzysta z [gpswiss-browser-export.md](gpswiss-browser-export.md), wykonuje cały eksport krótkimi tickami i nie wymaga opisanego poniżej workera. Ten dokument zachowuje diagnozę #1511 i opis opcjonalnego trybu CLI.

## Diagnoza

Sprawdzono aktualny `main` po #1510: `2cc58ca6f45736488b1f79d78db938a3fcbd2d2a`.

Zgłoszony run `8de6e27c-ad7c-470b-830b-b9ecee9446ba` ma checkpoint, ale nie ma `snapshot.json` ani `photo-tasks.json`, a blokada jest wolna. To dowód przerwania fazy przygotowania, nie dowód konkretnego rodzaju timeoutu. Bez produkcyjnych logów PHP/FPM i hostingu nie można rozstrzygnąć między limitem czasu, OOM, zakończeniem procesu i innym błędem infrastruktury.

W #1510 Start wykonywał kolejno w jednym żądaniu: zapis checkpointu `snapshot`, pełny odczyt bazy w jednej transakcji read-only, przygotowanie wszystkich zadań zdjęć i dopiero zapis końcowych plików. Nie istniał checkpoint umożliwiający kontynuację transakcji snapshotu. `catch(Throwable)` zapisywał zwykłe błędy, ale nie gwarantował obsługi OOM, przerwania przez FPM lub SIGKILL. `ignore_user_abort(true)` chroni przed zwykłym odłączeniem przeglądarki; nie unieważnia limitów serwera.

419 jest sprawdzany przez CSRF przed kontrolerem. Sam odrzucony POST nie może wytworzyć checkpointu tego żądania. Późniejszy 419 może zatrzymać przeglądarkę, ale nie dowodzi przyczyny przerwania procesu snapshotu.

## Zmiana architektury

HTTP Start i świadomy Retry zapisują tylko prywatny stan `snapshot_pending`. Odczyt bazy wykonuje dedykowany proces `gps:migration-snapshot`; nie działa jako długa operacja HTTP. Nie korzysta z kolejki Laravel ani globalnego harmonogramu, które w tym repo obsługują również integracje i mogą zapisywać bazę.

Jeden worker trzyma blokadę runu przez całą próbę. Każda próba ma nowe `snapshot_attempt_id`. Opóźniony worker lub komunikat błędu starszej próby nie może zmienić nowszej. Heartbeat opisuje etap i postęp, ale o aktywności decyduje blokada; stary heartbeat nie pozwala przejąć działającej próby.

Snapshot bazy nadal wymaga jednej transakcji REPEATABLE READ / READ ONLY dla InnoDB. Dzielenie go na niezależne żądania HTTP mieszałoby momenty odczytu. Przy przerwaniu tej transakcji można wykonać wyłącznie nowy pełny odczyt, nigdy dopisywać bieżących rekordów do starego fragmentu.

Pliki są zapisywane przez `.tmp` i atomowy rename. Po opublikowaniu obu plików powstaje `snapshot-ready.json` wiążący próbę z SHA-256 snapshotu i listy zadań. Dopiero ten commit oznacza gotowy zestaw. Awaria między publikacją plików a aktualizacją checkpointu pozwala użyć zweryfikowanego zestawu bez ponownego odczytu bazy. Niekompletny zestaw finalnych plików blokuje Retry i pozostaje zachowany po Abort.

## Stany panelu

| Stan snapshotu | Znaczenie i dostępne działanie |
| --- | --- |
| `snapshot_pending` | Przygotowana próba, worker nie potwierdził startu; panel pokazuje status launchera i dedykowane polecenie |
| `snapshot_running` | Proces trzyma blokadę; status/heartbeat można odczytywać, Retry i Abort są zablokowane |
| `snapshot_completed` | Gotowy zestaw plików; eksport przechodzi do zdjęć lub aktywuje istniejący zestaw przez Resume/tick |
| `snapshot_failed` | Błąd albo pozostawiony `snapshot_running`/legacy `snapshot` bez blokady; panel podaje safe reason i możliwość Retry/Abort |
| `snapshot_aborted` | Operator świadomie odrzucił próbę; pliki pozostały, można osobno przygotować nowy eksport |

Status zawiera m.in. `snapshot_process_active`, `snapshot_interrupted`, `snapshot_retry_allowed`, `snapshot_abort_allowed`, `snapshot_file_exists`, `snapshot_tasks_file_exists`, `snapshot_ready`, `snapshot_heartbeat` i `snapshot_attempt_id`. `snapshot_state` pozostaje `snapshot_completed`, gdy główny `state` przejdzie już do `photos`, `archive` lub `completed`.

Starszy checkpoint `state=snapshot` bez blokady jest pokazywany jako `snapshot_failed` / `snapshot_process_interrupted`. Sam GET nie modyfikuje tego checkpointu.

## Retry i Abort

Retry wymaga świadomego potwierdzenia w panelu, CSRF, tego samego właściciela i zgodnego identyfikatora bieżącej próby. Jest dozwolone tylko dla fazy snapshotu bez aktywnego procesu, przy `processed_images=0` i braku finalnych danych eksportu. Kontrola obejmuje faktyczne pliki, katalogi zdjęć, dziennik, paczki oraz nieznane artefakty — nie tylko licznik.

Retry zachowuje `run_id`, usuwa wyłącznie znane techniczne `.tmp`, zmienia identyfikator próby i rozpoczyna snapshot od zera. Stare dane tymczasowe nie są czytane jako źródło. Podwójne żądanie ze starym identyfikatorem próby jest odrzucane.

Abort również wymaga świadomego potwierdzenia i wolnej blokady. Ustawia `snapshot_aborted` / `ABORTED_SNAPSHOT`, zachowuje pliki i nie tworzy automatycznie kolejnego runu. Nowy Start to osobna decyzja operatora. Gotowego snapshotu ani fazy kopiowania zdjęć nie można wyczyścić tymi akcjami.

## Uruchomienie workera na hostingu

Repo nie potwierdza dostępnego managera procesów produkcyjnych. Dokumentacja stagingu wskazuje m.in. wyłączone `proc_open`; nie zakładamy, że web może uruchamiać CLI.

- Aby włączyć opcjonalny launcher, administrator ustawia `GPSWISS_MIGRATION_PHP_CLI` na zweryfikowaną absolutną ścieżkę **PHP CLI >=8.3**. Wymagane są Linux, dostępne `proc_open`, `/bin/sh` i `nohup`. Nie używamy FPM-owego `PHP_BINARY` jako zamiennika CLI.
- Bez tej konfiguracji lub przy niedostępnych funkcjach panel jawnie pokazuje `worker_launch_unavailable`. Nie zastępuje workera długim requestem HTTP.
- Administrator może uruchomić dedykowane polecenie pokazane przez panel **po Retry**, z aktualnym `--owner-id`, `--run` i `--attempt`. Należy użyć tego samego katalogu aplikacji, użytkownika plików, konfiguracji/cache i środowiska co panel. Nie uruchamiać globalnego `schedule:run` ani ogólnego `queue:work` w celu wykonania migracji.
- Przygotowanie próby utrwala fingerprint niejawnej tożsamości bazy i środowiska, bez haseł, loginów ani URL. Worker sprawdza go przed odczytem danych biznesowych; różnica kończy próbę jako `snapshot_source_context_mismatch`.
- `GPSWISS_MIGRATION_SNAPSHOT_MEMORY_MB` ustala limit pamięci CLI (domyślnie 1024 MiB, zakres 128–4096). Worker wyłącza limit czasu PHP CLI, ale nie omija ograniczeń hostingu. Zwykły błąd, wykryty timeout/OOM i pozostawiony bez blokady checkpoint mają odrębne bezpieczne powody. SIGKILL nie może wykonać handlera; jest rozpoznawany po stanie i wolnej blokadzie.

`worker_launch_requested` oznacza żądanie uruchomienia, nie potwierdzenie działania. Błąd startu PHP przed wejściem do aplikacji może pozostawić `snapshot_pending`; panel zatrzymuje oczekiwanie i udostępnia Retry/Abort oraz polecenie dla hostingu. Nie udaje aktywnego procesu.

## Kolejność dla zgłoszonego runu po wdrożeniu

1. Otwórz panel jako ten sam właściciel i odszukaj `8de6e27c-ad7c-470b-830b-b9ecee9446ba`.
2. Sprawdź wolną blokadę, brak finalnych snapshot/task files i `snapshot_retry_allowed=true`.
3. Potwierdź **Ponów snapshot od początku w tym samym run_id**. Nie używaj nowego Start jako obejścia.
4. Skonfigurowany launcher uruchomi worker; jeśli jest niedostępny, administrator wykonuje wyłącznie aktualne dedykowane polecenie z panelu. Obserwuj `snapshot_running`, heartbeat, a następnie `snapshot_completed` i fazę zdjęć.
5. Jeżeli Retry jest zablokowane przez finalne, niekompletne artefakty, zachowaj je i świadomie użyj Abort. Dopiero potem można osobno zdecydować o nowym eksporcie.

Implementacja zmienia tylko prywatne pliki eksportu i stan techniczny. Nie zmienia produktów, zdjęć źródłowych, samochodów, zamówień, cen, stocku ani integracji; nie wywołuje providerów, nie publikuje i nie importuje. W ramach przygotowania PR nie wykonywano produkcyjnego Retry, Abort ani eksportu.
