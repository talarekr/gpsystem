<?php

namespace App\Http\Controllers\Tools;

use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class PostDomainSwitchCheckController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const OLD_DOMAIN = 'gpsystem.'.'thecamels.pl';
    private const TARGET_DOMAIN = 'gpswiss.pl';

    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $warnings = [];
        $blockers = [];
        $host = $request->getHost();
        $appUrl = (string) config('app.url');

        if ($host !== self::TARGET_DOMAIN) {
            $warnings[] = 'Request host is not gpswiss.pl yet; expected before DNS/Laravel switch.';
        }
        if (Str::contains($appUrl, self::OLD_DOMAIN)) {
            $blockers[] = 'APP_URL still contains '.self::OLD_DOMAIN.'.';
        }
        if ((bool) config('app.debug')) {
            $warnings[] = 'APP_DEBUG is enabled; disable it for production after the domain switch.';
        }

        $samplePart = Part::query()->with('images')->whereNotNull('slug')->first() ?? Part::query()->with('images')->first();
        $sampleProductUrl = $samplePart ? route('storefront.product', $samplePart->slug ?: $samplePart->id, absolute: true) : null;
        $sampleImageUrl = $samplePart?->listingImageUrl();
        $assetUrl = asset('favicon.ico');
        $canonicalUrl = $sampleProductUrl ?? url('/czesci');

        foreach (['sample_product_url' => $sampleProductUrl, 'sample_image_url' => $sampleImageUrl, 'asset_url' => $assetUrl, 'canonical_url' => $canonicalUrl] as $label => $value) {
            if (is_string($value) && Str::contains($value, self::OLD_DOMAIN)) {
                $blockers[] = $label.' contains '.self::OLD_DOMAIN.'.';
            }
        }

        return response()->json([
            'ok' => count($blockers) === 0,
            'request_host' => $host,
            'APP_URL' => $appUrl,
            'APP_ENV' => app()->environment(),
            'APP_DEBUG' => (bool) config('app.debug'),
            'routes' => [
                '/czesci' => Route::has('storefront.catalog') ? route('storefront.catalog', absolute: true) : null,
                '/admin' => url('/admin'),
                '/zamowienie' => Route::has('storefront.checkout.show') ? route('storefront.checkout.show', absolute: true) : null,
            ],
            'sample_product_url' => $sampleProductUrl,
            'sample_image_url' => $sampleImageUrl,
            'canonical_meta_contains_old_domain' => Str::contains($canonicalUrl, self::OLD_DOMAIN),
            'asset_urls_contain_old_domain' => Str::contains($assetUrl, self::OLD_DOMAIN),
            'marketplace_urls_app_url_ready' => [
                'product_url' => $sampleProductUrl,
                'photo_url' => $sampleImageUrl,
                'generated_from' => 'route(..., absolute: true) / model image URL helpers using url() and storage helpers',
            ],
            'warnings' => $warnings,
            'blockers' => $blockers,
            'next_steps' => [
                'After gpswiss.pl serves Laravel, set APP_URL=https://gpswiss.pl, APP_ENV=production, APP_DEBUG=false.',
                'Run php artisan config:clear, route:clear, view:clear, cache:clear.',
                'Re-run this endpoint on https://gpswiss.pl/tools/post-domain-switch-check?token='.self::TOKEN.'.',
            ],
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
