<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceSyncLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApiIntegrationLogger
{
    private const MAX_FIELD_LENGTH = 4096;
    private const JWT_PATTERN = '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/';
    private const SENSITIVE_KEYS = ['password', 'passwd', 'secret', 'token', 'access_token', 'refresh_token', 'authorization', 'authdata', 'api_key', 'apikey', 'labelcontent', 'card', 'payment'];

    public function record(array $data): void
    {
        if (($data['action'] ?? null) === OrderStatusMarketplaceSyncService::ACTION) {
            $data = $this->withOrderStatusSyncMarkers($data);
        }

        try {
            MarketplaceSyncLog::query()->create([
                'marketplace' => (string) ($data['integration'] ?? $data['marketplace'] ?? 'unknown'),
                'action' => (string) ($data['action'] ?? 'api_call'),
                'status' => (string) ($data['status'] ?? 'success'),
                'http_status' => $data['http_status'] ?? $data['error_code'] ?? null,
                'message' => $this->truncate((string) ($data['message'] ?? '')),
                'order_id' => $data['order_id'] ?? null,
                'shipment_id' => $data['shipment_id'] ?? null,
                'marketplace_listing_id' => $data['marketplace_listing_id'] ?? null,
                'part_id' => $data['part_id'] ?? null,
                'duration_ms' => $data['duration_ms'] ?? null,
                'request_id' => $data['request_id'] ?? $data['correlation_id'] ?? null,
                'external_id' => $data['external_id'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'payload' => $this->sanitize([
                    'request' => $data['request'] ?? null,
                    'response' => $data['response'] ?? null,
                    'error' => $data['error'] ?? null,
                    'meta' => Arr::except($data, ['request', 'response', 'error']),
                ]),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('API integration log write failed', ['error' => $exception->getMessage()]);
        }
    }

    public function success(string $integration, string $action, string $message, array $context = []): void
    {
        $this->record($context + compact('integration', 'action', 'message') + ['status' => 'success']);
    }

    public function error(string $integration, string $action, Throwable|string $error, array $context = []): void
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;
        $this->record($context + compact('integration', 'action', 'message') + ['status' => 'error', 'error' => $message]);
    }

    public function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $keyString = strtolower((string) $key);
                $clean[$key] = $this->isSensitiveKey($keyString) ? '[redacted]' : $this->sanitize($item);
            }
            return $clean;
        }

        if (is_object($value)) {
            return $this->sanitize((array) $value);
        }

        if (is_string($value)) {
            return $this->truncate($this->maskSecretsInString($value));
        }

        return $value;
    }

    private function withOrderStatusSyncMarkers(array $data): array
    {
        $markers = [
            'order_status_sync_code_version' => OrderStatusMarketplaceSyncService::CODE_VERSION,
            'code_version' => OrderStatusMarketplaceSyncService::CODE_VERSION,
            'sync_writer' => self::class.'::record',
        ];

        foreach ($markers as $key => $value) {
            $data[$key] ??= $value;
        }

        foreach (['request', 'response', 'error'] as $summaryKey) {
            if (is_array($data[$summaryKey] ?? null)) {
                $data[$summaryKey] = $markers + $data[$summaryKey];
            }
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($key, $sensitive)) {
                return true;
            }
        }
        return false;
    }

    private function maskSecretsInString(string $value): string
    {
        return preg_replace(self::JWT_PATTERN, '[masked_jwt]', $value) ?? $value;
    }

    private function truncate(string $value): string
    {
        return mb_strlen($value) > self::MAX_FIELD_LENGTH ? mb_substr($value, 0, self::MAX_FIELD_LENGTH).'…[truncated]' : $value;
    }
}
