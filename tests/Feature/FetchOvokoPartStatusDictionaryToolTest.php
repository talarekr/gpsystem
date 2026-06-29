<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchOvokoPartStatusDictionaryToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fetches_part_status_dictionary_read_only_and_logs_safe_id_labels(): void
    {
        Http::fake([
            'api.rrr.lt/get/part_status' => Http::response([
                'status_code' => 'R200',
                'msg' => 'OK',
                'list' => [
                    ['id' => 1, 'name' => 'Available'],
                    ['id' => 2, 'name' => 'Sold'],
                ],
            ], 200),
        ]);

        MarketplaceAccount::query()->create([
            'marketplace' => 'ovoko',
            'code' => 'ovoko_main',
            'name' => 'Ovoko main',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://api.rrr.lt',
            'api_mode' => 'live',
            'api_credentials' => [
                'username' => 'secret-user',
                'password' => 'secret-password',
                'user_token' => 'secret-token',
            ],
            'api_settings' => ['default_part_status' => 9],
        ]);

        $response = $this->getJson('/tools/fetch-ovoko-part-status-dictionary?token=gps_images_import_2026');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('local_update', false)
            ->assertJsonPath('will_import_part', false)
            ->assertJsonPath('will_update_part', false)
            ->assertJsonPath('will_sync_stock', false)
            ->assertJsonPath('will_import_orders', false)
            ->assertJsonPath('endpoint_path', '/get/part_status')
            ->assertJsonPath('request_method', 'POST')
            ->assertJsonPath('request_format', 'form-data')
            ->assertJsonPath('configured_default_part_status', 9)
            ->assertJsonPath('statuses.0.id', '1')
            ->assertJsonPath('statuses.0.label', 'Available')
            ->assertJsonPath('statuses.1.id', '2')
            ->assertJsonPath('statuses.1.label', 'Sold')
            ->assertJsonMissing(['secret-user'])
            ->assertJsonMissing(['secret-password'])
            ->assertJsonMissing(['secret-token']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.rrr.lt/get/part_status'
                && str_contains($request->header('Content-Type')[0] ?? '', 'application/x-www-form-urlencoded')
                && $request['username'] === 'secret-user'
                && $request['password'] === 'secret-password'
                && $request['user_token'] === 'secret-token';
        });

        $log = MarketplaceSyncLog::query()->where('action', 'fetch_part_status_dictionary')->firstOrFail();
        $this->assertSame('ovoko', $log->marketplace);
        $this->assertSame('success', $log->status);
        $this->assertSame([
            ['id' => '1', 'label' => 'Available'],
            ['id' => '2', 'label' => 'Sold'],
        ], $log->payload['response']['statuses_safe']);
        $this->assertSame(false, $log->payload['meta']['ovoko_write']);
        $this->assertJsonStringNotContainsString('secret-password', json_encode($log->payload));
    }

    public function test_it_does_not_call_api_when_credentials_are_missing(): void
    {
        Http::fake();

        MarketplaceAccount::query()->create([
            'marketplace' => 'ovoko',
            'code' => 'ovoko_main',
            'name' => 'Ovoko main',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://api.rrr.lt',
            'api_mode' => 'live',
            'api_credentials' => ['username' => 'u'],
        ]);

        $this->getJson('/tools/fetch-ovoko-part-status-dictionary?token=gps_images_import_2026')
            ->assertStatus(422)
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('ovoko_write', false)
            ->assertJsonPath('api_status_message', 'Ovoko API credentials are not fully configured.');

        Http::assertNothingSent();
    }
}
