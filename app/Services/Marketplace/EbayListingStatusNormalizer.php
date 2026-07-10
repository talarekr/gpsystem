<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Arr;

class EbayListingStatusNormalizer
{
    public const MARKER = 'ebay_listing_active_state_normalization_v1';

    /**
     * Normalize a read-only eBay status response without treating API failures as ended.
     *
     * @param array<string,mixed> $api
     * @return array{normalized_status:string,is_really_active:bool,should_show_checkmark:bool,should_allow_relisting:bool,item_found:bool,raw_status:?string,start_time:?string,end_time:?string,end_reason:?string,error_type:?string}
     */
    public function normalize(array $api): array
    {
        $http = $api['http_status'] ?? null;
        $raw = $this->firstFilled([
            $api['api_listing_status'] ?? null,
            $api['raw_api_status'] ?? null,
            Arr::get($api, 'json.itemStatus'),
            Arr::get($api, 'json.estimatedAvailabilities.0.estimatedAvailabilityStatus'),
        ]);
        $endTime = $this->firstFilled([$api['end_date'] ?? null, Arr::get($api, 'json.itemEndDate'), Arr::get($api, 'json.item.endTime')]);
        $endReason = $this->firstFilled([$api['end_reason'] ?? null, Arr::get($api, 'json.endReason'), Arr::get($api, 'json.endingReason')]);
        $lower = strtolower((string) $raw);
        $itemFound = ! in_array((int) $http, [404, 410], true) && ! in_array($lower, ['not_found', 'invalid'], true);
        $errorType = null;

        if (in_array((int) $http, [401, 403], true)) {
            $status = 'unknown'; $errorType = 'authorization';
        } elseif (in_array((int) $http, [408, 429, 500, 502, 503, 504], true)) {
            $status = 'unknown'; $errorType = 'transient_api';
        } elseif (in_array((int) $http, [404, 410], true) || in_array($lower, ['not_found', 'not found'], true)) {
            $status = 'not_found'; $itemFound = false;
        } elseif (in_array($lower, ['invalid', 'deleted', 'removed'], true)) {
            $status = $lower === 'invalid' ? 'invalid' : 'ended';
        } elseif (in_array($lower, ['ended', 'inactive', 'completed', 'expired', 'unavailable'], true) || str_contains($lower, 'ended')) {
            $status = 'ended';
        } elseif (in_array($lower, ['active', 'published', 'live', 'in_stock', 'in-stock'], true)) {
            $status = 'active';
        } elseif ($endTime && strtotime((string) $endTime) !== false && strtotime((string) $endTime) < now()->timestamp) {
            $status = 'ended';
        } else {
            $status = 'unknown';
        }

        $active = $status === 'active';

        return [
            'normalized_status' => $status,
            'is_really_active' => $active,
            'should_show_checkmark' => $active,
            'should_allow_relisting' => in_array($status, ['ended', 'not_found', 'invalid'], true),
            'item_found' => $itemFound,
            'raw_status' => is_scalar($raw) ? (string) $raw : null,
            'start_time' => $this->firstFilled([$api['start_time'] ?? null, Arr::get($api, 'json.itemCreationDate')]),
            'end_time' => is_scalar($endTime) ? (string) $endTime : null,
            'end_reason' => is_scalar($endReason) ? (string) $endReason : null,
            'error_type' => $errorType,
        ];
    }

    private function firstFilled(array $values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') return $value;
        }
        return null;
    }
}
