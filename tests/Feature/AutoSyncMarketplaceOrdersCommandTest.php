<?php

namespace Tests\Feature;

use App\Services\Marketplace\ApiIntegrationLogger;
use App\Services\Marketplace\MarketplaceOrdersImportService;
use Mockery;
use Tests\TestCase;

class AutoSyncMarketplaceOrdersCommandTest extends TestCase
{
    public function test_scheduler_command_does_not_sync_when_disabled(): void
    {
        config(['marketplace_order_sync.enabled' => false]);

        $service = Mockery::mock(MarketplaceOrdersImportService::class);
        $service->shouldNotReceive('run');
        $this->app->instance(MarketplaceOrdersImportService::class, $service);

        $this->artisan('marketplace:auto-sync-orders')->assertSuccessful();
    }

    public function test_scheduler_command_syncs_configured_allegro_and_ebay_when_enabled(): void
    {
        config([
            'marketplace_order_sync.enabled' => true,
            'marketplace_order_sync.lookback_days' => 3,
            'marketplace_order_sync.channels' => 'allegro,ebay',
        ]);

        $service = Mockery::mock(MarketplaceOrdersImportService::class);
        $service->shouldReceive('run')->once()->with(Mockery::on(function (array $options): bool {
            return $options['channels'] === 'allegro,ebay'
                && $options['dry_run'] === false
                && $options['live_import'] === true
                && $options['trigger'] === 'scheduler'
                && isset($options['since']);
        }))->andReturn([
            'orders_fetched' => 2,
            'orders_created' => 1,
            'orders_updated' => 1,
            'orders_skipped' => 0,
            'errors' => [],
        ]);
        $this->app->instance(MarketplaceOrdersImportService::class, $service);

        $logger = Mockery::mock(ApiIntegrationLogger::class);
        $logger->shouldReceive('record')->once()->with(Mockery::on(function (array $data): bool {
            $flags = $data['response']['safety_flags'] ?? [];

            return $data['action'] === 'scheduled_order_sync'
                && $data['response']['channels'] === ['allegro', 'ebay']
                && $data['response']['timezone'] === 'Europe/Warsaw'
                && ($flags['read_only_marketplace_api'] ?? false) === true
                && ($flags['no_stock_sync'] ?? false) === true
                && ($flags['no_listings_sync'] ?? false) === true
                && ($flags['no_price_sync'] ?? false) === true
                && ($flags['no_shipment_sync'] ?? false) === true
                && ($flags['no_marketplace_write'] ?? false) === true;
        }));
        $this->app->instance(ApiIntegrationLogger::class, $logger);

        $this->artisan('marketplace:auto-sync-orders')->assertSuccessful();
    }
}
