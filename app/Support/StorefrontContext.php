<?php

namespace App\Support;

use Illuminate\Http\Request;

/** Host-derived identity; posted fields and language cookies cannot select a store. */
final class StorefrontContext
{
    public static function profile(Request $request): ?array
    {
        $host = strtolower($request->getHost());
        if ($host === 'www.gpswiss.fr') {
            $host = 'gpswiss.fr';
        }

        return config('storefronts', [])[$host] ?? null;
    }

    public static function isFrench(Request $request): bool
    {
        return (self::profile($request)['storefront_code'] ?? null) === 'gpswiss_fr';
    }

    /** Only cart/checkout entry links are localized in this stage. */
    public static function routeName(Request $request, string $suffix): string
    {
        return (self::isFrench($request) ? 'storefront.fr.' : 'storefront.').$suffix;
    }

    public static function orderMetadata(Request $request): array
    {
        $profile = self::profile($request);
        if (! $profile) {
            return [];
        }

        return [
            'source_channel' => 'storefront',
            'storefront_code' => $profile['storefront_code'],
            'locale' => self::isFrench($request) ? 'fr' : app()->getLocale(),
            // Identity only: FR checkout stays blocked until its own implementation.
            'payment_provider' => $profile['payment_provider'],
        ];
    }
}
