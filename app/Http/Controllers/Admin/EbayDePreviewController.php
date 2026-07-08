<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Marketplace\EbayDescriptionTemplateRenderer;
use App\Services\Marketplace\EbaySkuResolver;
use App\Services\Marketplace\MarketplaceImageSelectionService;
use App\Services\Marketplace\MarketplaceListingReadinessService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class EbayDePreviewController extends Controller
{
    private const CHANNEL = 'ebay_de';
    private const MARKETPLACE_ID = 'EBAY_DE';
    private const CONTENT_LANGUAGE = 'de-DE';
    private const ASSET_CHECK_METHOD = 'GET';
    private const ASSET_CHECK_USER_AGENT = 'Mozilla/5.0 (compatible; GPSystem-eBayAssetPreview/1.0; +https://gpswiss.pl)';

    private const ASSETS = [
        'icon_shipping' => 'icon-shipping.png',
        'icon_returns' => 'icon-returns.png',
        'icon_packaging' => 'icon-packaging.png',
        'icon_original' => 'icon-original.png',
        'europe_map' => 'europe-map.png',
        'dhl_logo' => 'dhl-logo.png',
        'dpd_logo' => 'dpd-logo.png',
    ];

    public function __invoke(
        Request $request,
        Part $part,
        MarketplaceListingReadinessService $readinessService,
        EbaySkuResolver $skuResolver,
        MarketplaceImageSelectionService $imageSelectionService,
        EbayDescriptionTemplateRenderer $renderer,
    ) {
        abort_unless($request->user()?->hasAnyRole(array_map(static fn (UserRole $role): string => $role->value, UserRole::cases())), 403);

        $part->loadMissing(['category', 'images', 'marketplaceListings', 'car']);

        $readiness = $readinessService->checkPartReadiness($part, self::CHANNEL);
        $prepared = $this->preparedTranslation($part);
        $preparedReady = ($prepared['status'] ?? 'not_prepared') === 'prepared';
        $preparedFields = is_array($prepared['fields'] ?? null) ? $prepared['fields'] : [];
        $safePayload = is_array($readiness['prepared_payload_preview_safe'] ?? null) ? $readiness['prepared_payload_preview_safe'] : [];
        $imageSelection = $imageSelectionService->selectForPart($part, 0, (bool) $request->boolean('check_images'), 'ebay_de');
        $imageUrls = $imageSelection['urls'];
        $renderData = $this->renderData($preparedFields, $part, $preparedReady);
        $listingDescription = $renderer->render(self::CHANNEL, $part, $renderData);
        $listingDescriptionPreviewHtml = $this->listingDescriptionPreviewHtml($listingDescription);
        $assetDiagnostics = $this->assetDiagnostics($renderer, (bool) $request->boolean('check_assets'));
        $assetUrlWarning = collect($assetDiagnostics)->contains(fn (array $asset): bool => ! $asset['absolute_https'] || ! $asset['source_exists'] || (($asset['http_check']['ok'] ?? true) === false));

        $title = (string) ($preparedReady && filled($preparedFields['title'] ?? null) ? $preparedFields['title'] : ($part->name ?? ''));
        $descriptionSource = $preparedReady && filled($preparedFields['title'] ?? null) ? 'marketplace_prepared_translations.ebay_de.fields.title' : 'fallback: parts.name';
        $description = $this->inventoryDescription($title, $inventoryPayloadSku = $skuResolver->resolve($part));

        $inventoryPayload = [
            'sku' => $inventoryPayloadSku,
            'product' => [
                'title' => $title,
                'description' => $description,
                'imageUrls' => $imageUrls,
                'aspects' => $safePayload['aspects'] ?? [],
            ],
            'condition' => 'USED_EXCELLENT',
            'availability' => ['shipToLocationAvailability' => ['quantity' => (int) ($part->quantity ?? 1)]],
        ];
        $offerPayload = [
            'sku' => $inventoryPayload['sku'],
            'marketplaceId' => self::MARKETPLACE_ID,
            'format' => 'FIXED_PRICE',
            'listingDuration' => 'GTC',
            'availableQuantity' => (int) ($part->quantity ?? 0),
            'categoryId' => $safePayload['category_id'] ?? null,
            'listingDescription' => $listingDescription,
            'pricingSummary' => ['price' => ['value' => $safePayload['price_eur'] ?? ($readiness['marketplace_price'] ?? null), 'currency' => 'EUR']],
            'merchantLocationKey' => data_get($safePayload, 'business_policies.merchant_location_key'),
            'listingPolicies' => [
                'fulfillmentPolicyId' => data_get($safePayload, 'business_policies.selected_fulfillment_policy_id'),
                'paymentPolicyId' => data_get($safePayload, 'business_policies.selected_payment_policy_id'),
                'returnPolicyId' => data_get($safePayload, 'business_policies.selected_return_policy_id'),
            ],
        ];

        return response()->view('admin.tools.marketplace.ebay-de-preview', [
            'part' => $part,
            'url' => route('admin.tools.marketplace.ebay-de-preview', $part),
            'preparedReady' => $preparedReady,
            'missingImages' => $imageUrls === [],
            'assetUrlWarning' => $assetUrlWarning,
            'title' => ['value' => $title, 'source' => $preparedReady && filled($preparedFields['title'] ?? null) ? 'marketplace_prepared_translations.ebay_de.fields.title' : 'fallback: parts.name'],
            'description' => ['value' => $description, 'length' => Str::length($description), 'source' => $descriptionSource],
            'listingDescription' => $listingDescription,
            'listingDescriptionPreviewHtml' => $listingDescriptionPreviewHtml,
            'inventoryPayload' => $inventoryPayload,
            'offerPayload' => $offerPayload,
            'images' => ['urls' => $imageUrls, 'selected' => $imageSelection['selected'], 'diagnostics' => $imageSelection['diagnostics']],
            'assetDiagnostics' => $assetDiagnostics,
            'diagnostics' => [
                'resolved_ebay_sku' => $inventoryPayload['sku'],
                'marketplace_id' => self::MARKETPLACE_ID,
                'content_language' => self::CONTENT_LANGUAGE,
                'listing_description_length' => Str::length($listingDescription),
                'inventory_description_length' => Str::length($description),
                'description_template_asset_urls' => $renderer->assetUrls(),
                'marketplace_images' => $imageSelection['diagnostics'],
                'readiness' => Arr::only($readiness, ['can_prepare', 'can_publish_later', 'missing_fields', 'warnings', 'blockers', 'prepared_payload_preview_safe']),
            ],
        ])->header('Content-Language', self::CONTENT_LANGUAGE);
    }

    private function listingDescriptionPreviewHtml(string $html): string
    {
        $html = (string) preg_replace('/<img\b(?![^>]*\breferrerpolicy=)/i', '<img referrerpolicy="no-referrer"', $html);

        return '<meta name="referrer" content="no-referrer">'.$html;
    }

    private function preparedTranslation(Part $part): array
    {
        $data = data_get(is_array($part->review_metadata) ? $part->review_metadata : [], 'marketplace_prepared_translations.'.self::CHANNEL, []);
        return is_array($data) ? $data : [];
    }

    private function renderData(array $fields, Part $part, bool $preparedReady): array
    {
        $data = [];
        foreach ($fields as $key => $value) {
            $data[str_replace('vehicle.', '', (string) $key)] = $value;
        }
        $data['title'] ??= $part->name ?? '';
        if (! $preparedReady) {
            $data['translation_fallback_notice'] = 'Najpierw przygotuj aukcję eBay DE.';
        }
        return $data;
    }

    private function assetDiagnostics(EbayDescriptionTemplateRenderer $renderer, bool $withHttpChecks): array
    {
        $urls = $renderer->assetUrls();
        return collect(self::ASSETS)->map(function (string $filename, string $key) use ($urls, $withHttpChecks): array {
            $url = (string) ($urls[$key] ?? '');
            [$selectedSourcePath, $selectedSourceVariant] = $this->selectedAssetSource($filename);
            $row = [
                'key' => $key,
                'filename' => $filename,
                'selected_source_path' => $selectedSourcePath,
                'selected_source_variant' => $selectedSourceVariant,
                'source_path' => $selectedSourcePath,
                'generated_url' => $url,
                'absolute_https' => strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' && filled(parse_url($url, PHP_URL_HOST)),
                'source_exists' => $selectedSourceVariant !== 'missing',
                'http_check' => null,
            ];
            if ($withHttpChecks && $row['absolute_https']) {
                $row['http_check'] = $this->httpCheck($url);
            }
            return $row;
        })->values()->all();
    }


    /** @return array{0: string, 1: string} */
    private function selectedAssetSource(string $filename): array
    {
        $candidates = [
            'ebay_template_folder' => storage_path('app/imports/ebay-template/'.$filename),
            'imports_root' => storage_path('app/imports/'.$filename),
        ];

        foreach ($candidates as $variant => $path) {
            if (is_file($path)) {
                return [$path, $variant];
            }
        }

        return [$candidates['ebay_template_folder'], 'missing'];
    }

    private function inventoryDescription(string $title, string $sku): string
    {
        $description = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($description === '') {
            $description = 'Autoteil '.$sku;
        }

        return mb_substr($description, 0, 3900);
    }

    private function httpCheck(string $url): array
    {
        try {
            $response = Http::withUserAgent(self::ASSET_CHECK_USER_AGENT)
                ->accept('image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8')
                ->timeout(8)
                ->connectTimeout(4)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => true,
                        'referer' => false,
                        'track_redirects' => true,
                    ],
                ])
                ->get($url);

            $contentType = (string) $response->header('content-type');
            $isImage = Str::startsWith(strtolower($contentType), 'image/');
            $redirectHistory = $this->redirectHistory($response->header('X-Guzzle-Redirect-History'));
            $finalUrl = $redirectHistory === [] ? $url : (string) end($redirectHistory);

            return [
                'ok' => $response->successful() && $isImage,
                'check_method' => self::ASSET_CHECK_METHOD,
                'user_agent' => self::ASSET_CHECK_USER_AGENT,
                'response_status' => $response->status(),
                'response_content_type' => $contentType !== '' ? $contentType : null,
                'response_body_snippet' => $isImage ? null : $this->bodySnippet($response->body()),
                'final_url' => $finalUrl,
                'redirect_count' => count($redirectHistory),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'check_method' => self::ASSET_CHECK_METHOD,
                'user_agent' => self::ASSET_CHECK_USER_AGENT,
                'response_status' => null,
                'response_content_type' => null,
                'response_body_snippet' => null,
                'final_url' => $url,
                'redirect_count' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function redirectHistory(string|array|null $history): array
    {
        if ($history === null || $history === '') {
            return [];
        }

        if (is_array($history)) {
            return array_values(array_filter(array_map('strval', $history)));
        }

        return array_values(array_filter(array_map('trim', explode(',', $history))));
    }

    private function bodySnippet(string $body): string
    {
        $snippet = Str::limit(trim((string) preg_replace('/[[:^print:]]+/u', ' ', strip_tags($body))), 200, '');

        return $snippet === '' ? '[empty body]' : $snippet;
    }
}
