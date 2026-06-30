<?php

namespace Tests\Feature;

use Tests\TestCase;

class EbayTemplateAssetsMaintenanceTest extends TestCase
{
    public function test_ebay_template_asset_is_public_during_frontend_maintenance(): void
    {
        config(['frontend-maintenance.enabled' => true]);

        $assetPath = storage_path('app/imports/ebay-template/icon-packaging.png');
        if (! is_dir(dirname($assetPath))) {
            mkdir(dirname($assetPath), 0775, true);
        }
        file_put_contents($assetPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        $response = $this->get('/ebay-template/assets/icon-packaging.png');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertStringNotContainsString('Trwa przerwa techniczna', $response->getContent());
    }


    public function test_legacy_wp_content_ebay_template_asset_is_public_during_frontend_maintenance(): void
    {
        config(['frontend-maintenance.enabled' => true]);

        $assetPath = storage_path('app/imports/ebay-template/icon-shipping.png');
        if (! is_dir(dirname($assetPath))) {
            mkdir(dirname($assetPath), 0775, true);
        }
        file_put_contents($assetPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        $response = $this->get('/wp-content/uploads/ebay-template/icon-shipping.png');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('Cache-Control', 'public, max-age=86400');
        $this->assertStringNotContainsString('Trwa przerwa techniczna', $response->getContent());
    }

    public function test_legacy_wp_content_ebay_template_asset_uses_filename_allowlist(): void
    {
        config(['frontend-maintenance.enabled' => true]);

        $assetPath = storage_path('app/imports/ebay-template/not-allowed.png');
        if (! is_dir(dirname($assetPath))) {
            mkdir(dirname($assetPath), 0775, true);
        }
        file_put_contents($assetPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        $response = $this->get('/wp-content/uploads/ebay-template/not-allowed.png');

        $response->assertNotFound();
    }

    public function test_storefront_stays_blocked_during_frontend_maintenance(): void
    {
        config(['frontend-maintenance.enabled' => true]);

        $response = $this->get('/czesci');

        $response->assertStatus(503);
        $response->assertSee('Trwa przerwa techniczna', false);
    }
}
