<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;

final class PaymentProviderResolver
{
    public function resolve(Request $request): string
    {
        // Only the server-side host selects the provider. Locale, cookies and
        // payment_provider/payment_method input cannot enable Stripe elsewhere.
        return in_array(strtolower(rtrim($request->getHost(), '.')), ['gpswiss.fr', 'www.gpswiss.fr'], true)
            ? 'stripe'
            : 'payu';
    }
}
