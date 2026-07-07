<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Admin\PartMarketplaceStatusResolver;
use App\Services\Marketplace\Api\AllegroApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;
use Throwable;

class AllegroMarketplaceDiagnoseController extends Controller
{
    public function __invoke(Request $request, PartMarketplaceStatusResolver $resolver): JsonResponse|View
    {
        $input = (string) $request->query('part_id', $request->query('part_ids', ''));
        $partIds = $this->partIds($input);
        $offerId = trim((string) $request->query('offer_id', ''));
        $checkApi = $request->boolean('check_api');
        $results = $partIds === [] ? [] : $this->diagnose($partIds, $offerId, $checkApi, $resolver);

        $payload = [
            'read_only' => true,
            'marketplace_write' => false,
            'publishing_triggered' => false,
            'ending_triggered' => false,
            'links_deleted' => false,
            'local_status_changed' => false,
            'input' => $input,
            'offer_id' => $offerId,
            'check_api' => $checkApi,
            'part_ids' => $partIds,
            'results' => $results,
        ];

        if ($request->expectsJson() || $request->query('format') === 'json') {
            return response()->json($payload);
        }

        return view('admin.tools.marketplace.allegro-diagnose', $payload);
    }

    /** @return array<int, int> */
    private function partIds(string $input): array
    {
        preg_match_all('/\d+/', $input, $matches);

        return collect($matches[0] ?? [])->map(fn (string $id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique()->values()->all();
    }

    /**
     * @param array<int, int> $partIds
     * @return array<int, array<string, mixed>>
     */
    private function diagnose(array $partIds, string $explicitOfferId, bool $checkApi, PartMarketplaceStatusResolver $resolver): array
    {
        $parts = Part::query()
            ->with(['marketplaceListings' => fn ($query) => $query->whereIn('marketplace', ['allegro', 'allegro_main'])->with('account')->orderBy('id')])
            ->whereIn('id', $partIds)
            ->get()
            ->keyBy('id');

        return collect($partIds)->map(function (int $partId) use ($parts, $explicitOfferId, $checkApi, $resolver): array {
            /** @var Part|null $part */
            $part = $parts->get($partId);

            if (! $part) {
                return [
                    'part_id' => $partId,
                    'found' => false,
                    'part' => null,
                    'marketplace_listings' => [],
                    'resolver_allegro' => ['has_link' => false, 'url' => null, 'is_active' => false, 'icon' => 'x', 'display_icon' => '✕', 'reason' => 'part_not_found'],
                    'allegro_api' => $checkApi ? $this->apiOfferStatus($explicitOfferId) : ['checked' => false, 'offer_id' => $explicitOfferId ?: null],
                ];
            }

            $listingRows = $part->marketplaceListings->map(fn (MarketplaceListing $listing): array => $this->listing($listing))->values()->all();
            $resolverRow = collect($resolver->rowsForPart($part))->firstWhere('key', 'allegro') ?? [];
            $resolvedOfferId = $this->resolvedOfferId($explicitOfferId, $resolverRow, $listingRows);

            return [
                'part_id' => $partId,
                'found' => true,
                'part' => [
                    'id' => $part->id,
                    'status' => $part->status,
                    'quantity' => $part->quantity,
                    'adminLocalAvailability' => $part->adminLocalAvailability(),
                ],
                'marketplace_listings' => $listingRows,
                'resolver_allegro' => [
                    'has_link' => (bool) ($resolverRow['has_link'] ?? false),
                    'url' => $resolverRow['url'] ?? null,
                    'is_active' => (bool) ($resolverRow['is_active'] ?? false),
                    'icon' => $resolverRow['icon'] ?? null,
                    'display_icon' => $resolverRow['display_icon'] ?? null,
                    'reason' => $resolverRow['reason'] ?? null,
                ],
                'allegro_api' => $checkApi ? $this->apiOfferStatus($resolvedOfferId) : ['checked' => false, 'offer_id' => $resolvedOfferId ?: null],
            ];
        })->all();
    }

    /** @param array<string, mixed> $resolverRow @param array<int, array<string, mixed>> $listingRows */
    private function resolvedOfferId(string $explicitOfferId, array $resolverRow, array $listingRows): ?string
    {
        foreach ([$explicitOfferId, Arr::get($resolverRow, 'external_offer_id'), Arr::get($listingRows, '0.external_offer_id'), Arr::get($listingRows, '0.external_listing_id')] as $value) {
            $id = trim((string) ($value ?? ''));
            if ($id !== '') return $id;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function listing(MarketplaceListing $listing): array
    {
        return [
            'id' => $listing->id,
            'marketplace' => $listing->marketplace,
            'channel' => $listing->account?->code,
            'status' => $listing->status,
            'sync_status' => $listing->sync_status,
            'match_status' => $listing->match_status,
            'external_offer_id' => $listing->external_offer_id,
            'external_listing_id' => $listing->external_listing_id,
            'url' => $listing->url,
            'last_api_status' => $listing->last_api_status,
            'last_error' => $listing->last_error,
        ];
    }

    /** @return array<string, mixed> */
    private function apiOfferStatus(?string $offerId): array
    {
        if (! filled($offerId)) {
            return ['checked' => true, 'exists' => false, 'offer_id' => null, 'error' => 'missing_offer_id'];
        }

        try {
            $account = MarketplaceAccount::query()->where('code', 'allegro_main')->first() ?: MarketplaceAccount::query()->where('marketplace', 'allegro')->first();
            $response = (new AllegroApiClient('allegro_main', $account))->productOffer($offerId);
            $json = $response['json'] ?? [];
            $publicationStatus = Arr::get($json, 'publication.status');

            return [
                'checked' => true,
                'offer_id' => $offerId,
                'exists' => (bool) ($response['ok'] ?? false),
                'http_status' => $response['http_status'] ?? null,
                'publication_status' => $publicationStatus,
                'stock_available' => Arr::get($json, 'stock.available'),
                'selling_mode' => Arr::get($json, 'sellingMode'),
                'is_active' => strtoupper((string) $publicationStatus) === 'ACTIVE',
                'is_ended' => strtoupper((string) $publicationStatus) === 'ENDED',
                'request_id' => $response['request_id'] ?? null,
                'error' => ($response['ok'] ?? false) ? null : ($json['message'] ?? $json['error'] ?? 'allegro_api_lookup_failed'),
            ];
        } catch (Throwable $exception) {
            return ['checked' => true, 'offer_id' => $offerId, 'exists' => false, 'error' => $exception::class.': '.$exception->getMessage()];
        }
    }
}
