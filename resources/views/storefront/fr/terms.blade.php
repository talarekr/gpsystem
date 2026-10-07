@extends('layouts.storefront')

@section('content')
<div class="sf-container sf-page sf-static-page">
    @include('storefront.partials.breadcrumbs')
    <section class="sf-static-card">
        <h1>CONDITIONS GÉNÉRALES DE VENTE</h1>

        <h2>1. Présentation de la boutique</h2>
        <p>Les présentes conditions concernent la boutique GPSwiss accessible à l’adresse <a href="https://gpswiss.fr">https://gpswiss.fr</a>, qui propose des pièces automobiles et des accessoires.</p>
        <p>Le vendeur est :</p>
        <p><strong>GREGOR swiss GRZEGORZ PACIOREK</strong><br>
            ul. Milanowska 137<br>
            08-460 Sobolew, Pologne<br>
            Identifiant fiscal polonais (NIP) : 8262157853<br>
            REGON : 368948917<br>
            E-mail : <a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a></p>

        <h2>2. Produits</h2>
        <p>Les caractéristiques, l’état, les références et le prix des pièces figurent sur leur fiche. Le client est invité à vérifier la compatibilité de la pièce avec son véhicule, notamment à l’aide du numéro VIN ou de la référence OEM, et à contacter le vendeur en cas de doute.</p>

        <h2>3. Commandes</h2>
        <p>Pour commander, le client sélectionne un produit, l’ajoute au panier, renseigne ses coordonnées, choisit les modes de livraison et de paiement disponibles et confirme sa commande après avoir pris connaissance des présentes conditions.</p>
        <p>Le client doit fournir des informations exactes et actualisées. Les commandes sont traitées dans la limite des produits disponibles.</p>

        <h2>4. Prix et paiement</h2>
        <p>Les prix et leur devise sont indiqués sur les fiches produits et dans le panier. Les prix affichés sont des prix bruts, taxes comprises lorsque celles-ci sont applicables. Les frais de livraison sont indiqués lors de la commande.</p>
        <p>Les moyens de paiement proposés sont ceux affichés au moment de la commande, notamment PayU lorsqu’il est disponible. Le traitement de la commande commence après réception du paiement ou selon les modalités du moyen de paiement sélectionné.</p>

        <h2>5. Livraison</h2>
        <p>La livraison est assurée par les transporteurs ou les autres modes proposés lors de la commande. Les modalités, frais et délais applicables sont précisés lors de la commande ou confirmés par le vendeur.</p>

        <h2>6. Rétractation et retours</h2>
        <p>Le consommateur dispose d’un délai de 14 jours à compter de la réception du produit pour notifier sa décision de se rétracter, sous réserve des exceptions prévues par la loi. Il peut contacter le vendeur à l’adresse <a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a> en indiquant son numéro de commande et les produits concernés.</p>
        <p>Le produit doit être renvoyé dans le délai légal, complet et correctement protégé pour le transport. Le consommateur supporte les frais directs de retour, sauf disposition légale ou accord contraire. Sa responsabilité peut être engagée en cas de dépréciation résultant de manipulations allant au-delà de celles nécessaires pour vérifier le produit.</p>
        <p>Le remboursement intervient selon les délais et modalités légaux. Le vendeur peut le différer jusqu’à la récupération du produit ou la réception d’une preuve de son expédition, selon le premier de ces événements.</p>

        <h2>7. Échanges</h2>
        <p>Un échange peut être demandé dans les 14 jours suivant la réception, sous réserve d’un accord avec le vendeur et de la disponibilité de la pièce de remplacement. Les conditions et frais de l’échange sont convenus avec le vendeur. Cette possibilité ne limite pas les droits légaux du consommateur.</p>

        <h2>8. Réclamations et garanties</h2>
        <p>Les réclamations relatives à un défaut ou à une non-conformité peuvent être adressées à <a href="mailto:biuro@gpswiss.pl">biuro@gpswiss.pl</a>, avec le numéro de commande et une description du problème.</p>
        <p>Les éventuelles garanties commerciales sont indiquées pour chaque produit. Elles s’appliquent sans préjudice des garanties légales et des droits impératifs du consommateur.</p>

        <h2>9. Transport et montage</h2>
        <p>Il est recommandé de vérifier l’état du colis à la réception et de signaler rapidement tout dommage au transporteur et au vendeur. Cette recommandation ne limite pas les droits légaux du client.</p>
        <p>Les pièces sont destinées à être montées par des professionnels. Une mauvaise sélection, un montage incorrect ou une utilisation inadaptée peuvent endommager le produit. Tout retour contre remboursement nécessite l’accord préalable du vendeur.</p>

        <h2>10. Compte client</h2>
        <p>Lorsqu’il est disponible, le compte client permet de consulter les commandes et les informations personnelles. Le client doit protéger ses identifiants et tenir ses coordonnées à jour.</p>

        <h2>11. Données personnelles</h2>
        <p>Le traitement des données personnelles est décrit dans la <a href="{{ route('storefront.privacy-policy') }}">politique de confidentialité de gpswiss.fr</a>.</p>

        <h2>12. Dispositions finales</h2>
        <p>Les modifications des présentes conditions ne portent pas atteinte aux droits acquis pour les commandes déjà passées. Le droit polonais s’applique sans priver le consommateur des protections impératives du droit applicable dans son pays de résidence. Les litiges relèvent des juridictions compétentes conformément aux règles applicables.</p>
    </section>
</div>
@endsection
