# GPSwiss FR — koszyk i checkout: trasy oraz rewrite

## Diagnoza

Przed poprawką routes/web.php definiował tylko /koszyk oraz /zamowienie.
/panier i /commande nie były trasami aplikacji. Header i koszyk używały nazwanych
tras PL, więc generowały na FR /koszyk i /zamowienie. Guard Etapu 1 działał na
/zamowienie i nie był przyczyną braku tras francuskich.

Repo nie zawiera public/.htaccess; dokumentacja shared hosting wskazuje lokalny
public_html/.htaccess, a deploy.example.php celowo zachowuje ten plik i bridge
index.php. Działający home nie dowodzi poprawnego rewrite podstron. Próba
odczytu produkcyjnych adresów narzędziem WWW nie powiodła się, więc konfiguracja
serwera nie została potwierdzona. Nie zmieniamy istniejących reguł serwerowych.

## Po deployu

- https://gpswiss.fr/panier otwiera normalny koszyk.
- https://gpswiss.fr/commande pokazuje francuską kontrolowaną blokadę płatności.
- POST /commande zachowuje guard Etapu 1: bez PayU, zapisów zamówień i stocku.
- Header FR, przycisk checkout oraz powrót z blokady prowadzą na /panier i
  /commande. Akcje koszyka nadal używają istniejących endpointów POST /koszyk/*,
  ale ich przekierowania FR prowadzą na /panier.
- Stare /koszyk i /zamowienie na FR pozostają kompatybilne. Brak zmian PL:
  /koszyk, /zamowienie i dotychczasowe płatności zachowują swoje trasy.
- Francuskie aliasy są ograniczone do gpswiss.fr i www.gpswiss.fr. GET/HEAD www
  przekierowuje 301 do https://gpswiss.fr z zachowaniem ścieżki/query. POST www
  checkout jest blokowany bez replayu do providera.

## Weryfikacja operatorska bez uruchamiania płatności

Po standardowym deployu kodu z odświeżeniem kompilowanego route cache sprawdź
GET /panier, /commande, /koszyk i /zamowienie na gpswiss.fr. Nie czyść application
cache/checkpointów tłumaczeń. Nowa migracja ani .env nie są wymagane przez ten PR.

Jeśli po deployu /panier i /commande nadal nie dochodzą do Laravel, a home
jest dostępny, sprawdź serwerowy rewrite/bridge oraz publikację właściwego
release. Jeśli nawet istniejące /koszyk, /zamowienie i /regulamin zwracają
serwerowe 404, wskazuje to na problem kierowania podstron do index.php, którego
same aliasy Laravel nie rozwiążą. Rozróżnij odpowiedź serwera od strony błędu
aplikacji. Ten PR nie potwierdza i nie naprawia DNS/TLS lub konfiguracji Apache.
