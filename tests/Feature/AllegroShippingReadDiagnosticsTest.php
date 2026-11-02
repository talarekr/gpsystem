<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Marketplace\ShippingRateAudit\AllegroShippingReadClient;
use App\Services\Marketplace\ShippingRateAudit\ShippingRateAuditService;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AllegroShippingReadDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $credentials = []): object
    {
        return (object) ['id' => 2, 'marketplace' => 'allegro', 'name' => 'Local label is not identity proof',
            'code' => 'allegro_main', 'api_enabled' => true, 'status' => 'active', 'api_base_url' => 'https://api.allegro.pl',
            'api_credentials' => Crypt::encryptString(json_encode(array_replace([
                'access_token' => 'ACCESS_SECRET', 'refresh_token' => 'REFRESH_SECRET', 'client_id' => 'CLIENT_SECRET_ID',
                'client_secret' => 'CLIENT_SECRET', 'access_token_expires_at' => now()->addHour()->toIso8601String(),
            ], $credentials)))];
    }

    #[DataProvider('responses')]
    public function test_http_status_is_distinguished_without_refresh_or_secret_output(int $status, string $reason): void
    {
        Http::fake(fn () => Http::response(['errors' => ['ACCESS_SECRET REFRESH_SECRET CLIENT_SECRET']], $status));
        $r = app(AllegroShippingReadClient::class)->get($this->account(), '/sale/shipping-rates');
        $this->assertSame($status, $r['http_status']);
        $this->assertSame($reason, $r['reason']);
        $this->assertFalse($r['oauth_refresh']);
        $this->assertSame($status === 401 ? true : null, $r['requires_refresh']);
        $this->assertStringNotContainsString('SECRET', json_encode($r));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
    }

    public static function responses(): array
    {
        return [[401, 'unauthorized_401'], [403, 'forbidden_403'], [429, 'provider_429'],
            [500, 'provider_5xx'], [503, 'provider_5xx'], [404, 'endpoint_error'], [302, 'endpoint_error']];
    }

    public function test_expired_token_has_no_get_or_refresh_and_reports_zero_write_block(): void
    {
        Http::fake();
        $r = app(AllegroShippingReadClient::class)->get($this->account(['access_token_expires_at' => now()->subMinute()->toIso8601String()]), '/sale/shipping-rates');
        $this->assertSame('oauth_refresh_required', $r['reason']);
        $this->assertSame('access_token_expired', $r['cause']);
        $this->assertSame('oauth_refresh_blocked_by_zero_write', $r['refresh_blocked_reason']);
        $this->assertTrue($r['token_expired']);
        $this->assertTrue($r['has_refresh_token']);
        $this->assertTrue($r['requires_refresh']);
        $this->assertNull($r['http_status']);
        $this->assertFalse($r['request_attempted']);
        Http::assertNothingSent();
    }

    public function test_local_account_and_credential_failures_are_separate(): void
    {
        Http::fake();
        $client = app(AllegroShippingReadClient::class);
        $this->assertSame('account_missing', $client->get(null, '/me')['reason']);
        $disabled = $this->account();
        $disabled->api_enabled = false;
        $this->assertSame('account_disabled', $client->get($disabled, '/me')['reason']);
        $empty = $this->account();
        $empty->api_credentials = null;
        $this->assertSame('credentials_missing', $client->get($empty, '/me')['reason']);
        $broken = $this->account();
        $broken->api_credentials = 'not-encrypted-SECRET';
        $r = $client->get($broken, '/me');
        $this->assertSame('credentials_unreadable', $r['reason']);
        $this->assertNull($r['has_access_token']);
        $this->assertStringNotContainsString('SECRET', json_encode($r));
        $this->assertSame('access_token_missing', $client->get($this->account(['access_token' => null]), '/me')['reason']);
        $endpoint = $this->account();
        $endpoint->api_base_url = 'https://untrusted.invalid';
        $this->assertSame('endpoint_error', $client->get($endpoint, '/me')['reason']);
        Http::assertNothingSent();
    }

    public function test_network_timeout_is_safe_and_not_a_credential_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('https://secret.invalid/?token=ACCESS_SECRET'));
        $r = app(AllegroShippingReadClient::class)->get($this->account(), '/sale/shipping-rates');
        $this->assertSame('network_error', $r['reason']);
        $this->assertNull($r['http_status']);
        $this->assertStringNotContainsString('SECRET', json_encode($r));
    }

    public function test_malformed_response_is_not_a_network_error(): void
    {
        Http::fake(fn () => Http::response('<html>ACCESS_SECRET</html>', 200));
        $r = app(AllegroShippingReadClient::class)->get($this->account(), '/sale/shipping-rates');
        $this->assertSame('invalid_response', $r['reason']);
        $this->assertSame(200, $r['http_status']);
        $this->assertStringNotContainsString('SECRET', json_encode($r));
    }

    public function test_missing_expiry_is_unknown_not_expired_and_get_can_succeed(): void
    {
        Http::fake(fn () => Http::response(['shippingRates' => []]));
        $r = app(AllegroShippingReadClient::class)->get($this->account(['access_token_expires_at' => null]), '/sale/shipping-rates');
        $this->assertTrue($r['ok']);
        $this->assertNull($r['token_expired']);
        $this->assertFalse($r['requires_refresh']);
        $this->assertSame(200, $r['http_status']);
    }

    public function test_targeted_diagnosis_confirms_identity_and_catalog_without_product_reads_or_writes(): void
    {
        DB::table('marketplace_accounts')->insert((array) $this->account());
        $before = DB::table('marketplace_accounts')->get()->toJson();
        Http::fake(['*/me' => Http::response(['login' => 'Maxhendler', 'client_secret' => 'SECRET']),
            '*/shipping-rates' => Http::response(['shippingRates' => [['id' => 'rate', 'name' => 'LISTY']]])]);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $r = app(ShippingRateAuditService::class)->diagnoseMainAccount();
        $this->assertTrue($r['account_id_code_match']);
        $this->assertTrue($r['identity']['matches_maxhendler']);
        $this->assertTrue($r['maxhendler_identity_confirmed']);
        $this->assertSame('Maxhendler', $r['identity']['provider_login']);
        $this->assertTrue($r['shipping_rates']['ok']);
        $this->assertSame(200, $r['shipping_rates']['http_status']);
        $this->assertSame(1, $r['shipping_rates']['shipping_rates_count']);
        $this->assertStringNotContainsString('SECRET', json_encode($r));
        foreach (ShippingRateAuditService::WRITES as $flag => $value) {
            $this->assertFalse($r[$flag]);
        }
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $sql);
            $this->assertStringNotContainsString('parts', $sql);
        }
        $this->assertSame($before, DB::table('marketplace_accounts')->get()->toJson());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
    }

    public function test_target_account_missing_or_mismatched_never_falls_back(): void
    {
        Http::fake();
        $service = app(ShippingRateAuditService::class);
        $this->assertSame('account_missing', $service->diagnoseMainAccount()['reason_code']);
        $a = $this->account();
        $a->id = 3;
        DB::table('marketplace_accounts')->insert((array) $a);
        $other = $this->account();
        $other->code = 'other';
        DB::table('marketplace_accounts')->insert((array) $other);
        $this->assertSame('account_mismatch', $service->diagnoseMainAccount()['reason_code']);
        Http::assertNothingSent();
    }

    public function test_structurally_invalid_catalog_keeps_http_200_but_blocks_success(): void
    {
        DB::table('marketplace_accounts')->insert((array) $this->account());
        Http::fake(['*/me' => Http::response(['login' => 'someone-else']), '*/shipping-rates' => Http::response(['wrong' => 'SECRET'])]);
        $r = app(ShippingRateAuditService::class)->diagnoseMainAccount();
        $this->assertFalse($r['identity']['matches_maxhendler']);
        $this->assertSame('invalid_response', $r['shipping_rates']['reason']);
        $this->assertSame(200, $r['shipping_rates']['http_status']);
        $this->assertStringNotContainsString('SECRET', json_encode($r));
    }

    public function test_targeted_diagnosis_route_requires_admin_and_returns_only_read_results(): void
    {
        $url = '/admin/tools/allegro/shipping-rate-backfill?mode=diagnose';
        $this->getJson($url)->assertUnauthorized();
        $this->seed(RoleSeeder::class);
        $admin = User::create(['name' => 'Owner', 'email' => 'diagnosis@example.test', 'password' => 'password']);
        $admin->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::fake();
        $this->getJson($url)->assertOk()->assertJsonPath('reason_code', 'account_missing')
            ->assertJsonPath('database_writes', false)->assertJsonPath('oauth_refresh', false)
            ->assertJsonMissingPath('rows');
        Http::assertNothingSent();
    }
}
