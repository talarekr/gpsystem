<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\ApiIntegrationLogger;
use App\Services\Marketplace\OvokoListingUrlBackfillService;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OvokoListingUrlBackfillController extends Controller
{
    private function queryBool(Request $request, string $key, bool $default = false): bool
    {
        if (! $request->query->has($key)) {
            return $default;
        }

        return filter_var($request->query($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private const CONFIRMATION = 'ovoko-url-backfill';
    private const DEFAULT_BULK_LIMIT = 100;
    private const MAX_BULK_LIMIT = 6500;
    private const MAX_DIAGNOSTIC_LIMIT = 1000;

    public function __invoke(Request $request, OvokoListingUrlBackfillService $backfill, ApiIntegrationLogger $logger): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canAccessPanel(Filament::getPanel('admin')), 403);

        $isBrowserBackfill = $request->is('admin/tools/ovoko/backfill-links');
        $parsedApply = $this->queryBool($request, 'apply');
        $parsedForce = $this->queryBool($request, 'force');
        $parsedMissingOnly = $request->query->has('missing_only')
            ? $this->queryBool($request, 'missing_only', true)
            : $this->queryBool($request, 'only_missing', true);
        $parsedIncludeInactive = $this->queryBool($request, 'include_inactive');
        $parsedDebug = $this->queryBool($request, 'debug');
        $parsedReattach = $this->queryBool($request, 'reattach');
        $parsedPartId = $request->filled('part_id') ? (int) $request->query('part_id') : null;
        $parsedPartIds = $this->parsePartIds((string) $request->query('part_ids', ''));

        $apply = $isBrowserBackfill
            ? $parsedApply
            : (($parsedApply || $request->is('admin/tools/ovoko/listing-url-backfill'))
                && $request->query('confirm') === self::CONFIRMATION);

        if ($isBrowserBackfill) {
            $limit = max(1, min(self::MAX_BULK_LIMIT, (int) $request->query('limit', self::DEFAULT_BULK_LIMIT)));
            $offset = max(0, (int) $request->query('offset', $request->query('page') ? (((int) $request->query('page') - 1) * $limit) : 0));
            $result = $parsedPartIds !== []
                ? $backfill->runBrowserBackfillForPartIds(
                    partIds: $parsedPartIds,
                    apply: $apply,
                    force: $parsedForce,
                    missingOnly: $parsedMissingOnly,
                    limit: $limit,
                    includeInactive: $parsedIncludeInactive,
                    debug: $parsedDebug,
                    reattach: $parsedReattach,
                )
                : $backfill->runBrowserBackfill(
                    apply: $apply,
                    force: $parsedForce,
                    missingOnly: $parsedMissingOnly,
                    limit: $limit,
                    offset: $offset,
                    partId: $parsedPartId,
                    includeInactive: $parsedIncludeInactive,
                    debug: $parsedDebug,
                    reattach: $parsedReattach,
                );
        } elseif (! $request->filled('listing_id') && ! $request->filled('part_id')) {
            $limit = max(1, min(self::MAX_BULK_LIMIT, (int) $request->query('limit', self::DEFAULT_BULK_LIMIT)));
            $offset = max(0, (int) $request->query('offset', 0));
            $result = $backfill->runLocalGeneratedBulk(
                apply: $apply,
                limit: $limit,
                offset: $offset,
                onlyMissing: $request->boolean('only_missing'),
                includeExistingInvalid: $request->boolean('include_existing_invalid'),
            );
        } else {
            $limit = max(1, min(self::MAX_DIAGNOSTIC_LIMIT, (int) $request->query('limit', self::DEFAULT_BULK_LIMIT)));

            $result = $backfill->run(
                apply: $apply,
                force: $parsedForce,
                partId: $parsedPartId,
                limit: $limit,
                listingId: $request->filled('listing_id') ? (int) $request->query('listing_id') : null,
                maxPages: max(1, min(50, (int) $request->query('max_pages', 3))),
            );
        }

        if (! $apply && ($request->filled('listing_id') || $request->filled('part_id'))) {
            $logger->success('ovoko', 'ovoko_listing_url_diagnostic', 'Ovoko listing URL diagnostic dry-run completed.', [
                'marketplace_listing_id' => $request->filled('listing_id') ? (int) $request->query('listing_id') : null,
                'part_id' => $request->filled('part_id') ? (int) $request->query('part_id') : null,
                'request' => $request->only(['listing_id', 'part_id', 'limit', 'force', 'max_pages']),
                'response' => ['summary' => $result['summary'], 'results' => $result['results']],
            ]);
        }

        return response()->json([
            'ok' => true,
            'mode' => $result['mode'],
            'dry_run' => ! $apply,
            'requested_part_id' => $request->query('part_id'),
            'requested_part_ids' => $request->query('part_ids'),
            'parsed_part_id' => $parsedPartId,
            'parsed_part_ids' => $parsedPartIds,

            'parsed_force' => $parsedForce,
            'parsed_missing_only' => $parsedMissingOnly,
            'parsed_apply' => $parsedApply,
            'parsed_limit' => $limit ?? null,
            'parsed_offset' => $offset ?? null,
            'debug' => $parsedDebug,
            'include_inactive' => $parsedIncludeInactive,
            'reattach_requested' => $parsedReattach,
            'apply_requested' => $parsedApply,
            'apply_confirmed' => $apply,
            'force' => $parsedForce,
            'missing_only' => $parsedMissingOnly,
            'only_missing' => $request->boolean('only_missing'),
            'only_missing_semantics' => $result['only_missing_semantics'] ?? null,
            'limit_requested' => $result['limit_requested'] ?? $limit,
            'limit_applied' => $result['limit_applied'] ?? $limit,
            'offset_requested' => $result['offset_requested'] ?? 0,
            'offset_applied' => $result['offset_applied'] ?? 0,
            'first_inspected_listing_id' => $result['first_inspected_listing_id'] ?? null,
            'last_inspected_listing_id' => $result['last_inspected_listing_id'] ?? null,
            'inspected_listing_ids_sample' => $result['inspected_listing_ids_sample'] ?? [],
            'total_ovoko_listings_count' => $result['total_ovoko_listings_count'] ?? null,
            'total_ovoko_missing_url_count' => $result['total_ovoko_missing_url_count'] ?? null,
            'include_existing_invalid' => $request->boolean('include_existing_invalid'),
            'local_update_only' => $apply,
            'ovoko_write' => false,
            'crm_import_part' => false,
            'publish' => false,
            'stock_order_price_sync' => false,
            'summary' => $result['summary'],
            'results' => $result['results'],
            'warnings' => $result['warnings'],
            'debug_info' => $result['debug'] ?? null,
            'example_urls' => $parsedPartIds !== [] ? $this->exampleUrls($request, $parsedPartIds, $parsedForce, $parsedMissingOnly, $parsedIncludeInactive, $parsedReattach) : null,
        ]);
    }

    /** @return array<int,int> */
    private function parsePartIds(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $id): int => (int) trim($id),
            explode(',', $value)
        ), fn (int $id): bool => $id > 0)));
    }

    /** @param array<int,int> $partIds */
    private function exampleUrls(Request $request, array $partIds, bool $force, bool $missingOnly, bool $includeInactive, bool $reattach): array
    {
        $base = $request->url();
        $common = [
            'part_ids' => implode(',', $partIds),
            'missing_only' => $missingOnly ? 1 : 0,
            'force' => $force ? 1 : 0,
            'include_inactive' => $includeInactive ? 1 : 0,
        ];
        if ($reattach) {
            $common['reattach'] = 1;
        }

        return [
            'dry_run' => $base.'?'.http_build_query($common + ['apply' => 0]),
            'apply_without_reattach' => $base.'?'.http_build_query(($common + ['reattach' => 0, 'apply' => 1])),
            'apply_with_reattach' => $base.'?'.http_build_query(($common + ['reattach' => 1, 'apply' => 1])),
        ];
    }
}
