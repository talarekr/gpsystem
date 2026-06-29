<?php

namespace App\Services\Marketplace\Publishing;

use App\Models\MarketplaceAccount;
use App\Models\Part;
use App\Services\Marketplace\Api\OvokoApiClient;

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
        $photoDiagnostics = $this->photoDiagnostics($form['fields'] ?? []);
        if (($form['ok'] ?? false) !== true) {
            return ['ok' => false, 'status' => 'payload_invalid', 'action' => 'crm/importPart', 'error' => (string) ($form['error'] ?? 'Ovoko publish payload is incomplete.'), 'request_summary' => $this->requestSummary($payload) + ['ovoko_form_keys' => array_keys($form['fields'] ?? []), 'ovoko_photo' => $photoDiagnostics], 'response_summary' => ['missing' => $form['missing'] ?? []]];
        }

        $result = (new OvokoApiClient('ovoko', $account))->importPart($form['fields']);
        $externalId = filled($result['part_id'] ?? null) ? (string) $result['part_id'] : null;
        $summary = [
            'endpoint' => $result['endpoint_used'] ?? null,
            'ovoko_status_code' => $result['api_status_code'] ?? null,
            'message' => $result['message'] ?? null,
            'part_id_present' => filled($externalId),
            'response_top_level_keys' => $result['response_top_level_keys'] ?? [],
            'ovoko_photo' => $photoDiagnostics,
        ];

        if (! ($result['api_ok'] ?? false) || ! $externalId) {
            return ['ok' => false, 'status' => 'api_error', 'action' => 'crm/importPart', 'http_status' => $result['http_status'] ?? null, 'error' => (string) ($result['message'] ?? 'Ovoko/RRR importPart failed.'), 'request_summary' => $this->requestSummary($payload) + ['ovoko_form_keys' => array_keys($form['fields']), 'ovoko_photo' => $photoDiagnostics], 'response_summary' => $summary];
        }

        return ['ok' => true, 'status' => 'published', 'listing_status' => 'published', 'action' => 'crm/importPart', 'http_status' => $result['http_status'] ?? null, 'external_offer_id' => $externalId, 'external_listing_id' => $externalId, 'response_summary' => $summary];
    }

    private function importPartPayload(Part $part, array $readiness, array $payload, MarketplaceAccount $account): array
    {
        $settings = is_array($account->api_settings) ? $account->api_settings : [];
        $vehicle = is_array($payload['vehicle'] ?? null) ? $payload['vehicle'] : [];
        $fields = array_filter([
            'category_id' => $payload['category_id'] ?? null,
            'car_id' => $this->ovokoCarId($part, $vehicle, $settings),
            'quality' => $payload['quality'] ?? $this->qualityFromPart($part) ?? $settings['default_quality'] ?? $settings['ovoko_default_quality'] ?? null,
            'status' => $payload['status'] ?? $settings['default_part_status'] ?? $settings['ovoko_default_part_status'] ?? null,
            'price' => $readiness['marketplace_price'] ?? $payload['price_pln'] ?? null,
            'original_currency' => $readiness['currency'] ?? $payload['currency'] ?? 'PLN',
            'external_id' => $payload['sku'] ?? $part->sku ?? ('gps-part-'.$part->id),
            'visible_code' => $payload['sku'] ?? $part->sku ?? null,
            'manufacturer_code' => $part->manufacturer_code ?? null,
            'notes' => trim(strip_tags((string) (($part->description ?? null) ?: ($part->short_description ?? null) ?: ($part->condition_notes ?? null)))) ?: null,
            'photo' => $this->publicImageUrls($payload['image_urls'] ?? [])[0] ?? null,
            'photos[]' => $this->publicImageUrls($payload['image_urls'] ?? []),
        ], fn ($value) => ! blank($value));

        $missing = [];
        if (blank($fields['category_id'] ?? null)) $missing[] = 'Ovoko: brakuje category_id dla wybranej kategorii '.($payload['category_mapping_name'] ?? $payload['category_mapping_path'] ?? $payload['local_category_id'] ?? 'części');
        if (blank($fields['car_id'] ?? null)) $missing[] = 'Ovoko: wybrane auto nie ma RRR car_id';
        if (blank($fields['quality'] ?? null)) $missing[] = 'Ovoko: nie udało się zmapować quality z wartości '.($part->condition_notes ?? '');
        if (blank($fields['status'] ?? null)) $missing[] = 'Uzupełnij domyślny status części Ovoko w ustawieniach konta.';
        if (blank($fields['photo'] ?? null)) $missing[] = 'Ovoko: zdjęcie części musi być publicznym URL-em HTTP/HTTPS. Szczegóły są w Logach.';
        if ($missing !== []) return ['ok' => false, 'fields' => $fields, 'missing' => $missing, 'error' => implode('; ', $missing)];

        return ['ok' => true, 'fields' => $fields];
    }

    /** @return array<int, string> */
    private function publicImageUrls(mixed $images): array
    {
        return array_values(array_filter(array_map(function (mixed $image): ?string {
            $url = null;
            if (is_string($image)) $url = $image;
            if (is_array($image) && is_string($image['url'] ?? null)) $url = $image['url'];
            if (is_object($image) && is_string($image->url ?? null)) $url = $image->url;
            $url = trim((string) $url);
            if ($url === '') return null;
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $host = (string) parse_url($url, PHP_URL_HOST);
            return in_array($scheme, ['http', 'https'], true) && $host !== '' ? $url : null;
        }, (array) $images)));
    }

    private function photoDiagnostics(array $fields): array
    {
        $photo = $fields['photo'] ?? null;
        $photos = is_array($fields['photos[]'] ?? null) ? $fields['photos[]'] : [];
        return [
            'photo_present' => filled($photo),
            'photo_shape' => get_debug_type($photo),
            'photo_scheme' => is_string($photo) ? parse_url($photo, PHP_URL_SCHEME) : null,
            'photo_host' => is_string($photo) ? parse_url($photo, PHP_URL_HOST) : null,
            'photos_count' => count($photos),
            'first_photo_matches_photo' => isset($photos[0]) && is_string($photo) && $photos[0] === $photo,
        ];
    }

    private function ovokoCarId(Part $part, array $vehicle, array $settings): mixed
    {
        $part->loadMissing('car');
        foreach ([$part->car?->external_id ?? null, data_get($part->car?->legacy_payload, 'ovoko_car_id'), data_get($part->car?->legacy_payload, 'rrr_car_id'), $vehicle['rrr_car_id'] ?? null, $vehicle['ovoko_car_id'] ?? null, $settings['default_car_id'] ?? null] as $value) {
            if (! blank($value)) return $value;
        }
        return null;
    }

    private function qualityFromPart(Part $part): mixed
    {
        $value = mb_strtolower(trim((string) ($part->condition_notes ?? '')));
        $map = ['używany' => 1, 'uzywany' => 1, 'używana' => 1, 'uzywana' => 1, 'used' => 1, 'nowy' => 2, 'nowa' => 2, 'new' => 2];
        return $map[$value] ?? null;
    }
}
