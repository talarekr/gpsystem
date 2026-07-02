<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Marketplace\AllegroCategoryParametersService;
use App\Services\Marketplace\AllegroDescriptionBuilder;
use App\Services\Marketplace\MarketplaceImageSelectionService;
use App\Services\Marketplace\AllegroOfferParametersBuilder;
use App\Services\Marketplace\AllegroSalesSettingsResolver;
use App\Services\Marketplace\AllegroCompatibilityMappingService;
use App\Services\Marketplace\Api\AllegroApiClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;

class MarketplaceListingDryRunController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const CHANNELS = ['ovoko', 'allegro_main'];
    private const DEFAULT_SAFETY_INFORMATION = 'Część używana pochodząca z demontażu pojazdu. Montaż powinien zostać wykonany przez wykwalifikowany warsztat lub osobę posiadającą odpowiednią wiedzę techniczną. Przed montażem należy porównać numer części i zgodność z pojazdem. Produkt nie jest zabawką.';

    public function readiness(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $channel = $this->channel($request);
        if (! in_array($channel, self::CHANNELS, true)) return $this->invalidChannelResponse($channel);

        $part = $this->part((int) $request->query('part_id'));
        if (! $part) return response()->json(['ok' => false, 'blockers' => ['part_not_found'], 'part_id' => (int) $request->query('part_id')], 404);

        return response()->json($this->readinessFor($part, $channel));
    }

    public function payload(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $channel = $this->channel($request);
        if (! in_array($channel, self::CHANNELS, true)) return $this->invalidChannelResponse($channel);

        $part = $this->part((int) $request->query('part_id'));
        if (! $part) return response()->json(['ok' => false, 'dry_run' => true, 'would_send' => false, 'blockers' => ['part_not_found'], 'part_id' => (int) $request->query('part_id')], 404);

        $readiness = $this->readinessFor($part, $channel);
        $payload = $this->payloadFor($part, $channel, $readiness);
        if ($channel === 'allegro_main') {
            $compatibilityDryRun = app(AllegroCompatibilityMappingService::class)->dryRun($part, $payload);
            $payload = $compatibilityDryRun['publish_payload_preview'] ?? $payload;
        } else {
            $compatibilityDryRun = null;
        }
        $plannedAction = $this->plannedAction($readiness);

        return response()->json([
            'ok' => true,
            'dry_run' => true,
            'part_id' => $part->id,
            'channel' => $channel,
            'planned_action' => $plannedAction,
            'would_send' => false,
            'api_mode' => 'local_read_only_dry_run',
            'payload' => $payload,
            'payload_summary' => [
                'title' => $payload['name'] ?? $payload['title'] ?? null,
                'price' => $payload['price']['amount'] ?? null,
                'currency' => $payload['price']['currency'] ?? 'PLN',
                'quantity' => $payload['stock']['quantity'] ?? $payload['quantity'] ?? null,
                'category_id' => $payload['category']['id'] ?? null,
                'images_count' => count($payload['images'] ?? []),
                'existing_listing' => (bool) ($readiness['existing_listing']['exists'] ?? false),
                'productSet_0_product_images_count' => count(data_get($payload, 'productSet.0.product.images', [])),
                'productSet_0_product_main_image_present' => filled(data_get($payload, 'productSet.0.product.images.0')),
                'productSet_0_product_id' => data_get($payload, 'productSet.0.product.id'),
                'compatibilityList_type' => data_get($payload, 'compatibilityList.type'),
                'tecdocSpecification_present' => array_key_exists('tecdocSpecification', $payload),
            ],
            'allegro_compatibility_dry_run' => $compatibilityDryRun,
            'blockers' => $readiness['blockers'],
            'warnings' => $readiness['warnings'],
        ]);
    }


    public function allegroResponsibleProducers(Request $request): JsonResponse
    {
        $account = $this->account('allegro_main');
        if (! $account) return response()->json(['ok' => false, 'http_status' => null, 'error' => 'Marketplace account allegro_main is missing.'], 422);

        $result = (new AllegroApiClient('allegro_main', $account))->responsibleProducers();

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'read_only' => true,
            'endpoint' => 'GET /sale/responsible-producers',
            'http_status' => $result['http_status'] ?? null,
            'responsible_producers' => $result['items'] ?? [],
            'error' => $result['error'] ?? null,
            'request_id' => $result['request_id'] ?? null,
        ], ($result['ok'] ?? false) ? 200 : 422);
    }

    public function allegroCompatibleProducts(Request $request): JsonResponse
    {
        $account = $this->account('allegro_main');
        if (! $account) return response()->json(['ok' => false, 'http_status' => null, 'error' => 'Marketplace account allegro_main is missing.'], 422);

        $phrase = trim((string) $request->query('phrase', ''));
        if ($phrase === '') return response()->json(['ok' => false, 'read_only' => true, 'endpoint' => 'GET /sale/compatible-products?type=CAR&phrase={phrase}', 'error' => 'Missing required query parameter: phrase.'], 422);

        $result = (new AllegroApiClient('allegro_main', $account))->compatibleProducts($phrase, 'CAR');

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'read_only' => true,
            'endpoint' => 'GET /sale/compatible-products?type=CAR&phrase={phrase}',
            'phrase' => $phrase,
            'type' => 'CAR',
            'http_status' => $result['http_status'] ?? null,
            'compatible_products' => $result['items'] ?? [],
            'error' => $result['error'] ?? null,
            'request_id' => $result['request_id'] ?? null,
        ], ($result['ok'] ?? false) ? 200 : 422);
    }




    public function allegroDescriptionUpdateDryRun(Request $request): JsonResponse
    {
        return $this->allegroDescriptionUpdateForPart($request, false);
    }

    public function allegroDescriptionUpdateApply(Request $request): JsonResponse
    {
        return $this->allegroDescriptionUpdateForPart($request, true);
    }

    private function allegroDescriptionUpdateForPart(Request $request, bool $apply): JsonResponse
    {
        $partId = (int) $request->query('part_id');
        $part = $this->part($partId);
        if (! $part) return response()->json(['ok' => false, 'part_id' => $partId, 'blockers' => ['part_not_found']], 404);

        $listing = MarketplaceListing::query()
            ->where('part_id', $part->id)
            ->whereIn('marketplace', ['allegro', 'allegro_main'])
            ->where(fn (Builder $query): Builder => $query->whereNotNull('external_offer_id')->orWhereNotNull('external_listing_id'))
            ->latest('id')
            ->first();
        $offerId = (string) ($listing?->external_offer_id ?: $listing?->external_listing_id ?: '');

        $images = $this->imageUrls($part);
        $built = app(AllegroDescriptionBuilder::class)->build($part, $images['public_urls_sample']);
        $description = $built['description'];
        $diagnostics = $this->allegroDescriptionUpdateDiagnostics($description);
        $blockers = array_values(array_unique(array_merge(
            $listing ? [] : ['missing_existing_allegro_offer'],
            $offerId !== '' ? [] : ['missing_allegro_offer_id'],
            $built['blockers'] ?? [],
            $this->allegroDescriptionUpdateBlockers($description, $diagnostics)
        )));
        $confirmed = hash_equals('allegro-description-update', (string) $request->query('confirm', ''));

        $raw = is_array($listing?->raw_payload) ? $listing->raw_payload : [];
        $currentDescription = $raw['description'] ?? data_get($raw, 'offer.description');
        $response = [
            'ok' => $blockers === [] && (! $apply || $confirmed),
            'dry_run' => ! $apply,
            'would_send' => $apply && $confirmed && $blockers === [],
            'applied' => false,
            'operation' => 'allegro_existing_offer_description_only_update',
            'endpoint' => 'PATCH /sale/product-offers/{offerId}',
            'part_id' => $part->id,
            'offer_id' => $offerId !== '' ? $offerId : null,
            'allegro_offer_id' => $offerId !== '' ? $offerId : null,
            'current_description_summary' => $this->allegroCurrentDescriptionSummary($currentDescription),
            'new_description_payload' => $description,
            'description_source' => \App\Services\Marketplace\AllegroGpSwissDescriptionTemplate::SOURCE,
            'description_template' => \App\Services\Marketplace\AllegroGpSwissDescriptionTemplate::TEMPLATE,
            'description_builder_class' => AllegroDescriptionBuilder::class,
            'description_contains_gp_swiss_intro' => data_get($built, 'diagnostics.description_contains_gp_swiss_intro', false),
            'description_contains_gp_swiss_footer' => data_get($built, 'diagnostics.description_contains_gp_swiss_footer', false),
            'description_contains_vehicle_fields' => data_get($built, 'diagnostics.description_contains_vehicle_fields', false),
            'description_publish_blocked_if_template_missing' => true,
            'main_image_url' => data_get($built, 'diagnostics.main_image_url'),
            'vehicle_fields' => data_get($built, 'diagnostics.description_vehicle_fields_present', []),
            'vehicle_diagnostics' => $built['diagnostics'] ?? [],
            'blockers' => $blockers,
            'safety_guards' => [
                'existing_offer_only' => true,
                'single_part_id_only' => true,
                'bulk_update' => false,
                'createProductOffer' => false,
                'publish_relist_end' => false,
                'updates_only' => ['description'],
                'unchanged' => ['price', 'stock', 'title', 'parameters', 'images', 'delivery', 'afterSalesServices', 'GPSR', 'payments', 'publication'],
            ],
        ];

        if (! $apply) return response()->json($response);
        if (! $confirmed) return response()->json(array_merge($response, ['ok' => false, 'error' => 'Missing confirm=allegro-description-update for apply.']), 422);
        if ($blockers !== []) return response()->json(array_merge($response, ['ok' => false, 'error' => 'Description update blocked.']), 422);

        $account = $listing?->account ?: $this->account('allegro_main');
        if (! $account) return response()->json(array_merge($response, ['ok' => false, 'error' => 'Marketplace account allegro_main is missing.']), 422);

        $started = microtime(true);
        $result = (new AllegroApiClient('allegro_main', $account))->updateProductOfferDescription($offerId, $description);
        MarketplaceSyncLog::query()->create([
            'marketplace' => 'allegro',
            'marketplace_listing_id' => $listing?->id,
            'part_id' => $part->id,
            'action' => 'allegro_description_update',
            'status' => ($result['ok'] ?? false) ? 'success' : 'failed',
            'http_status' => $result['http_status'] ?? null,
            'message' => 'Description-only update for existing Allegro offer.',
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'request_id' => $result['request_id'] ?? null,
            'external_id' => $offerId,
            'payload' => ['offer_id' => $offerId, 'part_id' => $part->id, 'description_source' => $response['description_source'], 'description_template' => $response['description_template'], 'description_builder_class' => $response['description_builder_class'], 'description_contains_gp_swiss_intro' => $response['description_contains_gp_swiss_intro'], 'description_contains_gp_swiss_footer' => $response['description_contains_gp_swiss_footer'], 'description_contains_vehicle_fields' => $response['description_contains_vehicle_fields'], 'description_publish_blocked_if_template_missing' => true, 'request_payload_keys' => ['description']],
            'created_at' => now(),
        ]);

        return response()->json(array_merge($response, ['ok' => (bool) ($result['ok'] ?? false), 'dry_run' => false, 'would_send' => true, 'applied' => (bool) ($result['ok'] ?? false), 'http_status' => $result['http_status'] ?? null, 'request_id' => $result['request_id'] ?? null, 'api_response' => $result['json'] ?? [], 'error' => ($result['ok'] ?? false) ? null : 'Allegro description-only update failed.']), ($result['ok'] ?? false) ? 200 : 422);
    }

    private function allegroCurrentDescriptionSummary(mixed $description): array
    {
        $sections = is_array($description) ? ($description['sections'] ?? []) : [];
        $text = is_string($description) ? $description : '';
        foreach (is_array($sections) ? $sections : [] as $section) {
            foreach (($section['items'] ?? []) as $item) {
                if (($item['type'] ?? null) === 'TEXT') $text .= ' '.strip_tags((string) ($item['content'] ?? ''));
            }
        }
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?: '');
        return ['sections_count' => is_array($sections) ? count($sections) : 0, 'text_length' => mb_strlen($text), 'preview' => mb_substr($text, 0, 240)];
    }

    public function allegroDescriptionUpdate(string $offerId, Request $request): JsonResponse
    {
        $part = $this->part((int) $request->query('part_id'));
        if (! $part) return response()->json(['ok' => false, 'offer_id' => $offerId, 'blockers' => ['part_not_found'], 'part_id' => (int) $request->query('part_id')], 404);

        $images = $this->imageUrls($part);
        $built = app(AllegroDescriptionBuilder::class)->build($part, $images['public_urls_sample']);
        $description = $built['description'];
        $diagnostics = $this->allegroDescriptionUpdateDiagnostics($description);
        $blockers = array_values(array_unique(array_merge($built['blockers'] ?? [], $this->allegroDescriptionUpdateBlockers($description, $diagnostics))));
        $applyRequested = $request->boolean('apply', false);
        $confirmed = hash_equals('update-allegro-description', (string) $request->query('confirm', ''));

        $response = [
            'ok' => $blockers === [] && (! $applyRequested || $confirmed),
            'dry_run' => ! $applyRequested,
            'would_send' => false,
            'applied' => false,
            'operation' => 'allegro_description_only_update',
            'endpoint' => 'PATCH /sale/product-offers/{offerId}',
            'offer_id' => $offerId,
            'part_id' => $part->id,
            'description' => $description,
            'diagnostics' => $diagnostics + [
                'builder' => $built['diagnostics'] ?? [],
                'additional_marketplaces_observed_not_modified' => ['allegro-business-pl', 'allegro-sk', 'allegro-cz', 'allegro-hu', 'allegro-business-cz'],
            ],
            'blockers' => $blockers,
            'safety_guards' => [
                'createProductOffer' => false,
                'relist_end_relist' => false,
                'updates_only' => ['description'],
                'unchanged' => ['price', 'stock', 'parameters', 'images', 'delivery', 'afterSalesServices', 'GPSR', 'payments', 'publication'],
            ],
        ];

        if (! $applyRequested) return response()->json($response);
        if (! $confirmed) return response()->json(array_merge($response, ['ok' => false, 'error' => 'Missing confirm=update-allegro-description for apply.']), 422);
        if ($blockers !== []) return response()->json(array_merge($response, ['ok' => false, 'error' => 'Description update blocked.']), 422);

        $account = $this->account('allegro_main');
        if (! $account) return response()->json(array_merge($response, ['ok' => false, 'error' => 'Marketplace account allegro_main is missing.']), 422);

        $result = (new AllegroApiClient('allegro_main', $account))->updateProductOfferDescription($offerId, $description);

        return response()->json(array_merge($response, [
            'ok' => (bool) ($result['ok'] ?? false),
            'dry_run' => false,
            'would_send' => true,
            'applied' => (bool) ($result['ok'] ?? false),
            'http_status' => $result['http_status'] ?? null,
            'request_id' => $result['request_id'] ?? null,
            'api_response' => $result['json'] ?? [],
            'error' => ($result['ok'] ?? false) ? null : 'Allegro description-only update failed.',
        ]), ($result['ok'] ?? false) ? 200 : 422);
    }

    private function allegroDescriptionUpdateDiagnostics(?array $description): array
    {
        $sections = is_array($description['sections'] ?? null) ? $description['sections'] : [];
        $text = '';
        $hasImage = false;
        foreach ($sections as $section) {
            foreach (($section['items'] ?? []) as $item) {
                if (($item['type'] ?? null) === 'TEXT') $text .= ' '.trim(strip_tags((string) ($item['content'] ?? '')));
                if (($item['type'] ?? null) === 'IMAGE' && filled($item['url'] ?? null)) $hasImage = true;
            }
        }
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?: '');

        return [
            'description_sections_count' => count($sections),
            'description_text_length' => mb_strlen($text),
            'description_has_image' => $hasImage,
            'description_first_text_preview' => mb_substr($text, 0, 240),
        ];
    }

    private function allegroDescriptionUpdateBlockers(?array $description, array $diagnostics): array
    {
        $blockers = [];
        if (! is_array($description) || ! is_array($description['sections'] ?? null) || count($description['sections']) < 1) $blockers[] = 'description_sections_empty';
        if (($diagnostics['description_text_length'] ?? 0) < 1) $blockers[] = 'description_text_empty';
        foreach (($description['sections'] ?? []) as $section) {
            foreach (($section['items'] ?? []) as $item) {
                if (($item['type'] ?? null) === 'TEXT' && trim((string) ($item['content'] ?? '')) === '<p></p>') $blockers[] = 'description_text_is_blank_paragraph';
            }
        }
        return $blockers;
    }

    public function allegroPreview(Request $request)
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $part = $this->part((int) $request->query('part_id'));
        if (! $part) return response()->json(['ok' => false, 'blockers' => ['part_not_found'], 'part_id' => (int) $request->query('part_id')], 404);

        $readiness = $this->readinessFor($part, 'allegro_main');
        $payload = $this->payloadFor($part, 'allegro_main', $readiness);
        $payload['will_make_marketplace_request'] = false;

        return view('admin.marketplace.allegro-listing-preview', [
            'part' => $part,
            'readiness' => $readiness,
            'preview' => $payload,
        ]);
    }

    public function coverage(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();
        $channel = $this->channel($request);
        if (! in_array($channel, self::CHANNELS, true)) return $this->invalidChannelResponse($channel);

        return response()->json($this->coverageFor($request, $channel));
    }

    public function coverageAll(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();

        $channels = [];
        foreach (self::CHANNELS as $channel) {
            $channels[$channel] = $this->coverageFor($request, $channel);
        }

        return response()->json([
            'ok' => true,
            'dry_run' => true,
            'channels' => $channels,
            'summary' => [
                'ready_count' => array_sum(array_column($channels, 'ready_count')),
                'not_ready_count' => array_sum(array_column($channels, 'not_ready_count')),
                'would_create_count' => array_sum(array_column($channels, 'would_create_count')),
                'would_update_count' => array_sum(array_column($channels, 'would_update_count')),
                'blocked_count' => array_sum(array_column($channels, 'blocked_count')),
            ],
        ]);
    }

    public function export(Request $request)
    {
        if (! $this->validToken($request)) return $this->invalidTokenResponse();
        $channel = $this->channel($request);
        if (! in_array($channel, self::CHANNELS, true)) return $this->invalidChannelResponse($channel);

        $rows = $this->partsQuery($request)->limit((int) $request->query('limit', 1000))->get()->map(function (Part $part) use ($channel) {
            $readiness = $this->readinessFor($part, $channel);
            return [
                $part->id,
                $channel,
                $readiness['ready'] ? '1' : '0',
                $this->plannedAction($readiness),
                $readiness['existing_listing']['exists'] ? '1' : '0',
                $readiness['existing_listing']['external_id'],
                $readiness['price'][$channel === 'ovoko' ? 'ovoko_price' : 'allegro_price'],
                $readiness['part']['quantity'],
                $readiness['category']['external_category_id'],
                $readiness['images']['count'],
                implode('|', $readiness['blockers']),
                implode('|', $readiness['warnings']),
            ];
        });

        return Response::streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['part_id','channel','ready','planned_action','existing_listing','external_id','price','quantity','category_id','images_count','blockers','warnings']);
            foreach ($rows as $row) fputcsv($out, $row);
            fclose($out);
        }, 'marketplace-listing-coverage-'.$channel.'.csv', ['Content-Type' => 'text/csv']);
    }

    private function readinessFor(Part $part, string $channel): array
    {
        $part->loadMissing(['images', 'category', 'marketplaceListings', 'car']);
        $blockers = [];
        $warnings = ['dry_run_only_no_marketplace_writes'];
        $price = $this->price($part, $channel);
        $mapping = $this->categoryMapping($part, $channel);
        $images = $this->imageUrls($part);
        $existing = $this->existingListing($part, $channel);
        $description = trim(strip_tags((string) (($part->description ?: $part->short_description) ?? '')));
        $visible = (bool) ($part->is_visible_storefront ?? false);

        if (! $visible) $warnings[] = 'storefront_not_visible';
        if (! is_numeric($price) || (float) $price <= 0) $blockers[] = 'missing_price';
        if ((bool) ($part->needs_review ?? false)) $blockers[] = 'part_needs_review';
        if (! is_numeric($part->quantity) || (int) $part->quantity <= 0) $blockers[] = 'missing_or_zero_quantity';
        if (blank($part->name)) $blockers[] = 'missing_name';
        if ($images['count'] < 1) $blockers[] = 'missing_images';
        if ($description === '') $blockers[] = 'missing_description';
        if (! $mapping) $blockers[] = 'missing_category_mapping';
        elseif ($mapping->is_blocked) $blockers[] = 'blocked_category';
        elseif (blank($mapping->external_category_id)) $blockers[] = 'missing_external_category_id';
        $allegroParameters = null;
        $allegroSalesSettings = null;
        if ($channel === 'allegro_main') {
            $allegroSalesSettings = app(AllegroSalesSettingsResolver::class)->resolve($this->account($channel), $part->allegro_shipping_rate_name ?? null);
            foreach (($allegroSalesSettings['blockers'] ?? []) as $salesSettingsBlocker) $blockers[] = $salesSettingsBlocker;
            $allegroDescription = app(AllegroDescriptionBuilder::class)->build($part, $images['public_urls_sample']);
            foreach ($allegroDescription['blockers'] as $descriptionBlocker) $blockers[] = $descriptionBlocker;
            foreach (($allegroDescription['diagnostics']['optional_donor_vehicle_fields_missing'] ?? []) as $missingOptionalField) $warnings[] = 'optional_donor_vehicle_field_missing:'.$missingOptionalField;
            foreach ($this->allegroGpsrBlockers($this->account($channel)) as $gpsrBlocker) $blockers[] = $gpsrBlocker;
        }
        if ($channel === 'allegro_main' && ! $mapping) $blockers[] = 'allegro_category_mapping_required_no_guessing';
        if ($channel === 'allegro_main' && $mapping && filled($mapping->external_category_id)) {
            $definitions = app(AllegroCategoryParametersService::class)->definitions((string) $mapping->external_category_id);
            $allegroParameters = app(AllegroOfferParametersBuilder::class)->build($part, $mapping, $definitions);
            if (! ($definitions['ok'] ?? false)) $blockers[] = $definitions['blocker'] ?? 'allegro_category_parameters_unavailable';
            if (($allegroParameters['missing_required_parameters'] ?? []) !== []) $blockers[] = 'allegro_required_category_parameters_missing';
        }
        if ($channel === 'ovoko' && blank($part->car_id) && ! is_array($part->vehicle_snapshot)) $warnings[] = 'missing_donor_vehicle_data';
        if ($channel === 'ovoko' && ! $this->hasCompleteDimensions($part)) $warnings[] = 'missing_ovoko_dimensions';

        $blockers = array_values(array_unique($blockers));
        $ready = $blockers === [];

        return [
            'ok' => true,
            'ready' => $ready,
            'part_id' => $part->id,
            'channel' => $channel,
            'part' => ['name' => $part->name, 'part_number' => $part->part_number, 'sku' => $part->sku, 'status' => $part->status, 'quantity' => $part->quantity, 'needs_listing' => (bool) $part->needs_listing, 'needs_review' => (bool) ($part->needs_review ?? false)],
            'visibility' => ['storefront_visible' => $visible, 'blocked_reason' => $visible ? null : 'storefront_not_visible'],
            'price' => $channel === 'ovoko' ? ['ovoko_price' => $price, 'currency' => 'PLN'] : ['allegro_price' => $price, 'fallback_price' => is_numeric($part->price) ? (float) $part->price : null, 'currency' => 'PLN'],
            'category' => ['local_category_id' => $part->category_id, 'local_category_path' => $part->category?->category_path ?? $part->category?->name, 'marketplace_category_mapping_exists' => (bool) $mapping, 'external_category_id' => $mapping?->external_category_id, 'is_blocked' => (bool) ($mapping?->is_blocked ?? false), 'block_reason' => $mapping?->block_reason],
            'images' => $images,
            'description' => ['has_name' => filled($part->name), 'has_description' => $description !== '', 'description_length' => mb_strlen($description)],
            'existing_listing' => $existing,
            'can_create' => $ready && ! $existing['exists'],
            'can_update' => $ready && $existing['exists'],
            'blockers' => $blockers,
            'warnings' => array_values(array_unique($warnings)),
            'allegro_parameters' => $allegroParameters,
            'allegro_sales_settings' => $allegroSalesSettings,
            'gpsr_diagnostics' => $channel === 'allegro_main' ? $this->allegroGpsrDiagnostics($this->account($channel)) : null,
            'will_make_marketplace_request' => false,
        ];
    }

    private function payloadFor(Part $part, string $channel, array $readiness): array
    {
        $description = (string) (($part->description ?: $part->short_description) ?? '');
        $common = [
            'sku' => $part->sku,
            'part_number' => $part->part_number,
            'category' => ['id' => $readiness['category']['external_category_id'], 'local_id' => $part->category_id, 'local_path' => $readiness['category']['local_category_path']],
            'price' => ['amount' => $channel === 'ovoko' ? $readiness['price']['ovoko_price'] : $readiness['price']['allegro_price'], 'currency' => 'PLN'],
            'quantity' => (int) $part->quantity,
            'images' => $readiness['images']['public_urls_sample'],
            'image_urls' => $readiness['images']['public_urls_sample'],
            'dimensions' => $this->dimensionsPayload($part),
            'description' => $description,
            'existing_listing' => $readiness['existing_listing'],
            'local_mapping' => ['part_id' => $part->id, 'marketplace_listing_id' => $readiness['existing_listing']['marketplace_listing_id']],
        ];

        if ($channel === 'ovoko') {
            return $common + ['title' => $part->name, 'donor_vehicle' => ['car_id' => $part->car_id, 'snapshot' => $part->vehicle_snapshot]];
        }

        $allegro = $readiness['allegro_parameters'] ?? [];
        $allegroDescription = app(AllegroDescriptionBuilder::class)->build($part, $common['images']);

        $productNameDiagnostics = $this->allegroProductNameDiagnostics($part);

        return array_merge($common, $productNameDiagnostics, ['name' => $part->name, 'stock' => ['available' => (int) $part->quantity, 'quantity' => (int) $part->quantity], 'description' => $allegroDescription['description'], 'allegro_description_diagnostics' => $allegroDescription['diagnostics'], 'parameters' => $allegro['payload_parameters'] ?? $allegro['offer_parameters'] ?? [], 'productSet' => [$this->allegroProductSetPreview($allegro, $channel, $productNameDiagnostics['product_name'], $common['image_urls'][0] ?? null)], 'payments' => $allegro['payments'] ?? [], 'allegro_parameter_diagnostics' => ['productSet[0].product.parameters' => $allegro['product_parameter_diagnostics'] ?? [], 'parameters' => $allegro['offer_parameter_diagnostics'] ?? [], 'payments' => $allegro['payment_diagnostics'] ?? [], 'all' => $allegro['parameter_source_diagnostics'] ?? []], 'allegro_payment_diagnostics' => $allegro['payment_diagnostics'] ?? [], 'allegro_parameters' => $allegro, 'allegro_product_parameters' => $allegro['product_parameters'] ?? [], 'allegro_offer_parameters' => $allegro['offer_parameters'] ?? [], 'missing_required_parameters' => $allegro['missing_required_parameters'] ?? [], 'unmapped_parameters' => $allegro['unmapped_parameters'] ?? [], 'parameter_definitions_source' => $allegro['parameter_definitions_source'] ?? 'none', 'will_make_marketplace_request' => false, 'shipping' => $this->accountSetting($channel, 'shipping'), 'payment' => $this->accountSetting($channel, 'payment'), 'return' => $this->accountSetting($channel, 'return'), 'delivery' => ['shippingRates' => ['id' => data_get($readiness, 'allegro_sales_settings.shippingRates.id')]], 'afterSalesServices' => array_filter(['returnPolicy' => ['id' => data_get($readiness, 'allegro_sales_settings.returnPolicy.id')], 'impliedWarranty' => ['id' => data_get($readiness, 'allegro_sales_settings.impliedWarranty.id')], 'warranty' => ['id' => data_get($readiness, 'allegro_sales_settings.warranty.id')]], fn ($row) => filled($row['id'] ?? null)), 'allegro_sales_settings' => $readiness['allegro_sales_settings'] ?? null, 'gpsr_diagnostics' => $readiness['gpsr_diagnostics'] ?? $this->allegroGpsrDiagnostics($this->account($channel)), 'diagnostics' => ['allegro_product_name' => $productNameDiagnostics]]);
    }

    private function coverageFor(Request $request, string $channel): array
    {
        $sampleLimit = max(1, min(100, (int) $request->query('sample_limit', 20)));
        $counts = ['ready_count'=>0,'not_ready_count'=>0,'existing_listing_count'=>0,'would_create_count'=>0,'would_update_count'=>0,'blocked_count'=>0,'missing_price_count'=>0,'missing_category_mapping_count'=>0,'blocked_category_count'=>0,'missing_images_count'=>0,'missing_description_count'=>0,'missing_required_marketplace_fields_count'=>0];
        $samples = ['sample_ready_parts'=>[],'sample_not_ready_parts'=>[],'sample_existing_listing_parts'=>[],'sample_blocked_parts'=>[]];
        $byBlocker = [];
        $scanned = 0;

        foreach ($this->partsQuery($request)->get() as $part) {
            $scanned++;
            $r = $this->readinessFor($part, $channel);
            $action = $this->plannedAction($r);
            $counts[$r['ready'] ? 'ready_count' : 'not_ready_count']++;
            if ($r['existing_listing']['exists']) $counts['existing_listing_count']++;
            if ($action === 'create') $counts['would_create_count']++;
            if ($action === 'update') $counts['would_update_count']++;
            if ($action === 'blocked') $counts['blocked_count']++;
            foreach ($r['blockers'] as $b) { $byBlocker[$b] = ($byBlocker[$b] ?? 0) + 1; }
            if (in_array('missing_price', $r['blockers'], true)) $counts['missing_price_count']++;
            if (in_array('missing_category_mapping', $r['blockers'], true) || in_array('missing_external_category_id', $r['blockers'], true)) $counts['missing_category_mapping_count']++;
            if (in_array('blocked_category', $r['blockers'], true)) $counts['blocked_category_count']++;
            if (in_array('missing_images', $r['blockers'], true)) $counts['missing_images_count']++;
            if (in_array('missing_description', $r['blockers'], true)) $counts['missing_description_count']++;
            if ($r['blockers'] !== []) $counts['missing_required_marketplace_fields_count']++;
            $row = ['part_id' => $part->id, 'ready' => $r['ready'], 'planned_action' => $action, 'blockers' => $r['blockers']];
            if ($r['ready'] && count($samples['sample_ready_parts']) < $sampleLimit) $samples['sample_ready_parts'][] = $row;
            if (! $r['ready'] && count($samples['sample_not_ready_parts']) < $sampleLimit) $samples['sample_not_ready_parts'][] = $row;
            if ($r['existing_listing']['exists'] && count($samples['sample_existing_listing_parts']) < $sampleLimit) $samples['sample_existing_listing_parts'][] = $row;
            if ($action === 'blocked' && count($samples['sample_blocked_parts']) < $sampleLimit) $samples['sample_blocked_parts'][] = $row;
        }

        return ['ok'=>true,'dry_run'=>true,'channel'=>$channel,'parts_scanned_count'=>$scanned,'parts_eligible_count'=>$scanned] + $counts + ['count_by_blocker'=>$byBlocker] + $samples + ['blockers'=>[],'warnings'=>['dry_run_only_no_marketplace_writes']];
    }

    private function partsQuery(Request $request): Builder
    {
        $query = Part::query()->with(['images','category','marketplaceListings','car'])->limit(max(1, min(1000, (int) $request->query('limit', 100))))->offset(max(0, (int) $request->query('offset', 0)));
        if ($request->boolean('storefront_visible_only', true)) $query->where('is_visible_storefront', true);
        if (! $request->boolean('include_needs_listing', false)) $query->where(function ($q) { $q->where('needs_listing', false)->orWhereNull('needs_listing'); });
        if (! $request->boolean('include_archived', false)) $query->where('status', '!=', 'archived');
        if (! $request->boolean('include_needs_review', false)) $query->where(function ($q) { $q->where('needs_review', false)->orWhereNull('needs_review'); });
        if (! $request->boolean('include_existing_listings', false) && Schema::hasTable('marketplace_listings')) {
            $channel = $this->channel($request);
            $marketplaces = $channel === 'allegro_main' ? ['allegro_main', 'allegro'] : [$this->marketplace($channel)];
            $query->whereDoesntHave('marketplaceListings', fn ($listingQuery) => $listingQuery->whereIn('marketplace', $marketplaces));
        }
        return $query->orderBy('id');
    }

    private function hasCompleteDimensions(Part $part): bool { foreach (['weight_kg', 'length_cm', 'width_cm', 'height_cm'] as $field) { if (! is_numeric($part->{$field} ?? null) || (float) $part->{$field} <= 0) return false; } return true; }
    private function dimensionsPayload(Part $part): array { return ['weight_kg' => is_numeric($part->weight_kg ?? null) ? (float) $part->weight_kg : null, 'length_cm' => is_numeric($part->length_cm ?? null) ? (float) $part->length_cm : null, 'width_cm' => is_numeric($part->width_cm ?? null) ? (float) $part->width_cm : null, 'height_cm' => is_numeric($part->height_cm ?? null) ? (float) $part->height_cm : null]; }
    private function plannedAction(array $readiness): string { if (($readiness['blockers'] ?? []) !== []) return 'blocked'; return ($readiness['existing_listing']['exists'] ?? false) ? 'update' : 'create'; }
    private function channel(Request $request): string { return (string) $request->query('channel', 'ovoko'); }
    private function validToken(Request $request): bool { return hash_equals(self::TOKEN, (string) $request->query('token', '')); }
    private function invalidTokenResponse(): JsonResponse { return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403); }
    private function invalidChannelResponse(string $channel): JsonResponse { return response()->json(['ok' => false, 'channel' => $channel, 'blockers' => ['unsupported_channel']], 422); }
    private function part(int $id): ?Part { return Part::query()->with(['images','category','marketplaceListings','car'])->find($id); }
    private function price(Part $part, string $channel): ?float { $value = $channel === 'ovoko' ? $part->ovoko_price : ($part->allegro_price ?? $part->price); return is_numeric($value) ? (float) $value : null; }
    private function marketplace(string $channel): string { return $channel === 'allegro_main' ? 'allegro' : $channel; }
    private function categoryMapping(Part $part, string $channel): ?MarketplaceCategoryMapping { if (! Schema::hasTable('marketplace_category_mappings') || ! $part->category_id) return null; $channels = $channel === 'allegro_main' ? ['allegro_main','allegro'] : ['ovoko']; return MarketplaceCategoryMapping::query()->where('local_category_id', $part->category_id)->whereIn('channel', $channels)->orderByRaw('case when channel = ? then 0 else 1 end', [$channel])->first(); }
    private function imageUrls(Part $part): array { $selection = app(MarketplaceImageSelectionService::class)->selectForPart($part, 10); return ['count' => $part->images->count(), 'public_urls_sample' => $selection['urls'], 'missing_public_images_count' => max(0, $part->images->count() - count($selection['urls'])), 'diagnostics' => $selection['diagnostics']]; }
    private function existingListing(Part $part, string $channel): array { $marketplaces = $channel === 'allegro_main' ? ['allegro_main', 'allegro'] : [$this->marketplace($channel)]; $listing = $part->marketplaceListings->first(fn ($item) => in_array($item->marketplace, $marketplaces, true)); return ['exists' => (bool) $listing, 'external_id' => $listing?->external_offer_id ?? $listing?->external_listing_id, 'status' => $listing?->status, 'marketplace_listing_id' => $listing?->id]; }
    private function account(string $channel): ?MarketplaceAccount { $code = $channel === 'ovoko' ? 'ovoko_main' : $channel; return Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', $code)->first() : null; }

    /** @return array<string, mixed> */
    private function allegroProductNameDiagnostics(Part $part): array
    {
        $partTitle = trim((string) ($part->name ?? ''));
        $mainPartCode = trim((string) (($part->part_number ?? null) ?: ($part->oem_number ?? null) ?: ($part->manufacturer_code ?? null) ?: ($part->sku ?? '')));
        $productName = $partTitle;
        $source = 'part_title';
        $fallbackUsed = false;

        if ($productName === '') {
            $fallbackUsed = true;
            $pieces = array_filter([data_get($part->vehicle_snapshot, 'make'), data_get($part->vehicle_snapshot, 'model'), $part->category?->name, $mainPartCode], fn ($value) => filled($value));
            $productName = trim(implode(' ', array_map(fn ($value) => trim((string) $value), $pieces)));
            $source = $productName !== '' && $productName !== $mainPartCode ? 'vehicle_category_code_fallback' : 'main_part_code_final_fallback';
        }

        if ($productName === '') {
            $productName = $mainPartCode;
            $source = 'main_part_code_final_fallback';
        }

        return ['product_name' => $productName, 'product_name_source' => $source, 'product_name_length' => mb_strlen($productName), 'part_title' => $partTitle, 'main_part_code' => $mainPartCode, 'product_name_fallback_used' => $fallbackUsed];
    }

    private function allegroProductSetPreview(array $allegro, string $channel, string $productName, ?string $mainImageUrl = null): array
    {
        $product = ['name' => trim($productName), 'parameters' => $allegro['product_parameters'] ?? []];
        if (filled($mainImageUrl)) $product['images'] = [trim($mainImageUrl)];
        $productSet = ['product' => $product];
        $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', $channel)->first() : null;
        $settings = is_array($account?->api_settings) ? $account->api_settings : [];
        $responsibleProducer = $this->allegroResponsibleProducer($settings);
        $safetyInformation = $this->allegroSafetyInformation($settings);
        if ($responsibleProducer !== null) $productSet['responsibleProducer'] = $responsibleProducer;
        if ($safetyInformation !== null) $productSet['safetyInformation'] = $safetyInformation;
        return $productSet;
    }

    private function allegroGpsrDiagnostics(?MarketplaceAccount $account): array
    {
        $settings = is_array($account?->api_settings) ? $account->api_settings : [];
        return [
            'responsibleProducer' => $this->allegroResponsibleProducer($settings) !== null ? 'configured' : 'missing: configure marketplace_accounts.api_settings.gpsr.responsibleProducer',
            'safetyInformation' => $this->allegroSafetyInformation($settings) !== null ? 'configured_or_defaulted' : 'missing: configure marketplace_accounts.api_settings.gpsr.safetyInformation',
            'safetyInformation_source' => filled(data_get($settings, 'gpsr.safetyInformation') ?? ($settings['safetyInformation'] ?? null) ?? data_get($settings, 'productSet.0.safetyInformation')) ? 'api_settings' : 'default_used_parts_text',
        ];
    }

    private function allegroGpsrBlockers(?MarketplaceAccount $account): array
    {
        $settings = is_array($account?->api_settings) ? $account->api_settings : [];
        return array_values(array_filter([
            $this->allegroResponsibleProducer($settings) === null ? 'allegro_gpsr_responsibleProducer' : null,
            $this->allegroSafetyInformation($settings) === null ? 'allegro_gpsr_safetyInformation' : null,
        ]));
    }

    private function allegroResponsibleProducer(array $settings): ?array
    {
        $value = $settings['responsibleProducer'] ?? data_get($settings, 'gpsr.responsibleProducer') ?? data_get($settings, 'productSet.0.responsibleProducer');
        if (! is_array($value)) return null;
        $type = strtoupper((string) ($value['type'] ?? ''));
        if ($type === 'ID' && filled($value['id'] ?? null)) return ['type' => 'ID', 'id' => (string) $value['id']];
        if ($type === 'NAME' && filled($value['name'] ?? null)) return ['type' => 'NAME', 'name' => trim((string) $value['name'])];
        return null;
    }

    private function allegroSafetyInformation(array $settings): ?array
    {
        $value = $settings['safetyInformation'] ?? data_get($settings, 'gpsr.safetyInformation') ?? data_get($settings, 'productSet.0.safetyInformation');
        if (is_array($value) && strtoupper((string) ($value['type'] ?? '')) === 'TEXT' && filled($value['description'] ?? null)) {
            return ['type' => 'TEXT', 'description' => trim(strip_tags((string) $value['description']))];
        }
        if (is_string($value) && trim(strip_tags($value)) !== '') return ['type' => 'TEXT', 'description' => trim(strip_tags($value))];
        return ['type' => 'TEXT', 'description' => self::DEFAULT_SAFETY_INFORMATION];
    }

    private function accountSetting(string $channel, string $key): mixed { $code = $channel === 'ovoko' ? 'ovoko_main' : $channel; $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', $code)->first() : null; return data_get($account?->api_settings ?? [], $key); }
}
