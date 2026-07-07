<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Admin\PartMarketplaceStatusResolver;
use App\Services\Marketplace\Api\AllegroApiClient;
use App\Services\Marketplace\AllegroListingStatusRefreshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            'queue_diagnostics' => $this->queueDiagnostics(),
            'part_ids' => $partIds,
            'results' => $results,
        ];

        if ($request->expectsJson() || $request->query('format') === 'json') {
            return response()->json($payload);
        }

        return view('admin.tools.marketplace.allegro-diagnose', $payload);
    }

    public function refreshPending(Request $request, AllegroListingStatusRefreshService $service): JsonResponse|View
    {
        $apply = $request->isMethod('post') && $request->boolean('apply');
        $limit = max(1, min(100, (int) $request->input('limit', 25)));
        $olderThanMinutes = max(0, (int) $request->input('older_than_minutes', 2));

        $candidates = $this->pendingRefreshQuery($olderThanMinutes)->limit($limit)->get();
        $rows = $candidates->map(fn (MarketplaceListing $listing): array => [
            'listing_id' => $listing->id,
            'part_id' => $listing->part_id,
            'offer_id' => $listing->external_offer_id,
            'status' => $listing->status,
            'last_api_status' => $listing->last_api_status,
            'updated_at' => optional($listing->updated_at)->toISOString(),
            'would_refresh' => true,
        ])->values()->all();

        $applied = [];
        if ($apply) {
            foreach ($candidates as $listing) {
                $applied[] = $service->refresh($listing, null, true);
            }
        }

        $payload = [
            'ok' => true,
            'mode' => $apply ? 'apply' : 'preview',
            'read_only' => ! $apply,
            'marketplace_write' => false,
            'publishing_triggered' => false,
            'ending_triggered' => false,
            'links_deleted' => false,
            'part_status_changed' => false,
            'filters' => ['marketplace' => 'allegro', 'status' => 'publication_pending', 'external_offer_id_required' => true, 'older_than_minutes' => $olderThanMinutes, 'limit' => $limit],
            'count' => count($rows),
            'rows' => $rows,
            'applied' => $applied,
        ];

        if ($request->expectsJson() || $request->query('format') === 'json') {
            return response()->json($payload);
        }

        return view('admin.tools.marketplace.allegro-pending-refresh', $payload);
    }


    public function refresh(Request $request, AllegroListingStatusRefreshService $service): JsonResponse
    {
        $listing = $this->listingForRefresh($request);
        if (! $listing) {
            return response()->json(['ok' => false, 'message' => 'No local Allegro listing found for the given part_id/offer_id.'], 404);
        }

        $result = $service->refresh($listing, trim((string) $request->input('offer_id', '')) ?: null);

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'write' => 'local_marketplace_listing_status_only',
            'marketplace_write' => false,
            'publishing_triggered' => false,
            'ending_triggered' => false,
            'links_deleted' => false,
            'part_status_changed' => false,
            'result' => $result,
        ], ($result['ok'] ?? false) ? 200 : 422);
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
            $postPublishRefresh = $this->postPublishRefreshDiagnostics($partId, $listingRows, $resolvedOfferId);

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
                'post_publish_refresh' => $postPublishRefresh,
            ];
        })->all();
    }


    private function listingForRefresh(Request $request): ?MarketplaceListing
    {
        $partId = $request->integer('part_id');
        $offerId = trim((string) $request->input('offer_id', ''));

        return MarketplaceListing::query()
            ->whereIn('marketplace', ['allegro', 'allegro_main'])
            ->when($partId > 0, fn ($query) => $query->where('part_id', $partId))
            ->when($offerId !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('external_offer_id', $offerId)->orWhere('external_listing_id', $offerId)))
            ->with('account')
            ->latest('id')
            ->first();
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
            'post_publish_refresh' => $this->postPublishRefreshDiagnostics((int) $listing->part_id, [['id' => $listing->id]], $listing->external_offer_id ?: $listing->external_listing_id),
        ];
    }

    private function pendingRefreshQuery(int $olderThanMinutes)
    {
        return MarketplaceListing::query()
            ->where('marketplace', 'allegro')
            ->where('status', 'publication_pending')
            ->whereNotNull('external_offer_id')
            ->where('external_offer_id', '<>', '')
            ->when($olderThanMinutes > 0, fn ($query) => $query->where('updated_at', '<=', now()->subMinutes($olderThanMinutes)))
            ->orderBy('updated_at')
            ->orderBy('id');
    }

    /** @param array<int, array<string, mixed>> $listingRows */
    private function postPublishRefreshDiagnostics(int $partId, array $listingRows, ?string $offerId): array
    {
        $listingIds = collect($listingRows)->pluck('id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        if (! Schema::hasTable('marketplace_sync_logs')) {
            return [
                'has_logs' => false,
                'last_job_status' => null,
                'last_attempt_time' => null,
                'last_attempt_number' => null,
                'api_publication_status' => null,
                'before_local_listing_status' => null,
                'after_local_listing_status' => null,
                'queue' => $this->queuedJobDiagnostics($listingIds, $offerId),
                'logs' => [],
                'warning' => 'marketplace_sync_logs table does not exist',
            ];
        }
        $logs = MarketplaceSyncLog::query()
            ->where('marketplace', 'allegro')
            ->whereIn('action', ['allegro_post_publish_status_refresh_scheduled', 'allegro_post_publish_status_refresh_executed', 'allegro_post_publish_status_refresh_skipped'])
            ->when($listingIds !== [], fn ($query) => $query->whereIn('marketplace_listing_id', $listingIds))
            ->when($listingIds === [], fn ($query) => $query->where('part_id', $partId))
            ->latest('created_at')
            ->limit(10)
            ->get();
        $lastExecuted = $logs->firstWhere('action', 'allegro_post_publish_status_refresh_executed');

        return [
            'has_logs' => $logs->isNotEmpty(),
            'last_job_status' => $lastExecuted?->status ?? $logs->first()?->status,
            'last_attempt_time' => optional($logs->first()?->created_at)->toISOString(),
            'last_attempt_number' => data_get($logs->first()?->payload, 'meta.attempt'),
            'api_publication_status' => data_get($lastExecuted?->payload, 'meta.api_publication_status') ?? data_get($lastExecuted?->payload, 'meta.api.publication_status'),
            'before_local_listing_status' => data_get($lastExecuted?->payload, 'meta.before_local_listing_status') ?? data_get($lastExecuted?->payload, 'meta.changes.status.before'),
            'after_local_listing_status' => data_get($lastExecuted?->payload, 'meta.after_local_listing_status') ?? data_get($lastExecuted?->payload, 'meta.changes.status.after'),
            'queue' => $this->queuedJobDiagnostics($listingIds, $offerId),
            'logs' => $logs->map(fn (MarketplaceSyncLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'status' => $log->status,
                'message' => $log->message,
                'listing_id' => $log->marketplace_listing_id,
                'offer_id' => $log->external_id,
                'attempt' => data_get($log->payload, 'meta.attempt'),
                'created_at' => optional($log->created_at)->toISOString(),
            ])->values()->all(),
        ];
    }

    private function queuedJobDiagnostics(array $listingIds, ?string $offerId): array
    {
        $out = ['connection' => config('queue.default'), 'jobs_table_exists' => Schema::hasTable('jobs'), 'failed_jobs_table_exists' => Schema::hasTable('failed_jobs'), 'pending_delayed_jobs' => [], 'failed_jobs' => []];
        if (Schema::hasTable('jobs')) {
            $out['pending_delayed_jobs'] = DB::table('jobs')->where('payload', 'like', '%RefreshAllegroListingStatusAfterPublish%')->orderByDesc('id')->limit(20)->get()->filter(function ($job) use ($listingIds, $offerId): bool {
                $payload = (string) $job->payload;
                return $listingIds === [] || collect($listingIds)->contains(fn ($id) => str_contains($payload, (string) $id)) || ($offerId && str_contains($payload, $offerId));
            })->map(fn ($job): array => ['id' => $job->id, 'queue' => $job->queue, 'attempts' => $job->attempts, 'available_at' => $job->available_at, 'reserved_at' => $job->reserved_at, 'created_at' => $job->created_at])->values()->all();
        }
        if (Schema::hasTable('failed_jobs')) {
            $out['failed_jobs'] = DB::table('failed_jobs')->where('payload', 'like', '%RefreshAllegroListingStatusAfterPublish%')->orderByDesc('id')->limit(10)->get()->map(fn ($job): array => ['id' => $job->id, 'queue' => $job->queue ?? null, 'failed_at' => $job->failed_at ?? null, 'exception' => mb_substr((string) ($job->exception ?? ''), 0, 500)])->values()->all();
        }
        return $out;
    }

    private function queueDiagnostics(): array
    {
        return [
            'queue_default' => config('queue.default'),
            'db_jobs_table_exists' => Schema::hasTable('jobs'),
            'failed_jobs_table_exists' => Schema::hasTable('failed_jobs'),
            'pending_refresh_jobs_count' => Schema::hasTable('jobs') ? DB::table('jobs')->where('payload', 'like', '%RefreshAllegroListingStatusAfterPublish%')->count() : null,
            'note' => 'Delayed jobs require a running queue worker for the configured queue connection. Cron fallback is scheduled via allegro:refresh-pending-listings.',
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
