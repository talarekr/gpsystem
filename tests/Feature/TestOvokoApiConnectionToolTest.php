<?php

namespace Tests\Feature;

use App\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TestOvokoApiConnectionToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_form_data_read_only_parts_endpoint_and_hides_credentials(): void
    {
        Http::fake([
            'api.rrr.lt/v2/get/parts?limit=1&page=1' => Http::response([
                'data' => [['id' => '20', 'name' => 'Engine control unit/module']],
                'pagination' => ['page' => 1, 'limit' => 1, 'total_count' => 1],
                'msg' => 'OK',
                'status_code' => 'R200',
            ], 200),
        ]);

        $account = MarketplaceAccount::query()->create([
            'marketplace' => 'ovoko',
            'code' => 'ovoko_main',
            'name' => 'Ovoko main',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://api.rrr.lt',
            'api_mode' => 'dry_run',
            'api_credentials' => [
                'username' => 'secret-user',
                'password' => 'secret-password',
                'user_token' => 'secret-token',
            ],
        ]);

        $response = $this->getJson('/tools/test-ovoko-api-connection?token=gps_images_import_2026');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('connection_ok', true)
            ->assertJsonPath('endpoint_used', 'https://api.rrr.lt/v2/get/parts?limit=1&page=1')
            ->assertJsonPath('request_method', 'POST')
            ->assertJsonPath('request_format', 'form-data')
            ->assertJsonPath('credentials_configured', true)
            ->assertJsonMissing(['secret-user'])
            ->assertJsonMissing(['secret-password'])
            ->assertJsonMissing(['secret-token']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.rrr.lt/v2/get/parts?limit=1&page=1'
                && str_contains($request->header('Content-Type')[0] ?? '', 'application/x-www-form-urlencoded')
                && $request['username'] === 'secret-user'
                && $request['password'] === 'secret-password'
                && $request['user_token'] === 'secret-token';
        });

        $account->refresh();
        $this->assertNotNull($account->last_connection_check_at);
        $this->assertSame('ok', $account->last_connection_status);
        $this->assertSame('Ovoko/RRR API connection test succeeded.', $account->last_connection_message);
    }

    public function test_http_200_api_error_is_not_connection_ok(): void
    {
        Http::fake([
            'api.rrr.lt/v2/get/parts?limit=1&page=1' => Http::response([
                'msg' => 'Invalid credentials',
                'status_code' => 'R401',
            ], 200),
        ]);

        MarketplaceAccount::query()->create([
            'marketplace' => 'ovoko',
            'code' => 'ovoko_main',
            'name' => 'Ovoko main',
            'status' => 'active',
            'api_enabled' => true,
            'api_base_url' => 'https://api.rrr.lt',
            'api_mode' => 'dry_run',
            'api_credentials' => [
                'username' => 'secret-user',
                'password' => 'secret-password',
                'user_token' => 'secret-token',
            ],
        ]);

        $this->getJson('/tools/test-ovoko-api-connection?token=gps_images_import_2026')
            ->assertOk()
            ->assertJsonPath('connection_ok', false)
            ->assertJsonPath('api_status_code', 'R401')
            ->assertJsonPath('api_status_message', 'Invalid credentials');
    }

    public function test_it_blocks_non_dry_run_configuration_before_calling_api(): void
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
            'api_credentials' => [
                'username' => 'secret-user',
                'password' => 'secret-password',
                'user_token' => 'secret-token',
            ],
        ]);

        $this->getJson('/tools/test-ovoko-api-connection?token=gps_images_import_2026')
            ->assertStatus(422)
            ->assertJsonPath('connection_ok', false)
            ->assertJsonPath('api_status_message', 'Ovoko API connection test is allowed only in dry_run mode.');

        Http::assertNothingSent();
    }
}
