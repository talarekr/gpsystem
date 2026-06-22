<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Marketplace\Api\MarketplaceApiManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketplaceApiFoundationController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __construct(private MarketplaceApiManager $manager) {}

    public function readiness(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();
        $data = [];
        foreach (MarketplaceApiManager::CHANNELS as $channel) $data[$channel] = $this->manager->client($channel)->getAccountReadiness();
        return response()->json(['ok' => true, 'channels' => $data]);
    }

    public function testConnection(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();
        $channel = (string) $request->query('channel');
        if (! in_array($channel, MarketplaceApiManager::CHANNELS, true)) return response()->json(['ok' => false, 'error_message_safe' => 'Unsupported channel.'], 422);
        return response()->json($this->manager->client($channel)->testConnection());
    }

    public function fetchOffersSample(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();
        $channel = (string) $request->query('channel');
        if (! in_array($channel, MarketplaceApiManager::CHANNELS, true)) return response()->json(['ok' => false, 'error_message_safe' => 'Unsupported channel.'], 422);
        return response()->json($this->manager->client($channel)->fetchOffersSample((int) $request->integer('limit', 10)));
    }

    public function priceStrategy(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();
        $part = Part::query()->find($request->integer('part_id'));
        if (! $part) return response()->json(['ok' => false, 'blockers' => ['Part not found.']], 404);
        $base = $part->price;
        $channels = [];
        foreach (['storefront', 'allegro_main', 'ovoko', 'ebay_de', 'ebay_fr'] as $channel) {
            $stored = $this->storedChannelPrice($part, $channel);
            $calculated = in_array($channel, ['ebay_de', 'ebay_fr'], true) && $base !== null ? round((float) $base * 1.25, 2) : $base;
            $source = match (true) {
                $channel === 'ovoko' && $stored === null => 'missing',
                $stored !== null => 'channel_override',
                $channel === 'storefront' || $channel === 'allegro_main' => 'base_price',
                default => 'calculated',
            };
            $channels[$channel] = ['calculated_price' => $channel === 'ovoko' ? null : $calculated, 'stored_channel_price' => $stored, 'price_source' => $source, 'warnings' => $source === 'missing' ? ['missing_channel_price'] : [], 'blockers' => []];
        }
        return response()->json(['ok' => true, 'part_id' => $part->id, 'base_price' => $base, 'channels' => $channels]);
    }

    public function stockReadiness(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();
        $part = Part::query()->find($request->integer('part_id'));
        if (! $part) return response()->json(['ok' => false, 'blockers' => ['Part not found.']], 404);
        $listings = MarketplaceListing::query()->where('part_id', $part->id)->get()->map(fn ($l) => ['channel' => $l->marketplace, 'external_offer_id' => $l->external_offer_id, 'marketplace_quantity' => $l->quantity, 'marketplace_status' => $l->status, 'quantity_diff' => $l->quantity === null ? null : $l->quantity - $part->quantity, 'status_diff' => $l->status !== null && $l->status !== $part->status])->values();
        return response()->json(['ok' => true, 'part_id' => $part->id, 'local_quantity' => $part->quantity, 'local_status' => $part->status, 'marketplace_samples' => $listings, 'read_only' => true]);
    }

    public function linkingHealth(Request $request): JsonResponse
    {
        if (! $this->validToken($request)) return $this->invalidToken();
        $data = [];
        foreach (['ovoko', 'allegro', 'ebay'] as $marketplace) {
            $q = MarketplaceListing::query()->where('marketplace', $marketplace);
            $dupeOffers = (clone $q)->whereNotNull('external_offer_id')->select('external_offer_id', DB::raw('count(*) as c'))->groupBy('external_offer_id')->having('c', '>', 1)->pluck('external_offer_id');
            $dupeParts = (clone $q)->whereNotNull('part_id')->select('part_id', DB::raw('count(*) as c'))->groupBy('part_id')->having('c', '>', 1)->pluck('part_id');
            $data[$marketplace] = ['listings_total' => (clone $q)->count(), 'mapped_to_existing_part_count' => (clone $q)->whereHas('part')->count(), 'missing_part_count' => (clone $q)->whereNotNull('part_id')->whereDoesntHave('part')->count(), 'duplicate_offer_id_count' => $dupeOffers->count(), 'duplicate_part_id_count' => $dupeParts->count(), 'conflict_count' => $dupeOffers->count() + $dupeParts->count(), 'empty_external_offer_id_count' => (clone $q)->where(fn($x) => $x->whereNull('external_offer_id')->orWhere('external_offer_id', ''))->count(), 'sample_mapped' => (clone $q)->whereHas('part')->limit(5)->get(['id','part_id','external_offer_id']), 'sample_missing_part' => (clone $q)->whereNotNull('part_id')->whereDoesntHave('part')->limit(5)->get(['id','part_id','external_offer_id']), 'sample_conflicts' => $dupeOffers->take(5)->values(), 'sample_duplicates' => $dupeParts->take(5)->values(), 'warnings' => [], 'blockers' => []];
        }
        return response()->json(['ok' => true, 'channels' => ['ovoko' => $data['ovoko'], 'allegro_main' => $data['allegro'], 'ebay_de' => $data['ebay'], 'ebay_fr' => $data['ebay']]]);
    }

    private function storedChannelPrice(Part $part, string $channel): mixed { return match ($channel) { 'allegro_main' => $part->allegro_price, 'ebay_de', 'ebay_fr' => $part->ebay_price, default => null }; }
    private function validToken(Request $request): bool { return hash_equals(self::TOKEN, (string) $request->query('token', '')); }
    private function invalidToken(): JsonResponse { return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403); }
}
