<?php

use App\Enums\UserRole;

return [
    'ui' => [
        'primary_color' => '#0B1F3A',
        'staff_locale' => 'pl',
    ],

    'roles' => [
        UserRole::OwnerAdmin->value => 'Owner/Admin',
        UserRole::Manager->value => 'Manager',
        UserRole::WarehouseProductStaff->value => 'Warehouse/Product Staff',
        UserRole::PricingStaff->value => 'Pricing Staff',
        UserRole::Viewer->value => 'Read-only/Viewer',
    ],

    'feature_flags' => [
        'integrations_enabled' => (bool) env('GPS_INTEGRATIONS_ENABLED', false),
        'woo_writes_enabled' => (bool) env('GPS_WOO_WRITES_ENABLED', false),
        'marketplace_publishing_enabled' => (bool) env('GPS_MARKETPLACE_PUBLISHING_ENABLED', false),
        'external_api_writes_enabled' => (bool) env('GPS_EXTERNAL_API_WRITES_ENABLED', false),
        'ebay_publishing_enabled' => (bool) env('GPS_EBAY_PUBLISHING_ENABLED', false),
        'allegro_integration_enabled' => (bool) env('GPS_ALLEGRO_INTEGRATION_ENABLED', false),
        'ovoko_integration_enabled' => (bool) env('GPS_OVOKO_INTEGRATION_ENABLED', false),
        'nbp_rates_enabled' => (bool) env('GPS_NBP_RATES_ENABLED', false),
    ],

    'internal_logistics_classes' => [
        'small' => 'Small',
        'medium' => 'Medium',
        'large' => 'Large',
        'oversize' => 'Oversize',
        'pallet' => 'Pallet',
    ],

    'channel_shipping_groups' => [
        'ebay' => [
            'shipping_30',
            'shipping_50',
            'shipping_130',
        ],
    ],

    'admin_navigation' => [
        'dashboard' => 'Dashboard',
        'mobile_intake' => 'Mobile Intake',
        'staging_items' => 'Staging Items',
        'product_catalog' => 'Product Catalog',
        'product_command_center' => 'Product Command Center',
        'pricing' => 'Pricing',
        'stock_locations' => 'Stock / Locations',
        'readiness' => 'Readiness',
        'woo_sync_preparation' => 'Woo Sync Preparation',
        'orders' => 'Orders',
        'error_center' => 'Error Center',
        'general_settings' => 'General Settings',
        'company_shop_identity' => 'Company / Shop Identity',
        'users_roles' => 'Users & Roles',
        'product_settings' => 'Product Settings',
        'product_intake_settings' => 'Product Intake Settings',
        'categories' => 'Categories',
        'attributes_parameters' => 'Attributes / Parameters',
        'pricing_settings' => 'Pricing Settings',
        'stock_warehouse_settings' => 'Stock / Warehouse Settings',
        'internal_logistics_classes' => 'Internal Logistics Classes',
        'channel_settings' => 'Channel Settings',
        'woocommerce_settings' => 'WooCommerce Settings',
        'ebay_settings' => 'eBay Settings',
        'ebay_de_settings' => 'eBay DE Settings',
        'ebay_fr_settings' => 'eBay FR Settings',
        'allegro_settings' => 'Allegro Settings',
        'ovoko_settings' => 'Ovoko Settings',
        'translation_content_templates' => 'Translation / Content Templates',
        'readiness_rules' => 'Readiness Rules',
        'automation_queue_settings' => 'Automation / Queue Settings',
        'feature_flags_safety' => 'Feature Flags / Safety',
        'audit_log' => 'Audit Log',
    ],
];
