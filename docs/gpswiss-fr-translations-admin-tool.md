# GPSwiss FR — tłumaczenia katalogu w panelu

Narzędzie: `/admin/tools/storefront/fr-translations`, również pod nazwą
**GPSwiss FR — tłumaczenia katalogu** w grupie **Ustawienia** panelu.
Dostęp mają wyłącznie role Owner/Admin i Manager. Wszystkie endpointy wymagają
zalogowania i uprawnień; domeny francuskiego storefrontu, w tym `gpswiss.fr`
i `www.gpswiss.fr`, są blokowane także dla zalogowanego administratora.

## Obsługa bez terminala

1. Otwórz narzędzie w panelu na domenie administracyjnej. Sprawdź flagę FR,
   gotowość konfiguracji Google oraz liczniki produktów i kategorii.
2. Kliknij **Sprawdź / Dry-run**. Wynik pokazuje rekordy, pola, szacowane znaki,
   powody pominięcia i przykłady. Wejście na stronę, status i dry-run nie
   wywołują Google API. Dry-run nie zapisuje katalogu ani tłumaczeń; cache
   przechowuje jedynie wynik podglądu, potrzebny do potwierdzenia startu.
3. Oceń zakres i możliwy koszt. Flaga `GPSWISS_FR_TRANSLATIONS_ENABLED=true`
   oraz gotowy istniejący provider Google są wymagane do apply. Konfigurację
   zmienia administrator serwera, poza tym narzędziem; formularz nie przyjmuje
   ani nie pokazuje kluczy.
4. Wpisz dokładnie `TRANSLATE-ALL-GPSWISS-FR-CATALOG` i kliknij
   **Przetłumacz cały katalog**. Start zapisuje `run_id` i zleca zadanie
   osobnej kolejce FR. HTTP nie wykonuje tłumaczeń.
5. Obserwuj automatycznie odświeżany status lub kliknij **Odśwież status**.
   **Pauza**, **Resume** i **Stop** sterują wyłącznie tym przebiegiem.
   Odświeżenie lub zamknięcie strony zachowuje stan; worker działa w tle.
   Zlecone już tłumaczenie pojedynczego rekordu może zakończyć się po pauzie
   lub stopie; przed następnym rekordem sprawdzany jest aktualny stan.

Dry-run jest przypisany do użytkownika, ważny godzinę i jednorazowy. Kolejny
przebieg wymaga nowego dry-run i ponownego wpisania tokena. Istniejący przebieg
w stanie running/paused/stopped_on_error należy wznowić lub zatrzymać.

## Wybór katalogu i błędy

Narzędzie wykorzystuje `FrenchCatalogTranslationService` z PR #1462:
`type=all`, `only_missing=true`, `chunk=100`, bez `include_failed` ani
`include_needs_update`. Obejmuje również produkty i kategorie niewidoczne.
Odczytuje pola i hashe zgodnie z tym samym serwisem co komenda masowa.

- Aktualne translated i wszystkie reviewed są chronione.
- Istniejące failed i needs_update są pomijane. Narzędzie nie rozszerza
  automatycznie zakresu o te rekordy; wymagają osobnego, jawnie ocenionego
  przebiegu zgodnie z dokumentacją PR #1462.
- Błąd 429/5xx z bieżącej próby może otrzymać maksymalnie trzy ponowienia
  po 30/120/300 sekundach. Odroczenie korzysta z kolejki, bez oczekiwania HTTP.
  Ponawiany jest tylko konkretny rekord bieżącego przebiegu.
- Po ostatecznym błędzie runner przechodzi do stopped_on_error, pokazuje
  kontrolowany kod i komunikat. Resume kontynuuje ten sam przebieg; Stop
  kończy go jako stopped. Nie ma ponownego tłumaczenia reviewed ani jawnego
  nadpisywania istniejących failed.
- Po przerwaniu workera Resume odczytuje checkpoint. Zapisany sukces jest
  doliczany bez API. Jeżeli rekord nadal jest queued po rozpoczętej próbie,
  wynik i koszt poprzedniego requestu są niepewne: otrzymuje kontrolowany błąd
  interrupted_attempt i runner się zatrzymuje. Kolejne Resume przechodzi do
  następnego rekordu; nie ponawia automatycznie niepewnego requestu.

Snapshot dry-run zapisuje górną granicę ID dla obu typów. Nowe rekordy ponad
nią wymagają kolejnego dry-run. Usunięte rekordy są pomijane. Źródło i status
każdego rekordu są ponownie sprawdzane przez istniejący serwis podczas apply.
Szacowane znaki i `chars_translated` są informacją operacyjną, nie rachunkiem
Google; ponowienia mogą generować dodatkowy koszt.

## Wymagania wdrożenia

Nie ma nowej migracji, zmiany domyślnej kolejki ani automatycznego startu przy
deployu. Wymagane są tabele Etapu 2A i istniejąca tabela jobs. Wszystkie procesy
muszą używać tego samego trwałego cache z atomic locks, np. database albo Redis.
Cache zawiera ostatni przebieg, podglądy i checkpointy; nie wolno go czyścić
podczas aktywnego przebiegu. Nie stosować produkcyjnie cache array ani oddzielnych
lokalnych cache na kilku serwerach.

Administrator infrastruktury powinien utrzymywać osobnego workera:

```bash
php artisan queue:work storefront-translations --queue=storefront-fr-translations --timeout=540 --tries=1
```

Połączenie i kolejka pochodzą z PR #1462 (`retry_after=660`). Partia obejmuje
maksymalnie 100 rekordów; po około 45 sekundach runner oddaje pracę następnemu
zadaniu. Retry błędów Google jest kontrolowany przez stan runnera. Nie zmieniać
workera marketplace. Po zmianie konfiguracji odświeżyć config cache i zrestartować
workera FR standardową procedurą. Bez workera start pozostaje running, a
`processed` nie rośnie; status pokazuje czas ostatniej aktualizacji.

## Endpointy

| Metoda | URL | Zachowanie |
| --- | --- | --- |
| GET | `/admin/tools/storefront/fr-translations` | Ekran i lokalne odczyty |
| GET | `/admin/tools/storefront/fr-translations/status` | Status read-only |
| POST | `/admin/tools/storefront/fr-translations/dry-run` | Podgląd bez API i zapisów katalogu |
| POST | `/admin/tools/storefront/fr-translations/start` | `dry_run_id` i `confirm`; tylko enqueue |
| POST | `/admin/tools/storefront/fr-translations/pause` | `run_id`; pauza |
| POST | `/admin/tools/storefront/fr-translations/resume` | `run_id`; wznowienie |
| POST | `/admin/tools/storefront/fr-translations/stop` | `run_id`; stop |

POST wymagają CSRF. Mutacje sprawdzają aktualny run_id; stare karty nie mogą
sterować nowszym przebiegiem. Krótkie blokady synchronizują zmiany stanu,
a osobna blokada zapobiega równoległemu wykonywaniu partii.

Błędy historyczne i bieżące pokazują ID, SKU oraz kontrolowane kody/komunikaty.
Surowe wyjątki i payloady Google nie trafiają do odpowiedzi. Zapis biznesowy
obejmuje wyłącznie `part_translations` i `category_translations`; dodatkowo
używany jest cache/kolejka. Checkout, PayU, Stripe, stock, zamówienia i kod
marketplace/Allegro/eBay/Ovoko pozostają poza zakresem.
