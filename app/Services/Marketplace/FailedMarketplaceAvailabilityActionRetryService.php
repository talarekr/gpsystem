<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Services\Marketplace\Api\AllegroApiClient;
use App\Services\Marketplace\Api\EbayApiClient;
use App\Services\Marketplace\Api\OvokoApiClient;
use Illuminate\Support\Arr;

class FailedMarketplaceAvailabilityActionRetryService
{
    /** @return array<string, mixed> */
    public function preview(MarketplaceSyncLog $failedLog): array
    {
        return $this->buildPlan($failedLog);
    }

    /** @return array<string, mixed> */
    public function retry(MarketplaceSyncLog $failedLog): array
    {
        $plan = $this->buildPlan($failedLog);
        if (! ($plan['retryable'] ?? false)) {
            return $this->writeRetryLog($failedLog, $plan, ['ok' => false, 'message' => $plan['blocker'] ?? 'Log is not retryable.'], 'blocked');
        }

        $listing = MarketplaceListing::query()->find($plan['marketplace_listing_id']);
        if (! $listing) {
            return $this->writeRetryLog($failedLog, $plan, ['ok' => false, 'message' => 'Marketplace listing is missing.'], 'blocked');
        }

        $account = $listing->account ?: MarketplaceAccount::query()->where('marketplace', $listing->marketplace)->first();
        $result = match ($plan['target_marketplace']) {
            'allegro' => $plan['event_type'] === PartAvailabilityEventService::EVENT_SOLD
                ? (new AllegroApiClient('allegro_main', $account))->endOffer((string) $plan['external_id'])
                : (new AllegroApiClient('allegro_main', $account))->activateOffer((string) $plan['external_id']),
            'ovoko' => $plan['event_type'] === PartAvailabilityEventService::EVENT_SOLD
                ? (new OvokoApiClient('ovoko', $account))->deactivatePart((string) $plan['external_id'])
                : (new OvokoApiClient('ovoko', $account))->restorePart((string) $plan['external_id']),
            'ebay' => (new EbayApiClient($listing->marketplace, $account))->setInventoryQuantity((string) $plan['external_id'], $plan['event_type'] === PartAvailabilityEventService::EVENT_SOLD ? 0 : 1, $listing->external_offer_id),
            default => ['ok' => false, 'message' => 'Unsupported marketplace retry target.'],
        };

        return $this->writeRetryLog($failedLog, $plan, $result, ($result['ok'] ?? false) ? 'success' : 'error');
    }

    /** @return array<string, mixed> */
    private function buildPlan(MarketplaceSyncLog $log): array
    {
        $listing = $log->marketplaceListing ?: ($log->marketplace_listing_id ? MarketplaceListing::query()->find($log->marketplace_listing_id) : null);
        $marketplace = $this->normalizeMarketplace((string) ($log->marketplace ?: $listing?->marketplace));
        $eventType = (string) Arr::get($log->payload ?? [], 'event_type', $this->eventTypeFromAction((string) $log->action));
        $externalId = $log->external_id ?: ($listing ? $this->externalId($listing, $marketplace) : null);
        $action = $this->retryAction($marketplace, $eventType, (string) $log->action);

        $plan = [
            'retryable' => false,
            'original_log_id' => $log->id,
            'marketplace_listing_id' => $listing?->id,
            'part_id' => $log->part_id ?: $listing?->part_id,
            'target_marketplace' => $marketplace,
            'listing_marketplace' => $listing?->marketplace,
            'event_type' => $eventType,
            'action' => $action,
            'external_id' => $externalId,
            'dry_run' => true,
            'local_part_state_will_change' => false,
            'full_availability_event_will_run' => false,
        ];

        if ($log->status !== 'error') return $plan + ['blocker' => 'Only failed marketplace_sync_logs rows are retryable.'];
        if (! in_array($marketplace, ['allegro', 'ovoko', 'ebay'], true)) return $plan + ['blocker' => 'Unsupported marketplace.'];
        if (! in_array($eventType, [PartAvailabilityEventService::EVENT_SOLD, PartAvailabilityEventService::EVENT_RESTORED], true)) return $plan + ['blocker' => 'Cannot infer sold/restored availability action.'];
        if (! $listing) return $plan + ['blocker' => 'Marketplace listing is missing.'];
        if (blank($externalId)) return $plan + ['blocker' => 'External marketplace ID is missing.'];

        return $plan + ['retryable' => true, 'blocker' => null];
    }

    private function writeRetryLog(MarketplaceSyncLog $failedLog, array $plan, array $result, string $status): array
    {
        $log = MarketplaceSyncLog::query()->create([
            'marketplace' => (string) ($plan['listing_marketplace'] ?? $failedLog->marketplace),
            'marketplace_listing_id' => $plan['marketplace_listing_id'] ?? $failedLog->marketplace_listing_id,
            'part_id' => $plan['part_id'] ?? $failedLog->part_id,
            'action' => (string) ($result['action'] ?? $plan['action'] ?? $failedLog->action),
            'status' => $status,
            'http_status' => $result['http_status'] ?? null,
            'message' => $result['message'] ?? null,
            'external_id' => $plan['external_id'] ?? $failedLog->external_id,
            'payload' => ['retry' => true, 'retry_of_log_id' => $failedLog->id, 'retry_plan' => $plan, 'request_summary' => $result['request_summary'] ?? [], 'response_summary' => $result['response_summary'] ?? []],
            'created_at' => now(),
        ]);

        if ($status === 'success' && ($listing = MarketplaceListing::query()->find($plan['marketplace_listing_id'] ?? null))) {
            $listing->forceFill(['quantity' => ($plan['event_type'] ?? null) === PartAvailabilityEventService::EVENT_SOLD ? 0 : 1, 'status' => ($plan['event_type'] ?? null) === PartAvailabilityEventService::EVENT_SOLD ? 'ended' : 'active', 'sync_status' => 'synced', 'last_api_status' => 'success', 'last_error' => null, 'last_synced_at' => now()])->save();
        }

        return ['ok' => $status === 'success', 'status' => $status, 'log_id' => $log->id, 'plan' => $plan, 'result' => $result];
    }

    private function normalizeMarketplace(string $marketplace): string { return str_starts_with($marketplace, 'ebay') ? 'ebay' : $marketplace; }
    private function eventTypeFromAction(string $action): ?string { return str_contains($action, 'activate') || str_contains($action, 'restore') ? PartAvailabilityEventService::EVENT_RESTORED : (str_contains($action, 'end') || str_contains($action, 'quantity') || str_contains($action, 'availability_update') ? PartAvailabilityEventService::EVENT_SOLD : null); }
    private function retryAction(string $marketplace, string $eventType, string $original): string { return match ($marketplace) { 'allegro' => $eventType === 'sold' ? 'allegro_end_offer' : 'allegro_activate_offer', 'ovoko' => 'crm/changePartStatus', 'ebay' => 'ebay_set_inventory_quantity', default => $original }; }
    private function externalId(MarketplaceListing $listing, string $marketplace): ?string { return match ($marketplace) { 'allegro' => $listing->external_offer_id ?: $listing->external_listing_id, 'ovoko' => $listing->external_listing_id ?: $listing->external_offer_id ?: Arr::get($listing->raw_payload ?: [], 'metadata.ovoko_part_id'), 'ebay' => $listing->sku ?: $listing->external_offer_id ?: $listing->external_listing_id, default => null } ?: null; }
}
