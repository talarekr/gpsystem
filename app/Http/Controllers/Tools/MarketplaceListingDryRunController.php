<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\AllegroCategoryParametersService;
use App\Services\Marketplace\AllegroOfferParametersBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;

class MarketplaceListingDryRunController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const CHANNELS = ['ovoko', 'allegro_main'];

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
        $plannedAction = $this->plannedAction($readiness);

        return response()->json([
            'ok' => true,
            'dry_run' => true,
            'part_id' => $part->id,
            'channel' => $channel,
            'planned_action' => $plannedAction,
            'would_send' => false,
            'api_mode' => 'local_read_only_dry_run',
            'payload' => $plannedAction === 'blocked' ? null : $payload,
            'payload_summary' => [
                'title' => $payload['name'] ?? $payload['title'] ?? null,
                'price' => $payload['price']['amount'] ?? null,
                'currency' => $payload['price']['currency'] ?? 'PLN',
                'quantity' => $payload['stock']['quantity'] ?? $payload['quantity'] ?? null,
                'category_id' => $payload['category']['id'] ?? null,
                'images_count' => count($payload['images'] ?? []),
                'existing_listing' => (bool) ($readiness['existing_listing']['exists'] ?? false),
            ],
            'blockers' => $readiness['blockers'],
            'warnings' => $readiness['warnings'],
        ]);
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
        return $common + ['name' => $part->name, 'stock' => ['available' => (int) $part->quantity, 'quantity' => (int) $part->quantity], 'parameters' => $allegro['offer_parameters'] ?? [], 'productSet' => [['product' => ['parameters' => $allegro['product_parameters'] ?? []]]], 'allegro_parameters' => $allegro, 'allegro_product_parameters' => $allegro['product_parameters'] ?? [], 'allegro_offer_parameters' => $allegro['offer_parameters'] ?? [], 'missing_required_parameters' => $allegro['missing_required_parameters'] ?? [], 'unmapped_parameters' => $allegro['unmapped_parameters'] ?? [], 'parameter_definitions_source' => $allegro['parameter_definitions_source'] ?? 'none', 'will_make_marketplace_request' => false, 'shipping' => $this->accountSetting($channel, 'shipping'), 'payment' => $this->accountSetting($channel, 'payment'), 'return' => $this->accountSetting($channel, 'return')];
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
    private function imageUrls(Part $part): array { $urls = $part->images->map(fn ($image) => method_exists($image, 'listingUrl') ? $image->listingUrl() : null); $public = $urls->filter()->values(); return ['count' => $part->images->count(), 'public_urls_sample' => $public->take(10)->all(), 'missing_public_images_count' => $urls->filter(fn ($url) => blank($url))->count()]; }
    private function existingListing(Part $part, string $channel): array { $marketplaces = $channel === 'allegro_main' ? ['allegro_main', 'allegro'] : [$this->marketplace($channel)]; $listing = $part->marketplaceListings->first(fn ($item) => in_array($item->marketplace, $marketplaces, true)); return ['exists' => (bool) $listing, 'external_id' => $listing?->external_offer_id ?? $listing?->external_listing_id, 'status' => $listing?->status, 'marketplace_listing_id' => $listing?->id]; }
    private function accountSetting(string $channel, string $key): mixed { $code = $channel === 'ovoko' ? 'ovoko_main' : $channel; $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', $code)->first() : null; return data_get($account?->api_settings ?? [], $key); }
}
