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
            'general_settings',
            'company_shop_identity',
            'users_roles',
            'product_settings',
            'product_intake_settings',
            'categories',
            'attributes_parameters',
            'pricing_settings',
            'stock_warehouse_settings',
            'internal_logistics_classes',
            'channel_settings',
            'woocommerce_settings',
            'ebay_settings',
            'ebay_de_settings',
            'ebay_fr_settings',
            'allegro_settings',
            'ovoko_settings',
            'translation_content_templates',
            'readiness_rules',
            'automation_queue_settings',
            'feature_flags_safety',
            'audit_log',
        ], array_keys(config('product-hub.admin_navigation')));
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
