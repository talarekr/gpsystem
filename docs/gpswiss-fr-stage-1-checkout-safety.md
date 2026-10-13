# GPSwiss FR — Etap 1: bezpieczny katalog bez sprzedaży

## Przyczyna

FR i PL korzystały ze wspólnego checkoutu, który bez sprawdzenia domeny
zapisywał zamówienie i wywoływał PayU. Stripe nie jest zaimplementowany.
Publiczna strona podziękowania udostępniała dane klienta po numeric ID.

## Zachowanie po deployu

- gpswiss.fr ma storefront_code=gpswiss_fr, locale=fr, docelowego providera
  stripe. To tożsamość profilu, nie potwierdzenie dostępności Stripe.
- gpswiss.pl zachowuje gpswiss_pl, wybór języka i checkout PayU.
- GET/HEAD publicznych tras storefrontu na www.gpswiss.fr przekierowują 301
  na https://gpswiss.fr z zachowaniem ścieżki/query. POST nie jest replayowany:
  dostaje kontekst FR i blokadę checkoutu. Admin, narzędzia i webhooki nie są
  przekierowywane przez tę zmianę.
- GET checkout FR pokazuje kontrolowany komunikat francuski, bez formularza
  płatności. POST nie odczytuje koszyka, nie zapisuje zamówienia ani pozycji,
  nie wywołuje sold/PayU i nie czyści koszyka. Powrót PayU na FR też jest
  zablokowany. Nie ma flagi pozwalającej przypadkowo włączyć FR checkout.
- Strona podziękowania wymaga podpisanego URL. Nowe linki mają ważność 7 dni;
  zmiana ID/hosta/query lub wygaśnięcie powoduje 403. Odpowiedź jest private,
  no-store. Link jest sekretem dostępu i nie należy go udostępniać publicznie.
- Standardowy powrót PL z PayU ma dostęp do zamówienia w sesji klienta, która
  wykonała checkout (grant do 7 dni), albo przez podpisany URL. Bez dostępu
  pokazuje tylko ogólny ekran: nie ujawnia numeru/statusu i nie odpytuje PayU.
  Stare linki powrotu bez grantu nadal otwierają ogólny ekran. Webhook PayU,
  jego podpis i aktualizacja statusu płatności pozostają bez zmian.
- StorefrontContext::orderMetadata i Order::storefrontCode/isFrenchStorefront
  stanowią bazę rozróżnienia zamówień FR w adminie. Nie tworzymy obecnie FR
  zamówień, nie robimy backfillu historycznych danych ani nowego filtra admina.

## Wdrożenie

Wystarczy standardowy deploy kodu, bez migracji i zmiany .env. Zachowaj istniejący
APP_KEY, cache i checkpointy tłumaczeń. Nie uruchamiaj nowego runu tłumaczeń.
GET FR checkout ma pokazać blokadę, katalog pozostać dostępny, PL zachować swój
formularz. Nie wykonuj realnego zamówienia ani testu providera w ramach tego PR.

Przekierowanie aplikacyjne wymaga, żeby www.gpswiss.fr kierowało na aplikację.
DNS i certyfikat TLS dla www muszą istnieć zanim żądanie dotrze do middleware;
kod nie naprawi certyfikatu ani DNS. To nie zostało zweryfikowane na produkcji.

## Dalsze etapy

Etap 2: zatwierdzone ceny EUR, kraj/adres FR, fakturowanie, koszty dostawy,
snapshoty pozycji i rzeczywiste oznaczenie zamówienia FR w adminie. Utrzymać
blokadę sprzedaży do ukończenia odrębnej ścieżki płatności.

Etap 3: Stripe FR, osobna kontrola dostępności, signed return, podpisany webhook,
idempotencja i walidacja kwoty/waluty. Dopiero po weryfikacji można zastąpić
blokadę checkoutu. Ten PR nie dodaje Stripe SDK, kluczy ani provider calls.

Zakres nie obejmuje tłumaczeń, katalogowych cen, stocku, kodu marketplace,
Allegro/eBay/Ovoko, PayuService ani PayuNotifyController. Zmiana dostępu do
publicznych stron zamówienia jest świadomą poprawką bezpieczeństwa również PL.
