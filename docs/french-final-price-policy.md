# GPSwiss.fr — finalna polityka cen

Zastępuje politykę #1492 (eBay.de EUR → Part EUR → PLN/NBP).
Lokalne listingi mogą zawierać historyczne lub błędnie oznaczone kwoty; nie są źródłem ceny sklepu FR.
Nie potwierdzano danych produkcyjnych w Codexie. Przykład 05L131705P pochodzi ze zgłoszenia operatora.

Zmiana decyzji biznesowej po #1496: Jarek wraca do +25%; dodatkowe +20% zostało usunięte.

## Wyliczenie

- Zwykłe produkty: `parts.price`, wyłącznie `currency=PLN`, ×1.25 / kurs NBP EUR.
- Jarek: `jarek_gearboxes.price`, wyłącznie PLN, ×1.25 / kurs NBP EUR (+25%).
- Marża przed konwersją; bez zaokrąglania marży do grosza, końcowe EUR half-up do 2 miejsc.
- Wspólny FrenchPlnPricing dla resolvera Part, prezentacji Jarka, audytu, SQL sortowania/filtrów i preview FR.
  Dane źródłowe mają 2 miejsca dziesiętne; iloczyn zachowuje 4 miejsca przed konwersją.
- Nie ma fallbacku do Part EUR, parts.ebay_price ani marketplace_listings. Nie-PLN jest jawnie niedostępne.
- Koszyk i checkout czytają te same resolvery; Stripe dostaje obliczone centy. Gateway i webhook bez zmian.
- Dostawa pozostaje osobno: dotychczasowe mapowania 55/70/130 EUR; Jarek 130 EUR.
- gpswiss.pl, PayU, ceny źródłowe, stock, marketplace, zdjęcia, tłumaczenia i URL-e bez zmian.

## Kurs / brak ceny

Raport czyta wyłącznie istniejący cache NBP i nie odświeża go ani nie zapisuje.
Runtime zachowuje istniejący currentEurPln(): przy braku ważnego cache próbuje dotychczasowego pobrania NBP.
Walidacja wymaga dodatniego kursu EUR z NBP_TABLE_A i poprawnej daty, nie przyszłej, maksymalnie 7 dni.
Przy niepowodzeniu front pokazuje Prix indisponible, zakup jest zablokowany, checkout nie tworzy zamówienia ani żądania Stripe.
Nie używa się domyślnego kursu ani kwoty PLN z etykietą EUR.

## Raport i kontrola operatora po deployu

1. Zaloguj się jako Owner/Manager na gpswiss.pl i otwórz
   `/admin/tools/storefront/fr-translations/price-audit?run=1`.
   `?format=json` daje podsumowanie, przykłady i do 50 przykładów Jarka;
   `?format=csv` daje wszystkie produkty w zakresie, także Jarka.
2. Sprawdź 05L131705P:
   `https://gpswiss.fr/produkt/vw-t-roc-2-0tdi-katalizator-dpf-filtr-czastek-stalych-05l131705p-05l131601f`.
   Przy bazie 2200 PLN wynik ma być 2750 / aktualny kurs raportu, zaokrąglony do 2 miejsc EUR.
   Lokalna kwota eBay.de 2200 EUR nie zmienia wyniku. Warning possible_pln_amount_labelled_eur jest heurystyką, nie diagnozą danych.
3. Wybierz zwykły produkt z raportu: porównaj kartę, listing, filtr/sort cenowy, koszyk i podsumowanie checkoutu.
   Dostawa jest osobną pozycją. Nie finalizuj płatności tylko dla kontroli cen.
4. Wybierz aktywnego Jarka: źródło 3000 PLN daje 3750 / aktualny kurs.
   Przy testowym kursie 4.00 wynik to 937.50 EUR +130 EUR dostawy.
   Zweryfikuj tę samą cenę na listingu, karcie produktu, w koszyku i świeżym checkoutcie Stripe oraz brak ekspozycji na gpswiss.pl.
5. Sprawdź produkt PLN na gpswiss.pl i dotychczasowy checkout PayU; cena PL nie ma nowej marży FR.
6. Raport bez cache może pokazać brak ceny, choć runtime potrafi pobrać kurs. Nie uruchamiaj providerów z raportu.
   Warning dużej różnicy od eBay.de nie oznacza, że cena FR jest błędna.

Nie ma migracji ani backfillu cen. Już zapisane zamówienia i sesje Stripe zachowują własny snapshot ceny;
webhook nie przelicza historycznych płatności nową polityką. Do kontroli po wdrożeniu użyj świeżego checkoutu.
Deploy/publikacja i produkcyjne zapisy nie były wykonywane w Codexie.
