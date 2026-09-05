@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page sf-static-page">
    @include('storefront.partials.breadcrumbs')
    <section class="sf-static-card">
        <h1>POLITYKA PRYWATNOŚCI</h1>

        <h2>1. Informacje ogólne</h2>
        <p>Niniejsza Polityka Prywatności określa zasady przetwarzania danych osobowych użytkowników oraz klientów sklepu internetowego dostępnego pod adresem <a href="https://gpswiss.pl">https://gpswiss.pl</a>, zwanego dalej „Sklepem”.</p>
        <p>Administratorem danych osobowych jest:</p>
        <p><strong>GREGOR swiss GRZEGORZ PACIOREK</strong><br>
            ul. Milanowska 137<br>
            08-460 Sobolew<br>
            NIP: 8262157853<br>
            REGON: 368948917<br>
            e-mail: <a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a></p>
        <p>Kontakt w sprawach dotyczących danych osobowych możliwy jest pod adresem e-mail:</p>
        <p><a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a></p>

        <h2>2. Zakres przetwarzanych danych</h2>
        <p>Administrator może przetwarzać dane osobowe podane przez użytkownika lub klienta w związku z korzystaniem ze Sklepu, składaniem zamówień, kontaktem ze Sprzedawcą lub obsługą reklamacji.</p>
        <p>Przetwarzane mogą być w szczególności:</p>
        <ul>
            <li>imię i nazwisko,</li>
            <li>nazwa firmy,</li>
            <li>adres dostawy,</li>
            <li>adres rozliczeniowy,</li>
            <li>adres e-mail,</li>
            <li>numer telefonu,</li>
            <li>NIP, jeżeli Klient żąda wystawienia faktury,</li>
            <li>dane dotyczące zamówienia,</li>
            <li>dane dotyczące płatności,</li>
            <li>dane dotyczące reklamacji, zwrotów lub wymian,</li>
            <li>adres IP oraz dane techniczne związane z korzystaniem ze strony.</li>
        </ul>

        <h2>3. Cele przetwarzania danych</h2>
        <p>Dane osobowe są przetwarzane w celu:</p>
        <ul>
            <li>obsługi zamówień składanych w Sklepie,</li>
            <li>zawarcia i wykonania umowy sprzedaży,</li>
            <li>realizacji płatności,</li>
            <li>realizacji dostawy,</li>
            <li>wystawiania faktur i dokumentów księgowych,</li>
            <li>obsługi zwrotów, wymian, reklamacji i gwarancji,</li>
            <li>prowadzenia korespondencji z Klientem,</li>
            <li>obsługi konta użytkownika, jeżeli zostało utworzone,</li>
            <li>zapewnienia prawidłowego działania Sklepu,</li>
            <li>dochodzenia lub obrony przed roszczeniami,</li>
            <li>realizacji obowiązków prawnych ciążących na Administratorze.</li>
        </ul>

        <h2>4. Podstawy prawne przetwarzania danych</h2>
        <p>Dane osobowe są przetwarzane na podstawie:</p>
        <ul>
            <li>niezbędności do zawarcia lub wykonania umowy,</li>
            <li>obowiązków prawnych ciążących na Administratorze, w szczególności obowiązków podatkowych i księgowych,</li>
            <li>prawnie uzasadnionego interesu Administratora, takiego jak obsługa korespondencji, zabezpieczenie roszczeń i zapewnienie bezpieczeństwa Sklepu,</li>
            <li>zgody użytkownika, jeżeli w danym przypadku jest wymagana.</li>
        </ul>

        <h2>5. Odbiorcy danych</h2>
        <p>Dane osobowe mogą być przekazywane podmiotom współpracującym z Administratorem wyłącznie w zakresie niezbędnym do realizacji usług Sklepu.</p>
        <p>Odbiorcami danych mogą być w szczególności:</p>
        <ul>
            <li>firmy kurierskie i operatorzy logistyczni,</li>
            <li>operatorzy płatności, w tym PayU,</li>
            <li>dostawcy usług hostingowych i informatycznych,</li>
            <li>biuro rachunkowe lub podmioty obsługujące księgowość,</li>
            <li>dostawcy narzędzi służących do obsługi Sklepu,</li>
            <li>organy publiczne, jeżeli obowiązek udostępnienia danych wynika z przepisów prawa.</li>
        </ul>

        <h2>6. Okres przechowywania danych</h2>
        <p>Dane osobowe są przechowywane przez okres niezbędny do realizacji celu, dla którego zostały zebrane.</p>
        <p>Dane związane z realizacją zamówień są przechowywane przez czas potrzebny do wykonania umowy, obsługi reklamacji, zwrotów i wymian, a następnie przez okres wymagany przepisami prawa lub niezbędny do dochodzenia albo obrony przed roszczeniami.</p>
        <p>Dane zawarte w dokumentach księgowych są przechowywane przez okres wymagany przepisami podatkowymi i rachunkowymi.</p>
        <p>Dane przetwarzane na podstawie zgody są przechowywane do czasu wycofania zgody, chyba że istnieje inna podstawa prawna dalszego przetwarzania.</p>

        <h2>7. Prawa osób, których dane dotyczą</h2>
        <p>Osobie, której dane dotyczą, przysługuje prawo do:</p>
        <ul>
            <li>dostępu do swoich danych,</li>
            <li>sprostowania danych,</li>
            <li>usunięcia danych,</li>
            <li>ograniczenia przetwarzania,</li>
            <li>przenoszenia danych,</li>
            <li>wniesienia sprzeciwu wobec przetwarzania,</li>
            <li>cofnięcia zgody, jeżeli dane są przetwarzane na podstawie zgody.</li>
        </ul>
        <p>W celu realizacji swoich praw można skontaktować się z Administratorem pod adresem:</p>
        <p><a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a></p>
        <p>Osobie, której dane dotyczą, przysługuje również prawo wniesienia skargi do Prezesa Urzędu Ochrony Danych Osobowych.</p>

        <h2>8. Dobrowolność podania danych</h2>
        <p>Podanie danych osobowych jest dobrowolne, ale niezbędne do złożenia i realizacji zamówienia, wystawienia dokumentu sprzedaży, dostawy Produktu, obsługi reklamacji, zwrotu lub kontaktu ze Sklepem.</p>
        <p>Brak podania wymaganych danych może uniemożliwić realizację zamówienia lub obsługę zgłoszenia.</p>

        <h2>9. Pliki cookies</h2>
        <p>Sklep może korzystać z plików cookies oraz podobnych technologii.</p>
        <p>Pliki cookies mogą być wykorzystywane w celu:</p>
        <ul>
            <li>zapewnienia prawidłowego działania strony,</li>
            <li>obsługi koszyka i procesu składania zamówienia,</li>
            <li>utrzymania sesji użytkownika,</li>
            <li>zapamiętywania wybranych ustawień,</li>
            <li>prowadzenia podstawowych statystyk,</li>
            <li>poprawy bezpieczeństwa i wygody korzystania ze Sklepu.</li>
        </ul>
        <p>Użytkownik może zarządzać plikami cookies za pomocą ustawień swojej przeglądarki internetowej.</p>
        <p>Ograniczenie stosowania plików cookies może wpłynąć na niektóre funkcje Sklepu.</p>

        <h2>10. Płatności online</h2>
        <p>W przypadku wyboru płatności online dane niezbędne do realizacji płatności mogą być przekazywane operatorowi płatności.</p>
        <p>Sklep korzysta z płatności PayU, jeżeli taka forma płatności jest dostępna podczas składania zamówienia.</p>
        <p>Operator płatności przetwarza dane zgodnie z własnymi zasadami i regulaminami.</p>

        <h2>11. Dostawa</h2>
        <p>W celu realizacji dostawy dane Klienta mogą zostać przekazane firmie kurierskiej lub innemu operatorowi dostawy.</p>
        <p>Przekazywane dane mogą obejmować w szczególności:</p>
        <ul>
            <li>imię i nazwisko lub nazwę firmy,</li>
            <li>adres dostawy,</li>
            <li>numer telefonu,</li>
            <li>adres e-mail,</li>
            <li>informacje niezbędne do doręczenia przesyłki.</li>
        </ul>

        <h2>12. Bezpieczeństwo danych</h2>
        <p>Administrator stosuje odpowiednie środki techniczne i organizacyjne mające na celu ochronę danych osobowych przed nieuprawnionym dostępem, utratą, zniszczeniem lub nieuprawnioną zmianą.</p>
        <p>Dostęp do danych mają wyłącznie osoby i podmioty, dla których jest to niezbędne do realizacji usług Sklepu lub obowiązków Administratora.</p>

        <h2>13. Zmiany Polityki Prywatności</h2>
        <p>Polityka Prywatności może zostać zmieniona w przypadku zmiany działania Sklepu, zmiany wykorzystywanych narzędzi, zmiany przepisów prawa lub zmiany sposobu przetwarzania danych.</p>
        <p>Aktualna wersja Polityki Prywatności jest dostępna na stronie:</p>
        <p><a href="{{ route('storefront.privacy-policy') }}">https://gpswiss.pl/polityka-prywatnosci</a></p>
    </section>
</div>
@endsection
