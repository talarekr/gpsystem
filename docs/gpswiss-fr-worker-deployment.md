# Opcjonalny worker GPSwiss FR — tylko tryb queue

Domyślny runner panelowy browser nie wymaga tej infrastruktury. Ta instrukcja
dotyczy wyłącznie jawnego GPSWISS_FR_ADMIN_RUNNER=queue. Dla operatora bez
shella użyj docs/gpswiss-fr-translations-admin-tool.md i Wznów w panelu.

## Ustalenia z repo

`config/queue.php` definiuje dedykowane połączenie database `storefront-translations`,
tabelę `DB_QUEUE_TABLE` (domyślnie jobs), połączenie DB_QUEUE_CONNECTION i kolejkę
`storefront-fr-translations`, retry_after=660. `RunFrenchCatalogTranslationBatch`
używa tego połączenia i kolejki, timeout=540 i tries=1.

Repo nie zawiera produkcyjnego CI/CD, Supervisor/systemd, Docker Compose,
Procfile ani Horizon. `deploy.example.php` jest stagingowym helperem DirectAdmin:
kopiuje pliki i wywołuje Kernel Laravel w HTTP; nie zarządza stałymi procesami.
Checklista stagingu jedynie sugeruje opcjonalny worker default i cron scheduler.
Nie potwierdza to ich działania na GPSwiss. Domyślny worker bez wskazania kolejki
nie konsumuje kolejki FR. Obecny scheduler nie wykonuje batchy FR.

## Operator — wyłącznie panel aplikacji

1. W istniejącym runie użyj **Pauza**, jeśli chcesz wstrzymać wykonywanie na czas
   instalacji. Nie używaj Stop, nowego dry-run ani Start. Instalacja workera może
   od razu podjąć joby pozostawione w kolejce.
2. Przekaż hostingowi/deployerowi tę checklistę, szablon
   `deploy/supervisor/storefront-fr-translations.conf.example`, run_id
   `39c2e732-e177-4899-9221-8b79b7aa67d8` i ostatni dispatch ze statusu.
3. Po potwierdzeniu usługi przez deployera odśwież status, zweryfikuj run_id
   i kliknij **Wznów**. To odzyskuje istniejący checkpoint, bez nowego apply.
4. Obserwuj wzrost processed i aktualizację rekordu. Jeśli stoi, przekaż status
   deployerowi. Wznów nie zastępuje instalacji workera.

## Hosting/deployer — jednorazowe przygotowanie infrastruktury

Wykonuje osoba mająca uprawnienia do zarządzania serwerem, nie operator panelu.
Jeżeli produkcja używa już managera procesów, dodaj osobny proces FR w nim.
Nie zmieniaj workerów default ani marketplace. Szablon Supervisor w repo jest
konfiguracją do instalacji, nie samoczynnie wykonywanym hookiem.

1. Ustal produkcyjną ścieżkę aplikacji/current, ścieżkę PHP CLI (>=8.3, pcntl
   dla timeoutów), użytkownika aplikacji i współdzielony storage. Zastąp wszystkie
   placeholdery w szablonie. Nie kopiuj ścieżek stagingowych na produkcję.
2. Zapewnij Supervisor i jego start przy boot serwera. Zainstaluj w jego
   katalogu include plik `gpswiss-storefront-fr-translations.conf`; katalog
   zależy od hostingu. Log ma być poza publicznym katalogiem, zapisywalny przez
   użytkownika aplikacji. Proces korzysta z produkcyjnego .env aplikacji.
3. HTTP i worker muszą mieć tę samą bazę jobs oraz cache/store/prefix/lock store.
   Wymagany trwały cache z atomic locks (database/Redis), zachowany pomiędzy
   release'ami. Nie zmieniaj CACHE_PREFIX przy aktywnym runie. Nie czyść cache.
4. Po uzgodnionym wdrożeniu aktywuj wyłącznie nowy program przez managera:
   `supervisorctl reread`, `supervisorctl update gpswiss-storefront-fr-translations`.
   Potwierdź `supervisorctl status gpswiss-storefront-fr-translations` = RUNNING,
   brak restart loop i brak błędów połączenia/cache w logu. Aktywacja może
   rozpocząć rzeczywiste tłumaczenie istniejącego runu; przed nią operator może
   użyć Pauza. Nie uruchamiaj testowego apply ani nowego runu.

Proces utrzymywany przez managera wykonuje:

```text
php artisan queue:work storefront-translations --queue=storefront-fr-translations --timeout=540 --tries=1
```

Szablon dodaje sleep=3 i no-interaction, numprocs=1, automatyczny restart i
stopwaitsecs=700. Nie skracaj retry_after=660 ani czasu łagodnego zatrzymania.

## Integracja z każdym kolejnym deployem

Dodaj do rzeczywistego pipeline'u deployera (nie ma go w tym repo) dedykowany
krok restartu programu FR po publikacji release/config, przed uznaniem deployu
za zakończony: `supervisorctl restart gpswiss-storefront-fr-translations`, potem
sprawdzenie RUNNING i logu. Wykonuj go z uprawnieniami ograniczonymi do tej
usługi. Nie używaj restart all ani globalnego queue:restart dla innych workerów.
Przy deployu nieatomowym najpierw łagodnie zatrzymaj usługę FR, poczekaj na jej
zakończenie, opublikuj pliki i uruchom usługę. Stop procesu nie oznacza przycisku
Stop w aplikacji. Nie zabijaj w połowie provider call; respektuj stopwaitsecs.

Zachowaj storage/.env/cache/checkpointy przy zmianie release. Kompilowane
config/route/view cache można odświeżyć; `cache:clear` i `optimize:clear` są
niedozwolone przy aktywnym runie. Helper stagingowy został zmieniony tak, aby
ich nie wywoływać. Jeśli na serwerze istnieje osobna kopia deploy.php, deployer
musi zaktualizować również ją — synchronizacja celowo zachowuje ten plik.

Kryterium odbioru infrastruktury: deployer potwierdza stały proces, restart po
deployu i automatyczny start przy boot oraz zgodność cache/DB z HTTP. Sam merge
lub deploy plików nie stanowi potwierdzenia działającego workera.

## Hosting bez obsługi stałych procesów

Najbezpieczniej poprosić dostawcę o zarządzany background worker lub przenieść
obsługę tego procesu do hostingu, który go wspiera, z dostępem do tej samej bazy
kolejki i trwałego cache. Jeśli hosting ma panel „background workers”, wpisanie
w nim powyższego procesu przez administratora hostingu zastępuje Supervisor.
Operator aplikacji nadal korzysta wyłącznie z Wznów/Pauza i statusu.

Nie dodajemy wykonywania tłumaczeń w pollingu HTTP ani browserowym deployu:
limity czasu requestów mogą przerwać płatne wywołanie. Cron/scheduler też wymaga
konfiguracji hostingu i nie zapewnia sam z siebie stałego procesu. Do czasu
zapewnienia managera pozostaw run w Pauza. Nie ma bezpiecznego przycisku w
aplikacji, który nada uprawnienia infrastruktury lub zainstaluje usługę systemową.
