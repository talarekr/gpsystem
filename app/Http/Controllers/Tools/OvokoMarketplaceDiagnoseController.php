<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Services\Admin\PartMarketplaceStatusResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OvokoMarketplaceDiagnoseController extends Controller
{
    public function __invoke(Request $request, PartMarketplaceStatusResolver $resolver): JsonResponse|View
    {
        $input = (string) $request->query('part_id', $request->query('part_ids', ''));
        $partIds = $this->partIds($input);
        $results = $partIds === [] ? [] : $this->diagnose($partIds, $resolver);

        $payload = [
            'read_only' => true,
            'marketplace_write' => false,
            'relisting_triggered' => false,
            'input' => $input,
            'part_ids' => $partIds,
            'results' => $results,
        ];

        if ($request->expectsJson() || $request->query('format') === 'json') {
            return response()->json($payload);
        }

        return view('admin.tools.marketplace.ovoko-diagnose', $payload);
    }

    /**
     * @return array<int, int>
     */
    private function partIds(string $input): array
    {
        preg_match_all('/\d+/', $input, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int, int> $partIds
     * @return array<int, array<string, mixed>>
     */
    private function diagnose(array $partIds, PartMarketplaceStatusResolver $resolver): array
    {
        $parts = Part::query()
            ->with(['marketplaceListings' => fn ($query) => $query->where('marketplace', 'ovoko')->orderBy('id')])
            ->whereIn('id', $partIds)
            ->get()
            ->keyBy('id');

        return collect($partIds)
            ->map(function (int $partId) use ($parts, $resolver): array {
                /** @var Part|null $part */
                $part = $parts->get($partId);

                if (! $part) {
                    return [
                        'part_id' => $partId,
                        'found' => false,
                        'status' => 'not_found',
                        'part' => null,
                        'marketplace_listings' => [],
                        'resolver' => [
                            'has_link' => false,
                            'url' => null,
                            'is_active' => false,
                            'icon' => 'x',
                            'display_icon' => '✕',
                            'reason' => 'part_not_found',
                            'title' => 'Part not found',
                        ],
                    ];
                }

                $ovokoRow = collect($resolver->rowsForPart($part))->firstWhere('key', 'ovoko') ?? [];

                return [
                    'part_id' => $partId,
                    'found' => true,
                    'status' => 'found',
                    'part' => [
                        'id' => $part->id,
                        'status' => $part->status,
                        'quantity' => $part->quantity,
                        'admin_local_availability' => $part->adminLocalAvailability(),
                        'needs_listing' => (bool) $part->needs_listing,
                    ],
                    'marketplace_listings' => $part->marketplaceListings
                        ->map(fn (MarketplaceListing $listing): array => $this->listing($listing))
                        ->all(),
                    'resolver' => [
                        'has_link' => (bool) ($ovokoRow['has_link'] ?? false),
                        'url' => $ovokoRow['url'] ?? null,
                        'is_active' => (bool) ($ovokoRow['is_active'] ?? false),
                        'icon' => $ovokoRow['icon'] ?? null,
                        'display_icon' => $ovokoRow['display_icon'] ?? null,
                        'reason' => $ovokoRow['reason'] ?? null,
                        'title' => $ovokoRow['title'] ?? null,
                    ],
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function listing(MarketplaceListing $listing): array
    {
        return [
            'id' => $listing->id,
            'marketplace' => $listing->marketplace,
            'status' => $listing->status,
            'sync_status' => $listing->sync_status,
            'match_status' => $listing->match_status,
            'last_api_status' => $listing->last_api_status,
            'last_error' => $listing->last_error,
            'external_offer_id' => $listing->external_offer_id,
            'external_listing_id' => $listing->external_listing_id,
            'url' => $listing->url,
            'resolved_listing_url' => $this->listingUrl($listing),
            'resolved_external_offer_id' => $this->externalOfferId($listing),
        ];
    }

    private function externalOfferId(MarketplaceListing $listing): ?string
    {
        foreach ([$listing->external_offer_id, $listing->external_listing_id] as $value) {
            $id = trim((string) ($value ?? ''));
            if ($id !== '') {
                return $id;
            }
        }

        return null;
    }

    private function listingUrl(MarketplaceListing $listing): ?string
    {
        $url = trim((string) ($listing->url ?? ''));

        return $url === '' ? null : $url;
    }
}
