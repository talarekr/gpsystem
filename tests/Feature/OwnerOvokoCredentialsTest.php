<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OwnerOvokoCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/tools/ovoko-credentials';
    private const VALUES = ['username' => 'synthetic-user', 'password' => 'synthetic-password<&>', 'user_token' => 'synthetic-token'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \LogicException('Provider calls forbidden'));
        MarketplaceAccount::query()->create([
            'marketplace' => 'ovoko', 'name' => 'Ovoko main', 'code' => 'ovoko_main',
            'api_credentials' => self::VALUES + ['unrelated_secret' => 'must-not-be-returned'],
        ]);
    }

    private function owner(): User
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner-read@test.invalid', 'password' => 'password']);
        $user->assignRole(Role::findOrCreate(UserRole::OwnerAdmin->value, 'web'));

        return $user;
    }

    public function test_get_never_reveals_and_post_only_reads_allowlisted_values(): void
    {
        $user = $this->owner();
        $before = DB::table('marketplace_accounts')->where('code', 'ovoko_main')->first();
        $this->assertStringNotContainsString(self::VALUES['user_token'], $before->api_credentials);
        $queries = [];
        DB::listen(function ($event) use (&$queries) { $queries[] = $event->sql; });
        $this->actingAs($user)->get(self::URL)->assertOk()->assertDontSee(self::VALUES['user_token']);
        $response = $this->postJson(self::URL, ['action' => 'read_saved_credentials'])->assertOk();
        $this->assertSame(['credentials' => self::VALUES], $response->json());
        $this->assertStringNotContainsString('must-not-be-returned', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertEquals($before, DB::table('marketplace_accounts')->where('code', 'ovoko_main')->first());
        foreach ($queries as $sql) $this->assertMatchesRegularExpression('/^select\b/i', $sql);
        Http::assertNothingSent();
    }

    public function test_guest_and_non_owner_cannot_read(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $user = User::query()->create(['name' => 'Reader', 'email' => 'reader-read@test.invalid', 'password' => 'password']);
        $this->actingAs($user)->getJson(self::URL)->assertForbidden();
        $this->postJson(self::URL, ['action' => 'read_saved_credentials'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_bad_ciphertext_has_safe_error_and_no_secret_output(): void
    {
        DB::table('marketplace_accounts')->where('code', 'ovoko_main')->update(['api_credentials' => 'invalid-ciphertext']);
        $this->actingAs($this->owner())->postJson(self::URL, ['action' => 'read_saved_credentials'])
            ->assertStatus(422)->assertExactJson(['error' => 'ovoko_credentials_cannot_be_decrypted']);
        Http::assertNothingSent();
    }

    public function test_missing_account_is_not_created(): void
    {
        DB::table('marketplace_accounts')->where('code', 'ovoko_main')->delete();
        $this->actingAs($this->owner())->postJson(self::URL, ['action' => 'read_saved_credentials'])
            ->assertNotFound()->assertExactJson(['error' => 'ovoko_account_not_found']);
        $this->assertDatabaseMissing('marketplace_accounts', ['code' => 'ovoko_main']);
    }

    public function test_post_requires_csrf_when_unit_test_bypass_is_disabled(): void
    {
        $this->actingAs($this->owner());
        $this->app->instance('env', 'local');
        $this->withSession(['_token' => 'synthetic-csrf'])
            ->postJson(self::URL, ['action' => 'read_saved_credentials'])->assertStatus(419);
        $this->withSession(['_token' => 'synthetic-csrf'])
            ->postJson(self::URL, ['action' => 'read_saved_credentials'], ['X-CSRF-TOKEN' => 'synthetic-csrf'])->assertOk();
        Http::assertNothingSent();
    }
}
