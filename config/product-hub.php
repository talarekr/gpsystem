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
        'dashboard' => 'Strona główna',
        'analytics' => 'Analityka',
        'vehicles' => 'Samochody',
        'cars_create' => 'Dodaj samochód',
        'cars_index' => 'Wszystkie samochody',
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
    ],

    'sales_channels_navigation' => [
        'allegro_settings' => 'Allegro',
        'woo_sync_preparation' => 'WooCommerce',
        'ebay_de_settings' => 'eBay DE',
        'ebay_fr_settings' => 'eBay FR',
        'ovoko_settings' => 'Ovoko',
    ],

    'admin_navigation_remapped_pages' => [
        'mobile_intake' => 'Części',
        'staging_items' => 'Części',
        'product_catalog' => 'Części',
        'readiness' => 'Zadania',
        'automation_queue_settings' => 'Zadania',
        'woocommerce_settings' => 'Ustawienia',
        'all_settings_subpages' => 'Ustawienia',
    ],

];
