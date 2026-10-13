# GPSwiss.fr — spójny koszyk i checkout

FR używał prostych widoków draftu z okresu blokady płatności. Nie korzystały one z istniejących klas layoutu PL, dlatego pola i podsumowanie wyglądały jak formularz techniczny. Poprawka zmienia prezentację, nie wycenę ani flow rozliczeń.

## Oczekiwany wygląd

Desktop: nagłówek i przycisk powrotu, dwie kolumny. Koszyk po lewej ma karty ze zdjęciem (lub placeholderem), nazwą, numerem części/SKU, ilością, ceną jednostkową EUR, sumą i usuwaniem. Pod listą aktualizacja koszyka. Po prawej panel Sous-total / Livraison / Total i przycisk przejścia dalej. Mobilnie panel jest pod listą; użyte te same responsive reguły PL.

Checkout: po lewej białe, zaokrąglone sekcje Informations de facturation, Adresse de livraison, Méthode de livraison, Paiement i Acceptations. Radio wyboru klienta/alternatywnego adresu, pola w siatce, kraj France, opcjonalny SIRET/TVA wyłącznie w sekcji firmy, bez NIP. Po prawej Résumé z pozycją i wartościami EUR oraz Payer par carte. Zastosowane klasy sf-checkout-layout, sf-checkout-grid, sf-field, sf-checkout-card, sf-checkout-options, sf-checkout-terms i sf-checkout-summary. Wspólny CSS PL pozostaje; nowe reguły mają wyłącznie scope sf-fr-cart / sf-fr-checkout i zawijają długie komunikaty.

Kwoty pochodzą z tego samego draftu FrenchCheckoutService: EUR produktu, lokalne eBay FR EUR lub zatwierdzone NBP z #1482, bez zmiany source prices. Dostawa z istniejącego resolvera 55/70/130 EUR jest wyświetlana konkretnie w koszyku i checkout, nie jako „calculés au panier”. Brak wyceny pokazuje Livraison indisponible pour cet article i blokuje przejście/płatność. Jedna pozycja, jedna sztuka; ilość w formularzu ma max=1, a serwer zachowuje dotychczasowe zabezpieczenia.

## Zachowane flow

1. Klient sprawdza koszyk i przechodzi do checkout tylko z poprawną wyceną jednej sztuki.
2. Wypełnia adres i klika Enregistrer mes coordonnées. Pozostawiono istniejący endpoint zapisu danych w sesji.
3. Po zapisie i spełnieniu dotychczasowych guards pojawia się istniejący formularz Stripe z checkout_key. Klient zaznacza CGV i klika Payer par carte. Checkbox należy do formularza Stripe przez form=fr-stripe-payment; brak zagnieżdżonych formularzy.
4. Zmiana adresu/typu klienta/adresu dostawy wyłącza przycisk płatności do ponownego zapisu, aby nie zapłacić z użyciem poprzednich zapisanych danych. Po odświeżeniu niezapisane edycje nie są traktowane jako zapisane; serwer pozostaje źródłem prawdy.

Nie łączymy adresowego POST i płatności w nowe flow. Nie zmieniamy Stripe session/webhook/settlement, PayU PL, stocku, stawek, marketplace ani runnera. Zachowano wyraźny komunikat Stripe TEST; usunięto niepotrzebne techniczne wyjaśnienia draftu klientowi.

## Po standardowym deployu — przegląd przez operatora

- Otwórz /panier z jednym istniejącym produktem. Sprawdź zdjęcie/placeholder, FR nazwę, SKU, ilość, cenę EUR, konkretną dostawę, total, usuń i aktualizację. Nie zmieniaj stocku/ceny/mapowania, aby wymusić próbę.
- Sprawdź desktop i szerokość telefonu: brak poziomego przepełnienia, podsumowanie po prawej / poniżej, przyciski i pola w stylu PL.
- Na /commande przełącz Particulier/Entreprise i adres identyczny/inny. SIRET/TVA są opcjonalne; France pozostaje krajem FR. Zapisz adres; potem ponownie zmień pole i potwierdź, że płatność wymaga ponownego zapisu.
- Sprawdź powiązanie CGV z formularzem płatności i aktywny przycisk przy poprawnych danych. Realny test Stripe wyłącznie w uzgodnionym TEST po zakończeniu tłumaczeń; nie wykonywano go z Codexa.
- Sprawdź kontrolowaną niedostępność ceny/dostawy, bez przejścia dalej i bez PayU/BLIK/pobrania/odbioru.
- PL /koszyk i /zamowienie zachowują własne widoki, style, dane i provider.

Bez nowych migracji/workerów ani ręcznych komend operatora. Nie czyścić checkpointów/cache i nie tworzyć runu tłumaczeń. Testy lokalne używają Http::preventStrayRequests i Mail::fake. Nie wykonywano produkcyjnych zapisów, API/Google, realnych maili ani deployu. Opis layoutu powyżej jest instrukcją wizualnego sprawdzenia; automatyczne testy sprawdzają klasy, markup, wartości i guards, nie zastępują przeglądu ekranu na docelowym urządzeniu.
