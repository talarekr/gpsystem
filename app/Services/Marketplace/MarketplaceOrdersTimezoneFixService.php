<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceSyncLog;
use App\Models\Order;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MarketplaceOrdersTimezoneFixService
{
    public const CONFIRM = 'orders-timezone-fix';

    public function __construct(private readonly MarketplaceOrderTimeService $timeService) {}

    public function run(array $options): array
    {
        $channels = $this->channels((string) ($options['channels'] ?? 'allegro,ebay'));
        $since = $this->since((string) ($options['since'] ?? '2026-06-29 00:00:00'));
        $apply = (bool) ($options['apply'] ?? false);
        $confirmed = hash_equals(self::CONFIRM, (string) ($options['confirm'] ?? ''));
        $dryRun = ! ($apply && $confirmed);
        $schema = $this->schemaDiagnostics();

        $summary = [
            'ok' => true,
            'dry_run' => $dryRun,
            'apply_requested' => $apply,
            'confirm_ok' => $confirmed,
            'timezone' => MarketplaceOrderTimeService::LOCAL_TIMEZONE,
            'channels' => $channels,
            'since' => $since,
            'orders_checked' => 0,
            'orders_would_update' => 0,
            'orders_updated' => 0,
            'sample' => [],
            'diagnostics' => [],
            'schema' => $schema,
            'safety_flags' => [
                'read_only' => $dryRun,
                'orders_changed' => false,
                'order_items_changed' => false,
                'stock_changed' => false,
                'listings_changed' => false,
                'prices_changed' => false,
                'shipments_changed' => false,
                'marketplace_write' => false,
                'allegro_write' => false,
                'ebay_write' => false,
            ],
        ];

        if (! $schema['orders_table_exists'] || ! $schema['orders_columns']['marketplace']['exists']) {
            $summary['ok'] = false;
            $summary['diagnostics'][] = [
                'level' => 'error',
                'message' => 'orders table or orders.marketplace column is missing; timezone fix cannot scope Allegro/eBay orders safely.',
                'would_update' => false,
                'correction_method' => 'manual_offset',
            ];
            $this->writeSyncLog($summary, $dryRun);

            return $summary;
        }

        Order::query()
            ->whereIn('marketplace', $channels)
            ->chunkById(100, function ($orders) use (&$summary, $dryRun, $since): void {
                foreach ($orders as $order) {
                    $diagnostic = $this->diagnostic($order);
                    if (! $this->inScope($diagnostic, $since)) {
                        continue;
                    }

                    $summary['orders_checked']++;
                    if ($diagnostic['would_update']) {
                        $summary['orders_would_update']++;
                    }

                    if (count($summary['sample']) < 10) {
                        $summary['sample'][] = Arr::only($diagnostic, ['order_id','marketplace','marketplace_order_id','current_ordered_at','source_utc_ordered_at','corrected_ordered_at_local','correction_method','would_update','already_corrected','source_diagnostics']);
                    }
                    if (count($summary['diagnostics']) < 100) {
                        $summary['diagnostics'][] = $diagnostic;
                    }

                    if (! $dryRun && $diagnostic['would_update'] && in_array($diagnostic['correction_method'], ['metadata_utc'], true)) {
                        $meta = is_array($order->meta) ? $order->meta : [];
                        $meta['orders_timezone_fix'] = [
                            'corrected_at' => now()->toISOString(),
                            'from' => $diagnostic['current_ordered_at'],
                            'to' => $diagnostic['corrected_ordered_at_local'],
                            'source_utc_ordered_at' => $diagnostic['source_utc_ordered_at'],
                        ];
                        $order->forceFill(['ordered_at' => $diagnostic['corrected_ordered_at_local'], 'meta' => $meta])->save();
                        $summary['orders_updated']++;
                    }
                }
            });

        $summary['safety_flags']['orders_changed'] = $summary['orders_updated'] > 0;
        $this->writeSyncLog($summary, $dryRun);

        return $summary;
    }

    private function diagnostic(Order $order): array
    {
        $source = $this->payloadDiagnostics($order);
        $sourceUtc = $this->sourceUtcOrderedAt($order, $source['raw_payload_array']);
        $corrected = $this->timeService->marketplaceUtcToLocalStorage($sourceUtc);
        $current = $this->timeService->localDisplay($order->ordered_at);
        $alreadyCorrected = filled(data_get($source['meta_array'], 'orders_timezone_fix.corrected_at')) || ($corrected !== null && $current === $corrected);
        $wouldUpdate = $corrected !== null && ! $alreadyCorrected && $current !== $corrected;

        return [
            'order_id' => $order->id,
            'marketplace' => $order->marketplace,
            'marketplace_order_id' => $order->marketplace_order_id,
            'current_ordered_at' => $current,
            'source_utc_ordered_at' => $sourceUtc === null ? null : $this->timeService->marketplaceUtcIso($sourceUtc),
            'corrected_ordered_at_local' => $corrected,
            'correction_method' => $sourceUtc === null ? 'manual_offset' : 'metadata_utc',
            'would_update' => $wouldUpdate,
            'already_corrected' => $alreadyCorrected,
            'source_diagnostics' => Arr::except($source, ['raw_payload_array', 'meta_array']),
        ];
    }

    private function inScope(array $diagnostic, string $since): bool
    {
        $basis = $diagnostic['corrected_ordered_at_local'] ?? $diagnostic['current_ordered_at'] ?? null;

        return is_string($basis) && $basis >= $since;
    }

    private function sourceUtcOrderedAt(Order $order, array $raw): ?string
    {
        $keys = $order->marketplace === 'allegro'
            ? ['boughtAt', 'orderedAt', 'purchasedAt', 'createdAt', 'creationDate', 'created_at', 'checkoutCompletedAt']
            : ['boughtAt', 'orderedAt', 'purchasedAt', 'creationDate', 'createdAt', 'created_at'];

        foreach ($keys as $key) {
            if ($this->timeService->parseMarketplaceUtc($raw[$key] ?? null)) {
                return $raw[$key];
            }
        }

        if ($order->marketplace === 'allegro') {
            $items = array_values(array_filter($raw['lineItems'] ?? [], 'is_array'));
            $dates = [];
            foreach ($items as $item) {
                if ($this->timeService->parseMarketplaceUtc($item['boughtAt'] ?? null)) {
                    $dates[] = $item['boughtAt'];
                }
            }
            sort($dates);
            return $dates[0] ?? null;
        }

        return null;
    }

    private function payloadDiagnostics(Order $order): array
    {
        return [
            'raw_payload_column_exists' => Schema::hasColumn('orders', 'raw_payload'),
            'raw_payload_raw_type' => get_debug_type($order->getRawOriginal('raw_payload')),
            'raw_payload_cast_type' => get_debug_type($order->raw_payload),
            'raw_payload_array' => is_array($order->raw_payload) ? $order->raw_payload : [],
            'raw_payload_usable' => is_array($order->raw_payload),
            'meta_column_exists' => Schema::hasColumn('orders', 'meta'),
            'meta_raw_type' => get_debug_type($order->getRawOriginal('meta')),
            'meta_cast_type' => get_debug_type($order->meta),
            'meta_array' => is_array($order->meta) ? $order->meta : [],
            'meta_usable' => is_array($order->meta),
        ];
    }

    private function schemaDiagnostics(): array
    {
        $columns = ['id', 'marketplace', 'marketplace_order_id', 'ordered_at', 'raw_payload', 'meta'];
        $ordersExists = Schema::hasTable('orders');
        $orderColumns = [];
        foreach ($columns as $column) {
            $orderColumns[$column] = [
                'exists' => $ordersExists && Schema::hasColumn('orders', $column),
                'type' => $ordersExists && Schema::hasColumn('orders', $column) ? Schema::getColumnType('orders', $column) : null,
            ];
        }

        $logColumns = [];
        $logExists = Schema::hasTable('marketplace_sync_logs');
        foreach (['marketplace','action','status','message','payload','created_at','order_id','shipment_id','http_status','duration_ms','request_id','external_id','tracking_number'] as $column) {
            $logColumns[$column] = $logExists && Schema::hasColumn('marketplace_sync_logs', $column);
        }

        return [
            'orders_table_exists' => $ordersExists,
            'orders_columns' => $orderColumns,
            'order_model_casts' => (new Order())->getCasts(),
            'marketplace_sync_logs_table_exists' => $logExists,
            'marketplace_sync_log_columns' => $logColumns,
        ];
    }

    private function writeSyncLog(array $summary, bool $dryRun): void
    {
        if (! Schema::hasTable('marketplace_sync_logs')) {
            return;
        }

        $attributes = [
            'marketplace' => 'marketplace',
            'action' => 'orders_timezone_fix',
            'status' => $summary['orders_updated'] > 0 ? 'success' : 'dry_run',
            'message' => $dryRun ? 'Dry-run local marketplace order timezone correction.' : 'Applied local marketplace order timezone correction.',
            'payload' => Arr::except($summary, ['diagnostics']),
            'created_at' => now(),
        ];

        $attributes = array_filter(
            $attributes,
            fn (string $column): bool => Schema::hasColumn('marketplace_sync_logs', $column),
            ARRAY_FILTER_USE_KEY
        );

        try {
            MarketplaceSyncLog::query()->create($attributes);
        } catch (Throwable $exception) {
            // Keep the diagnostic endpoint available even if the audit table is out of sync.
        }
    }

    private function since(string $value): string
    {
        try {
            $requested = Carbon::parse($value, MarketplaceOrderTimeService::LOCAL_TIMEZONE);
        } catch (Throwable) {
            $requested = Carbon::parse('2026-06-29 00:00:00', MarketplaceOrderTimeService::LOCAL_TIMEZONE);
        }

        $minimum = Carbon::parse('2026-06-29 00:00:00', MarketplaceOrderTimeService::LOCAL_TIMEZONE);

        return ($requested->lt($minimum) ? $minimum : $requested)->format('Y-m-d H:i:s');
    }

    private function channels(string $value): array
    {
        $channels = array_map('trim', explode(',', strtolower($value)));
        $channels = array_values(array_intersect($channels, ['allegro', 'ebay']));

        return $channels === [] ? ['allegro', 'ebay'] : $channels;
    }
}
