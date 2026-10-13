# GPSwiss.fr — zatwierdzony fallback NBP po #1481

Poprzednia polityka wymagała istniejącej ceny EUR i zabraniała konwersji PLN. Stąd Prix indisponible dla produktów PLN bez lokalnej ceny eBay FR EUR. Nowa decyzja biznesowa dopuszcza konwersję NBP.

Wspólny FrenchPriceResolver obsługuje karty (FrenchProductPresentation), draft (FrenchCheckoutService) i Stripe Checkout:

1. parts.price w EUR ma pierwszeństwo.
2. Następnie jednoznaczna aktywna lokalna cena eBay FR EUR po part_id, jak wcześniej. Sprzeczne ceny EUR nadal blokują zamiast wybierać przypadkową cenę lub maskować konflikt konwersją.
3. Jeśli nie ma EUR i parts.currency=PLN, dodatnia parts.price jest dzielona przez kurs EUR/PLN z istniejącego `app/Services/Marketplace/NbpExchangeRateService.php`, metoda `rateFor('EUR')`. Nie używamy parts.ebay_price ani marży eBay do tej konwersji.

Nie ma nowego providera, harmonogramu, tabeli kursów ani ręcznego kursu. NBP używa istniejącego cache `nbp_table_a_rate:EUR:latest` i TTL `GPS_NBP_CACHE_HOURS` (domyślnie 12 h), z zapytaniem do API NBP tylko przy braku cache. Nie zmieniamy modułu NBP ani integracji marketplace. W obrębie jednego requestu wynik, także niepowodzenie, jest używany ponownie, aby listing nie wykonywał zapytania dla każdej karty.

Zaokrąglenie takie jak dotychczas w MarketplaceListingReadinessService/EbayListingDryRunService: `round(PLN / kurs, 2)`, jawnie half-up, następnie integer cents. Bez marży i bez zaokrąglenia do całych EUR. Przykład: 100 PLN / 4,321 = 23,14 EUR.

Fail-safe: kurs musi być pozytywny/skończony, ok=true, currency=EUR, source=NBP_TABLE_A, z poprawną datą nie w przyszłości i nie starszą niż 7 dni kalendarzowych. Ten limit daje zapas na weekendy/święta, ale nie pozwala rozliczać cen ze starym kursem. Wynik musi mieścić się w bezpiecznym zakresie cents; dodatkowe limity totalu nadal sprawdza Stripe checkout. Niedostępny/niepoprawny kurs => Prix indisponible i blokada koszyka/checkoutu, bez zamówienia/provider call. Nie zgadujemy kursu ani waluty źródłowej (inne/brak waluty nie jest traktowane jako PLN).

`config/storefront-fr-checkout.php` domyślnie wybiera `existing_eur_nbp`. Jawne wcześniejsze polityki `existing_eur` i `catalog_eur` zachowują znaczenie bez konwersji. Deploy nie wymaga nowego .env, migracji ani workera; standardowy proces musi wczytać aktualny config aplikacji. Dostęp do API NBP musi być możliwy, gdy istniejący cache wygaśnie. Nie czyścić cache/checkpointów tłumaczeń.

Snapshot pozycji zawiera `price_source` i `price_conversion`: PLN źródłowe, kurs, datę/numer tabeli, EUR i politykę rounding. Fingerprint checkoutu uwzględnia te dane; zmiana kursu wymaga ponownego podglądu/potwierdzenia. Już utworzone Stripe sessions/Order mają zamrożony payload i kwotę EUR — nie aktualizujemy ich po zmianie kursu, nie zmieniamy webhooka ani settlement.

Tekst kart i strony produktu: **Frais de livraison calculés au panier**. Bez darmowej dostawy, obietnicy następnego dnia ani godziny 13:30. Stawki dostawy pozostają 55/70/130 EUR z tego samego read-only mapowania. Brak/niejednoznaczność mapowania nadal blokuje zakup.

Naprawiono także sklejenie `EUR@else` w szablonie product page, które mogło wydrukować równocześnie cenę i tekst niedostępności. Test sprawdza brak takiego tekstu przy poprawnej konwersji.

Po deployu w przeglądarce: sprawdź istniejące EUR, lokalne eBay FR EUR i produkt PLN z poprawną dostawą. Cena FR powinna być EUR na karcie i w checkout; PL pozostaje PLN. Sprawdź nowy tekst dostawy oraz neutralną blokadę dla brakującej dostawy/kursu. Stripe sprawdzać tylko w uzgodnionym trybie TEST po zakończeniu tłumaczeń, bez ręcznych zmian cen/stocku/mapowań. W Codexie NBP/Stripe/mail są fake/mock; nie wykonywano produkcyjnych zapisów, realnych zapytań NBP/Google/płatności ani deployu.

Filtry/sortowanie cen nadal używają źródłowej kolumny Part i są osobnym tematem; ten PR zmienia wspólną prezentację/wycenę FR, bez zmiany danych cenowych i marketplace.
