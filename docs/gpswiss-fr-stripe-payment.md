# GPSwiss FR — Stripe (Etap 3)

## Zakres i audyt

`CheckoutController` wybiera operatora z hosta po stronie serwera:
`gpswiss.fr` / `www.gpswiss.fr` → Stripe;
`gpswiss.pl` / `www.gpswiss.pl` oraz pozostałe dotychczasowe hosty → istniejący PayU.
Locale, cookie i pola klienta nie wybierają operatora. PL zachowuje walidację PayU/BLIK,
payload PayU, notify, return oraz metadane zamówienia.

Stripe wykorzystuje hosted Checkout z kartami, bez danych karty w aplikacji.
Kwota i waluta pochodzą z zapisanego zamówienia, locale sesji to `fr`.
Obsługiwane są EUR i PLN; koszyk z mieszaną, nieobsługiwaną walutą lub zerową kwotą
jest blokowany przed zapisem zamówienia. Nie wykonujemy konwersji cen/walut.

Istniejąca kolejność zapisu order/items i wywołania `sold()` pozostaje zachowana.
Webhook i return nie wywołują stock/sold ani integracji marketplace.
Cancel/failure nie przywraca dostępności części. Zamówienia trafiają do obecnego panelu.
Jeśli API Stripe jest chwilowo niedostępne po zapisie, zamówienie pozostaje w panelu,
koszyk zostaje wyczyszczony, a klient otrzymuje bezpieczny komunikat z numerem zamówienia.

## Konfiguracja

```dotenv
STRIPE_FR_ENABLED=false
STRIPE_FR_MODE=test
STRIPE_FR_SECRET_KEY=
STRIPE_FR_PUBLISHABLE_KEY=
STRIPE_FR_WEBHOOK_SECRET=
```

Flaga pozostaje domyślnie wyłączona. Serwis odrzuca tryb live i klucze live.
Włączony checkout wymaga skonfigurowanych kluczy testowych oraz sekretu webhooka.
Secret/webhook key należy ustawić przez obecny mechanizm konfiguracji środowiska,
bez umieszczania wartości w Git, panelu lub logach. Publishable key jest konfiguracją
dla storefrontu; hosted Checkout nie potrzebuje Stripe.js.
Brak konfiguracji blokuje FR przed utworzeniem zamówienia i `sold()`, bez wpływu na PL.

## Metadane i status

Brak migracji ani backfillu. Nowe zamówienia FR mają w `orders.meta`:
`source=storefront`, `payment_provider=stripe`, `storefront_code=gpswiss_fr`.
`meta.stripe` przechowuje referencje sesji/PaymentIntent, tryb test, kwotę/walutę,
losowy token potwierdzenia oraz identyfikatory przetworzonych eventów.
Nie zapisujemy pełnych odpowiedzi operatora ani danych kart.
Nie używamy kolumn `marketplace*` do oznaczania Stripe ani nie oznaczamy historii marketplace.

## Endpointy

- `POST https://gpswiss.fr/stripe/fr/webhook` — `stripe.fr.webhook`, także host `www.gpswiss.fr`.
- `GET https://gpswiss.fr/stripe/fr/success/{order}?token=…` — potwierdzenie tylko do odczytu.
- `GET https://gpswiss.fr/stripe/fr/cancel/{order}?token=…` — przerwany checkout tylko do odczytu.

Return/cancel wymagają losowego tokenu przypisanego do zamówienia i nie ustawiają `paid`.
Widok pokazuje tylko numer i lokalny status, z nagłówkami `private, no-store` oraz
`Referrer-Policy: no-referrer`. Callbacki są budowane dla stałej domeny `gpswiss.fr`,
nie dla hosta ani query przekazanego przez klienta.

Webhook używa raw body oraz `Stripe-Signature` (HMAC SHA-256 z tolerancją 300 sekund),
weryfikuje event/object test mode, zamówienie, metadane, kwotę, walutę i referencje
sesji/PaymentIntent. Eventy są deduplikowane w transakcji z blokadą zamówienia.
Późniejszy failure nie cofa `paid`. Wyjątek CSRF dotyczy wyłącznie nowego endpointu;
Stripe webhook działa także przy frontend maintenance. PayU notify pozostaje bez zmian.

## Test Stripe w test mode

1. Po zatwierdzonym merge/deploy pozostawić produkcyjną flagę `false`.
   W kontrolowanym środowisku testowym użyć checkoutu pod hostem FR.
2. W Stripe Dashboard w **test mode** pobrać `sk_test_…` i `pk_test_…`.
   Dodać test webhook dla `/stripe/fr/webhook` z eventami:
   `checkout.session.completed`, `payment_intent.succeeded`, `payment_intent.payment_failed`.
   Ustawić przypisany do tego endpointu `whsec_…`.
3. W środowisku testowym ustawić powyższe zmienne, `STRIPE_FR_MODE=test` i
   `STRIPE_FR_ENABLED=true`; odświeżyć konfigurację standardową procedurą deploymentu.
   Do lokalnego forwardowania można użyć `stripe listen --forward-to …/stripe/fr/webhook`
   z nagłówkiem Host FR, używając sekretu **tego listenera**. Stałe return URL nadal
   prowadzą do `gpswiss.fr`; nie zmieniamy DNS/TLS.
4. Utworzyć rzeczywistą sesję przez checkout FR. Sprawdzić kwotę, walutę i metadane
   w Dashboard. Zapłacić kartą testową `4242 4242 4242 4242`, dowolną przyszłą datą i CVC.
   `paid`/`processing` mają wynikać z webhooka, a success ma jedynie odczytać status.
5. Sprawdzić cancel, odrzucenie karty `4000 0000 0000 0002` i 3DS
   `4000 0025 0000 3155`. Wysyłać ponownie ten sam event z Dashboard:
   status/metadane nie powinny zmieniać się ponownie. Generyczne fixture eventów
   `stripe trigger` nie zawierają powiązań tego zamówienia i powinny być odrzucone.
6. Sprawdzić PL PayU/BLIK, oba aliasy `www` oraz odrzucenie złego podpisu,
   kwoty/waluty/metadanych i live mode. Wyłączenie flagi ma blokować tylko FR.
   Po testach pozostawić flagę wyłączoną do uzgodnionego uruchomienia.

Nie wykonano rzeczywistych połączeń płatniczych ani produkcyjnego włączenia Stripe.
Ten PR nie zmienia DNS/TLS, dokumentów prawnych, tłumaczeń, stocku ani integracji
Allegro/eBay/Ovoko. Zmienia tylko wybór operatora w istniejącym checkoutcie storefrontu.

## Weryfikacja

Końcowy pakiet: **163 testy, 820 asercji, PASS**. Obejmuje 103 nowe przypadki Stripe,
checkoutu i podpisanego PayU notify oraz 60 istniejących regresji PayU, hosta FR,
tłumaczeń, legal, dostępności, prezentacji płatności, fulfillment marketplace i panelu.
Sprawdzono podpisy/mismatch, event dedup, statusy, return bez `paid`, input spoofing,
wyłączenie FR, idempotencję/timeout retry i współbieżne aktualizacje metadanych.
PHP lint 12 plików, Pint wskazanych plików płatności/testów oraz `git diff --check` przechodzą.

Szerszy pakiet 91 istniejących regresji ma 18 errors i 19 failures zarówno na
niezmienionym `main` (`7393f78d`), jak i tym branchu; porównano identyczne testy,
tożsamości niepowodzeń i typy błędów. Brak nowych regresji w tym pakiecie.
Istniejących problemów fixture/katalogu poza zakresem Etapu 3 nie naprawiano.

Runtime: PHP 8.4.26, Laravel 11.57.0, Filament 3.3.54, PHPUnit 11.5.56.
Repo nie wersjonuje `composer.lock`; lokalny zestaw zależności rozwiązano zgodnie
z oryginalnym `composer.json` i sprawdzono wymagania platformy. Manifest zależności
nie został zmieniony. Połączenia Stripe w testach są fake; test z rzeczywistą
kartą testową i sekretami operatora pozostaje etapem po uzgodnionym deploymentcie.

```bash
php vendor/bin/phpunit \
  tests/Feature/StorefrontPaymentProviderTest.php \
  tests/Feature/StripeFrWebhookTest.php \
  tests/Unit/Payments/StripeFrServiceTest.php \
  tests/Unit/Payments/PayuServiceTest.php \
  tests/Feature/StorefrontFrenchHostTest.php \
  tests/Feature/StorefrontFrenchCatalogTest.php \
  tests/Feature/StorefrontTranslationsTest.php \
  tests/Feature/StorefrontLegalPagesTest.php \
  tests/Feature/StorefrontVisibilityTest.php \
  tests/Unit/OrderShippingPaymentDisplayResolverTest.php \
  tests/Feature/MarketplaceOrderFulfillmentSyncServiceTest.php \
  tests/Feature/AdminOrderPaginationTest.php
```
