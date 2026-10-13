# GPSwiss.fr — Etap 3: Stripe Checkout

## Zakres i źródła danych

FR nie miał wykonawcy płatności: draft był zawsze zablokowany. Ten etap dodaje
hostowany Stripe Checkout (wyłącznie karta, EUR, fr) z domyślnie wyłączoną flagą.
PL nadal używa dotychczasowego PayU. Nie zmieniono PayuService, PayuNotify,
stawek eBay, danych marketplace ani runnera tłumaczeń.

Cena jest pobierana na serwerze z istniejącego parts.price w EUR; jeśli produkt
ma walutę PLN, z aktywnej lokalnej oferty marketplace_listings w EUR, powiązanej
part_id i channel ebay_fr (lub ebay + konto code=ebay_fr). Dwie różne ceny FR
blokują checkout. Nie używamy parts.ebay_price jako EUR, nie wywołujemy NBP,
eBay ani przelicznika. Źródło ceny trafia do snapshotu pozycji. Dane lokalne
ofert mogą być nieaktualne — przed aktywacją sprawdź ich poprawność w panelu.

Dostawa jest read-only wyceną istniejącego resolvera eBay FR: 55/70/130 EUR,
ze sprawdzeniem mapowania grupy/policy ID. Brak, blokada lub sprzeczne przypisanie
nie pozwalają zapłacić. Dozwolona jest jedna pozycja w ilości 1, kompletny adres
billing/shipping FR i akceptacja CGV. Ceny/totale z POST są ignorowane.

## Konfiguracja przez istniejącą ścieżkę wdrożenia

Deploy musi wykonać migrację 2026_10_13_000002: stripe_fr_attempts i
stripe_fr_events. Operator nie uruchamia workera ani shella. Tabele zapewniają
trwałą idempotencję i lokalne blokowanie równoległych prób FR. Zachowaj APP_KEY,
cache, checkpointy tłumaczeń i istniejące konfiguracje PayU. Nie wdrażaliśmy tego
PR i nie zmienialiśmy produkcyjnego .env.

Ustaw przez swój mechanizm konfiguracji aplikacji (sekrety wyłącznie po stronie
serwera, nie w repo ani formularzu klienta):

```dotenv
STRIPE_FR_ENABLED=false
STRIPE_FR_MODE=test
STRIPE_FR_SECRET_KEY=sk_test_...
STRIPE_FR_PUBLISHABLE_KEY=pk_test_...
STRIPE_FR_WEBHOOK_SECRET=whsec_...
```

W Stripe Dashboard w trybie testowym dodaj webhook:

```text
https://gpswiss.fr/payments/stripe/fr/webhook
```

Subskrybuj checkout.session.completed i checkout.session.expired. Wybierz wersję
API endpointu 2025-06-30.basil (tę samą co klient API w kodzie). Skopiuj sekret
tego endpointu, nie sekret innego endpointu ani Stripe CLI. Ustaw flagę true
wyłącznie gdy klucze i webhook testowy są skonfigurowane; odśwież config cache
przez standardowy deploy. Publishable key jest przewidziany w konfiguracji;
hostowane Checkout nie wymaga Stripe.js. Jeśli go podasz, musi odpowiadać mode.
Brak secret/webhook secret/migracji lub niezgodność test/live blokuje płatności.

Webhook jest jedynym endpointem Stripe zwolnionym z CSRF. Podpis HMAC SHA256
obejmuje raw body, timestamp i sekret; tolerancja wynosi 300 sekund. Callback
jest dozwolony także podczas maintenance, wyłącznie na gpswiss.fr. Flaga
ENABLED=false blokuje nowe sesje, ale webhook nadal rozlicza istniejące zamówienia
przy zachowanym sekrecie i trybie. Nie zmieniaj mode/sekretów w trakcie otwartych
płatności bez zaplanowanego rozliczenia starych sesji.

## Ręczny test przez przeglądarkę — dopiero po deployu przez operatora

1. Pozostaw mode=test i użyj kluczy sk_test_/pk_test_. Włącz flagę po konfiguracji
   webhooka. Nie używaj kluczy live w tym teście.
2. Wybierz jeden produkt z istniejącą poprawną ceną EUR i przypisaniem dostawy
   FR. Nie zmieniaj waluty/ceny wspólnego produktu PLN, aby wymusić test.
3. Na /panier sprawdź nazwę FR, cenę EUR, dostawę i total. Na /commande wpisz
   adres FR i wybierz „Enregistrer mon aperçu”. Potwierdź CGV, następnie
   „Commander et payer avec Stripe”. Strona powinna wyraźnie pokazywać tryb test.
4. Na hostowanej stronie Stripe użyj karty testowej 4242 4242 4242 4242,
   przyszłej daty i dowolnego trzycyfrowego CVC. Nigdy prawdziwej karty.
5. Powrót ma podpisany URL; nie oznacza paid sam z siebie. W Stripe Dashboard
   sprawdź delivery webhooka i odpowiedź 200. W adminie sprawdź GPSwiss.fr,
   EUR, Stripe, payment_status=paid, mode=test i status=on_hold.
6. Testowa płatność nie zmienia stocku i nie uruchamia wysyłki. Powtórz dostarczenie
   tego samego eventu w Dashboard: brak podwójnej obsługi. Anulowanie w Stripe
   wraca na podpisaną stronę; pozwala ponownie otworzyć tę samą sesję.
7. Sprawdź blokadę dla PLN bez ceny FR EUR, braku dostawy i koszyka >1.
   Nie powinny powstać zamówienia ani requesty do Stripe.
8. Przed live potwierdź kompletność dokumentów FR (w tym mediator z audytu),
   prawidłowego sprzedawcę/VAT/konto Stripe oraz dane cen i dostaw. Ustawienie
   ENABLED=true nie uzupełnia brakujących decyzji/dokumentów.

## Idempotencja, niepewne odpowiedzi i stany

checkout_key jest generowany przez serwer w sesji i wiąże snapshot koszyka,
adresu, ceny i dostawy. Zmiana danych wymaga ponownego potwierdzenia. Podwójny
POST ma tę samą próbę, zamówienie, zamrożony payload i Idempotency-Key Stripe.
Timeout/500 nie tworzy następnego zamówienia: ponów z tej samej karty/sesji.
Uncertain provider response pozostawia pending próbę, nie udaje anulowania.
Nie publikujemy surowych błędów ani kluczy providera.

Checkout Session wygasa po 35 minutach; FR hold jest uwzględniany maksymalnie
40 minut. Hold nie zmienia quantity. To osłona równoległych płatności FR,
nie globalna rezerwacja dla PL/marketplace. Po wygaśnięciu nie trzeba workera;
kolejny GET pozwala uzyskać nowy checkout_key. Przy utracie sesji starego klienta
nie wydajemy publicznego dostępu do próby po ID — sprawdź pending w adminie i
Dashboard. Brak webhooka może pozostawić stare pending; nie oznaczaj paid na
podstawie return URL. Niepewnej próby nie usuwaj i nie powtarzaj niezależnie
przed jej rozliczeniem/wygaśnięciem.

Webhook w jednej transakcji sprawdza session ID, klucz próby, order ID/reference,
storefront, provider, locale, EUR, dokładny total, test/live i status. event_id
ma unikalny zapis; lock order i part serializuje konkurencyjne callbacks. Drugi
różny event dla tej samej płatności nie zdejmuje stocku ponownie. Podpisany event
może powiązać session ID z creating próbą, jeśli przyjdzie przed odpowiedzią API.
Return/cancel są signed i read-only. Nie zapisujemy danych karty.

W test: paid + on_hold, review_reason=test_payment_no_fulfillment; stock bez zmian.
W live: tylko zatwierdzony paid webhook zdejmuje jedną sztukę lokalnie. Przy
ostatniej sztuce lokalnie ustawia sold/niewidoczny/sale_source=gpswiss_fr.
Aktualizacja omija Part::saving, który przeliczałby eBay price nawet przy zmianie
stocku. Nie wywołujemy marketplace sold/sync ani nie modyfikujemy ofert.

Jeżeli PL lub marketplace sprzeda produkt podczas otwartej sesji FR, poprawny
webhook zapisuje paid + on_hold z stock_conflict_after_payment, bez dalszego
zdjęcia stocku. Operator musi rozwiązać konflikt/zwrot w Stripe. Automatyczna
synchronizacja ofert marketplace i refundy/reconciliation nie są częścią tego
PR. Live wymaga monitorowania tych konfliktów, delivery webhooków i pending.

## Źródła techniczne

- https://docs.stripe.com/api/checkout/sessions/create
- https://docs.stripe.com/api/idempotent_requests
- https://docs.stripe.com/webhooks/signature
- https://docs.stripe.com/testing
