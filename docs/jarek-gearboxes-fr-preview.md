# Skrzynie Jarka — preview gpswiss.fr

## Problem i zakres PR

`jarek_gearboxes` jest osobnym katalogiem. FR katalog/koszyk/checkout używają `Part`, a `FrenchPriceResolver` przelicza PLN bez narzutu Jarka. Wspólny `Part::scopeStorefrontVisible()` nie ogranicza do hosta FR i nie sprawdza `is_visible_storefront`. `StripeFrWebhook` zmniejsza `parts.quantity` po płatności live. Zwykły import i ustawienie `ready` nie realizują osobnej ekspozycji FR, ceny +25% i niezależnego stocku.

Ten PR dodaje wyłącznie inspekcję. Nie tworzy/modyfikuje Part, JarekGearbox, cen, stocku, mapowań, listingów lub zamówień. Nie wywołuje NBP, eBay, Allegro, Ovoko, Google, tłumaczeń ani płatności. Nie zawiera apply, migracji, zmian checkoutu/webhooka, publikacji lub deployu.

## Dostęp i użycie

GET `/admin/tools/jarek-gearboxes/fr-storefront-preview`

Wymagana istniejąca sesja i rola `owner_admin`. Inne role panelu, w tym viewer/manager, nie otrzymują dostępu. Endpoint ma prywatne no-store/noindex, nie używa limitera zapisującego cache. Dla tego endpointu zwykłe StartSession zastępuje odczyt sesji bez zapisu, garbage collection i cache locks; pozostałe trasy zachowują zwykłe sesje. Samo remember-me nie wystarcza: odtwarzanie logowania mogłoby migrować/zapisywać sesję, więc narzędzie wymaga aktywnej sesji po zalogowaniu.

- HTML: podsumowanie całego katalogu oraz próbka (domyślnie 50).
- JSON: `?format=json` albo Accept: application/json.
- Pobranie JSON próbki: `?download=1`.
- Kolejne próbki: `?limit=200&offset=200` (limit 1–200).
- Eksport obejmuje tylko wskazaną próbkę; podsumowanie obejmuje cały katalog. To żywy odczyt, bez zapisanego snapshotu.

Produkcyjną liczbę i kompletność ustali administrator po udostępnieniu narzędzia w osobnym etapie. Nie są wnioskowane z fixture testowych.

## Cena i cache NBP

Podstawa: `jarek_gearboxes.price`, czyli cena źródłowej oferty Allegro, nie potwierdzona cena zakupu/netto. Dla PLN:

```
PLN po narzucie = round(cena bazowa * 1.25, 2, HALF_UP)
EUR = round(PLN po narzucie / kurs EURPLN NBP, 2, HALF_UP)
```

Nie zapisujemy tego do `parts.price`, `ebay_price` ani snapshotu. Nie-PLN jest blockerem; nie stosujemy ogólnego fallbacku ani ręcznego kursu.

Nowy odczyt w istniejącym `NbpExchangeRateService` sprawdza tylko cache `nbp_table_a_rate:EUR:latest`, potem `nbp_table_a_eur_rate`. Wymaga ok=true, EUR, NBP_TABLE_A, dodatniego skończonego kursu i poprawnej daty z ostatnich 7 dni (bez daty przyszłej). Brak/stary/nieprawidłowy cache blokuje obliczenie. Narzędzie nigdy nie uzupełnia cache; nie uruchamiać starego prepare-preview w celu obejścia tego ograniczenia.

Odczyt database/file cache omija Laravel get(), które usuwa wygasłe wpisy. Redis wykonuje tylko GET; array store służy procesowi/testom. Nieobsługiwany store zwraca brak kursu.

Historyczna cena eBay DE jest pokazywana, jeśli istnieje `_jarek_price_fetch` w snapshot albo jednoznacznie rozpoznany lokalny listing DE. Zawiera źródło/datę i `is_current_verified=false`. Nie jest to bieżący odczyt eBay ani pełna historia zmian. Brak ceny historycznej nie zastępuje ceny bazowej i nie blokuje obliczenia FR.

## Kategoria, zdjęcia i dostawa

Kategoria: istniejący odpowiadający Part, następnie jednoznaczne mapowanie Allegro kategorii Jarka, następnie dokładna nazwa lokalnej kategorii. Niejasne/brakujące lub zablokowane mapowanie to blocker. Nie tworzymy kategorii.

Dostawa używa obecnego `FrenchDeliveryQuoteService` z niesaved Part jako wejściem typowanym. Wynik pokazuje kwotę, shipping_group, fulfillment_policy_id, mapping_id, fallback_mapping_id i źródło/reason. Akceptowane są wyłącznie rozpoznane mapowania FR 55/70/130 EUR; nie ma domyślnego wyboru 130 EUR. Brak rozpoznanej dostawy to blocker.

Zdjęcia liczone z lokalnych plików lub zapisanych URL. Brak zdjęć blokuje; obecność URL nie potwierdza zdalnej dostępności. Żadnych HTTP HEAD/GET zdjęć.

## Stock i eligible/blocked

Decyzja biznesowa: wszystkie rekordy źródłowe są rozważane niezależnie od quantity/statusu Allegro; qty FR=1. Source quantity jest tylko informacją. Sprzedaż FR ma nie zmniejszać Jarka ani stocku marketplace; nie robimy sync.

`data_ready=true` oznacza kompletność danych sprawdzanych przez preview (w tym cena, cache, opis, zdjęcia, kategoria, dostawa i rozpoznane duplikaty).

`eligible` oznacza brak wszystkich blockerów i bezpieczeństwo kolejnego apply w obecnym kodzie. Obecnie wszystkie rekordy mają trzy blokery architektury:

- `pl_visibility_risk`: po zwykłym przygotowaniu Part do sprzedaży wspólny katalog może pokazać go też na PL. Flaga `is_visible_storefront=false` tego nie gwarantuje. Preview pokazuje także aktualnie widoczne ID istniejących Part.
- `independent_fr_stock_required`: webhook Stripe i koszyk operują na stocku Part; nie można użyć źródłowego Part jako stocku ekspozycji FR.
- `jarek_fr_markup_resolver_required`: checkout FR nie ma specjalnej polityki ceny Jarka +25%.

Dlatego `eligible_for_fr_count=0` jest oczekiwane nawet przy `data_ready_count>0`. `blocked` nie oznacza niedostępności u Jarka; oznacza brak gotowości technicznej.

## Propozycja następnego etapu (bez implementacji/apply)

1. Odczytać preview produkcyjne i zatwierdzić listę źródeł oraz kategorię/dostawę. Sprawdzić zdjęcia, podstawę podatkową i warunki zwrotu starej skrzyni (kod DE wymaga takiego komunikatu).
2. Zaprojektować osobny rekord ekspozycji per storefront (np. unikalny `storefront_code + source_type + source_id`), z `gpswiss_fr` i qty=1. Nie ustawiać wspólnego źródłowego Part na ready. Wszystkie publiczne ścieżki: katalog, wyszukiwanie, produkt, sitemap, koszyk i checkout muszą stosować ograniczenie storefront. Sam filtr listy nie wystarcza.
3. Dodać dedykowaną politykę ceny ekspozycji Jarka w FR: bez zapisu narzutu do ceny bazowej, używaną identycznie w prezentacji i przy ponownym sprawdzeniu ceny przed Stripe.
4. Stock/rezerwacja/płatność mają działać na rekordzie ekspozycji FR. Żadnego zmniejszania źródłowego Part/JarekGearbox, wywoływania sold()/sync ani providerów marketplace. Po płatności niezależny stan ekspozycji może przejść do sprzedanej; zasada ponownego udostępnienia wymaga ustalenia w następnym etapie. Nie zakładać automatycznego resetu qty.
5. Testy FR-only, bezpośrednich URL PL, pricing, rezerwacji, idempotencji webhooka i równoczesnych zakupów. Nowy dry-run z konkretną listą zmian i snapshotem do zatwierdzenia.
6. Dopiero osobna zgoda na apply/import/publikację FR. Ten PR nie daje takiej możliwości.

Zwykły import istniejący w module nie przenosi pełnych powiązań eBay do Part. Sprawdzamy istniejące Part przez SKU, source_system/external_id i legacy_payload; wiele dopasowań blokuje. To nie gwarantuje wykrycia wszystkich dawnych importów z dowolnie zmienionymi identyfikatorami.

## Walidacja

Test `JarekGearboxFrStorefrontPreviewTest` obejmuje autoryzację, +25%/EUR z istniejącego cache, brak zapisów SQL/modeli i HTTP, qty=1 mimo zerowego stocku, dostawę FR, historyczny cache DE, brakujące dane, duplikaty, niejednoznaczną kategorię, ryzyko PL/stock, HTML/JSON i paginację. Dodatkowo prawdziwy odczyt sesji database, wymuszona lottery GC i wygasły cache database/file muszą pozostawić dane bez zmian.

Weryfikacja lokalna: PHP 8.4.26, Laravel 11.57.0, Filament 3.3.54, SQLite in-memory. 53 testy nowego preview i regresji FR przeszły (617 asercji), następnie 8 testów modułu Jarka (93 asercje). Istniejący `test_resume_route_requires_csrf` nie przechodzi także z oryginalnym bootstrap/main: oczekuje 419, otrzymuje 401. Nie zmieniamy jego endpointu ani tego zachowania.
