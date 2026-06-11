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
        'settings' => 'Settings',
        'users_roles' => 'Users / Roles',
    ],
];
