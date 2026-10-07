<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentProviderResolver;
use App\Services\Payments\StripeFrService;
use App\Services\Payments\StripeFrWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class StripeFrWebhookController extends Controller
{
    public function __invoke(Request $request, StripeFrService $stripe, StripeFrWebhookService $webhooks, PaymentProviderResolver $providers): Response
    {
        if ($providers->resolve($request) !== 'stripe') {
            return response('Not found', 404);
        }
        if (! $stripe->isAvailable()) {
            return response('Stripe Checkout is unavailable', 503);
        }
        $body = $request->getContent();
        if (strlen($body) > 1048576) {
            return response('Invalid event', 400);
        }
        if (! $stripe->verifySignature($body, $request->header('Stripe-Signature'))) {
            return response('Invalid signature', 401);
        }
        try {
            $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response('Invalid event', 400);
        }
        if (! is_array($event)) {
            return response('Invalid event', 400);
        }
        try {
            $webhooks->handle($event);
        } catch (InvalidArgumentException) {
            return response('Payment event does not match', 422);
        } catch (RuntimeException) {
            // Return only a stable message; Stripe response bodies, customer
            // data, request headers and credentials must never be exposed.
            return response('Payment confirmation is temporarily unavailable', 503);
        }

        return response('OK', 200);
    }
}
