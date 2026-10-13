# GPSwiss FR — Etap 2: draft EUR i adresy, bez sprzedaży

## Audyt źródła dostaw

Lokalne przypisania to marketplace_category_mappings: local_category_id,
channel=ebay_fr, shipping_group, fulfillment_policy_id, is_blocked. Repo nie
zawiera produkcyjnego eksportu aktualnego pokrycia kategorii; nie odczytywaliśmy
produkcyjnej bazy ani eBay API. Poniższe kwoty wynikają ze źródeł w kodzie,
nie są potwierdzeniem bieżącej zawartości polityk na zdalnym eBay.

EbayShippingPolicyResolutionService::availablePolicyMapping('ebay_fr') definiuje:

| Grupa | Policy ID | EUR |
| --- | --- | --- |
| fr_55_eur | 260547694013 | 55 |
| fr_70_eur | 260547464013 | 70 |
| fr_130_eur | 260547754013 | 130 |

EbayCategoryShippingPolicyCsvImportService importuje legacy CSV
storage/app/imports/RAFEL WEB DESIGNER (1).csv: oznaczenia 30/50/130 mapuje dla FR
na 55/70/130, a nie na 30/50/130. Kod nie potwierdza wspomnianej stawki 40 EUR.
Importer nie jest wykonywany w checkoutcie. Resolver odczytuje bez API lokalną
kategorię, fallback tego samego external_category_id, następnie przodków
(ebay_fr/ebay). Obecny resolver/importer i stawki pozostają niezmienione.

FrenchDeliveryQuoteService jest read-only adapterem tego resolvera. Kwotę
wyprowadza z grupy należącej do jego znanej mapy FR i sprawdza dokładną zgodność
policy ID. Blokada kategorii/fallbacku, brak przypisania, sprzeczna polityka lub
niejednoznaczne stawki fallbacku oznaczają niedostępny koszt, nigdy zero.
Snapshot pozycji zawiera ID/grupę/źródło wyceny dla późniejszej weryfikacji.

Mapowania eBay opisują politykę pojedynczej oferty. Repo nie daje zatwierdzonej
reguły sumowania/max ani additional-unit costs dla koszyka sklepu. Dlatego
wycena całej dostawy jest dostępna tylko dla jednego produktu w ilości 1.
Dla wielu pozycji/sztuk pokazujemy controlled unavailable do zatwierdzenia
reguły agregacji. Nie wymyślamy kosztu zbiorczego.

## Audyt cen

CartService używa parts.price i parts.currency, z fallbackiem PLN.
EbayListingDryRunService używa parts.ebay_price (PLN) lub parts.price * 1.25,
następnie dzieli przez eurPln z NbpExchangeRateService. To estymacja ceny eBay,
nie zatwierdzony cennik GPSwiss.fr. Nie wywołujemy tego serwisu ani NBP.

config/storefront-fr-checkout.php ma pricing_policy=null: domyślnie brak
zatwierdzonego cennika EUR. Nie przepisujemy liczb PLN jako EUR. Jest przygotowany
wariant catalog_eur, który może używać wyłącznie bieżących parts.price już w EUR
po jawnej decyzji. Nie należy zmieniać waluty wspólnych produktów, aby aktywować
FR, bo wpłynęłoby to na PL. Dla obecnego katalogu PLN trzeba wybrać osobny cennik
EUR, zatwierdzone ceny eBay FR lub jawny przelicznik. Ten PR niczego nie wybiera.

## Zachowanie panelu i checkoutu

GET /panier i /commande na FR pokazują własne widoki i draft: gpswiss_fr, fr,
EUR, docelowy provider stripe, snapshot nazwy FR (fallback źródłowy), SKU,
part_id, numer części, ilość, cena/waluta, link oraz metadane dostawy.
Unknown prices/totals są null, a nie 0. Ceny pobieramy z aktualnego rekordu,
nie z POST ani starej ceny snapshotu sesji. Snapshot ma też standardowe pola
order_attributes/order_items pod przyszły zapis zamówienia.

Formularz adresów używa FR, numeru i ulicy w jednej linii, opcjonalnego
uzupełnienia adresu i pięciocyfrowego kodu pocztowego. Firma wymaga nazwy,
SIRET (14 cyfr) i numer TVA są opcjonalne. Brak wymaganego polskiego NIP.
Dostępna do wyceny jest wyłącznie dostawa kurierska; brak pobrania/odbioru.
POST /commande/apercu z CSRF waliduje dane i zachowuje je tylko w sesji.
Nie ma zamówienia w DB, rezerwacji ani płatności. Dostęp ograniczony domenami FR.

can_place_order pozostaje zawsze false, nawet gdy ceny i dostawa są kompletne.
POST /commande pozostaje zablokowany przed odczytem koszyka i wszystkimi
zapisami/providerami. Model Order rozróżnia storefronty; przyszłe FR rekordy
z odpowiednim meta mają etykietę GPSwiss.fr w tabeli i szczegółach admina.
Nie tworzymy takich rekordów na produkcji ani nie robimy backfillu.

## Wdrożenie i Etap 3

Standardowy deploy kodu, bez migracji/.env. Zachowaj cache i aktywne checkpointy
tłumaczeń. PL pozostaje na dotychczasowych widokach, cenach i PayU. Nie zmieniamy
runnera tłumaczeń ani żadnego kodu marketplace/integracji/stocku.

Przed Stripe: zatwierdź finalne źródło cen EUR, regułę wielopozycyjnej dostawy
oraz zgodność lokalnych mapowań ze stawkami biznesowymi. Etap 3 musi ponownie
zweryfikować ceny, dostępność, adresy i koszt na serwerze, atomowo zapisać
prawidłowe order_attributes/order_items, dodać idempotentne płatności Stripe,
podpisany webhook i bezpieczny powrót. Podgląd nie jest writerem zamówień i nie
może zostać bezpośrednio zapisany, gdy zawiera null ceny/koszty.
