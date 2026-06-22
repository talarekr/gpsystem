<?php

namespace App\Http\Controllers\Tools;

use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class PostDomainSwitchCheckController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const OLD_DOMAIN = 'gpsystem.'.'thecamels.pl';
    private const TARGET_DOMAIN = 'gpswiss.pl';
    private const TARGET_APP_URL = 'https://gpswiss.pl';

    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $warnings = [];
        $blockers = [];
        $host = $request->getHost();
        $appUrl = (string) config('app.url');
        $appEnv = app()->environment();
        $appDebug = (bool) config('app.debug');

        $this->expect('request_host', $host === self::TARGET_DOMAIN, 'Request host is not gpswiss.pl.', $blockers);
        $this->expect('APP_URL', $appUrl === self::TARGET_APP_URL, 'APP_URL is not https://gpswiss.pl.', $blockers);
        $this->expect('APP_ENV', $appEnv === 'production', 'APP_ENV is not production.', $blockers);
        $this->expect('APP_DEBUG', $appDebug === false, 'APP_DEBUG is not false.', $blockers);

        $samplePart = Part::query()->with('images')->whereNotNull('slug')->first() ?? Part::query()->with('images')->first();
        $sampleProductUrl = $samplePart ? route('storefront.product', $samplePart->slug ?: $samplePart->id, absolute: true) : null;
        $sampleImageUrl = $samplePart?->listingImageUrl();
        $assetUrls = [
            asset('favicon.ico'),
            asset('css/storefront.css'),
            asset('js/storefront-category-menu.js'),
            asset('js/storefront-product-gallery.js'),
            asset('js/storefront-product-carousel.js'),
        ];
        $canonicalUrl = $sampleProductUrl ?? url('/czesci');
        $metaUrls = [$canonicalUrl, url()->current()];

        foreach (['sample_product_url' => $sampleProductUrl, 'sample_image_url' => $sampleImageUrl] as $label => $value) {
            if (is_string($value) && Str::contains($value, self::OLD_DOMAIN)) {
                $blockers[] = $label.' contains '.self::OLD_DOMAIN.'.';
            }
        }

        if ($this->containsOldDomain($metaUrls)) {
            $blockers[] = 'canonical/meta contains '.self::OLD_DOMAIN.'.';
        }

        if ($this->containsOldDomain($assetUrls)) {
            $blockers[] = 'asset URLs contain '.self::OLD_DOMAIN.'.';
        }

        $urlChecks = [
            '/storage' => $this->checkUrl(self::TARGET_APP_URL.'/storage', allowForbidden: true),
            '/czesci' => $this->checkUrl(self::TARGET_APP_URL.'/czesci'),
            '/admin' => $this->checkUrl(self::TARGET_APP_URL.'/admin'),
        ];

        foreach ($urlChecks as $path => $check) {
            if (! $check['ok']) {
                $blockers[] = $path.' is not reachable on gpswiss.pl: '.$check['message'];
            }
        }

        if ($sampleProductUrl === null) {
            $warnings[] = 'No sample product found; sample_product_url and product canonical checks are limited.';
        }
        if ($sampleImageUrl === null) {
            $warnings[] = 'No sample image found; sample_image_url check is limited.';
        }

        return response()->json([
            'ok' => count($blockers) === 0,
            'request_host' => $host,
            'APP_URL' => $appUrl,
            'APP_ENV' => $appEnv,
            'APP_DEBUG' => $appDebug,
            'checks' => [
                'request_host_is_gpswiss_pl' => $host === self::TARGET_DOMAIN,
                'app_url_is_gpswiss_pl' => $appUrl === self::TARGET_APP_URL,
                'app_env_is_production' => $appEnv === 'production',
                'app_debug_is_false' => $appDebug === false,
                'sample_product_url_without_old_domain' => ! $this->containsOldDomain([$sampleProductUrl]),
                'sample_image_url_without_old_domain' => ! $this->containsOldDomain([$sampleImageUrl]),
                'canonical_meta_without_old_domain' => ! $this->containsOldDomain($metaUrls),
                'asset_urls_without_old_domain' => ! $this->containsOldDomain($assetUrls),
                'storage_works_on_gpswiss_pl' => $urlChecks['/storage']['ok'],
                'czesci_works' => $urlChecks['/czesci']['ok'],
                'admin_works' => $urlChecks['/admin']['ok'],
            ],
            'routes' => [
                '/czesci' => Route::has('storefront.catalog') ? route('storefront.catalog', absolute: true) : null,
                '/admin' => url('/admin'),
                '/storage' => url('/storage'),
            ],
            'http_checks' => $urlChecks,
            'sample_product_url' => $sampleProductUrl,
            'sample_image_url' => $sampleImageUrl,
            'canonical_meta_urls' => $metaUrls,
            'asset_urls' => $assetUrls,
            'canonical_meta_contains_old_domain' => $this->containsOldDomain($metaUrls),
            'asset_urls_contain_old_domain' => $this->containsOldDomain($assetUrls),
            'warnings' => $warnings,
            'blockers' => $blockers,
            'next_steps' => count($blockers) === 0
                ? ['Domain switch checks passed. Keep monitoring logs and storefront/admin access.']
                : ['Fix blockers, clear Laravel caches if .env changed, then re-run this endpoint on https://gpswiss.pl/tools/post-domain-switch-check?token='.self::TOKEN.'.'],
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<int, mixed> $values */
    private function containsOldDomain(array $values): bool
    {
        foreach ($values as $value) {
            if (is_string($value) && Str::contains($value, self::OLD_DOMAIN)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, string> $blockers */
    private function expect(string $label, bool $passes, string $message, array &$blockers): void
    {
        if (! $passes) {
            $blockers[] = $label.': '.$message;
        }
    }

    /** @return array{ok: bool, status: int|null, message: string} */
    private function checkUrl(string $url, bool $allowForbidden = false): array
    {
        try {
            $response = Http::timeout(8)->withoutRedirecting()->get($url);
            $status = $response->status();

            return [
                'ok' => ($status >= 200 && $status < 400) || ($allowForbidden && $status === 403),
                'status' => $status,
                'message' => 'HTTP '.$status,
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'status' => null,
                'message' => $exception->getMessage(),
            ];
        }
    }
}
