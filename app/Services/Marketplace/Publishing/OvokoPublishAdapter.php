<?php

namespace App\Services\Marketplace\Publishing;

use App\Models\MarketplaceAccount;
use App\Models\Part;
use App\Services\Marketplace\Api\OvokoApiClient;
use Illuminate\Support\Arr;

class OvokoPublishAdapter extends BaseMarketplacePublishAdapter
{
    protected function channel(): string { return 'ovoko'; }
    protected function marketplace(): string { return 'ovoko'; }
    protected function accountCode(): string { return 'ovoko_main'; }

    protected function performLivePublish(Part $part, array $readiness, array $payload, ?MarketplaceAccount $account): array
    {
        if (! $account || ! $account->api_enabled || blank($account->api_base_url)) {
            return ['ok' => false, 'status' => 'not_configured', 'action' => 'crm/importPart', 'error' => 'Ovoko API account is not configured.', 'request_summary' => $this->requestSummary($payload), 'response_summary' => ['missing' => ['ovoko_main account/api_base_url/api_enabled']]];
        }

        $credentials = is_array($account->api_credentials) ? $account->api_credentials : [];
        foreach (['username', 'password', 'user_token'] as $key) {
            if (blank($credentials[$key] ?? null)) {
                return ['ok' => false, 'status' => 'not_configured', 'action' => 'crm/importPart', 'error' => 'Ovoko API credentials are not configured.', 'request_summary' => $this->requestSummary($payload), 'response_summary' => ['missing' => [$key]]];
            }
        }

        $form = $this->importPartPayload($part, $readiness, $payload, $account);
        if (($form['ok'] ?? false) !== true) {
            return ['ok' => false, 'status' => 'payload_invalid', 'action' => 'crm/importPart', 'error' => (string) ($form['error'] ?? 'Ovoko publish payload is incomplete.'), 'request_summary' => $this->requestSummary($payload) + ['ovoko_form_keys' => array_keys($form['fields'] ?? [])], 'response_summary' => ['missing' => $form['missing'] ?? []]];
        }

        $result = (new OvokoApiClient('ovoko', $account))->importPart($form['fields']);
        $externalId = filled($result['part_id'] ?? null) ? (string) $result['part_id'] : null;
        $summary = [
            'endpoint' => $result['endpoint_used'] ?? null,
            'ovoko_status_code' => $result['api_status_code'] ?? null,
            'message' => $result['message'] ?? null,
            'part_id_present' => filled($externalId),
            'response_top_level_keys' => $result['response_top_level_keys'] ?? [],
        ];

        if (! ($result['api_ok'] ?? false) || ! $externalId) {
            return ['ok' => false, 'status' => 'api_error', 'action' => 'crm/importPart', 'http_status' => $result['http_status'] ?? null, 'error' => (string) ($result['message'] ?? 'Ovoko/RRR importPart failed.'), 'request_summary' => $this->requestSummary($payload) + ['ovoko_form_keys' => array_keys($form['fields'])], 'response_summary' => $summary];
        }

        return ['ok' => true, 'status' => 'published', 'listing_status' => 'published', 'action' => 'crm/importPart', 'http_status' => $result['http_status'] ?? null, 'external_offer_id' => $externalId, 'external_listing_id' => $externalId, 'response_summary' => $summary];
    }

    private function importPartPayload(Part $part, array $readiness, array $payload, MarketplaceAccount $account): array
    {
        $settings = is_array($account->api_settings) ? $account->api_settings : [];
        $vehicle = is_array($payload['vehicle'] ?? null) ? $payload['vehicle'] : [];
        $fields = array_filter([
            'category_id' => $payload['category_id'] ?? null,
            'car_id' => $settings['default_car_id'] ?? $vehicle['car_id'] ?? null,
            'quality' => $settings['default_quality'] ?? $settings['ovoko_default_quality'] ?? null,
            'status' => $settings['default_part_status'] ?? $settings['ovoko_default_part_status'] ?? null,
            'price' => $readiness['marketplace_price'] ?? $payload['price_pln'] ?? null,
            'original_currency' => $readiness['currency'] ?? $payload['currency'] ?? 'PLN',
            'external_id' => $payload['sku'] ?? $part->sku ?? ('gps-part-'.$part->id),
            'visible_code' => $payload['sku'] ?? $part->sku ?? null,
            'manufacturer_code' => $part->manufacturer_code ?? null,
            'notes' => trim(strip_tags((string) (($part->description ?? null) ?: ($part->short_description ?? null) ?: ($part->condition_notes ?? null)))) ?: null,
            'photo' => Arr::first((array) ($payload['image_urls'] ?? [])),
            'photos[]' => array_values((array) ($payload['image_urls'] ?? [])),
        ], fn ($value) => ! blank($value));

        $missing = [];
        foreach (['category_id', 'car_id', 'quality', 'status'] as $key) if (blank($fields[$key] ?? null)) $missing[] = $key;
        if ($missing !== []) return ['ok' => false, 'fields' => $fields, 'missing' => $missing, 'error' => 'Ovoko/RRR requires category_id, car_id, quality and status for /crm/importPart. Configure missing values in ovoko_main api_settings or prepared payload.'];

        return ['ok' => true, 'fields' => $fields];
    }
}
