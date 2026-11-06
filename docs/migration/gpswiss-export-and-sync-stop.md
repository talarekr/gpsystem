# GPSwiss → Wystawczesc: pełny eksport v2 i procedura zatrzymania integracji

Ten dokument opisuje narzędzie eksportu oraz przyszłą, osobno zatwierdzaną procedurę
operacyjną. Nie jest poleceniem wykonania importu, zatrzymania integracji ani zmiany
ofert. Utworzenie/wdrożenie PR nie generuje automatycznie paczek produkcyjnych.

Źródło: pojedyncza instalacja GPSYSTEM na gpswiss.pl. Cel: jawnie wybrany tenant
Wystawczesc. W tej sesji nie ma połączenia SELECT-only z produkcyjną bazą, dostępu
do produkcyjnych rootów oryginałów ani zatwierdzonego wspólnego snapshotu DB/storage.
Dlatego aktualne liczby i rzeczywista próbka nie są potwierdzone. Dawnych 7318 części
nie wolno traktować jako pomiaru na dzień nowego eksportu. Nie zastępować produkcji
fixture ani danymi syntetycznymi. Do wykonania potrzeba: odczytu właściwej bazy,
odczytu właściwych rootów/storage i prywatnego miejsca na pliki eksportu; nie
przesyłać haseł/tokenów w czacie, URL-ach ani opisie PR.

## Uruchomienie na źródle i prywatne pobieranie

Panel OwnerAdmin: `/admin/tools/gpswiss-migration-export`. Jeden run tworzy paczkę
próbną oraz pełną. Fragment adresu zapisuje UUID; po reloadzie/błędzie sieci wznowić
ten sam run, zamiast rozpoczynać drugi snapshot. Pauza zatrzymuje lokalną pętlę,
nie usuwa zapisanej pracy. Przy dużych plikach użyć CLI:

```
php artisan gps:migration-export --owner-id=ID_LOKALNEGO_OPERATORA
php artisan gps:migration-export --owner-id=ID_LOKALNEGO_OPERATORA --run=UUID_EKSPORTU
```

Domyślny owner 0 oznacza eksport CLI-only. Do prywatnego pobierania z panelu użyć
rzeczywistego lokalnego ID operatora, nie ID tenanta docelowego. Narzędzie nie
przyjmuje danych dostępowych w argumentach lub parametrach URL.

**Paczki v2 zawierają dane osobowe kupujących, adresy i dane fakturowe zamówień.**
Są to dane teraz jawnie objęte zakresem migracji, a nie publiczny katalog. Katalog
roboczy `storage/app/private/migration-exports/{UUID}` ma dostęp 0700, pliki 0600.
Nie wystawiać go przez publiczny symlink/CDN. Pobranie wymaga zalogowanego
OwnerAdmin i zgodności właściciela runu; UUID sam w sobie nie upoważnia do dostępu.
Nie tworzyć publicznych ani signed download linków dla osób bez autoryzacji.
Nie zapisywać treści paczek w publicznych logach, ticketach czy artefaktach CI.
Transport i retencję kopii/backupów ograniczyć do uprawnionych operatorów; czyszczenie
starych eksportów wymaga normalnej zatwierdzonej procedury, nie kasowania źródła.

PHP ZipArchive/fileinfo i odpowiednia wolna przestrzeń są wymagane. Pamiętać o
przestrzeni na kopie oryginałów, pełne archiwum, próbkę i pliki częściowe. Oryginały
powyżej limitu 100 MiB są jawnie blokowane, nie pomijane bez raportu. Eksporter
odczytuje dane/pliki źródła, a zapisuje tylko nowe prywatne artefakty. Nie przenosi,
nie zmienia nazw i nie usuwa źródłowych części, zamówień ani zdjęć.

## Zakres danych i struktura paczek

`sample.zip` i `full.zip` mają ten sam format v2 i pochodzą z jednego snapshotu DB.
Manifest podaje wersję, UUID runu, pochodzenie/środowisko, daty odczytu, rzeczywiste
liczby, zakres spójności, ostrzeżenia i sumy kontrolne. Nie można traktować paczki
v1 jako równoważnej: v1 pomijała zamówienia i ograniczała dane samochodów/produktu.

Pliki podstawowe: `products.json/.csv`, `cars.json`, `categories.json`,
`storage-locations.json`, `photos.json/.csv`, `missing-originals.json`,
`dependency-issues.json`, schema i manifest. Dodatkowe relacyjne zbiory mają wspólny
układ `datasets/<nazwa_tabeli>.json/.csv`. Osobny zbiór źródłowych metadanych zdjęć
zachowuje biznesowe kolumny rekordu, oprócz manifestu kopii pliku. Słownik `fields.generated.json` opisuje
odczytany schemat, typy i relacje; `statuses.json` reguły/statusy. Listy źródłowych
ID zapisują `sold-part-ids.json/.csv` oraz `parts-to-list-ids.json/.csv`.

### Części

Pełna paczka obejmuje wszystkie Part: sprzedane, do wystawienia, niewystawione,
nieaktywne i archiwalne, bez filtra ilości lub linku oferty. Zachowuje biznesowe
kolumny rzeczywistego schematu, kody/ID, opisy, parametry i atrybuty z oczyszczonych
JSON-ów. Nie polega wyłącznie na dawnym allowlistowanym skrócie pól. Brakujące pola
lub nieobsługiwane zbiory mają być ujawnione w metadanych pokrycia; nie wolno
uzupełniać ich zgadywaną wartością.

Mapowanie panelu: główny kod = `part_number`, dodatkowe = `additional_part_codes`,
jakość = `condition_notes`, pozycja = pierwsza wypełniona wartość
`review_metadata.part_position`, `legacy_payload.part_position`,
`legacy_payload.position`. `storage_location_id` odnosi się do źródłowej lokalizacji.
Zachować źródłowe `car_id` i osobno Ovoko ID auta; równość tych ID nie jest założeniem.
Ceny sklepu/Allegro/eBay/Ovoko i walutę zachować jako wartości zapisane; brak VAT/EAN
pozostaje brakiem, bez zakładania 23%, netto/brutto albo numeru na podstawie OEM.
Źródłowy `Part::saving` może przeliczać ceny, dlatego eksporter nie używa model save.

Grupy są zdefiniowane przez kod panelu:

- **Sprzedane:** `Part::scopeSold()` stosuje `parts.status = 'sold'`.
- **Do wystawienia:** `PartResource::adminPartsToListQuery()` stosuje
  `parts.needs_listing = true`.
- To dwa niezależne kryteria, mogą się przecinać; zachować obie flagi i wykazać
  sprzeczności, nie poprawiać źródła. `quantity = 0`, brak URL, samo archived czy
  brak publikacji nie definiują żadnej z tych dwóch grup.
- Dostępność sprzedaży (`ready`/`published` i dodatni stock) i publiczna widoczność
  są innymi stanami. Są eksportowane osobno, a nie redukowane do jednego active.

Powiązania kont zachowuje osobny `marketplace_account_identities`: tylko lokalne
ID, kanał, kod/nazwa konta, status i dostępna identyfikacja środowiska. Umożliwia to
rozróżnienie rynku/konta eBay lub Ovoko, bez przenoszenia credentials, ustawień OAuth,
kluczy integracji ani aktywnego połączenia. ID konta to referencja źródłowa, a nie
polecenie utworzenia połączenia o tym samym ID na docelowym tenancie.

### Samochody i słowniki

Pełny `cars` obejmuje wszystkie samochody z panelu, w tym bez części. Zachować
źródłowe lokalne i zewnętrzne ID, dane, parametry, statusy i biznesowe JSON-y po
usunięciu sekretów. Jeśli zapisano VIN/rejestrację lub dane sprzedawcy, podlegają
prywatnemu dostępowi paczki, nie publicznemu CSV. Nie sprowadzać auta do wyłącznie
make/model ani eksportować tylko aut znalezionych przez bieżące parts.car_id.

`ovoko_car_dictionary_entries` to osobny katalog marek/modeli/innych słowników
(`dictionary`, `ovoko_id`, `ovoko_brand_id`, zakres lat, nazwa/raw dane), nie lista
aut dawców. Powiązania z parts/cars i słownikiem dokumentować osobno. Nie nadawać
lokalnym modelom/dawcom wspólnego ID tylko dlatego, że nazywają się podobnie.

### Zamówienia i dane powiązane

Pełne `orders` obejmuje wszystkie kanały i statusy bez filtrów „aktywne”, daty,
marketplace czy test_import. Zachować identyfikatory, oryginalny kanał/rynek/konto,
daty, statusy, snapshoty adresów/faktury, pozycje, kwoty, płatności, dostawę i uwagi.
W `order_items` zapisane `product_name`, `sku`, `part_number` i ceny są prawdą
historycznej pozycji, nawet gdy `part_id` jest NULL lub odwołuje się do usuniętego
produktu. Nie zastępować tych pól aktualną nazwą/SKU części i nie usuwać pozycji
bez produktu. Każde takie powiązanie raportować z rzeczywistą przyczyną.

Zachować przechowywane metadane historii, podatków/rabatów, płatności/zwrotów i kwot
z JSON, a nie dopowiadać brakujących zdarzeń z obecnego statusu. Osobne zbiory obejmują
istniejące tabele pozycji, przesyłek, zwrotów i bezpiecznych rekordów płatności/zdarzeń.
`shipments.order_id` wiąże przesyłkę z orders; tracking, carrier/service i receiver
snapshot są danymi zamówienia, natomiast token/API-key/sekret w request/response nie.

**Ważna historyczna relacja:** `customer_returns.order_id` w bieżącej migracji
`2026_06_19_000002` odnosi się do `shop_events.id`, nie `orders.id`;
`CustomerReturn::order()` również wskazuje ShopEvent. Nie łączyć tabel po przypadkowo
równych liczbach. `shop_events` przechowuje zdarzenia order/return/complaint/payment
itd.; eksportować właściwe zdarzenia i zachować ich odrębne źródłowe ID.
`local_sales` jest osobnym zapisem sprzedaży lokalnej z part_snapshot, kwotą,
metodą płatności i datą, a nie canonical Order.

Stripe ma `stripe_fr_attempts` i `stripe_fr_events`. Fakty i referencje płatności,
statusy i przetworzone zdarzenia trzeba zachować, ale checkout_key/session_id,
checkout_url, client secrets, podpisy i akcjonowalne linki sesji nie są zwykłymi
identyfikatorami potrzebnymi do przeniesienia zamówienia. Hasła, tokeny, klucze API,
sekrety płatności, PAN/CVV/full card, ciasteczka i Authorization muszą zostać usunięte
rekurencyjnie również z tekstowo zapisanych JSON-ów. Danych kupującego nie należy
ogólnie anonimizować/usunąć: są objęte prywatnym zakresem migracji. Nie eksportować
całej tabeli użytkowników z hasłami/remember tokenami w celu pozyskania adresu klienta.

### Próbka pięciu rzeczywistych części

Wybrać pięć różnych istniejących Part, jeśli tyle jest w źródle: sprzedany według
statusu, do wystawienia według flagi, posiadający eBay, posiadający Ovoko, oraz
przypadek uzupełniający (np. wiele zdjęć/brak oryginału). Jedna część może spełniać
kilka reguł; nie tworzyć fikcyjnego rekordu, aby wypełnić brakujący przypadek.
Manifest ma wykazać, które przypadki istnieją i które są nieobecne w snapshotcie.
Przy źródle mniejszym niż pięć jawnie podać mniejszą liczbę.

Próbka obejmuje powiązane oryginały, auta i zależności oraz przykładowe rzeczywiste
zamówienia wybranych części. Dla wybranego zamówienia zachować pełen zestaw jego
pozycji i zapisane sumy; nie usuwać innych pozycji tylko po to, by pasowały do pięciu
produktów. Odwołanie do istniejącej części poza próbką jest `out_of_sample`, a nie
brakiem w źródle. Jeżeli nie ma powiązanego zamówienia, wykazać ten fakt, bez
syntetycznego zamówienia i bez domyślnego pobierania danych losowego klienta.

## Statusy przechowywane w kodzie

Słownik z chwili tego audytu nie zastępuje rzeczywistych wartości ze snapshotu:

| Zbiór | Znane wartości i znaczenie |
| --- | --- |
| Part.status | draft (szkic), needs_review (do sprawdzenia), ready (w sprzedaży), published (opublikowana), sold (sprzedana), archived (archiwalna) |
| Part.needs_listing | Niezależna flaga grupy „do wystawienia”; nie wyprowadzać jej z ilości ani statusu oferty |
| Order.status | new, processing, on_hold, ready_to_ship, ready_for_pickup, shipped, picked_up, completed, cancelled |
| Order.marketplace_status | Oryginalny status kanału; nie jest lokalnym Order.status |
| Order.payment_status | Przechowywana wartość płatności; nie ustalać jej wyłącznie z Order.status |
| Shipment.shipment_status | draft, previewed, created, label_created, label_missing, remote_created_label_missing, failed |
| CustomerReturn.status | Domyślnie pending; zachować każdą faktyczną wartość, bez zgadywania refundu z samego statusu |

Anulowanie nie oznacza automatycznie zwrotu kwoty, a brak pozycji w historii nie
dowodzi, że zdarzenie nie nastąpiło. Zachować zapisane fakty, daty i kwoty; brak
osobnej tabeli historii ujawnić, zamiast wygenerować pozorną historię.

## CSV, identyfikatory i zdjęcia

CSV: UTF-8 BOM i średnik; wartości złożone pozostają także w JSON, bez spłaszczania
historii/adresu w stratny tekst. ID/kody/SKU/EAN przechowywać jako tekst, zachowując
zera początkowe. Importując CSV do arkusza ustawić kolumny identyfikatorów na tekst;
sam CSV nie wymusza typu w Excelu. Liczby dziesiętne są zapisane jako stringi bez
konwersji waluty. Brak wartości SQL/JSON = null, CSV = `\N`; zero i pusty string są
odrębne. Literalny tekst zaczynający się od backslash jest escapowany, aby literalne
`\N` nie zmieniło się w null. Formułopodobne komórki są escapowane dla arkuszy;
JSON pozostaje podstawą odtworzenia oryginalnej wartości.

Daty mają format/precision wynikające ze źródła i są opisane w wygenerowanym słowniku.
Nie dopisywać UTC do pozbawionego strefy lokalnego DATETIME. Manifestowe daty snapshotu
są ISO-8601 z offsetem. Kanał i marketplace status pozostają oddzielone od lokalnego
statusu zamówienia. Oryginalne nieznane statusy są zachowywane, nie mapowane na new.

Zdjęcia zachowują oryginalną nazwę; katalog źródłowego Part izoluje kolizje między
produktami. Jeżeli kilka zdjęć jednej części ma tę samą nazwę, potrzebny jest
podkatalog źródłowego image_id/roli zamiast nadpisania pliku lub zmiany nazwy.
Manifest utrwala original_filename, package_path, part_id/image_id, rolę,
sort_order/is_primary, rozmiar i SHA-256. `code_photo_path` jest osobną rolą.
Nie zastępować oryginału miniaturą; brak/nieczytelność/zmiana/konflikt kopii są
raportowane. Nie pobierać zdalnego URL ani nie naprawiać danych przy eksporcie.

## Spójność i granica importu

MySQL capture wymaga InnoDB oraz jednego REPEATABLE READ / READ ONLY dla całego
wybranego zbioru tabel; SQLite używa jednej transakcji odczytu. Kolejne ticki
pracują na prywatnym snapshotcie, nie odczytują na nowo aktualnych Part/Order.
Za spójny snapshot plików nie wolno uznawać samej transakcji bazy. Fingerprinty
zarejestrowane później i per-file SHA dowodzą integralności konkretnej kopii, nie
atomowej zgodności wszystkich oryginałów z chwilą snapshotu DB. Manifest opisuje
rzeczywistą metodę kopii oraz ograniczenia. Finalny cutover wymaga skoordynowanego
write freeze lub wspólnego snapshotu bazy/storage. Eksporter sam tego nie wykonuje.

W paczkach obowiązuje `automatic_import_allowed=false`, brak automatycznej publikacji,
aktualizacji ofert, synchronizacji, zmiany stocku i wysyłania wiadomości. Source
status/needs_listing/visibility są dowodem do migracji, nie poleceniem uruchomienia
obserwatorów. Wystawczesc importer musi najpierw wymagać jawnego tenanta UUID,
planu kolizji i potwierdzenia; nigdy nie nadpisywać produktu po samym źródłowym ID/SKU.

**eBay/Ovoko:** zachować historyczne ID ofert, URL, rynek, konto i statusy do osobnego
uzgodnienia istniejących powiązań. Ich eksport nie daje zgody na API update ani przejęcie
oferty. **Allegro:** stare ID/statusy są wyłącznie historyczną informacją; nie tworzyć
z nich aktywnych mappingów. Nowe wystawienie ma nastąpić później z Wystawczesc, dopiero
po osobnym zatwierdzeniu kategorii/parametrów/dostawy i publikacji.

## Procedura zatrzymania i powrotu integracji — NIE wykonywać w ramach eksportu

1. Ustalić rzeczywisty deployment: aktywna baza/instancja, cron/timery systemd,
   schedule:work, Supervisor/Horizon, queue backend/nazwy, HTTP/browser runners,
   procesy aplikacji, konta Allegro/eBay/Ovoko i zewnętrzna automatyzacja. Nie zgadywać
   usług/nazw procesu z repozytorium. Nie zrzucać haseł, tokenów ani pełnych payloadów
   jobs do terminala, raportu lub publicznego ticketu.
2. Wybrać jednego właściciela stanów/zamówień na czas cutoveru. Oferty mogą sprzedawać
   również po zatrzymaniu lokalnego sync. Potrzebny kontrolowany delta feed albo osobno
   zatwierdzona pauza sprzedaży po stronie providera. Eksporter nie robi żadnej z tych
   rzeczy. Nie zatrzymywać odbioru płatności/klientów bez planu zachowania zdarzeń.
3. Najpierw zatrzymać nowych producentów pracy: ręczne publish/relist/price/stock,
   masowe edycje, browser runners i automatyzację deploymentu. W `routes/console.php`
   co pięć minut działa `marketplace:auto-sync-orders` (warunek
   `GPS_MARKETPLACE_ORDER_SYNC_ENABLED`) oraz niezależny
   `allegro:refresh-pending-listings --limit=50 --older-than-minutes=2`. Drugi nie ma
   tego warunku; zmiana pojedynczej flagi nie zamraża integracji. Osobno obsłużyć
   faktyczne cron/schedule:work i zadania zewnętrzne; nie wyłączać całego serwera.
4. Zarejestrować oczekujące, opóźnione, wykonywane i failed jobs po metadanych.
   `RefreshAllegroListingStatusAfterPublish` może wykonać pięć prób i samo zaplanować
   kolejną za dwie minuty. `ProcessOvokoStockSyncRunBatch` dispatchuje kolejną partię
   dla queued/running, a `SyncOvokoCarModelsBatchJob` dotyczy katalogu modeli.
   Kolejki obejmują database/Redis/sync i osobną storefront-fr-translations; ustalić
   rzeczywiste konfiguracje produkcji. Uzgodnione zadania drainować, następnie
   łagodnie zatrzymać właściwych konsumentów i ich automatyczny restart. Nie robić
   queue:clear/flush, nie uruchamiać wszystkich failed/retry jobs i nie polegać tylko
   na queue:restart, bo nadzorca może natychmiast wznowić pracę. Sync driver i żądania
   HTTP pozostają możliwe mimo zatrzymania workerów.
5. Webhooki/callbacki mają oddzielną granicę. W kodzie są `/payu/notify` i
   `/payments/stripe/fr/webhook`; callbacki OAuth nie są webhookiem zamówień.
   Nie znaleziono dedykowanej trasy Allegro/eBay/Ovoko webhook, co nie dowodzi braku
   subskrypcji na providerze, reverse proxy ani innej aplikacji. Ustalić rzeczywiste
   rejestracje/ingress. Zdarzenia wymagające obsługi przechować trwale i prywatnie,
   deduplikować po ID i rozdzielić akceptację zdarzenia od mutacji katalogu/stocku.
   Nie zwracać sukcesu i wyrzucać zdarzenia, nie blokować płatności bez backlogu.
6. Potwierdzić quiescence po upływie okna retry: brak aktywnego request/job/runnera,
   brak nowego dispatchu harmonogramu i brak handlera zmieniającego dane. Pusta kolejka
   lub jedna flaga nie jest dowodem. Wykonać uzgodniony snapshot DB/storage i eksport;
   zapisać rzeczywiste liczby, braki i hashe. Eksport nie wykonuje tej procedury.
7. Próbkę zaimportować dopiero po osobnej zgodzie, do izolowanego tenanta w no-sync,
   no-publish i no-message mode. Sprawdzić ID/kody/zera, ceny i waluty/podatki, statusy,
   wszystkie samochody/relacje, pełne przykładowe zamówienia i pozycje bez produktu,
   original_filename/order/primary/SHA. Nie odtwarzać aktywnych starych ofert Allegro.
   Pełny import wymaga oddzielnego zatwierdzenia; GPSwiss pozostaje niezależną kopią.
8. Wznowić osobno według planu: jeden writer dla danej oferty/konta eBay/Ovoko,
   uzgodnienie delty zamówień/płatności/stocku, kontrolowany replay backlogu bez
   podwójnych wiadomości/refundów, wybrane timery/workery i obserwacja. Nie włączyć
   równocześnie dwóch automatycznych writerów tych samych ofert. Rollback przywraca
   uzgodnioną władzę nad danymi i eventami, nie usuwa źródła i nie powiela transakcji.

Narzędzie nie zmienia env, kolejek, cron, webhooków, kont, ofert, stanów ani plików
źródłowych. Nie wysyła wiadomości. Wszystkie wymienione działania stop/import/resume
pozostają przyszłymi operacjami operatora.

Bezpieczny link eBay zachowuje numeryczne parametry `var`, `item`, `itemid`, gdy
identyfikują ofertę/wariant; tokeny, dane logowania, fragmenty i pozostałe parametry
nie są przenoszone. Nazwy i ścieżki zdjęć oraz kody/GTIN zachowują tekstowe cyfry —
nie są traktowane jako dane karty na podstawie samego podobieństwa numeru. Faktyczne
pola kart i sekrety są usuwane przed tą obsługą identyfikatorów.
