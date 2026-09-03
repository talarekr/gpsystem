# Audyt integracji Ovoko API w GPSwiss

**Data audytu:** 2026-09-03  
**Zakres:** statyczny przegląd repozytorium; bez odpytywania Ovoko i bez odczytu/zapisu produkcyjnej bazy danych  
**Tryb:** tylko odczyt. Jedyną zmianą jest niniejszy dokument. Nie dodano endpointu, ponieważ istnieją już dwa bezpieczniejsze endpointy diagnostyczne: cache słowników oraz preview payloadu auta.

## 1. Podsumowanie

Integracja jest scentralizowana częściowo. Transport Ovoko znajduje się głównie w `app/Services/Marketplace/Api/OvokoApiClient.php`, ale wywołania i logika biznesowa są rozproszone między serwis słowników, kontrolery narzędzi oraz adapter publikacji części. Konfiguracja nie pochodzi z `.env`: konto `ovoko_main`, URL, tryb, ustawienia i zaszyfrowane credentials są przechowywane w `marketplace_accounts`.

Najważniejsze ustalenia:

* istnieje lokalny cache dziewięciu słowników samochodowych i zachowywany jest pełny `raw_payload`;
* model Ovoko jest w praktyce pojedynczym ID modyfikacji/generacji z `/get/car_models/{brand_id}`; osobnych słowników „model ogólny”, „generacja”, „silnik” i „wariant” **nie znaleziono**;
* bezpieczny preview `GET /admin/tools/ovoko/import-car-payload-preview?car_id=...` nie wywołuje Ovoko ani nie mutuje bazy;
* właściwe utworzenie auta odbywa się przez `POST /crm/importCar`, po wymaganym tokenie confirm, i zapisuje ID w `cars.legacy_payload.ovoko_car_id`;
* publikacja części nie tworzy auta automatycznie: wymaga istniejącego Ovoko `car_id` i blokuje się przy jego braku;
* istnieje drugi, starszy flow mapowania auta. Jego „dry-run” wykonuje read-only request do `/v2/get/cars`, a akcja `mapping-apply` dopuszcza **GET i POST** oraz może utworzyć auto. To jest krytyczne ryzyko CSRF/niezamierzonego write;
* wiele legacy narzędzi `/tools/*` jest poza grupą `Authenticate`, w tym endpointy GET o nazwach wskazujących na lokalne mutacje. Jest to krytyczne ryzyko niezależnie od samego flow auta;
* nie ma retry/backoff/circuit breaker. Są timeouty 15, 20 lub domyślnie 30 s. Część ścieżek sanitizuje błędy, ale surowa odpowiedź API bywa zapisywana w `legacy_payload`, a ogólne logowanie nie ustanawia spójnego `marketplace_write`;
* `api_mode` nie stanowi globalnej blokady write: klientowe metody `importCar`, `importPart` i `changePartStatus` nie sprawdzają trybu. Guard jest zależny od kontrolera/adaptera;
* statyczny audyt nie potwierdza aktualnej liczby rekordów, kompletności popularnych marek ani realnych konfliktów nazw. Wymaga to odczytu produkcyjnego endpointu diagnostycznego, którego w tym audycie celowo nie wywołano.

## 2. Konfiguracja Ovoko API

### Źródło konfiguracji

| Element | Lokalizacja | Ustalenie |
|---|---|---|
| Konto | `marketplace_accounts`, rekord `code=ovoko_main` | `marketplace=ovoko`, `status`, `api_enabled`, `api_base_url`, `api_mode` |
| Sekrety | `marketplace_accounts.api_credentials` | Laravel cast `encrypted:array`; klucze `username`, `password`, `user_token` |
| Ustawienia | `marketplace_accounts.api_settings` | JSON; m.in. `default_part_status` / `ovoko_default_part_status` |
| UI | `app/Filament/Pages/Settings/OvokoSettings.php` | Hasła są polami password, nie są ponownie wypełniane, puste pole zachowuje sekret |
| Domyślny base URL | UI/migracje | `https://api.rrr.lt` |
| ENV | — | dedykowanych kluczy ENV Ovoko **nie znaleziono** |

Migracja `database/migrations/2026_06_21_000000_add_ovoko_api_settings_to_marketplace_accounts.php` dodaje wszystkie pola API. `app/Models/MarketplaceAccount.php` szyfruje `api_credentials` poprzez frameworkowy encrypted cast. `OvokoSettings::getAccount()` używa `firstOrCreate`, więc samo wejście w ekran ustawień może wykonać lokalny write, gdy konta nie ma.

### Sandbox / production

Osobnego sandbox URL ani przełącznika środowiska **nie znaleziono**. UI oferuje `dry_run` i `live`, podczas gdy `AbstractMarketplaceApiClient::getAccountReadiness()` uznaje za poprawne `dry_run` lub `read_only`. Jest więc niespójność dostępnych wartości. Sam URL jest dowolnym URL-em zapisanym przez administratora. **Wniosek z kodu:** rozdział sandbox/production jest konwencją konfiguracji, nie twardą izolacją.

### Endpointy bazowe i API

Wszystkie ścieżki są doklejane do `marketplace_accounts.api_base_url`:

* read: `POST /v2/get/parts`, `POST /v2/get/cars`, `POST /get/categories`;
* słowniki: `POST /get/car_brands`, `/get/car_models/{brand_id}`, `/get/fuel`, `/get/gearbox_type`, `/get/car_body_type`, `/get/wheel`, `/get/wheel_drive`, `/get/car_status`, `/get/car_class`;
* write: `POST /crm/importCar`, `POST /crm/importPart`, `POST /crm/changePartStatus`;
* dodatkowe endpointy zamówień/przesyłek istnieją w kliencie i adapterze przesyłek, ale nie są częścią tworzenia auta.

Nie zweryfikowano dokumentacji zewnętrznej ani odpowiedzi serwera. Nazwy i semantyka są faktami wyłącznie o aktualnym kodzie.

## 3. Klient HTTP i autoryzacja

### Klasy

* `app/Services/Marketplace/Api/MarketplaceApiClient.php` — interfejs klienta.
* `app/Services/Marketplace/Api/AbstractMarketplaceApiClient.php` — readiness, próbki ofert, wspólna konfiguracja i bezpieczny komunikat wyjątku.
* `app/Services/Marketplace/Api/OvokoApiClient.php` — wszystkie główne transporty Ovoko.
* `app/Services/Marketplace/Api/MarketplaceApiManager.php` — wybiera konto i tworzy klient dla kanału.
* `app/Services/Marketplace/Ovoko/OvokoCarDictionaryService.php` — cache, diagnostyka, mapping i payload auta.
* `app/Services/Marketplace/Publishing/OvokoPublishAdapter.php` — payload i publikacja części.
* `app/Services/Marketplace/Shipments/OvokoShipmentAdapter.php` — operacje przesyłkowe, poza flow auta.

Autoryzacja to pola formularza `username`, `password`, `user_token` dodawane do body każdego requestu. Nie jest używany nagłówek Bearer. Requesty są `application/x-www-form-urlencoded` (`asForm()`; dla powtarzalnych pól części własne kodowanie).

### Timeout, retry i błędy

* test części: 15 s;
* zmiana statusu: 20 s;
* słowniki/import/search: domyślnie 30 s;
* retry/backoff/jitter: **nie znaleziono**;
* `Http::throw()` nie jest używane, status HTTP i `status_code` są interpretowane ręcznie;
* `importCar` uznaje odpowiedź „exist/already” z ID za idempotentny sukces;
* deduplikacja przed write nie jest atomowa po stronie GPS — główny import opiera się na lokalnym `ovoko_car_id` oraz stałym `external_id=gps-car-{id}`.

### Logi i maskowanie

Pozytywne elementy: payload auta przekazywany do odpowiedzi nie zawiera auth; `safeError()` maskuje parametry o nazwach `password`, `user_token`, `token`, `secret`, `authorization`, `username`; readiness pokazuje jedynie flagi „configured”.

Ryzyka:

* `OvokoImportCarController::sanitizeResponse()` usuwa tylko `endpoint_used`; zagnieżdżone `payload` odpowiedzi zostaje zapisane w `cars.legacy_payload.import_car_response_payload`. Nie ma dowodu, że Ovoko nigdy nie echo-uje danych żądania;
* przy nieudanym imporcie kontroler także wykonuje lokalny write diagnostyczny;
* `OvokoCarDictionaryService::log()` zapisuje summary, ale nie dodaje jawnie `marketplace_write=false`;
* transport nie ma wspólnego middleware do redakcji request/response ani jednolitego structured audit logu;
* wyjątki runnera modeli są logowane bez regexowego maskowania (`exception_message`), choć typowe wywołanie nie wkłada credentials do URL.

## 4. Pobierane słowniki Ovoko

| Słownik lokalny | Endpoint (POST) | Parametry poza auth | Kiedy / mechanizm | Format obsługiwany |
|---|---|---|---|---|
| `brands` | `/get/car_brands` | brak | ręczny sync | `list`, `data`, `items`, `result` albo root array |
| `models` | `/get/car_models/{brand_id}` | `brand_id` w ścieżce | ręcznie dla marki albo batch runner | jw. |
| `fuel` | `/get/fuel` | brak | ręczny `enums`/`all` | jw. |
| `gearbox_type` | `/get/gearbox_type` | brak | ręczny `enums`/`all` | jw. |
| `body_type` | `/get/car_body_type` | brak | ręczny `enums`/`all` | jw. |
| `wheel` | `/get/wheel` | brak | ręczny `enums`/`all` | jw. |
| `wheel_drive` | `/get/wheel_drive` | brak | ręczny `enums`/`all` | jw. |
| `car_status` | `/get/car_status` | brak | ręczny `enums`/`all` | jw. |
| `car_class` | `/get/car_class` | brak | ręczny `enums`/`all` | jw. |

`OvokoApiClient::extractDictionaryRows()` toleruje kilka kształtów i mapy `id => name`. ID jest wybierane kolejno z `id`, `value`, `code`, `car_model_id`, `model_id`; nazwa z `name`, `status_name`, `pl`, `en`, `label`, `title`, `value`. To jest elastyczne, ale może błędnie potraktować `value` raz jako ID, raz jako nazwę.

Synchronizacja nie usuwa rekordów nieobecnych w nowej odpowiedzi. `updateOrCreate` tylko dodaje/aktualizuje, więc cache może zawierać wartości wycofane przez Ovoko. Nie znaleziono TTL ani okresowego schedulera. `scope=all` celowo pomija modele, aby uniknąć setek requestów; modele pobiera osobny, kolejkowany runner per marka (`OvokoCarModelSyncRunnerService`, `SyncOvokoCarModelsBatchJob`).

## 5. Lokalne tabele/cache słowników

Jedyna tabela samochodowa to `ovoko_car_dictionary_entries`:

* `dictionary`, `ovoko_id`, `name`;
* `ovoko_brand_id` jako parent dla modeli;
* `year_from`, `year_to`;
* JSON `raw_payload`;
* `synced_at`, timestamps;
* unique (`dictionary`, `ovoko_id`, `ovoko_brand_id`).

Model: `app/Models/OvokoCarDictionaryEntry.php`. Migracja: `database/migrations/2026_07_09_000000_create_ovoko_car_dictionary_entries_table.php`.

Przykładowy **syntetyczny** rekord zgodny ze schematem (nie odczytano produkcji):

```json
{"dictionary":"models","ovoko_id":"1548","name":"BMW 3 E46","ovoko_brand_id":"<brand-id>","year_from":1998,"year_to":2006,"raw_payload":{"id":"1548","name":"BMW 3 E46"},"synced_at":"<timestamp>"}
```

Liczby rekordów i próbki można bez zewnętrznego requestu odczytać przez admin-only `GET /admin/tools/ovoko/car-dictionaries-diagnose`. **Nie odczytano ich podczas audytu**, więc bieżące counts, braki popularnych marek i konflikt `VW`/`Volkswagen`: **nie ustalono**. Endpoint opcjonalny `car-dictionaries-audit` nie został dodany, bo istniejący diagnose pokrywa konfigurację bez sekretów, counts, próbki, timestampy i raw opt-in.

Nie znaleziono osobnych tabel/cache dla roku, silnika, generacji, wariantu, koloru, typu pojazdu ani strony kierownicy. Rok jest lokalnym polem; pojemność/kod/moc silnika i kolor są lokalnymi tekstami/liczbami.

## 6. Mapowanie lokalne → Ovoko

Mapowania auta są ręcznie wybieranymi ID zapisanymi w `cars.legacy_payload`. UI znajduje się w `app/Filament/Resources/CarResource.php`; opcje pochodzą z lokalnego cache. Przy wyborze ID UI kopiuje nazwę słownikową do widocznych pól lokalnych. Nie ma fuzzy/alias mappingu „VW → Volkswagen”; model UI grupuje nazwy heurystycznie, ale wysyła wybrane konkretne ID.

| Lokalne pole | Lokalna wartość / źródło | Pole Ovoko API | Wartość Ovoko | Źródło mapowania | Wymagane | Co jeśli brak |
|---|---|---|---|---|---|---|
| `legacy_payload.ovoko_brand_id` | ID wybrane w UI | brak osobnego pola | ID marki tylko do zawężenia modeli | cache `brands` | pośrednio | model może nie zostać poprawnie wybrany; readiness sprawdza cache |
| `legacy_payload.ovoko_car_model_id` (`rrr_car_model_id` fallback) | ID | `car_model` | ID z `models` | ręczne/cache | tak | twardy blocker |
| `production_year` lub `first_registration_year` | liczba | `car_years` | rok | bez słownika | tak w głównym flow | twardy blocker |
| `legacy_payload.ovoko_status_id` | ID | `status` | ID `car_status` | ręczne; ID `1` auto dla lokalnego `kupiony`, jeśli istnieje w cache | tak w głównym flow | twardy blocker |
| `legacy_payload.ovoko_fuel_id` | ID; UI kopiuje nazwę do `fuel_type` | `car_fuel` | ID `fuel` | ręczne/cache | nie | pominięte |
| `legacy_payload.ovoko_gearbox_type_id` | ID | `car_gearbox_type` | ID `gearbox_type` | ręczne/cache | nie | pominięte |
| `legacy_payload.ovoko_body_type_id` | ID | `car_body_type` | ID `body_type` | ręczne/cache | nie | pominięte |
| `legacy_payload.ovoko_wheel_drive_id` | ID | `car_wheel_drive` | ID `wheel_drive` | ręczne/cache | nie | pominięte |
| `legacy_payload.ovoko_wheel_id` | ID | `car_wheel_type` | ID `wheel` | ręczne/cache | nie | pominięte |
| `engine_capacity_cm3` | liczba | `car_engine_cubic_capacity` | liczba lokalna | bez słownika | nie | pominięte |
| `engine_power_kw` | liczba | `car_engine_power` | liczba lokalna | bez słownika | nie | pominięte |
| `mileage_km` | liczba | `car_mileage` | liczba lokalna | bez słownika | nie | pominięte |
| `engine_code` | tekst | `car_engine_code` | tekst lokalny | bez słownika | nie | pominięte |
| `gearbox_code` | tekst | `car_gearbox_code` | tekst lokalny | bez słownika | nie | pominięte |
| `color` / `color_code` | tekst | `car_color` / `car_color_code` | tekst lokalny | bez słownika | nie | pominięte |
| `interior` | tekst | `car_interior` | tekst lokalny | bez słownika | nie | pominięte |
| `purchase_price` | liczba | `car_price` | liczba lokalna | bez słownika | nie | pominięte |
| `defects_notes` | tekst | `defectation_notes` | tekst lokalny | bez słownika | nie | pominięte |
| `purchase_date` / `dismantled_at` | data | `purchase_date` / `dismantling_at` | `Y-m-d H:i:s` | bez słownika | nie | pominięte |
| `vin` | tekst | `car_body_number` | tekst lokalny | bez słownika | nie | pominięte |
| lokalne `car.id` | liczba | `external_id` | `gps-car-{id}` | hardcoded | zawsze dodawane | nie dotyczy |
| `steering_side` | tekst | — | — | **nie znaleziono** | nieznane | nie wysyłane |
| typ pojazdu / `car_class` | ewentualne ID cache | — | — | słownik pobierany, brak payload mappingu | nieznane | nie wysyłane |

Główny readiness weryfikuje obecność ID w cache. Nie ma fallbacku z lokalnego tekstu na dictionary ID, co jest bezpieczne. Wyjątek stanowi legacy `OvokoCarMappingController::createPayload()`, który wysyła lokalny tekst `fuel_type` jako `car_fuel`, `vin` zamiast `car_body_number` i `mileage` zamiast `car_mileage`. **Wniosek z kodu:** ten starszy payload może być niezgodny z nowszym potwierdzonym mappingiem.

## 7. Tworzenie samochodu w Ovoko

### Główny flow

1. Operator uzupełnia auto w Filament `CarResource` i zapisuje lokalne ID słownikowe w `legacy_payload`.
2. `GET /admin/tools/ovoko/local-car-ovoko-readiness?car_id=...` wywołuje `OvokoCarDictionaryService::readiness()` tylko lokalnie.
3. `GET /admin/tools/ovoko/import-car-payload-preview?car_id=...` pokazuje minimalny i rozszerzony payload bez requestu i write.
4. `POST /admin/tools/ovoko/import-car` wymaga `confirm=import-ovoko-car`.
5. Kontroler blokuje istniejące lokalne `ovoko_car_id`, brak modelu/statusu/roku, brak rekordu słownika i brak credentials.
6. `OvokoApiClient::importCar()` wysyła `POST /crm/importCar`.
7. Po sukcesie `car_id` jest zapisywane do `cars.legacy_payload.ovoko_car_id`, wraz z requestem, sanitized response i timestampem.

Nie znaleziono przycisku/action w `CarResource`, który bezpośrednio odpala `import-car`; dostępna jest infrastruktura URL narzędzi. Z panelu jest natomiast edycja mappingów. **Wniosek z kodu:** write może być wywoływany bezpośrednio narzędziem HTTP, nie standardową akcją rekordu Filament.

### Legacy flow

`GET /admin/tools/ovoko/cars/{car}/mapping-dry-run` wykonuje search w Ovoko (`POST /v2/get/cars`) mimo nazwy „dry-run”. `GET|POST .../mapping-apply?confirm=ovoko-car-map` może:

* przypisać ręcznie podany car ID;
* przy jednym kandydacie przypisać go;
* gdy search jest niedostępny i jest `car_model`, automatycznie wywołać `importCar`;
* zapisać ID do `cars.external_id` i ustawić `source_system=ovoko` (inny storage niż główny flow).

To tworzy dwa źródła prawdy. `mappedIds()` próbuje je scalać, preferując atrybut `ovoko_car_id`, potem `external_id`, potem legacy payload.

### Błędy, retry, deduplikacja

Nie ma retry. Przy exception lub błędzie API główny kontroler zapisuje diagnostykę lokalnie, ale nie zapisuje `ovoko_car_id`. Odpowiedź „existing” z ID jest traktowana jako sukces. Stały `external_id` wspiera idempotencję po stronie Ovoko, ale nie ma lokalnej blokady współbieżnych requestów przed API; lock jest dopiero podczas zapisu sukcesu.

## 8. Payload samochodu do Ovoko

Dokładny kształt głównego payloadu (puste opcjonalne pola są usuwane):

```json
{
  "car_model": "1548",
  "car_years": 2004,
  "status": "1",
  "external_id": "gps-car-123",
  "car_fuel": "1",
  "car_gearbox_type": "2",
  "car_body_type": "3",
  "car_wheel_drive": "4",
  "car_wheel_type": "5",
  "car_engine_cubic_capacity": 1995,
  "car_engine_power": 110,
  "car_mileage": 220000,
  "car_engine_code": "ABC",
  "car_gearbox_code": "XYZ",
  "car_color": "czarny",
  "car_color_code": "A1",
  "car_interior": "skóra",
  "car_price": "5000.00",
  "defectation_notes": "opis lokalny",
  "purchase_date": "2026-01-01 00:00:00",
  "dismantling_at": "2026-01-02 00:00:00",
  "car_body_number": "<VIN>"
}
```

To przykład syntetyczny, nie dane produkcyjne. Do requestu klient dodaje `username`, `password`, `user_token`, których preview i response kontrolera nie pokazują. ID słownikowe: `car_model`, `status`, fuel, gearbox, body, drive, wheel. Teksty/liczby lokalne: pozostałe pola. Marka nie jest wysyłana osobno. Walidacja przed requestem obejmuje model/status/rok, istnienie mapping ID w cache, lokalny status oraz brak już zapisanego Ovoko ID. Nie ma walidacji zakresu roku, VIN, jednostek mocy/pojemności/ceny ani długości tekstów.

Preview istnieje i jest lokalny. Sanitized request jest zwracany jako `request_payload_without_auth`. Response jest tylko częściowo sanitizowany, jak opisano w sekcji bezpieczeństwa.

## 9. Przypisanie części do samochodu Ovoko

`Part` posiada `car_id`; snapshot pojazdu jest wtórny. `OvokoPublishAdapter::importPartPayload()` ładuje relację `part->car` i rozwiązuje Ovoko ID auta. Payload części `POST /crm/importPart` zawiera między innymi:

* `category_id` — ID mapowania kategorii Ovoko;
* `car_id` — istniejące ID samochodu Ovoko;
* `quality`, `status`, `price`, `original_currency`;
* `external_id`, kody, opis oraz publiczne URL-e zdjęć.

Brak `category_id`, `car_id`, jakości, statusu lub zdjęcia jest blockerem publikacji części. Kategoria części nie wpływa w kodzie na pola tworzonego auta. Samochód **nie jest automatycznie tworzony przez publikację części**. Właściwa kolejność to: lokalne auto → mapping/import auta → zapis Ovoko car ID → publikacja części. Błąd auta blokuje produkt przez brak `car_id`.

## 10. Narzędzia admina i commandy

Poniżej narzędzia najbardziej istotne dla żądanego zakresu. Pełna lista tras Ovoko znajduje się w `routes/web.php`.

| URL / command | Charakter | Ovoko request | lokalny write | marketplace write | Guard |
|---|---|---:|---:|---:|---|
| `GET /admin/tools/ovoko/car-dictionaries-diagnose` | read | nie | nie | nie | admin auth |
| `POST /admin/tools/ovoko/sync-car-dictionaries` | import cache | read API | tak | nie | admin + confirm `sync-ovoko-car-dictionaries` |
| `GET /admin/tools/ovoko/car-models-sync-runner[/status]` | status | nie | nie | nie | admin |
| `POST .../car-models-sync-runner/start|run-next-batch|stop` | cache runner | read API w batchu | tak | nie | admin; POST, własne guardy runnera |
| `GET /admin/tools/ovoko/local-car-ovoko-readiness` | read | nie | nie | nie | admin |
| `GET /admin/tools/ovoko/import-car-payload-preview` | read | nie | nie | nie | admin |
| `POST /admin/tools/ovoko/import-car` | write | tak | tak | **tak: tworzy auto** | admin + exact confirm |
| `POST /admin/tools/ovoko/cars/set-status-mapping` | mapping | nie | tak | nie | admin + confirm w kontrolerze |
| `GET .../cars/{car}/mapping-dry-run` | dry-run/search | tak, read | nie | nie | admin; nazwa nie oznacza offline |
| `GET|POST .../cars/{car}/mapping-apply` | mapping/create | read + możliwy write | tak | możliwy importCar | admin + query confirm; **GET dozwolony** |
| `GET /admin/tools/ovoko/linked-products-check` | diagnose | read | nie | nie | admin |
| `GET /admin/tools/ovoko/sold-mapping-check` | diagnose | read | nie | nie | admin |
| `GET /admin/tools/ovoko/import-product-data` | preview/import danych | do sprawdzenia per params | możliwy | nie powinien publikować | admin |
| `GET /admin/tools/ovoko/part-status-sync-diagnose` | diagnose | read | nie | nie | admin |
| `GET /admin/tools/marketplace/ovoko-diagnose` | diagnose | możliwy read | możliwy status check write lokalny | nie | admin |
| `GET /admin/tools/ovoko-stock-sync-dry-run` | preview | read | nie | nie | admin |
| `GET|POST /admin/tools/ovoko-stock-sync-apply` | write | tak | tak | tak | admin + confirm w kontrolerze; **GET dozwolony** |
| `GET|POST /admin/tools/ovoko/unlink-stale-listing` | local unlink | możliwy read | możliwy | nie | admin + confirm, lecz **GET dozwolony** |
| category mapper / batch mapping | category | read categories | apply zapisuje | nie | admin; apply POST/confirm zależny od kontrolera |
| `marketplace:import-ovoko-orders --dry-run` | read-only | read | nie | nie | tylko `--dry-run` obsługiwany |
| `marketplace:import-ovoko-mapping [--dry-run]` | local import | nie | tak bez flagi | nie | dry-run opcjonalny |
| `marketplace:import-ovoko-manual-mapping [--dry-run]` | local import | nie | tak bez flagi | nie | dry-run opcjonalny |
| `marketplace:build-ovoko-mappings-from-parts [--dry-run]` | local mapping | nie | tak bez flagi | nie | dry-run opcjonalny |
| `marketplace:backfill-ovoko-links [--apply]` | local backfill | możliwy read | tylko apply | nie | apply flag |
| `marketplace:backfill-ovoko-listing-urls [--apply]` | local backfill | możliwy read | tylko apply | nie | apply flag |
| `marketplace:diagnose-ovoko-status` | read-only | nie | nie | nie | CLI |
| `marketplace:repair-part-8212-ovoko-price-log --apply --confirm=...` | local repair | nie | apply | nie | apply + confirm |

Nie znaleziono cyklicznego schedulera słowników lub auta. Są kolejki uruchamiane jawnie przez runner. Pozostałe narzędzia dotyczą zamówień, wysyłek, cen, stocku, mapping reset i listing URLs; nie były uruchamiane w audycie.

## 11. Zabezpieczenia read-only / write / confirm

### Dobre zabezpieczenia

* główne trasy `/admin/tools/ovoko/*` są w grupie Filament `Authenticate`;
* główny import auta jest POST-only i wymaga stałego confirm;
* preview payloadu jest offline/local-only;
* brak mappingu wymaganych ID blokuje główny import;
* credentials są szyfrowane w DB i UI ich nie odtwarza;
* response readiness nie pokazuje sekretów;
* publikacja części blokuje się bez auta, kategorii i innych wymaganych danych.

### Luki / ryzyka

1. **Krytyczne:** `mapping-apply` dopuszcza GET, a po confirm może wywołać `importCar`. Write nie powinien być uruchamialny metodą GET.
2. **Krytyczne:** poza admin group istnieje duży legacy blok `/tools/*` bez `Authenticate`. Zawiera read API oraz GET-y o nazwach `delete`, `hide`, `clean`, `start`, `run`, `clear`. Audyt statyczny potwierdza brak middleware na poziomie tras; szczegółowy guard każdego kontrolera nie jest jednolity.
3. **Wysokie:** stock apply/start/cancel/tick dopuszczają GET w części tras. Nawet przy confirm URL może trafić do historii, logów, prefetchera lub skanera.
4. **Wysokie:** `api_mode` nie blokuje write centralnie. Bezpieczny tryb zależy od callerów.
5. **Wysokie:** dwa flow auta mają różne payloady i różne miejsca zapisu ID (`legacy_payload` kontra `external_id`).
6. **Średnie:** `marketplace_write` nie jest obowiązkowym polem wspólnego log schema; logi słowników go nie dodają, choć faktycznie write marketplace nie występuje.
7. **Średnie:** sanitizacja odpowiedzi importCar jest płytka; surowy payload API trafia lokalnie do auta.
8. **Średnie:** stałe confirm są publicznie widoczne w kodzie i nie są jednorazowe ani związane z użytkownikiem/obiektem.
9. **Średnie:** brak retry jest bezpieczny wobec duplikatów, lecz timeout po przyjęciu requestu może pozostawić auto utworzone zdalnie i nieoznaczone lokalnie.
10. **Średnie:** status readiness wymaga jednocześnie poprawnego `ovoko_status_id` i niepustego lokalnego `car.status`; komunikat `status` jest dwuznaczny.

## 12. Ryzyka i braki

### Kompletne lub dobrze pokryte

* zaszyfrowane przechowywanie trzech credentials;
* centralny klient podstawowych endpointów;
* cache ID/nazwy/raw/timestamp i relacja marka → model;
* ręczny sync z confirm i kontrolowany batch modeli;
* lokalny readiness i preview pełnego payloadu;
* blokada publikacji części bez Ovoko car ID;
* zachowanie stałego `external_id` dla idempotencji.

### Nie znaleziono / nie potwierdzono

* dedykowanych ENV Ovoko;
* formalnego sandboxu;
* retry/backoff;
* automatycznego harmonogramu słowników;
* osobnego endpointu/słownika generacji, silników i wariantów;
* mappingu strony kierownicy oraz typu pojazdu/`car_class` do importCar;
* słownika kolorów i lat;
* automatycznego tworzenia auta podczas publikacji części;
* jednoznacznej dokumentowanej walidacji jednostek i zakresów;
* pewności, że wszystkie wartości API są aktualne — sync nie usuwa stale rows;
* produkcyjnych counts, próbek, braków marek i konfliktów nazw — nie łączono się z produkcją.

### Czy auta mogą wyjść z błędnymi wartościami?

Tak. Główny flow ogranicza to przez ID cache, lecz nadal możliwe są: stare ID pozostawione przez upsert-only cache, zły ręczny wybór, błędna interpretacja `car_model` jako generacji/modyfikacji, błędne jednostki pól liczbowych oraz swobodne teksty. Legacy flow dodatkowo wysyła tekst paliwa i inne nazwy pól niż główny flow. `car_class` jest pobierane, ale niewysyłane; `steering_side` niewysyłane.

## 13. Rekomendacje kolejnych etapów

Bez wykonywania ich w ramach tego audytu:

1. Natychmiast zmienić wszystkie write routes na POST/PUT/DELETE-only i usunąć możliwość apply przez GET, w pierwszej kolejności car mapping i stock runner.
2. Objąć cały legacy `/tools/*` autoryzacją administratora i CSRF; osobno zinwentaryzować każdy GET wykonujący lokalną mutację.
3. Dodać centralny `writeAllowed()` w kliencie/managerze, domyślnie deny; wymagane: `api_mode=live`, jawna intencja, capability i correlation ID.
4. Wycofać legacy `OvokoCarMappingController::createPayload()` albo skierować go do `OvokoCarDictionaryService::readiness()`. Ujednolicić storage Ovoko car ID w dedykowanej kolumnie lub relacji.
5. Rekurencyjnie sanitizować request/response przed logowaniem; nie przechowywać pełnej odpowiedzi, tylko allowlistę `status_code`, `car_id`, `message`, bez echo auth.
6. Ustanowić obligatoryjny envelope logu: `external_request`, `external_method`, `marketplace_write`, `local_write`, `dry_run`, `confirmed_by`, `correlation_id`.
7. Dodać strategię freshness: `last successful sync`, próg wieku, oznaczanie/dezaktywację rekordów znikających z API zamiast pozostawiania ich bezterminowo.
8. Na produkcji uruchomić wyłącznie istniejący admin-only local diagnostic `car-dictionaries-diagnose` (bez sync), aby zebrać counts/próbki i sprawdzić VW/Volkswagen. Nie używać `include_raw=1` poza kontrolowanym dostępem.
9. Potwierdzić z aktualną oficjalną specyfikacją Ovoko wymagane pola, enumy, jednostki i semantykę `car_model`, `status`, `car_class`, kierownicy i kolorów.
10. Dodać mockowane contract tests dla pełnego payloadu, timeoutu po przyjęciu, odpowiedzi „already exists”, duplikatu równoległego i redakcji zagnieżdżonych sekretów.

## Oświadczenie wykonania

* Request do Ovoko: **nie wykonano żadnego**.
* Lokalny write aplikacyjny/DB/cache: **nie wykonano żadnego**.
* Marketplace write: **nie wykonano żadnego**.
* Import apply / sync / cache clear / scheduler / job: **nie uruchamiano**.
* Zmiany produkcyjne: **brak**.
* Zmienione pliki repozytorium: wyłącznie `docs/audits/ovoko-api-audit-2026-09-03.md`.
* Endpoint `car-dictionaries-audit`: **nie dodano**; istniejące endpointy diagnostyczne wystarczają i ograniczają zakres zmian produkcyjnych.

