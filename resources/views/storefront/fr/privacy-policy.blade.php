@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page sf-static-page">
    @include('storefront.partials.breadcrumbs')
    <section class="sf-static-card">
        <h1>POLITIQUE DE CONFIDENTIALITÉ</h1>

        <h2>1. Responsable du traitement</h2>
        <p>Cette politique concerne les utilisateurs de <a href="https://gpswiss.fr">https://gpswiss.fr</a>. Le responsable du traitement est :</p>
        <p><strong>GREGOR swiss GRZEGORZ PACIOREK</strong><br>
            ul. Milanowska 137<br>
            08-460 Sobolew, Pologne<br>
            Identifiant fiscal polonais (NIP) : 8262157853<br>
            REGON : 368948917<br>
            Contact : <a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a></p>

        <h2>2. Données traitées</h2>
        <p>Nous pouvons traiter les coordonnées du client, ses adresses de facturation et de livraison, les informations de son entreprise, les données de commande et de paiement, sa correspondance, les informations relatives aux retours et réclamations, ainsi que l’adresse IP et les données techniques nécessaires au fonctionnement et à la sécurité du site.</p>

        <h2>3. Finalités et bases juridiques</h2>
        <p>Les données servent à préparer et exécuter les commandes, traiter les paiements et livraisons, gérer les comptes, répondre aux demandes et assurer le service après-vente. Ces traitements reposent sur les mesures précontractuelles et l’exécution du contrat.</p>
        <p>Les obligations fiscales et comptables reposent sur les obligations légales. La sécurité du service, la gestion de la correspondance et la défense des droits reposent sur les intérêts légitimes du responsable. Lorsque le consentement est requis, le traitement repose sur ce consentement, qui peut être retiré.</p>

        <h2>4. Destinataires</h2>
        <p>Les données nécessaires peuvent être transmises aux transporteurs, prestataires de paiement, dont PayU lorsqu’il est utilisé, prestataires d’hébergement et d’informatique, services comptables et autorités habilitées. Les prestataires de paiement appliquent également leurs propres règles de confidentialité.</p>

        <h2>5. Conservation</h2>
        <p>Les données sont conservées pendant la durée nécessaire à l’exécution du contrat et au traitement du service après-vente, puis pendant les durées imposées par les obligations légales ou nécessaires à la défense des droits. Les données traitées sur la base du consentement sont conservées jusqu’à son retrait, sauf autre fondement juridique applicable.</p>

        <h2>6. Vos droits</h2>
        <p>Dans les conditions prévues par le RGPD, vous disposez de droits d’accès, de rectification, d’effacement, de limitation, de portabilité et d’opposition. Vous pouvez retirer votre consentement sans remettre en cause la licéité du traitement antérieur.</p>
        <p>Pour exercer vos droits, contactez <a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a>. Vous pouvez déposer une réclamation auprès d’une autorité de contrôle compétente, notamment la CNIL en France ou l’autorité polonaise UODO.</p>

        <h2>7. Fourniture des données</h2>
        <p>La fourniture des données est volontaire, mais les données nécessaires à une commande, une livraison, une facture ou une demande de service doivent être communiquées pour que celle-ci puisse être traitée.</p>

        <h2>8. Cookies</h2>
        <p>Le site utilise des cookies pour gérer la session, le panier et les préférences de langue. Vous pouvez gérer les cookies dans votre navigateur ; leur blocage peut affecter certaines fonctions du site. Les cookies nécessitant un consentement sont soumis aux règles applicables.</p>

        <h2>9. Sécurité et mises à jour</h2>
        <p>Le responsable applique des mesures techniques et organisationnelles destinées à protéger les données contre les accès non autorisés, la perte et l’altération. Cette politique peut être mise à jour pour refléter l’évolution du service ou des règles applicables.</p>
        <p>La version actuelle est disponible sur la <a href="{{ route('storefront.privacy-policy') }}">page de confidentialité de gpswiss.fr</a>. Consultez également les <a href="{{ route('storefront.terms') }}">conditions générales de vente</a>.</p>
    </section>
</div>
@endsection
