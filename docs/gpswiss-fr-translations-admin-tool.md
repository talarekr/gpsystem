# GPSwiss FR — tłumaczenia z panelu bez workera

## Przyczyna i rozwiązanie

Poprzedni runner dispatchował wyłącznie job `RunFrenchCatalogTranslationBatch`
do `storefront-translations` / `storefront-fr-translations`. Bez procesu queue
worker run pozostawał running na 0. Operator nie ma shella ani dostępu do
zarządzania hostingiem, więc domyślnym wykonawcą jest teraz otwarta strona admina.
Wdrożenie samego kodu wystarcza przy istniejącej konfiguracji providera,
fladze FR, tabelach tłumaczeń i trwałym cache z atomic locks.

## Instrukcja operatora

1. Deploy kodu, zachowując cache/checkpointy. Nie wymagamy Supervisor, shella,
   schedulera ani kontaktu z hostingiem. Nie ustawiaj trybu queue.
2. Otwórz `/admin/tools/storefront/fr-translations` na gpswiss.pl.
3. Dla NOWEGO runu wykonaj dry-run i potwierdź tokenem
   `TRANSLATE-ALL-GPSWISS-FR-CATALOG`, następnie Start.
4. Zostaw stronę otwartą. Panel co 3 sekundy odczytuje status i zleca POST
   następnej partii, dopiero gdy poprzedni request się zakończył.
5. Obserwuj processed, aktualny rekord, błędy i tryb wykonania. Pauza zatrzymuje
   kolejne partie. Stop kończy run i nie pozwala go wznowić.
6. Po zamknięciu karty, błędzie sieci lub wygaśnięciu sesji nie są wysyłane kolejne
   requesty. Po powrocie zaloguj się, odśwież status i kliknij Wznów dla TEGO
   SAMEGO runu. Samo wejście na stronę nie uruchamia płatnych wywołań.

## Obecny run 39c2e732-e177-4899-9221-8b79b7aa67d8

Po deployu odśwież status i kliknij **Wznów**. Nie wykonuj nowego dry-run ani
Start. Wznów zachowuje run_id, started_at, cursor, pending, zakres i liczniki;
przełącza także stary checkpoint queue na domyślny browser. Nie usuwa starych
jobów z kolejki. Stare joby i ich callback failed ignorują run przełączony do
browser. Jeśli stary worker jeszcze trzyma lease, Wznów zwróci busy; nie usuwaj
blokady ręcznie. Po awarii lease wygasa po 600 sekundach.

## Granice requestu i bezpieczeństwo

HTTP batch obejmuje najwyżej **jeden rekord** (produkt lub kategorię). To celowo
mniejszy zakres niż 10–25: rekord zawiera kilka pól i może wymagać kilku
wywołań providera. Nie da się przerwać już wysłanego provider call po zamknięciu
karty; bieżący rekord może zakończyć się na serwerze. Czas zależy od timeoutów
istniejącego providera i hostingu, jeden rekord nie gwarantuje konkretnego
limitu czasu HTTP. Po niepewnym przerwaniu nie restartuj całego apply.

Runner używa wspólnej blokady processing (600s), krótkiej mutation lock oraz
istniejących blokad rekordów. Dwa requesty nie tłumaczą jednocześnie. Nie zwalnia
się cudzych blokad. Kolejny tick zachowuje cursor i ponownie sprawdza stan
rekordu. Status GET, start i resume nie wywołują Google API. Tylko POST tick
wykonuje wywołania w trybie browser. Endpoint wymaga istniejącego run_id,
admin auth, Owner/Admin lub Manager, CSRF i limitu żądań jak pozostałe endpointy.
Francuskie domeny storefrontu nie umożliwiają obsługi narzędzia.

- Aktualne translated i wszystkie reviewed są chronione.
- Istniejące failed i needs_update są pomijane; only_missing pozostaje włączone.
- Wyłącznie retry konkretnej próby tego runu przy 429/5xx może powtórzyć failed:
  backoff 30/120/300s. Browser czeka do retry_at; nie ma sleep ani dispatchu.
- Pauza/Stop mogą nastąpić podczas requestu; wynik bieżącego rekordu nie nadpisuje
  stanu kontrolnego. Następny tick w paused/stopped nie tłumaczy.
- Checkpoint sukcesu zapisany w DB jest odzyskiwany bez nowego provider call.
  Niepewny queued attempt otrzymuje interrupted_attempt i zatrzymuje run;
  kolejne Wznów przechodzi dalej bez automatycznego ponawiania niepewnego call.
- Nie czyść cache, nie zmieniaj CACHE_PREFIX ani store podczas runu. Cache musi
  być trwały i wspólny dla HTTP/procesów, z atomic locks (database lub Redis).

Run może mieć status running po zamknięciu strony: to stan checkpointu, nie dowód
aktywnej karty. Timer pokazany przez UI dotyczy tej karty. Błąd ticku zatrzymuje
lokalny timer i wymaga jawnego Wznów; panel nie ponawia sam niepewnego requestu.

## Opcjonalna kolejka

Domyślne `GPSWISS_FR_ADMIN_RUNNER=browser` nie wymaga zmiany .env. Tylko jawne
ustawienie `GPSWISS_FR_ADMIN_RUNNER=queue` wybiera starą ścieżkę. Wtedy wymagany
jest worker z `docs/gpswiss-fr-worker-deployment.md`, batch do 100 rekordów/45s,
timeout=540, retry_after=660. Nie uruchamiaj go dla trybu browser.

## Endpointy

GET strony/status są read-only. POST dry-run zapisuje jedynie raport cache.
POST start tworzy run i zużywa raport. POST resume/pause/stop przyjmują run_id.
POST `/admin/tools/storefront/fr-translations/process-next-batch` przyjmuje
run_id i zwraca status JSON po najwyżej jednym rekordzie; paused/stopped,
opóźniony retry i zajęty processing lease nie wykonują provider call.

Zakres zapisów biznesowych pozostaje ograniczony do part_translations oraz
category_translations. Checkout, płatności, zamówienia, marketplace i stock
pozostają poza tą zmianą.

## Trwały recovery po błędzie, reloadzie i utracie cache

Nowa migracja tworzy `storefront_fr_translation_runs`. Wdrożenie musi wykonać
migrację standardową ścieżką deployu (helper browserowy wywołuje migrate).
Nie wymaga to workera ani shella operatora. Nie czyść cache ani checkpointów.
Bez migracji panel blokuje nowy Start/tick i pokazuje komunikat o tabeli runów.

Każda mutacja zapisuje pełny checkpoint w DB przed zapisaniem wskaźnika cache
oraz przed wywołaniem providera. Baza jest nadrzędnym źródłem po utworzeniu runu.
Status, tick i Wznów odnajdują ostatni run po kolejności utworzenia w tabeli,
nie po sesji operatora ani wyłącznie po cache. GET status nie importuje runu,
nie zapisuje cache i nie uruchamia tłumaczenia. Reload wymaga jawnego Wznów.

Zachowany stary checkpoint cache zostaje zaimportowany do tabeli przy najbliższej
mutacji po migracji (np. Wznów), z zachowaniem ID, licznika, pending i cursora.
Jeśli checkpoint sprzed tej poprawki już zniknął i nie ma go w DB, nie można
odtworzyć jego ID/cursora z samych zapisanych tłumaczeń. Panel pokazuje wtedy
liczbę translated/reviewed z ostatniego odczytu katalogu i pozwala na nowy
**dry-run → token → Start**, wyłącznie po ręcznym potwierdzeniu operatora.
Aktualne translated/reviewed są chronione, failed/needs_update pomijane.
Nie ma automatycznego Startu ani odtwarzania runu przez odczyt statusu.

Ostatni stopped/completed nie jest automatycznie wznawiany; nie odkopujemy
starszego incomplete runu za nowszym stanem końcowym. Failed jest niedokończonym
runem do Wznów/Stop i blokuje tworzenie kolejnego apply. Awaria DB zwraca błąd,
nie udaje idle ani nie pozwala na cache-only Start. Istniejące mutation,
processing i per-record locks pozostają wymagane i nie są ręcznie usuwane.

Dla obecnej sytuacji (np. 144 zapisane, brak run_id): po deployu z migracją
odśwież status. Jeśli odnaleziono checkpoint — Wznów. Jeśli panel nadal wskazuje
brak checkpointu w bazie/cache — nowy dry-run, oceń zakres missing i ręcznie
potwierdź Start. Zapisane 144 tłumaczenia nie zostaną wyzerowane; aktualne gotowe
rekordy będą pomijane. Nie można zagwarantować odzyskania utraconego starego ID.
