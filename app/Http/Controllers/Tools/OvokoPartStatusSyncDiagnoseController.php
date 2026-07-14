<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Services\Marketplace\Api\OvokoApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class OvokoPartStatusSyncDiagnoseController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $partId = (int) $request->query('part_id');
        if ($partId <= 0) {
            return response()->json(['ok' => false, 'blockers' => ['part_id_required'], 'safety_flags' => $this->safetyFlags()], 422);
        }

        $part = Part::query()->with(['marketplaceListings' => fn ($q) => $q->where('marketplace', 'ovoko')])->find($partId);
        if (! $part) {
            return response()->json(['ok' => false, 'part_id' => $partId, 'blockers' => ['part_not_found'], 'safety_flags' => $this->safetyFlags()], 404);
        }

        $listing = $this->activeListing($part);
        $externalId = $this->externalId($listing);
        $account = $listing?->account ?: MarketplaceAccount::query()->where('marketplace', 'ovoko')->first();

        return response()->json([
            'ok' => true,
            'marker' => 'ovoko_part_status_sync_mapping_audit_v1',
            'part_id' => $part->id,
            'local' => [
                'status' => $part->status,
                'status_label' => $part->adminStatusLabel(),
                'admin_local_availability' => $part->adminLocalAvailability(),
                'quantity' => (int) $part->quantity,
                'is_visible_storefront' => (bool) $part->is_visible_storefront,
                'needs_listing' => (bool) $part->needs_listing,
                'sale_source' => $part->sale_source,
                'sold_at' => optional($part->sold_at)->toISOString(),
            ],
            'ovoko_status_sync_enabled' => (bool) ($account?->api_enabled ?? false),
            'active_ovoko_listing' => $listing ? $this->listingSummary($listing, $externalId) : null,
            'identifier_used_for_status_sync' => [
                'field_priority' => ['external_listing_id', 'external_offer_id', 'raw_payload.metadata.ovoko_part_id'],
                'value' => $externalId,
            ],
            'status_mapping' => $this->statusMapping(),
            'planned_actions' => [
                'w_sprzedazy_to_sprzedana' => $this->plannedAction('sold', $externalId),
                'sprzedana_to_w_sprzedazy' => $this->plannedAction('restored', $externalId),
            ],
            'trigger_path' => [
                'panel_endpoint' => 'PATCH /parts/{part}/local-availability',
                'controller' => 'App\\Http\\Controllers\\Admin\\PartLocalAvailabilityController::update',
                'local_updater' => 'App\\Services\\Admin\\PartLocalAvailabilityUpdater::update',
                'sync_service' => 'App\\Services\\Marketplace\\PartAvailabilityEventService::sold/restored',
                'queue' => false,
                'automatic_on_local_availability_change' => true,
                'admin_tool_stock_sync' => 'GET/POST /admin/tools/ovoko-stock-sync-* is separate and local-stock-only; it does not write to Ovoko.',
            ],
            'latest_related_ovoko_logs' => $this->latestLogs($part, $listing),
            'safety_flags' => $this->safetyFlags(),
        ]);
    }

    private function activeListing(Part $part): ?MarketplaceListing
    {
        return $part->marketplaceListings->sortByDesc(fn (MarketplaceListing $listing): int => in_array(strtolower((string) $listing->status), ['active', 'published', 'live', 'imported'], true) ? 1 : 0)->first();
    }

    private function externalId(?MarketplaceListing $listing): ?string
    {
        return $listing ? ($listing->external_listing_id ?: $listing->external_offer_id ?: Arr::get($listing->raw_payload ?: [], 'metadata.ovoko_part_id')) : null;
    }

    private function plannedAction(string $eventType, ?string $externalId): array
    {
        $status = $eventType === 'sold' ? OvokoApiClient::PART_STATUS_SOLD_OUT : OvokoApiClient::PART_STATUS_IN_STOCK;
        return [
            'event_type' => $eventType,
            'would_request' => filled($externalId),
            'request_type' => 'status_update',
            'endpoint' => '/crm/changePartStatus',
            'http_method' => 'POST',
            'content_type' => 'application/x-www-form-urlencoded',
            'client_method' => $eventType === 'sold' ? 'OvokoApiClient::deactivatePart' : 'OvokoApiClient::restorePart',
            'payload_preview' => ['part_id' => $externalId, 'status' => $status],
            'payload_auth_fields_omitted' => ['username', 'password', 'user_token'],
            'ovoko_status_value' => $status,
            'quantity_value_sent' => null,
            'price_sent' => false,
            'external_id_field_used' => 'part_id',
            'notes' => 'No importPart/updatePart/stock quantity/price payload is sent by this availability status path.',
        ];
    }

    private function statusMapping(): array
    {
        return [
            'ready_w_sprzedazy' => ['local_status' => 'ready', 'event' => 'restored', 'ovoko_status' => OvokoApiClient::PART_STATUS_IN_STOCK, 'quantity_sent' => null, 'local_quantity_after_click' => 1],
            'published_opublikowana' => ['local_status' => 'published', 'admin_availability' => 'for_sale', 'direct_click_mapping' => 'not a local-availability target; availability restore saves ready'],
            'sold_sprzedana' => ['local_status' => 'sold', 'event' => 'sold', 'ovoko_status' => OvokoApiClient::PART_STATUS_SOLD_OUT, 'quantity_sent' => null, 'local_quantity_after_click' => 0],
            'reserved_zarezerwowana' => ['local_status' => 'reserved', 'exists_in_part_status_options' => false, 'ovoko_status_constant_if_used' => OvokoApiClient::PART_STATUS_RESERVED, 'sent_by_current_panel_path' => false],
            'hidden_inactive_archived' => ['local_status' => 'archived', 'admin_availability' => 'not_for_sale', 'sent_by_current_panel_path' => false],
        ];
    }

    private function listingSummary(MarketplaceListing $listing, ?string $externalId): array
    {
        return Arr::only($listing->toArray(), ['id', 'external_offer_id', 'external_listing_id', 'url', 'status', 'sync_status', 'last_api_status', 'quantity', 'price', 'currency']) + ['resolved_ovoko_part_id' => $externalId];
    }

    private function latestLogs(Part $part, ?MarketplaceListing $listing): array
    {
        return MarketplaceSyncLog::query()
            ->where('marketplace', 'ovoko')
            ->where(fn ($q) => $q->where('part_id', $part->id)->when($listing, fn ($qq) => $qq->orWhere('marketplace_listing_id', $listing->id)))
            ->whereIn('action', ['crm/changePartStatus', 'availability_update', 'skip_source_channel'])
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (MarketplaceSyncLog $log): array => [
                'id' => $log->id,
                'created_at' => optional($log->created_at)->toISOString(),
                'action' => $log->action,
                'status' => $log->status,
                'http_status' => $log->http_status,
                'message' => $log->message,
                'external_id' => $log->external_id,
                'request_summary' => data_get($log->payload, 'request_summary'),
                'response_summary' => data_get($log->payload, 'response_summary'),
            ])->all();
    }

    private function safetyFlags(): array
    {
        return ['read_only' => true, 'no_mutation' => true, 'no_ovoko_request' => true, 'no_publish' => true];
    }
}
