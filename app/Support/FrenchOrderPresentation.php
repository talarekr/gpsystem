<?php

namespace App\Support;

use App\Models\Order;

final class FrenchOrderPresentation
{
    public static function status(string $status): string
    {
        return [
            'new' => 'Commande reçue', 'processing' => 'En préparation',
            'on_hold' => 'En attente de vérification', 'ready_to_ship' => 'Prête à être expédiée',
            'ready_for_pickup' => 'Prête à être retirée', 'shipped' => 'Expédiée',
            'picked_up' => 'Retirée', 'completed' => 'Terminée', 'cancelled' => 'Annulée',
        ][$status] ?? 'Statut à vérifier';
    }

    public static function payment(string $status): string
    {
        return ['pending' => 'En attente de paiement', 'paid' => 'Paiement confirmé',
            'failed' => 'Paiement non abouti', 'refunded' => 'Remboursé',
        ][$status] ?? 'Paiement à vérifier';
    }

    public static function link(Order $order): string
    {
        // Fixed FR-domain route + HTTPS, independent of APP_URL and webhook/admin host.
        $urls = clone app('url');
        $urls->forceScheme('https');

        return $urls->temporarySignedRoute('storefront.fr.order', now()->addDays(30), ['order' => $order->id]);
    }
}
