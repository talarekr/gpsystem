<?php

namespace App\Services\Marketplace\Publishing;

use App\Models\MarketplaceAccount;
use App\Models\Part;
use App\Services\Marketplace\Api\OvokoApiClient;
use Illuminate\Support\Facades\Http;
use Throwable;

class OvokoPublishAdapter extends BaseMarketplacePublishAdapter
{
    private const OVOKO_USED_QUALITY = 0;
    private const OVOKO_NEW_QUALITY = 1;

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
        $formDiagnostics = $this->formDiagnostics($form['fields'] ?? [], $form['diagnostics'] ?? []);
        $photoDiagnostics = $this->photoDiagnostics($form['fields'] ?? []) + $formDiagnostics;
        if (($form['ok'] ?? false) === true && ! ($photoDiagnostics['any_photo_accessible_publicly'] ?? false)) {
            return ['ok' => false, 'status' => 'payload_invalid', 'action' => 'crm/importPart', 'error' => 'Ovoko photo is not publicly accessible.', 'ui_error' => 'Ovoko nie może pobrać zdjęcia części. Szczegóły są w Logach.', 'request_summary' => $this->requestSummary($payload) + ['ovoko_form_keys' => array_keys($form['fields'] ?? []), 'ovoko_photo' => $photoDiagnostics], 'response_summary' => ['ovoko_photo' => $photoDiagnostics]];
        }

        if (($form['ok'] ?? false) !== true) {
            return ['ok' => false, 'status' => 'payload_invalid', 'action' => 'crm/importPart', 'error' => (string) ($form['error'] ?? 'Ovoko publish payload is incomplete.'), 'request_summary' => $this->requestSummary($payload) + ['ovoko_form_keys' => array_keys($form['fields'] ?? []), 'ovoko_photo' => $photoDiagnostics] + $formDiagnostics, 'response_summary' => ['missing' => $form['missing'] ?? [], 'diagnostics' => $formDiagnostics]];
        }

        $result = (new OvokoApiClient('ovoko', $account))->importPart($form['fields']);
        $externalId = filled($result['part_id'] ?? null) ? (string) $result['part_id'] : null;
        $shopUrl = filled($result['shop_url'] ?? null) ? trim((string) $result['shop_url']) : null;
        $summary = [
            'endpoint' => $result['endpoint_used'] ?? null,
            'ovoko_status_code' => $result['api_status_code'] ?? null,
            'message' => $result['message'] ?? null,
            'ovoko_part_id' => $externalId,
            'ovoko_shop_url_present' => filled($shopUrl),
            'ovoko_shop_url' => $shopUrl,
            'part_id_present' => filled($externalId),
            'response_top_level_keys' => $result['response_top_level_keys'] ?? [],
            'ovoko_photo' => $photoDiagnostics,
            'ovoko_part_codes' => $formDiagnostics['ovoko_part_codes'] ?? [],
            'ovoko_primary_part_code' => $formDiagnostics['ovoko_primary_part_code'] ?? null,
            'ovoko_part_codes_field_name' => $formDiagnostics['ovoko_part_codes_field_name'] ?? null,
            'ovoko_part_codes_encoding_shape' => $formDiagnostics['ovoko_part_codes_encoding_shape'] ?? null,
        ];

        if (! ($result['api_ok'] ?? false) || ! $externalId) {
            return ['ok' => false, 'status' => 'api_error', 'action' => 'crm/importPart', 'http_status' => $result['http_status'] ?? null, 'error' => (string) ($result['message'] ?? 'Ovoko/RRR importPart failed.'), 'request_summary' => $this->requestSummary($payload) + ['ovoko_form_keys' => array_keys($form['fields']), 'ovoko_photo' => $photoDiagnostics] + $formDiagnostics, 'response_summary' => $summary];
        }

        return ['ok' => true, 'status' => 'published', 'listing_status' => 'published', 'action' => 'crm/importPart', 'http_status' => $result['http_status'] ?? null, 'external_offer_id' => $externalId, 'external_listing_id' => $externalId, 'url' => $shopUrl, 'request_summary' => $this->requestSummary($payload) + ['ovoko_form_keys' => array_keys($form['fields']), 'ovoko_photo' => $photoDiagnostics] + $formDiagnostics, 'response_summary' => $summary, 'log_context' => ['ovoko_part_id' => $externalId, 'ovoko_shop_url_present' => filled($shopUrl), 'ovoko_shop_url' => $shopUrl]];
    }

    private function importPartPayload(Part $part, array $readiness, array $payload, MarketplaceAccount $account): array
    {
        $settings = is_array($account->api_settings) ? $account->api_settings : [];
        $vehicle = is_array($payload['vehicle'] ?? null) ? $payload['vehicle'] : [];
        $ovokoPhotoUrls = $this->publicImageUrls($payload['image_urls'] ?? []);

        $part->loadMissing('car');
        $partCodeDiagnostics = $this->ovokoPartCodeDiagnostics($part);
        $partCodes = $partCodeDiagnostics['ovoko_optional_codes'];
        $car = $this->ovokoCarDiagnostics($part, $vehicle, $settings);
        $condition = $this->ovokoConditionDiagnostics($part);

        $fields = array_filter([
            'category_id' => $payload['category_id'] ?? null,
            'car_id' => $car['ovoko_car_id'] ?? null,
            'quality' => $condition['ovoko_quality'],
            'status' => $payload['status'] ?? $settings['default_part_status'] ?? $settings['ovoko_default_part_status'] ?? null,
            'price' => $readiness['marketplace_price'] ?? $payload['price_pln'] ?? null,
            'original_currency' => $readiness['currency'] ?? $payload['currency'] ?? 'PLN',
            'external_id' => $payload['sku'] ?? $part->sku ?? ('gps-part-'.$part->id),
            'visible_code' => $payload['sku'] ?? $part->sku ?? null,
            'manufacturer_code' => $partCodeDiagnostics['ovoko_manufacturer_code'] ?? null,
            'other_code' => $part->oem_number ?? $part->manufacturer_code ?? null,
            'optional_codes' => $partCodes,
            'notes' => trim(strip_tags((string) (($part->description ?? null) ?: ($part->short_description ?? null) ?: ($part->condition_notes ?? null)))) ?: null,
            'photo' => $ovokoPhotoUrls[0] ?? null,
            'photos[]' => $ovokoPhotoUrls,
        ], fn ($value) => ! blank($value));

        $missing = [];
        if (blank($fields['category_id'] ?? null)) $missing[] = 'Ovoko: brakuje category_id dla wybranej kategorii '.($payload['category_mapping_name'] ?? $payload['category_mapping_path'] ?? $payload['local_category_id'] ?? 'części');
        if (blank($fields['car_id'] ?? null)) $missing[] = 'Ovoko: wybrane auto nie ma RRR/Ovoko car_id. Najpierw utwórz/mapuj auto w Ovoko i zapisz jego car_id przy lokalnym aucie.';
        if (blank($fields['quality'] ?? null)) $missing[] = 'Ovoko: nie udało się zmapować quality z wartości '.($part->condition_notes ?? '');
        if (blank($fields['status'] ?? null)) $missing[] = 'Uzupełnij domyślny status części Ovoko w ustawieniach konta.';
        if (blank($fields['photo'] ?? null)) $missing[] = 'Ovoko: zdjęcie części musi być publicznym URL-em HTTP/HTTPS. Szczegóły są w Logach.';
        $diagnostics = array_merge($condition, ['ovoko_status' => $fields['status'] ?? null, 'raw_condition_payload_fields' => ['quality' => $fields['quality'] ?? null, 'status' => $fields['status'] ?? null]], $car, $partCodeDiagnostics);
        if (blank($fields['car_id'] ?? null)) $diagnostics['blocked_reason'] = 'missing_ovoko_car_id';
        if (($partCodeDiagnostics['ovoko_codes_look_like_title'] ?? false) === true) $diagnostics['warning'] = 'ovoko_code_looks_like_full_title';
        if ($missing !== []) return ['ok' => false, 'fields' => $fields, 'missing' => $missing, 'error' => implode('; ', $missing), 'diagnostics' => $diagnostics];

        return ['ok' => true, 'fields' => $fields, 'diagnostics' => $diagnostics];
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
            return $scheme === 'https' && $host !== '' ? $url : null;
        }, (array) $images)));
    }


    private function formDiagnostics(array $fields, array $payloadDiagnostics = []): array
    {
        $photos = is_array($fields['photos[]'] ?? null) ? array_values($fields['photos[]']) : [];
        $partCodes = is_array($fields['optional_codes'] ?? null) ? array_values($fields['optional_codes']) : [];

        return $payloadDiagnostics + [
            'ovoko_form_encoding' => 'application/x-www-form-urlencoded',
            'ovoko_part_codes' => $partCodes,
            'ovoko_primary_part_code' => $partCodes[0] ?? null,
            'ovoko_part_codes_field_name' => 'optional_codes',
            'ovoko_part_codes_encoding_shape' => is_array($fields['optional_codes'] ?? null) ? 'repeated_optional_codes' : get_debug_type($fields['optional_codes'] ?? null),
            'ovoko_part_codes_source' => $payloadDiagnostics['ovoko_codes_source'] ?? 'part.part_number first, then part.oem_number and part.manufacturer_code',
            'ovoko_photo_field_type' => get_debug_type($fields['photo'] ?? null),
            'ovoko_photos_field_encoding_shape' => is_array($fields['photos[]'] ?? null) ? 'repeated_photos_brackets' : get_debug_type($fields['photos[]'] ?? null),
            'ovoko_photos_are_repeated_keys' => is_array($fields['photos[]'] ?? null),
            'ovoko_photos_repeated_keys_preview' => array_map(fn (string $url): array => ['name' => 'photos[]', 'value' => $url, 'value_shape' => $this->safeUrlShape($url)], array_slice(array_filter($photos, 'is_string'), 0, 3)),
        ];
    }

    /** @return array<int, string> */
    private function ovokoPartCodeDiagnostics(Part $part): array
    {
        $codes = [];
        $source = [];
        $sourceLookedLikeTitle = false;
        foreach ([
            'part_number' => $part->part_number ?? null,
            'oem_number' => $part->oem_number ?? null,
            'manufacturer_code' => $part->manufacturer_code ?? null,
        ] as $field => $value) {
            $code = trim((string) $value);
            if ($this->looksLikeTitleCode($code, (string) ($part->name ?? ''))) {
                $sourceLookedLikeTitle = true;
                $code = $this->extractCodeFromTitleLikeValue($code) ?? '';
            }
            if ($code !== '' && ! in_array($code, $codes, true)) {
                $codes[] = $code;
                $source[] = $field;
            }
        }

        return [
            'part_title' => $part->name,
            'part_part_number' => $part->part_number,
            'part_oem_number' => $part->oem_number,
            'part_manufacturer_code' => $part->manufacturer_code,
            'ovoko_manufacturer_code' => $codes[0] ?? null,
            'ovoko_optional_codes' => $codes,
            'ovoko_codes_source' => implode(',', array_unique($source)) ?: 'none',
            'ovoko_codes_look_like_title' => $sourceLookedLikeTitle || collect($codes)->contains(fn (string $code): bool => $this->looksLikeTitleCode($code, (string) ($part->name ?? ''))),
        ];
    }

    private function photoDiagnostics(array $fields): array
    {
        $photo = $fields['photo'] ?? null;
        $photos = is_array($fields['photos[]'] ?? null) ? $fields['photos[]'] : [];
        $photoCheck = is_string($photo) ? $this->checkPhotoUrl($photo) : null;
        $checks = array_map(fn (string $url): array => $this->checkPhotoUrl($url), array_slice(array_values(array_filter($photos, 'is_string')), 0, 2));
        $firstPhotoPath = isset($photos[0]) && is_string($photos[0]) ? $this->urlPath($photos[0]) : null;

        return [
            'final_photo_url' => is_string($photo) ? $photo : null,
            'final_photos_urls' => array_values(array_filter($photos, 'is_string')),
            'photo_present' => filled($photo),
            'photo_shape' => get_debug_type($photo),
            'photo_scheme' => is_string($photo) ? parse_url($photo, PHP_URL_SCHEME) : null,
            'photo_host' => is_string($photo) ? parse_url($photo, PHP_URL_HOST) : null,
            'photo_path' => is_string($photo) ? $this->urlPath($photo) : null,
            'photo_basename' => is_string($photo) ? basename($this->urlPath($photo)) : null,
            'photo_extension' => is_string($photo) ? pathinfo($this->urlPath($photo), PATHINFO_EXTENSION) ?: null : null,
            'photo_path_has_expected_prefix' => is_string($photo) ? str_starts_with($this->urlPath($photo), '/storage/parts/photos/') : false,
            'photos_count' => count($photos),
            'first_photo_path' => $firstPhotoPath,
            'first_photo_path_has_expected_prefix' => is_string($firstPhotoPath) ? str_starts_with($firstPhotoPath, '/storage/parts/photos/') : false,
            'first_photo_matches_photo' => isset($photos[0]) && is_string($photo) && $photos[0] === $photo,
            'photo_http_status' => $photoCheck['photo_http_status'] ?? null,
            'photo_content_type' => $photoCheck['photo_content_type'] ?? null,
            'photo_content_length' => $photoCheck['photo_content_length'] ?? null,
            'photo_final_url_host' => $photoCheck['photo_final_url_host'] ?? null,
            'photo_redirect_count' => $photoCheck['photo_redirect_count'] ?? null,
            'photo_accessible_publicly' => $photoCheck['photo_accessible_publicly'] ?? false,
            'photo_exact_url_check' => $photoCheck,
            'any_photo_accessible_publicly' => (bool) ($photoCheck['photo_accessible_publicly'] ?? false) || collect($checks)->contains(fn (array $check): bool => (bool) ($check['photo_accessible_publicly'] ?? false)),
            'photos_access_checks' => $checks,
            'ovoko_photo_delivery_mode' => 'storage_url',
        ];
    }

    private function checkPhotoUrl(string $url): array
    {
        $base = [
            'url_shape' => $this->safeUrlShape($url),
            'photo_http_status' => null,
            'photo_content_type' => null,
            'photo_content_length' => null,
            'photo_final_url_host' => parse_url($url, PHP_URL_HOST),
            'photo_redirect_count' => 0,
            'photo_accessible_publicly' => false,
        ];

        try {
            $response = Http::timeout(5)->withOptions(['allow_redirects' => ['track_redirects' => true]])->head($url);
            if (! $this->usablePhotoResponse($response)) {
                $response = Http::timeout(6)->withHeaders(['Range' => 'bytes=0-1023'])->withOptions(['allow_redirects' => ['track_redirects' => true]])->get($url);
            }

            $status = $response->status();
            $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            $finalUrl = (string) ($response->handlerStats()['url'] ?? $url);
            $redirectHistory = $response->header('X-Guzzle-Redirect-History');

            return array_merge($base, [
                'photo_http_status' => $status,
                'photo_content_type' => $contentType ?: null,
                'photo_content_length' => is_numeric($response->header('Content-Length')) ? (int) $response->header('Content-Length') : null,
                'photo_final_url_host' => parse_url($finalUrl, PHP_URL_HOST) ?: $base['photo_final_url_host'],
                'photo_redirect_count' => $redirectHistory ? count(array_filter(array_map('trim', explode(',', $redirectHistory)))) : 0,
                'photo_accessible_publicly' => $status === 200 && str_starts_with($contentType, 'image/'),
            ]);
        } catch (Throwable $e) {
            return array_merge($base, ['photo_error' => $e::class]);
        }
    }

    private function usablePhotoResponse($response): bool
    {
        $contentType = strtolower((string) $response->header('Content-Type'));
        return $response->status() === 200 && str_starts_with($contentType, 'image/');
    }

    private function safeUrlShape(string $url): array
    {
        $path = $this->urlPath($url);
        return ['scheme' => parse_url($url, PHP_URL_SCHEME), 'host' => parse_url($url, PHP_URL_HOST), 'path' => $path, 'basename' => basename($path), 'path_extension' => pathinfo($path, PATHINFO_EXTENSION) ?: null, 'path_length' => strlen($path), 'path_has_expected_prefix' => str_starts_with($path, '/storage/parts/photos/')];
    }

    private function urlPath(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }

    private function ovokoCarDiagnostics(Part $part, array $vehicle, array $settings): array
    {
        $storedOvokoCarId = $part->car?->getAttribute('ovoko_car_id')
            ?? $part->car?->external_id
            ?? data_get($part->car?->legacy_payload, 'ovoko_car_id')
            ?? data_get($part->car?->legacy_payload, 'rrr_car_id')
            ?? ($vehicle['ovoko_car_id'] ?? null)
            ?? ($vehicle['rrr_car_id'] ?? null);

        $candidates = [
            'stored_ovoko_car_id' => $storedOvokoCarId,
            'local_car_id_fallback' => $part->car?->id,
            'account.settings.default_car_id' => $settings['default_car_id'] ?? null,
        ];
        $id = null; $source = null;
        foreach ($candidates as $candidateSource => $value) {
            if (! blank($value)) { $id = $value; $source = $candidateSource; break; }
        }
        return [
            'local_car_id' => $part->car_id,
            'local_car_make' => $part->car?->make,
            'local_car_model' => $part->car?->model,
            'local_car_year' => $part->car?->production_year ?? $part->car?->first_registration_year,
            'local_car_vin' => $part->car?->vin,
            'local_car_engine_code' => $part->car?->engine_code,
            'ovoko_car_id' => $id,
            'ovoko_car_id_present' => filled($id),
            'ovoko_car_id_source' => $source,
            'can_create_or_map_ovoko_car' => false,
        ];
    }

    private function ovokoConditionDiagnostics(Part $part): array
    {
        return [
            'local_condition' => $part->condition_notes,
            'ovoko_condition_field_name' => 'quality',
            'ovoko_condition_value' => self::OVOKO_USED_QUALITY,
            'ovoko_condition_meaning' => 'used',
            'ovoko_quality' => self::OVOKO_USED_QUALITY,
            'ovoko_status' => null,
            'condition_source' => 'business_rule_all_gp_swiss_parts_are_used; Ovoko/RRR importPart condition is quality, where 0=used and 1=new; status is availability/publication status, not item condition',
            'condition_mapping_verified' => true,
            'condition_mapped_as_used' => self::OVOKO_USED_QUALITY !== self::OVOKO_NEW_QUALITY,
            'ovoko_new_quality_value' => self::OVOKO_NEW_QUALITY,
            'raw_condition_payload_fields' => ['quality' => self::OVOKO_USED_QUALITY],
        ];
    }

    private function looksLikeTitleCode(string $value, string $title = ''): bool
    {
        $value = trim($value);
        if ($value === '') return false;
        return str_contains($value, ' ') && (mb_strlen($value) > 30 || ($title !== '' && mb_strtolower($value) === mb_strtolower(trim($title))));
    }

    private function extractCodeFromTitleLikeValue(string $value): ?string
    {
        if (preg_match('/\b[A-Z0-9]{2,}[A-Z][A-Z0-9]*\d[A-Z0-9]*\b$/u', trim($value), $matches)) {
            return $matches[0];
        }
        return null;
    }
}
