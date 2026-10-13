<?php

namespace App\Mail;

use App\Models\Order;
use App\Support\FrenchOrderPresentation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class FrenchOrderMail extends Mailable
{
    public function __construct(public Order $order, public string $kind)
    {
        $this->order->loadMissing('items');
    }

    public function envelope(): Envelope
    {
        $subject = match ($this->kind) {
            'awaiting' => 'Commande reçue — paiement en attente',
            'paid' => 'Confirmation de paiement',
            'expired' => 'Session de paiement expirée',
        };

        return new Envelope(
            from: new Address(config('storefront-contact.fr.email'), config('storefront-contact.fr.name')),
            replyTo: [new Address(config('storefront-contact.fr.email'), config('storefront-contact.fr.name'))],
            subject: $subject.' — '.$this->order->order_number,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.storefront-fr-order', with: [
            'orderUrl' => FrenchOrderPresentation::link($this->order),
        ]);
    }
}
