# Status operacyjny i ręczne wznowienie po #1515

Przyczyna blokady w kodzie: `drive(false)` wymagał poprawnego GET statusu przed pierwszym tickiem. Po czterech błędach `readCurrent()` zgłaszał `status_fetch_retry_exhausted`, którego recovery nie ponawiał. Zdrowy checkpoint i poprawny poprzedni tick nie wystarczały do wznowienia. Bez logów hostingu nie przypisujemy konkretnych produkcyjnych błędów GET do OOM, resetu czy timeoutu.

## Compact GET

GET `.../UUID?format=compact` czyta prywatny `operational-progress.json` (maks. 8192 B), małe pliki diagnostyczne i stat/fingerprint plików. Nie wywołuje `status()`/`index()`, nie skanuje ani nie dekoduje checkpointu/shardów. Autoryzacja czyta mały `owner.json`; dla starszego katalogu bez tego pliku dopuszcza ograniczony rozmiarem root state. Pełny status i artefakty pozostają osobnymi, prywatnymi żądaniami operatora.

Po każdym zakończonym wywołaniu wykonującym pracę, pauzę/wznowienie lub restart publikowana jest atomowo projekcja operacyjna: run/attempt, state, phase, dataset, processed/total/remaining, cursor, next_action, busy, last_success, last_tick_reason i fingerprint checkpointu oraz root state. To dane pochodne; eksport i fencing nadal korzystają z oryginalnego checkpointu. `busy` w odpowiedzi jest sprawdzany bieżącą nieblokującą blokadą runu, a nie odczytywany jako historyczny lease.

Dla istniejącego runu bez projekcji pierwszy GET pod blokadą tworzy ją wyłącznie z małego root state oraz małego cache poprzedniej wersji, o ile fingerprint cache pasuje do checkpointu. Jeśli nie ma poprawnego cache, zwraca `progress_unavailable=true`, liczniki/cursor `null` i prawdziwy stan/attempt/next_action z root state. Nie zgaduje liczb i nie skanuje indeksu. Pierwszy kolejny normalny tick/KICK, który i tak odczytuje checkpoint do pracy, publikuje prawdziwe liczniki. Kolejne GET pozostają O(1) względem liczby rekordów/shardów. Nieudany zapis projekcji nie unieważnia zatwierdzonego checkpointu; brak danych operacyjnych ma jawny safe reason.

## KICK

POST `.../UUID/kick` przyjmuje tylko `attempt_id` (run_id w URL) i wymaga tej samej sesji, roli OwnerAdmin, własności eksportu, CSRF oraz read-only DB/session middleware. Cursor/dataset/action w body są zabronione. To osobna ścieżka kontrolera, niezależna od compact GET. Odpowiedź ma limit 8192 B. Preflight czyta ograniczony root/progress state, nie pełny indeks/manifesty/raporty.

Pod istniejącym flock runu serwer sprawdza aktualny attempt, run, tryb browser v3, źródło DB i stan. Wyłącznie `snapshot_running` w obsługiwanej fazie może wykonać JEDNO istniejące bounded `snapshot_tick`. Dopiero właściwa praca ticka odczytuje pełny checkpoint i jego aktualny cursor. Nie zmienia formatu v3, granic, selekcji datasetów, rozmiaru partii ani zapisanych danych eksportowych. Zwraca `kick_reason` i jawny blocker: `busy`, `completed`, `paused`, `run_failed`, `attempt_mismatch`, `stale_attempt`, `state_not_runnable`, `snapshot_source_context_mismatch` lub bezpieczny błąd ticka.

KICK nie resetuje próby ani nie wznawia samowolnie pauzy. Zatwierdzone shardy/offsety i blokada zapewniają brak ponownego zapisania tych samych rekordów. Powtórzenie żądania po utracie odpowiedzi wykonuje kolejną jednostkę od serwerowego checkpointu, zamiast powtarzać cursor przeglądarki. Nie jest to globalna deduplikacja żądań HTTP: dwa kolejne zaakceptowane KICK mogą przesunąć postęp o dwie partie, zawsze bez powielenia rekordów. Aktywny tick zwraca busy; frontend ma jedną pętlę i nie odpala drugiego KICK automatycznie po utracie odpowiedzi.

Dla whitelisted `snapshot_failed / marketplace_listings / snapshot_row_updated_after_cutoff` istnieje teraz dodatkowy, świadomy tryb recovery datasetu przez Resume/KICK. Sprawdza integralność poprzednich shardów, tworzy wyłącznie nową generację listingów i zachowuje attempt; pozostałe failed nadal są blokowane. Pełny kontrakt i granice dowodu opisuje [audyt mutable listings](gpswiss-mutable-listings.md).

## Ręczne Wznów

Po resetowaniu wyłącznie lokalnych retry/timerów runner próbuje 4 compact GET (timeout 10 s, backoff 250/500/1000 ms). Jeżeli się nie powiodą, a ostatni znany stan wskazuje ten sam run/attempt, browser snapshot_running/snapshot_tick, brak busy/last_error i `tick_completed`, świadome Wznów wysyła jeden KICK. Nie czeka na `status_loaded`; pokazuje `status_fetch_retry_exhausted`, `fallback_kick`, a po sukcesie `tick_completed` i kontynuuje zwykłe ticki. Nie tworzy runu/attemptu. Jeśli backend zgłasza pauzę/koniec/błąd/zmianę attemptu, pokazuje faktyczny stan lub blocker. 401/419 wymagają loginu/reloadu i nigdy nie prowadzą do KICK; 403/404/422 również nie uruchamiają fallbacku.

Mały, pozbawiony danych biznesowych ostatni stan operacyjny jest zachowany w sessionStorage tej karty, by reload przy niedostępnym statusie umożliwił operatorowi Wznów. Sam reload nie wysyła POST. Ten cache jest tylko wskazówką do autoryzowanego żądania; backend nie ufa jego cursor/licznikom. Po zmianie konta autoryzacja właściciela nadal blokuje operację. Brak sessionStorage lub nieznany attempt nie uprawniają do zgadywania ani KICK.

Panel zachowuje osobno `status_attempts` 1–4 także po udanym KICK/ticku: HTTP/null, duration_ms, response_bytes, Content-Length/Type, reason_code, fetch timeout/exception type i navigator.onLine. Nie pokazuje body, tokenów, komunikatów wyjątku ani stacków. TypeError fetch nie pozwala wiarygodnie potwierdzić resetu połączenia. Obsługa fatal/shutdown jest best effort; SIGKILL/błąd przed kontrolerem nie gwarantują diagnostyki.

## Weryfikacja i wdrożenie

Testy PHP używają rzeczywistego writer/query/fencingu z lokalnymi fixture o metadanych produkcyjnego checkpointu 50428/79957, marketplace_listings/cursor15640. KICK przechodzi do 50433/15645, kolejny do 50438/15650, bez duplikacji i zmian granic. Sprawdzają blokady, owner/CSRF/source guard, brak DML/provider calls, brak indeksu w GET także dla uszkodzonego wielkiego checkpointu i stały mały rozmiar odpowiedzi. Chromium sprawdza permanentnie niedostępny GET, cztery błędy i fallback, timeout/503/malformed, utratę odpowiedzi, reload, double click i odmowy backendu.

Po deployu odświeżyć panel w tej samej karcie i kliknąć **Wznów istniejący eksport** dla runu `8de6e27c-ad7c-470b-830b-b9ecee9446ba`, attemptu `9cb33c92-3f1e-4db0-b08d-052715aa5f4c`. Nie używać Ponów snapshot. Nie wykonano eksportu produkcyjnego, importu, operacji biznesowych ani providerów.
