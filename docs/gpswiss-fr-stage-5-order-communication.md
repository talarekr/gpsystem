# GPSwiss.fr — Etap 5: maile i historia zamówień

## Audyt przed zmianą

- `EmailTemplate` i `EmailTemplateType` opisują polskie szablony w panelu, ale nie mają wykonawcy wysyłki w checkoutach ani webhookach. Ten etap nie zmienia tych szablonów ani ich aktywności.
- Checkout PL/PayU nie wysyłał maili zamówień; jego zachowanie pozostaje takie samo. Istnieją oddzielne wysyłki formularza kontaktowego, intake warsztatowego i mechanizm resetu hasła.
- `CustomerAccountController` na PL pobiera `ShopEvent` z dopasowaniem e-maila. Takie zdarzenia nie stanowią historii rzeczywistych `Order` z checkoutu FR.
- Rejestracja nie wymaga weryfikacji właściciela e-maila. Nie można bezpiecznie udostępniać zamówień gościnnych osobie, która założyła konto z pasującym adresem.
- Istniejące strony thank-you/Stripe return mają signed access. Return nie potwierdza płatności; tylko zweryfikowany webhook zmienia jej stan. Etykiety `Order::statusOptions()` są polskie, więc FR wymaga osobnej prezentacji.

## Które maile i kiedy

| Wiadomość | Wyzwalacz | Czego nie oznacza |
| --- | --- | --- |
| Commande reçue — paiement en attente | Poprawnie utworzona/zapisana Stripe Checkout Session dla istniejącego Order FR | Nie potwierdza opłacenia |
| Confirmation de paiement | Zatwierdzony webhook `checkout.session.completed`, po weryfikacji kwoty, waluty, tożsamości, podpisu i zapisie paid | Powrót klienta ani sama sesja nie wywołują tego maila |
| Session de paiement expirée | Zatwierdzony webhook `checkout.session.expired`, który zmienia pending/new na failed/cancelled | Zamknięcie przeglądarki lub przycisk anulowania Stripe nie oznacza anulowania zamówienia |

W test mode mail paid jasno wskazuje płatność testową bez wysyłki towaru. Wygaśnięcie dostarczone po paid nie wysyła anulowania. Kolejny event dla tej samej płatności również nie powtarza maila paid.

Nadawca i reply-to: `contact@gpswiss.fr`, nazwa `GREGOR SWISS FR`. W treści dane z konfiguracji Etapu 4 oraz utrwalone snapshoty pozycji Order (nazwa FR, SKU, ilość, cena, waluta), koszt dostawy i total. PL, PayU, stock, ceny, marketplace i runner tłumaczeń nie są zmieniane.

Nie dodajemy automatycznych maili przy ręcznych zmianach statusów: brak istniejącego, jednoznacznego mechanizmu zdarzeń sklepu FR. Klient widzi aktualny status przez link/konto.

## Link i historia — obsługa bez shella operatora

Mail zawiera HTTPS signed URL na stałej domenie `gpswiss.fr`, `/commande/suivi/{order}`, ważny 30 dni. Działa bez logowania, ale sam numeric ID, zmieniony lub wygasły podpis nie udostępnia danych. Strona ma private/no-store, noindex/noarchive i no-referrer. Link jest osobistym sekretem; nie wolno go publikować ani przesyłać innym osobom. Po wygaśnięciu klient kontaktuje się z obsługą, zamiast otrzymywać publiczny dostęp po ID.

Podgląd pokazuje francuskie etykiety statusu zamówienia/płatności, pozycje, kwoty i kontakt. Pending/new ma odnośnik do istniejącego signed Stripe return, gdzie można wznowić tę samą sesję, jeśli nadal jest otwarta. Nie tworzy nowej płatności. Dotychczasowy signed thank-you dla Order FR również pokazuje widok FR.

`https://gpswiss.fr/moje-konto` pokazuje paginowaną historię rzeczywistych Order FR przypisanych przez `customer_id` podczas checkoutu zalogowanego klienta. Nie pokazuje Order PL, ShopEvent, cudzych ani gościnnych zamówień tylko dlatego, że e-mail pasuje. Nowe konto nie przejmuje zamówień gościnnych. Zmiana e-maila nie pozwala przejąć cudzej historii.

Konto gościa korzysta z bezpiecznego linku mailowego. Na FR zwroty/reklamacje są kierowane do `contact@gpswiss.fr`; stary formularz zapisujący zwrot dla ShopEvent jest zablokowany na FR, także przy bezpośrednim POST. PL zachowuje dotychczasowe konto i formularz.

## Deploy i transport poczty

Standardowy deploy musi wykonać migrację `2026_10_13_000003_create_storefront_fr_order_notifications_table.php`. Operator nie potrzebuje shella ani workera. Nie czyścić checkpointów/cache tłumaczeń i nie zmieniać APP_KEY (unieważni podpisane linki).

Wysyłka jest synchroniczna po commit, przez istniejący transport Laravel `mail.default` / `MAIL_MAILER`. Nie zmieniamy globalnego nadawcy PL; FR ustawia nadawcę i reply-to tylko w swoim Mailable. Transport musi dopuszczać nadawcę `contact@gpswiss.fr` (zweryfikowana domena/nadawca, odpowiednie SPF/DKIM u dostawcy). `.env.example` ma `MAIL_MAILER=log`; log/array nie dostarczają maili do klienta. Ten PR nie instaluje serwera SMTP ani nie zmienia `.env`.

Przed realną sprzedażą sprawdź konfigurację poczty przez swoją istniejącą ścieżkę konfiguracji/deployu. Jeśli działa tylko log, mail nie dotrze mimo wykonania kodu. `sent` w rejestrze oznacza przekazanie do skonfigurowanego transportu, nie gwarancję dostarczenia do skrzynki. Monitorowanie odbić/dostarczalności leży po stronie dostawcy poczty.

Po wdrożeniu, dopiero w uzgodnionym teście Stripe test: sprawdź jeden awaiting po rozpoczęciu, brak paid po samym return, jeden paid po webhooku oraz brak powtórki po resend eventu z Dashboard. Sprawdź dane FR i dostęp signed linku w prywatnym oknie bez logowania. Ten etap nie wysyłał prawdziwych maili ani nie wykonywał produkcyjnych płatności.

## Idempotencja i niepewna dostawa

Tabela `storefront_fr_order_notifications` ma unikalne `(order_id, kind)`, niezależnie od event_id. Rejestr paid/expired powstaje w transakcji webhooka; wysyłka dopiero po commit. Pending jest przejmowane atomowo pod lockiem zamówienia/wiadomości, potem SMTP działa poza transakcją. Powtórzone/równoległe dostarczenia nie przejmują `sending` ani `sent`.

Pending po przerwaniu requestu przed claim może zostać wysłane przy ponowieniu odpowiedniego webhooka lub ponowieniu tej samej próby checkoutu. Awaiting, które stało się nieaktualne przed wysyłką (np. płatność już paid), jest `suppressed`.

Nie ma gwarancji exactly-once między bazą i SMTP: request może umrzeć po wysyłce, ale przed zapisem sent. Dlatego stare `sending` i `uncertain` nie są automatycznie ponawiane. Exception zapisuje `uncertain` i komunikat w logu z ID zamówienia/wiadomości bez treści i e-maila klienta; płatność pozostaje paid. Przed ręcznym kontaktem sprawdź dostarczenie w panelu dostawcy poczty, aby nie powtórzyć zaakceptowanego maila. Bez potwierdzenia transportu nie resetować wpisów ani ponawiać Stripe w celu wymuszenia maila. Ten PR nie dodaje narzędzia administracyjnego do ponownych wysyłek.

## Etap 6 — SEO i final audit

- Zweryfikować domenę/SSL/www, canonical/hreflang, sitemap/robots i wykluczenie prywatnych stron zamówienia/konta.
- Przejść katalog → koszyk → checkout test → webhook → mail → signed link → konto oraz izolację PL.
- Zakończyć tłumaczenia i sprawdzić fallbacki/teksty FR.
- Uzupełnić mediatora, hosting oraz faktyczny wykaz cookies/dostawców; sprawdzić zgodność ze skonfigurowanymi usługami.
- Potwierdzić SMTP i dostarczalność FR oraz dane sprzedawcy/konta Stripe, źródła EUR/dostawy.
- Rozważyć osobny etap weryfikacji e-maila/przypinania zamówień gościnnych, resetów haseł po francusku, FR returns, status notifications i narzędzia obsługi niepewnych maili. Nie są warunkiem bezpiecznego odczytu gościnnego przez signed URL w tym PR.
