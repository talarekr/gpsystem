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
            'dashboard',
            'mobile_intake',
            'staging_items',
            'product_catalog',
            'product_command_center',
            'pricing',
            'stock_locations',
            'readiness',
            'woo_sync_preparation',
            'orders',
            'error_center',
            'settings',
            'users_roles',
        ], array_keys(config('product-hub.admin_navigation')));
    }
}
