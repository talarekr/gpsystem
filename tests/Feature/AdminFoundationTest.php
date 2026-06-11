<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use Tests\TestCase;

class AdminFoundationTest extends TestCase
{
    public function test_product_hub_risky_integrations_are_disabled_by_default(): void
    {
        $this->assertFalse(config('product-hub.feature_flags.integrations_enabled'));
        $this->assertFalse(config('product-hub.feature_flags.woo_writes_enabled'));
        $this->assertFalse(config('product-hub.feature_flags.marketplace_publishing_enabled'));
        $this->assertFalse(config('product-hub.feature_flags.external_api_writes_enabled'));
        $this->assertFalse(config('product-hub.feature_flags.ebay_publishing_enabled'));
        $this->assertFalse(config('product-hub.feature_flags.allegro_integration_enabled'));
        $this->assertFalse(config('product-hub.feature_flags.ovoko_integration_enabled'));
        $this->assertFalse(config('product-hub.feature_flags.nbp_rates_enabled'));
    }

    public function test_mvp_roles_are_configured(): void
    {
        $this->assertSame([
            UserRole::OwnerAdmin->value => 'Owner/Admin',
            UserRole::Manager->value => 'Manager',
            UserRole::WarehouseProductStaff->value => 'Warehouse/Product Staff',
            UserRole::PricingStaff->value => 'Pricing Staff',
            UserRole::Viewer->value => 'Read-only/Viewer',
        ], config('product-hub.roles'));
    }

    public function test_admin_navigation_placeholders_are_configured(): void
    {
        $this->assertSame([
            'dashboard' => 'Strona główna',
            'analytics' => 'Analityka',
            'vehicles' => 'Samochody',
            'product_command_center' => 'Części',
            'stock_locations' => 'Magazynowanie',
            'orders' => 'Zamówienia',
            'shipments' => 'Przesyłki',
            'error_center' => 'Zadania',
            'allegro_integration' => 'Integracja Allegro',
            'users_roles' => 'Pracownicy',
            'general_settings' => 'Ustawienia',
            'audit_log' => 'Logowania',
            'help' => 'Pomoc',
        ], config('product-hub.admin_navigation'));

        $this->assertSame([
            'allegro_settings' => 'Allegro',
            'woo_sync_preparation' => 'WooCommerce',
            'ebay_de_settings' => 'eBay DE',
            'ebay_fr_settings' => 'eBay FR',
            'ovoko_settings' => 'Ovoko',
        ], config('product-hub.sales_channels_navigation'));

        $this->assertSame([
            'mobile_intake' => 'Części',
            'staging_items' => 'Części',
            'product_catalog' => 'Części',
            'readiness' => 'Zadania',
            'automation_queue_settings' => 'Zadania',
            'woocommerce_settings' => 'Ustawienia',
            'all_settings_subpages' => 'Ustawienia',
        ], config('product-hub.admin_navigation_remapped_pages'));
    }

    public function test_internal_logistics_classes_are_separate_from_ebay_shipping_groups(): void
    {
        $this->assertSame([
            'small' => 'Small',
            'medium' => 'Medium',
            'large' => 'Large',
            'oversize' => 'Oversize',
            'pallet' => 'Pallet',
        ], config('product-hub.internal_logistics_classes'));

        $this->assertSame([
            'shipping_30',
            'shipping_50',
            'shipping_130',
        ], config('product-hub.channel_shipping_groups.ebay'));
    }
}
