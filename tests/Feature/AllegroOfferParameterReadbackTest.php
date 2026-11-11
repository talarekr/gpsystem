<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceAccount;
use App\Models\User;
use App\Services\MigrationExport\AllegroOfferParameterReadback;
use App\Services\MigrationExport\AllegroParametersSupplement;
use App\Services\MigrationExport\AllegroSchemaRefresh;
use App\Services\MigrationExport\BrowserMigrationSnapshot;
use App\Services\MigrationExport\GpswissMigrationExport;
use App\Services\MigrationExport\MigrationExportException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AllegroOfferParameterReadbackTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $run;

    private string $attempt;

    private string $parent;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/offer-readback-'.Str::uuid();
        config(['migration_export.root' => $this->root.'/exports']);
        $main = MarketplaceAccount::query()->create(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Test', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => ['access_token' => 'SYNTHETIC_ACCESS', 'refresh_token' => 'SYNTHETIC_REFRESH', 'access_token_expires_at' => now()->addDay()->toIso8601String()]]);
        $other = MarketplaceAccount::query()->create(['marketplace' => 'allegro', 'code' => 'other', 'name' => 'Other', 'status' => 'active', 'api_enabled' => true]);
        DB::table('part_categories')->insert(['id' => 7, 'name' => 'Parts']);
        DB::table('marketplace_category_mappings')->insert(['local_category_id' => 7, 'channel' => 'allegro_main', 'external_category_id' => '100']);
        for ($i = 1; $i <= 4; $i++) {
            DB::table('parts')->insert(['id' => $i, 'name' => 'Part '.$i, 'sku' => '000'.$i, 'part_number' => 'OEM-'.$i, 'category_id' => $i < 3 ? 7 : null]);
        }
        $rows = [
            [1, 'allegro_main', '10001', null, $main->id], [1, 'allegro', '10001', null, $main->id],
            [1, 'allegro_main', '10002', null, $main->id], [2, 'allegro_main', null, '10003', $main->id],
            [3, 'allegro_main', '10004', null, $main->id], [4, 'allegro', '10004', null, $main->id],
            [null, 'allegro', '10005', null, $main->id], [3, 'allegro', 'not-an-id', null, $main->id],
            [3, 'allegro_main', '10006', '10007', $main->id], [3, 'allegro_main', '10008', null, $other->id], [1, 'ebay_de', '10009', null, null],
        ];
        foreach ($rows as [$part,$market,$offer,$listing,$account]) {
            DB::table('marketplace_listings')->insert(['part_id' => $part, 'marketplace' => $market, 'external_offer_id' => $offer, 'external_listing_id' => $listing, 'marketplace_account_id' => $account]);
        }
        DB::table('allegro_parameter_selections')->insert(['part_id' => 2, 'allegro_category_id' => '100', 'parameter_id' => 'manual', 'value_id' => 'v1']);
        $prepared = app(GpswissMigrationExport::class)->prepareBrowser(7);
        $this->run = $prepared['run_id'];
        $this->attempt = $prepared['snapshot_attempt_id'];
        $this->parent = $this->root.'/exports/'.$this->run;
        $engine = app(BrowserMigrationSnapshot::class);
        $status = $engine->index($this->parent, $this->attempt);
        for ($i = 0; $i < 300 && ! $status['done'] && ! $status['failed']; $i++) {
            $status = $engine->tick($this->parent, $this->attempt);
        }
        $this->assertTrue($status['done']);
        $this->fake(fn ($r) => Http::response(str_contains($r->url(), '/sale/categories/') ? $this->schema(str_ends_with($r->url(), '/product-parameters')) : $this->offer(basename($r->url())), 200));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function service(): AllegroOfferParameterReadback
    {
        return app(AllegroOfferParameterReadback::class);
    }

    private function fake(callable $callback): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function schema(bool $product): array
    {
        $oe = ['id' => '227345', 'name' => 'OEM', 'type' => 'string', 'required' => true, 'options' => ['describesProduct' => true, 'requiredForProduct' => true]];

        return ['parameters' => $product ? [$oe] : [
            ['id' => 'manual', 'name' => 'Wybór', 'type' => 'dictionary', 'required' => true, 'options' => ['describesProduct' => false], 'dictionary' => [['id' => 'v1', 'value' => 'One'], ['id' => 'v2', 'value' => 'Two']]],
            ['id' => 'extra', 'name' => 'Dodatkowy', 'type' => 'string', 'required' => true, 'options' => ['describesProduct' => false]], $oe,
            ['id' => 'number', 'name' => 'Liczba', 'type' => 'float', 'required' => false], ['id' => 'range', 'name' => 'Zakres', 'type' => 'float', 'required' => false],
        ]];
    }

    private function offer(string $id): array
    {
        $part = $id === '10003' ? 2 : 1;

        return ['id' => $id, 'category' => ['id' => '100'], 'parameters' => [
            ['id' => 'manual', 'valuesIds' => [$id === '10002' ? 'v2' : 'v1']], ['id' => 'extra', 'values' => ['retained extra']], ['id' => 'number', 'values' => ['2.50'], 'unit' => 'kg'], ['id' => 'range', 'rangeValue' => ['from' => '0', 'to' => '10'], 'unit' => 'cm'], ['id' => 'obsolete', 'values' => ['keep old parameter'], 'futureValue' => ['full' => true]],
        ], 'productSet' => [['product' => ['id' => 'product-'.$id, 'parameters' => [['id' => '227345', 'values' => ['OEM-'.$part]]]]]], 'unknownOfferField' => ['keep' => true], 'access_token' => 'MUST_NOT_EXPORT', 'description' => ['sections' => [['items' => [['content' => 'Bearer MUST_NOT_EXPORT_EITHER']]]]]];
    }

    private function plan(): array
    {
        $s = $this->service()->plan(7, $this->run, $this->attempt);
        $this->dir = $this->root.'/exports/.allegro-offer-readback/'.$this->run.'/'.$this->attempt.'/plans/'.$s['plan_id'];
        for ($i = 0; $i < 100 && $s['phase'] === 'planning'; $i++) {
            $s = $this->service()->tick(7, $this->run, $this->attempt);
        }
        $this->assertSame('planned', $s['phase']);

        return $s;
    }

    private function fetch(): array
    {
        $s = $this->plan();
        $s = $this->service()->start(7, $this->run, $this->attempt, $s['plan_digest'], true);
        for ($i = 0; $i < 100 && ! $s['done'] && $s['phase'] !== 'blocked'; $i++) {
            if ($s['wait_seconds'] > 0) {
                $this->travel($s['wait_seconds'] + 1)->seconds();
            }
            $s = $this->service()->tick(7, $this->run, $this->attempt);
        }

        return $s;
    }

    private function schemas(): array
    {
        $r = app(AllegroSchemaRefresh::class);
        $s = $r->plan(7, $this->run, $this->attempt);
        for ($i = 0; $i < 100 && in_array($s['phase'], ['planning', 'preview']); $i++) {
            $s = $r->tick(7, $this->run, $this->attempt);
        }
        $s = $r->start(7, $this->run, $this->attempt, $s['plan_digest'], true);
        for ($i = 0; $i < 100 && ! $s['done']; $i++) {
            $s = $r->tick(7, $this->run, $this->attempt);
        }
        $this->assertTrue($s['done']);

        return $s;
    }

    private function package(): string
    {
        $r = app(AllegroParametersSupplement::class);
        $s = $r->start(7, $this->run, $this->attempt, requireFresh: true, requireReadback: true);
        for ($i = 0; $i < 300 && ! $s['done']; $i++) {
            $s = $r->tick(7, $this->run, $this->attempt);
        }
        $this->assertTrue($s['done']);

        return dirname($r->download(7, $this->run, $this->attempt, 'manifest'));
    }

    private function rows(string $dir, string $name): array
    {
        return array_map(fn ($line) => json_decode($line, true), file($dir.'/'.$name.'.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    private function hashes(): array
    {
        $out = [];
        foreach (File::allFiles($this->parent) as $f) {
            $out[$f->getRelativePathname()] = hash_file('sha256', $f->getPathname());
        }

        return $out;
    }

    public function test_plan_uses_frozen_links_deduplicates_and_excludes_orphans_ids_and_wrong_accounts_without_provider_calls(): void
    {
        DB::table('marketplace_listings')->update(['external_listing_id' => '999999']);
        $before = $this->hashes();
        $queries = [];
        DB::listen(function ($e) use (&$queries) {
            $queries[] = $e->sql;
        });
        $s = $this->plan();
        $this->assertSame(4, $s['parts_with_allegro']);
        $this->assertSame(4, $s['unique_offer_ids']);
        $this->assertSame(4, $s['expected_gets']);
        $this->assertSame(2, $s['duplicate_offer_ids']);
        $this->assertSame(2, $s['invalid_offer_ids']);
        $this->assertSame(1, $s['conflicting_id_fields']);
        $this->assertSame(1, $s['parts_with_multiple_offers']);
        $this->assertSame(1, $s['offers_linked_to_multiple_parts']);
        $this->assertSame(1, $s['unsupported_accounts']);
        $this->assertSame(1, $s['orphan_listings_excluded']);
        Http::assertNothingSent();
        $this->assertSame($before, $this->hashes());
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('marketplace_listings', strtolower($sql));
        }
        $this->assertSame($s['plan_id'], $this->service()->plan(7, $this->run, $this->attempt)['plan_id']);
        try {
            $this->service()->start(7, $this->run, $this->attempt, str_repeat('0', 64), true);
            $this->fail();
        } catch (MigrationExportException $e) {
            $this->assertSame('offer_plan_confirmation_mismatch', $e->reasonCode);
        }
    }

    public function test_full_sanitized_get_receipts_all_value_forms_and_one_get_per_offer_no_business_writes(): void
    {
        $before = $this->hashes();
        $accounts = DB::table('marketplace_accounts')->get()->toJson();
        $queries = [];
        DB::listen(function ($e) use (&$queries) {
            $queries[] = $e->sql;
        });
        $s = $this->fetch();
        $this->assertTrue($s['done']);
        $this->assertSame(4, $s['performed_gets']);
        $this->assertSame(4, $s['successes']);
        Http::assertSentCount(4);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://api.allegro.pl/sale/product-offers/10001');
        $r = $this->service()->receipt($this->dir, '10001');
        $this->assertTrue($r['raw_response']['unknownOfferField']['keep']);
        $this->assertSame(['from' => '0', 'to' => '10'], $r['raw_response']['parameters'][3]['rangeValue']);
        $this->assertSame(hash('sha256', $r['raw_response_text']), $r['sha256']);
        $this->assertStringNotContainsString('MUST_NOT_EXPORT', json_encode($r));
        $this->assertSame($before, $this->hashes());
        $this->assertSame($accounts, DB::table('marketplace_accounts')->get()->toJson());
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(select|pragma)/i', $sql);
        }
        $this->assertSame($s + ['busy' => false], $this->service()->status(7, $this->run, $this->attempt));
        $this->assertTrue($this->service()->tick(7, $this->run, $this->attempt)['done']);
        Http::assertSentCount(4);
    }

    public function test_lost_response_or_checkpoint_adopts_receipt_without_duplicate_get_and_retains_plan(): void
    {
        $s = $this->plan();
        $this->service()->start(7, $this->run, $this->attempt, $s['plan_digest'], true);
        $old = file_get_contents($this->dir.'/checkpoint.json');
        $after = $this->service()->tick(7, $this->run, $this->attempt);
        $this->assertSame(1, $after['processed']);
        file_put_contents($this->dir.'/checkpoint.json', $old);
        $adopted = $this->service()->tick(7, $this->run, $this->attempt);
        $this->assertSame(1, $adopted['processed']);
        $this->assertSame($s['plan_id'], $adopted['plan_id']);
        Http::assertSentCount(1);
    }

    public static function failureCases(): array
    {
        return [['404', 404, false], ['429', 429, false], ['500', 500, false], ['network', null, false], ['malformed', 200, false], ['401', 401, true], ['403', 403, true]];
    }

    #[DataProvider('failureCases')]
    public function test_safe_failure_retry_and_auth_blocking(string $kind, ?int $status, bool $blocked): void
    {
        $this->fake(function () use ($kind, $status) {
            if ($kind === 'network') {
                return Http::failedConnection();
            }

            return Http::response($kind === 'malformed' ? 'not json' : ['access_token' => 'NEVER_ERROR_BODY'], $status, $kind === '429' ? ['Retry-After' => '3'] : []);
        });
        $s = $this->fetch();
        $this->assertSame($blocked ? 'blocked' : 'completed', $s['phase']);
        $this->assertStringNotContainsString('NEVER_ERROR_BODY', json_encode($s));
        $expected = $blocked ? 1 : ($status === 404 ? 4 : 16);
        Http::assertSentCount($expected);
        if ($blocked) {
            $this->assertSame(0, $s['processed']);
            $this->assertContains($s['blocked_reason'], ['oauth_refresh_required', 'forbidden_403']);
        } else {
            $this->assertSame(4, $s['processed']);
        }
        foreach (Http::recorded() as [$r]) {
            $this->assertSame('GET', $r->method());
        }
    }

    public function test_expired_access_token_blocks_without_oauth_post(): void
    {
        $a = MarketplaceAccount::where('code', 'allegro_main')->first();
        $a->api_credentials = ['access_token' => 'SYNTHETIC_EXPIRED', 'refresh_token' => 'DO_NOT_REFRESH', 'access_token_expires_at' => now()->subHour()->toIso8601String()];
        $a->save();
        $s = $this->fetch();
        $this->assertSame('oauth_refresh_required', $s['blocked_reason']);
        $this->assertSame(0, $s['performed_gets']);
        Http::assertNothingSent();
    }

    public function test_supplement_uses_actual_values_preserves_conflicts_unknown_parameters_and_exports_import_csv(): void
    {
        $this->schemas();
        $this->fetch();
        $before = $this->hashes();
        Http::assertSentCount(6);
        $dir = $this->package();
        Http::assertSentCount(6);
        $this->assertSame($before, $this->hashes());
        $rows = $this->rows($dir, 'allegro_existing_offer_parameters');
        $old = collect($rows)->firstWhere('parameter_id', 'obsolete');
        $this->assertTrue($old['historical_schema_mismatch']);
        $this->assertTrue($old['raw_value']['futureValue']['full']);
        $complete = collect($this->rows($dir, 'allegro_parameter_completeness'))->first(fn ($r) => $r['part_id'] === '2' && $r['allegro_category_id'] === '100');
        $this->assertSame('COMPLETE', $complete['status']);
        $this->assertGreaterThan(0, $complete['existing_offer_parameter_count']);
        $this->assertGreaterThan(0, $complete['resolved_from_existing_offer_count']);
        $this->assertSame(1, $complete['product_required_resolved_count']);
        $conflict = collect($this->rows($dir, 'allegro_parameter_conflicts'))->first(fn ($r) => $r['part_id'] === '1' && $r['parameter_id'] === 'manual');
        $this->assertNotNull($conflict);
        $this->assertContains('existing_allegro_offer', array_column($conflict['candidates'], 'provenance'));
        $import = $this->rows($dir, 'allegro_parametry_czesci');
        $selected = collect($import)->first(fn ($r) => $r['part_id'] === '2' && $r['parameter_id'] === 'manual');
        $this->assertSame('existing_allegro_offer', $selected['source']);
        $this->assertSame(2, $selected['candidate_count']);
        $bad = collect($import)->first(fn ($r) => $r['part_id'] === '1' && $r['parameter_id'] === 'manual');
        $this->assertSame('CONFLICT', $bad['status']);
        $this->assertNull($bad['values_ids']);
        $this->assertGreaterThan(1, count($bad['candidates']));
        $manifest = json_decode(file_get_contents($dir.'/manifest.json'), true);
        $this->assertSame(3, $manifest['contract_revision']);
        $this->assertFalse($manifest['provider_calls']);
        $this->assertFalse($manifest['automatic_publish']);
        foreach (['allegro_category_parameters_cache', 'allegro_product_parameters_cache', 'allegro_parameter_mappings', 'allegro_parameter_selections', 'allegro_resolved_parameters', 'allegro_parameter_completeness', 'allegro_parameter_conflicts', 'allegro_schema_freshness', 'allegro_existing_offer_parameters', 'allegro_parametry_czesci'] as $name) {
            $this->assertFileExists($dir.'/'.$name.'.csv');
        }
        $this->assertSame($dir.'/allegro_parametry_czesci.csv', app(AllegroParametersSupplement::class)->download(7, $this->run, $this->attempt, 'parameters-csv'));
    }

    public function test_private_owner_endpoints_and_explicit_package_confirmation(): void
    {
        $u = User::query()->forceCreate(['id' => 7, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'test']);
        Role::findOrCreate(UserRole::OwnerAdmin->value);
        $u->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($u);
        $url = route('admin.tools.gpswiss-migration-export.allegro-offer-readback', ['run' => $this->run]);
        $s = $this->postJson($url, ['parent_attempt_id' => $this->attempt, 'action' => 'plan'])->assertOk()->json();
        Http::assertNothingSent();
        $this->getJson($url.'?parent_attempt_id='.$this->attempt)->assertOk();
        $this->schemas();
        $this->fetch();
        $package = route('admin.tools.gpswiss-migration-export.allegro-parameters-supplement', ['run' => $this->run]);
        $this->postJson($package, ['parent_attempt_id' => $this->attempt, 'action' => 'plan'])->assertOk()->assertJsonPath('plan.status', 'PLANNING');
        $plan = $this->supplementPreview();
        $this->getJson($package.'?parent_attempt_id='.$this->attempt.'&fresh_plan_id='.$plan['fresh_plan_id'].'&readback_plan_id='.$plan['readback_plan_id'])->assertOk()->assertJsonPath('plan.plan_id', $plan['plan']['plan_id'])->assertJsonPath('plan.status', 'READY_FOR_CONFIRMATION')->assertJsonPath('next_action', 'confirm');
        $payload = ['parent_attempt_id' => $this->attempt, 'action' => 'start', 'plan_id' => $plan['plan']['plan_id'], 'plan_digest' => $plan['plan']['plan_digest'], 'confirm' => true, 'fresh_plan_id' => $plan['fresh_plan_id'], 'readback_plan_id' => $plan['readback_plan_id']];
        $this->postJson($package, array_replace($payload, ['fresh_plan_id' => (string) Str::uuid()]))->assertStatus(409)->assertJsonPath('reason_code', 'supplement_refresh_plan_changed');
        $this->postJson($package, array_replace($payload, ['readback_plan_id' => (string) Str::uuid()]))->assertStatus(409)->assertJsonPath('reason_code', 'supplement_offer_plan_changed');
        $this->postJson($package, array_replace($payload, ['plan_id' => $plan['fresh_plan_id']]))->assertStatus(409)->assertJsonPath('reason_code', 'supplement_plan_confirmation_mismatch');
        $this->postJson($package, ['parent_attempt_id' => $this->attempt, 'action' => 'start'])->assertStatus(409)->assertJsonPath('reason_code', 'supplement_plan_confirmation_mismatch');
        $this->postJson($package, ['parent_attempt_id' => $this->attempt, 'action' => 'start', 'confirm' => true, 'plan_id' => $plan['plan']['plan_id'], 'plan_digest' => $plan['plan']['plan_digest'], 'readback_plan_id' => $plan['readback_plan_id'], 'fresh_plan_id' => $plan['fresh_plan_id']])->assertOk();
        $other = User::query()->create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'test']);
        $other->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($other)->getJson($url.'?parent_attempt_id='.$this->attempt)->assertNotFound();
        $other->removeRole(UserRole::OwnerAdmin->value);
        $this->getJson($url.'?parent_attempt_id='.$this->attempt)->assertForbidden();
    }

    public function test_multiple_product_slots_are_retained_and_missing_second_slot_is_not_complete(): void
    {
        $this->fake(function ($r) {
            if (str_contains($r->url(), '/sale/categories/')) {
                return Http::response($this->schema(str_ends_with($r->url(), '/product-parameters')));
            }
            $offer = $this->offer(basename($r->url()));
            $offer['productSet'][] = ['product' => ['id' => 'second-product', 'parameters' => []]];

            return Http::response($offer);
        });
        $this->schemas();
        $this->fetch();
        $dir = $this->package();
        $report = collect($this->rows($dir, 'allegro_parameter_completeness'))->first(fn ($r) => $r['part_id'] === '2' && $r['allegro_category_id'] === '100');
        $this->assertSame('PARTIAL', $report['status']);
        $this->assertSame(2, $report['product_required_count']);
        $this->assertSame(1, $report['product_required_resolved_count']);
        $this->assertContains(['product_set_index' => 1, 'parameter_id' => '227345'], $report['missing_product_slots']);
        $raw = $this->rows($dir, 'allegro_existing_offer_readbacks')[0];
        $this->assertIsString($raw['raw_response_text']);
        $this->assertSame($raw['sha256'], hash('sha256', $raw['raw_response_text']));
    }

    public function test_confirmed_receipt_corruption_blocks_and_new_plan_preserves_previous_files(): void
    {
        $first = $this->fetch();
        $oldDir = $this->dir;
        $hash = hash_file('sha256', $oldDir.'/checkpoint.json');
        $next = $this->service()->plan(7, $this->run, $this->attempt, newPlan: true);
        $this->assertNotSame($first['plan_id'], $next['plan_id']);
        $this->assertSame($hash, hash_file('sha256', $oldDir.'/checkpoint.json'));
        $receipt = $oldDir.'/receipts/'.hash('sha256', '10001').'.json';
        $data = json_decode(file_get_contents($receipt), true);
        $data['data']['raw_response']['id'] = '999';
        file_put_contents($receipt, json_encode($data));
        try {
            $this->service()->receipt($oldDir, '10001');
            $this->fail();
        } catch (MigrationExportException $e) {
            $this->assertSame('offer_receipt_corrupt', $e->reasonCode);
        }
        try {
            $this->service()->withCompletedPlan(7, $this->run, $this->attempt, $first['plan_id'], fn () => null);
            $this->fail();
        } catch (MigrationExportException $e) {
            $this->assertSame('supplement_offer_readback_required', $e->reasonCode);
        }
    }

    public function test_404_readback_does_not_claim_complete_from_local_fallback(): void
    {
        $this->schemas();
        $this->fake(fn () => Http::response(['secret' => 'not-exported'], 404));
        $this->fetch();
        $dir = $this->package();
        $report = collect($this->rows($dir, 'allegro_parameter_completeness'))->first(fn ($r) => $r['part_id'] === '2' && $r['allegro_category_id'] === '100');
        $this->assertSame('SCHEMA_UNKNOWN', $report['status']);
        $this->assertTrue($report['readback_unavailable']);
        $this->assertSame(4, count($this->rows($dir, 'allegro_offer_readback_freshness')));
        $this->assertSame([], $this->rows($dir, 'allegro_existing_offer_parameters'));
    }

    public function test_auth_resume_retains_previously_confirmed_offers_and_wrong_parent_remains_blocked(): void
    {
        $seen = [];
        $denied = true;
        $this->fake(function ($r) use (&$seen, &$denied) {
            $id = basename($r->url());
            $seen[] = $id;
            if ($id === '10002' && $denied) {
                return Http::response([], 403);
            }

            return Http::response($this->offer($id));
        });
        $state = $this->fetch();
        $this->assertSame('blocked', $state['phase']);
        $this->assertSame(1, $state['processed']);
        $denied = false;
        $this->service()->start(7, $this->run, $this->attempt, $state['plan_digest'], true);
        for ($i = 0; $i < 10; $i++) {
            $state = $this->service()->tick(7, $this->run, $this->attempt);
            if ($state['done']) {
                break;
            }
        }
        $this->assertTrue($state['done']);
        $this->assertSame(1, count(array_filter($seen, fn ($id) => $id === '10001')));
        $this->assertSame(2, count(array_filter($seen, fn ($id) => $id === '10002')));
        try {
            $this->service()->status(7, $this->run, (string) Str::uuid());
            $this->fail();
        } catch (MigrationExportException $e) {
            $this->assertSame('supplement_parent_attempt_mismatch', $e->reasonCode);
        }
    }

    public function test_current_account_identity_mismatch_blocks_before_get(): void
    {
        $plan = $this->plan();
        DB::table('marketplace_accounts')->where('code', 'allegro_main')->update(['code' => 'previous-main']);
        MarketplaceAccount::query()->create(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Replacement', 'status' => 'active', 'api_enabled' => true, 'api_credentials' => ['access_token' => 'SYNTHETIC_OTHER']]);
        $this->service()->start(7, $this->run, $this->attempt, $plan['plan_digest'], true);
        $state = $this->service()->tick(7, $this->run, $this->attempt);
        $this->assertSame('offer_account_identity_changed', $state['blocked_reason']);
        $this->assertSame(0, $state['performed_gets']);
        Http::assertNothingSent();
    }

    public function test_offer_retry_after_is_shared_with_schema_refresh_without_repeating_get(): void
    {
        $this->fake(fn () => Http::response([], 429, ['Retry-After' => '10']));
        $plan = $this->plan();
        $this->service()->start(7, $this->run, $this->attempt, $plan['plan_digest'], true);
        $this->service()->tick(7, $this->run, $this->attempt);
        Http::assertSentCount(1);
        $schemas = app(AllegroSchemaRefresh::class);
        $status = $schemas->plan(7, $this->run, $this->attempt);
        for ($i = 0; $i < 100 && in_array($status['phase'], ['planning', 'preview']); $i++) {
            $status = $schemas->tick(7, $this->run, $this->attempt);
        }
        $schemas->start(7, $this->run, $this->attempt, $status['plan_digest'], true);
        $status = $schemas->tick(7, $this->run, $this->attempt);
        Http::assertSentCount(1);
        $this->assertGreaterThan(0, $status['wait_seconds']);
    }

    public function test_category_changed_after_schema_plan_is_unknown_not_falsely_historical_mismatch(): void
    {
        $this->schemas();
        $this->fake(function ($r) {
            $offer = $this->offer(basename($r->url()));
            $offer['category']['id'] = '99999';

            return Http::response($offer);
        });
        $this->fetch();
        $dir = $this->package();
        foreach ($this->rows($dir, 'allegro_existing_offer_parameters') as $row) {
            $this->assertFalse($row['historical_schema_mismatch']);
            $this->assertSame('schema_unknown', $row['schema_status']);
        }
        $report = collect($this->rows($dir, 'allegro_parameter_completeness'))->first(fn ($r) => $r['part_id'] === '2' && $r['allegro_category_id'] === '99999');
        $this->assertSame('SCHEMA_UNKNOWN', $report['status']);
        $this->assertNull($report['required_parameter_count']);
    }

    public function test_parameter_codes_retain_leading_zeroes_without_leaking_secrets(): void
    {
        $this->schemas();
        $this->fake(function ($r) {
            $offer = $this->offer(basename($r->url()));
            // Synthetic OEM code starts with zero; generic card heuristics must not erase parameter values.
            $offer['productSet'][0]['product']['parameters'][0]['values'] = ['0000000000000018'];

            return Http::response($offer);
        });
        $this->fetch();
        $dir = $this->package();
        $row = collect($this->rows($dir, 'allegro_existing_offer_parameters'))->firstWhere('parameter_id', '227345');
        $this->assertSame(['0000000000000018'], $row['values']);
        $raw = $this->rows($dir, 'allegro_existing_offer_readbacks')[0];
        $this->assertStringContainsString('0000000000000018', $raw['raw_response_text']);
        $this->assertStringNotContainsString('MUST_NOT_EXPORT', $raw['raw_response_text']);
    }

    private function supplementPreview(): array
    {
        $service = app(AllegroParametersSupplement::class);
        $state = $service->plan(7, $this->run, $this->attempt);
        for ($i = 0; $i < 400 && $state['next_action'] === 'plan_tick'; $i++) {
            $state = $service->tick(7, $this->run, $this->attempt);
        }
        $this->assertSame('READY_FOR_CONFIRMATION', $state['plan']['status']);
        $this->assertFalse($state['done']);

        return $state;
    }

    private function cFolder(array $state): string
    {
        return $this->root.'/exports/.allegro-parameters-supplements/'.$this->run.'/'.$this->attempt.'/fresh-'.$state['fresh_plan_id'].'/offers-'.$state['readback_plan_id'];
    }

    private function inputHashes(): array
    {
        $out = [];
        foreach (File::allFiles($this->root.'/exports') as $file) {
            if (str_starts_with($file->getRelativePathname(), '.allegro-parameters-supplements/')) {
                continue;
            }
            $out[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
        }

        return $out;
    }

    public function test_c_plan_is_durable_distinct_pinned_and_counts_real_parameters_without_get_or_archives(): void
    {
        $a = $this->schemas();
        $b = $this->fetch();
        $before = $this->inputHashes();
        $this->fake(fn () => throw new \LogicException('Provider forbidden during C'));
        $service = app(AllegroParametersSupplement::class);
        $first = $service->plan(7, $this->run, $this->attempt);
        $this->assertSame('PLANNING', $first['plan']['status']);
        $this->assertTrue(Str::isUuid($first['plan']['plan_id']));
        $this->assertNotSame($a['plan_id'], $first['plan']['plan_id']);
        $this->assertNotSame($b['plan_id'], $first['plan']['plan_id']);
        $this->assertSame($a['plan_id'], $first['plan']['fresh_plan_id']);
        $this->assertSame($b['plan_id'], $first['plan']['readback_plan_id']);
        $this->assertSame($first['plan'], $service->status(7, $this->run, $this->attempt)['plan']);
        $this->assertSame($first['plan']['plan_id'], $service->plan(7, $this->run, $this->attempt)['plan']['plan_id']);
        $state = $this->supplementPreview();
        $plan = $state['plan'];
        $folder = $this->cFolder($state);
        $this->assertSame($first['plan']['plan_id'], $plan['plan_id']);
        $this->assertSame('confirm', $state['next_action']);
        $this->assertSame(4, $plan['parts_total']);
        $this->assertSame(4, $plan['parts_processed']);
        $this->assertSame(4, array_sum($plan['part_statuses']));
        $this->assertGreaterThan(0, $plan['parameters_found']);
        $this->assertGreaterThan(0, $plan['source_values']['existing_allegro_offer']);
        $this->assertGreaterThan(0, $plan['source_values']['manual_selection']);
        $this->assertGreaterThan(0, $plan['source_values']['derived_from_part']);
        $this->assertSame(35, $plan['expected_files']);
        $this->assertSame(0, $plan['provider_gets']);
        $this->assertSame(hash_file('sha256', $folder.'/plan-definition.json'), $plan['plan_digest']);
        $this->assertFileDoesNotExist($folder.'/manifest.json');
        $this->assertFileDoesNotExist($folder.'/archive-manifest.json');
        $this->assertSame([], glob($folder.'/archive-volumes/*.zip'));
        $this->assertSame($state['plan'], app(AllegroParametersSupplement::class)->status(7, $this->run, $this->attempt)['plan']);
        Http::assertNothingSent();
        $this->assertSame($before, $this->inputHashes());
    }

    public function test_completed_b_with_404_is_a_valid_input_and_preview_remains_unconfirmed(): void
    {
        $this->schemas();
        $this->fake(fn () => Http::response([], 404));
        $readback = $this->fetch();
        $this->assertTrue($readback['done']);
        $this->assertSame(4, $readback['not_found_404']);
        $this->fake(fn () => throw new \LogicException('No repeated GET'));
        $state = $this->supplementPreview();
        $folder = $this->cFolder($state);
        $this->assertSame(4, $state['plan']['readback_not_found_404']);
        $this->assertSame(0, $state['plan']['readback_successes']);
        $this->assertSame(4, $state['plan']['part_statuses']['SCHEMA_UNKNOWN']);
        $this->assertNull($state['plan']['confirmed_at']);
        $before = hash_file('sha256', $folder.'/checkpoint.json');
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame($state, app(AllegroParametersSupplement::class)->tick(7, $this->run, $this->attempt));
        }
        $this->assertSame($before, hash_file('sha256', $folder.'/checkpoint.json'));
        $this->assertFileDoesNotExist($folder.'/manifest.json');
        Http::assertNothingSent();
    }

    public function test_c_confirmation_requires_its_own_uuid_and_digest_then_reuses_exact_preview_files(): void
    {
        $this->schemas();
        $this->fetch();
        $this->fake(fn () => throw new \LogicException('Provider forbidden'));
        $service = app(AllegroParametersSupplement::class);
        $state = $this->supplementPreview();
        $plan = $state['plan'];
        $folder = $this->cFolder($state);
        foreach ([[$plan['plan_id'], $plan['plan_digest'], false], [$state['fresh_plan_id'], $plan['plan_digest'], true], [$state['readback_plan_id'], $plan['plan_digest'], true], [$plan['plan_id'], str_repeat('0', 64), true]] as [$id, $digest, $confirm]) {
            try {
                $service->confirmPlan(7, $this->run, $this->attempt, $id, $digest, $confirm);
                $this->fail();
            } catch (MigrationExportException $e) {
                $this->assertSame('supplement_plan_confirmation_mismatch', $e->reasonCode);
            }
        }
        $csv = hash_file('sha256', $folder.'/allegro_parametry_czesci.csv');
        $inputs = $this->inputHashes();
        $state = $service->confirmPlan(7, $this->run, $this->attempt, $plan['plan_id'], $plan['plan_digest'], true);
        $this->assertSame('CONFIRMED', $state['plan']['status']);
        $this->assertSame('manifest', $state['phase']);
        $this->assertSame($state, $service->confirmPlan(7, $this->run, $this->attempt, $plan['plan_id'], $plan['plan_digest'], true));
        $queries = [];
        DB::listen(function ($e) use (&$queries) {
            $queries[] = $e->sql;
        });
        for ($i = 0; $i < 100 && ! $state['done']; $i++) {
            $state = $service->tick(7, $this->run, $this->attempt);
        }
        $this->assertTrue($state['done']);
        $this->assertSame([], $queries);
        $this->assertSame($csv, hash_file('sha256', $folder.'/allegro_parametry_czesci.csv'));
        $this->assertSame($inputs, $this->inputHashes());
        $manifest = json_decode(file_get_contents($folder.'/manifest.json'), true);
        $this->assertSame($plan['plan_id'], $manifest['supplement_plan']['plan_id']);
        $this->assertSame($plan['plan_digest'], $manifest['supplement_plan']['plan_digest']);
        Http::assertNothingSent();
    }

    public function test_c_plan_definition_corruption_blocks_confirmation_without_touching_input_receipts(): void
    {
        $this->schemas();
        $this->fetch();
        $state = $this->supplementPreview();
        $before = $this->inputHashes();
        file_put_contents($this->cFolder($state).'/plan-definition.json', '{}');
        try {
            app(AllegroParametersSupplement::class)->confirmPlan(7, $this->run, $this->attempt, $state['plan']['plan_id'], $state['plan']['plan_digest'], true);
            $this->fail();
        } catch (MigrationExportException $e) {
            $this->assertSame('supplement_plan_integrity_failed', $e->reasonCode);
        }
        $this->assertSame($before, $this->inputHashes());
    }

    public function test_c_preview_lost_checkpoint_replays_local_work_without_duplicate_rows_or_counts(): void
    {
        $this->schemas();
        $this->fetch();
        $this->fake(fn () => throw new \LogicException('No provider'));
        $service = app(AllegroParametersSupplement::class);
        $state = $service->plan(7, $this->run, $this->attempt);
        $folder = $this->cFolder($state);
        $before = file_get_contents($folder.'/checkpoint.json');
        $service->tick(7, $this->run, $this->attempt);
        file_put_contents($folder.'/checkpoint.json', $before);
        $state = $this->supplementPreview();
        $this->assertSame(4, $state['dataset_counts']['allegro_existing_offer_readbacks']);
        $this->assertSame(4, $state['plan']['parts_processed']);
        $this->assertSame(4, array_sum($state['plan']['part_statuses']));
        Http::assertNothingSent();
    }

    public function test_preexisting_started_c_package_remains_resumable_and_is_never_reset_by_plan(): void
    {
        $this->schemas();
        $this->fetch();
        $service = app(AllegroParametersSupplement::class);
        $state = $service->start(7, $this->run, $this->attempt, requireFresh: true, requireReadback: true);
        $folder = $this->cFolder($state);
        $before = hash_file('sha256', $folder.'/checkpoint.json');
        try {
            $service->plan(7, $this->run, $this->attempt);
            $this->fail();
        } catch (MigrationExportException $e) {
            $this->assertSame('supplement_already_started_without_preview', $e->reasonCode);
        }
        $this->assertSame($before, hash_file('sha256', $folder.'/checkpoint.json'));
        for ($i = 0; $i < 300 && ! $state['done']; $i++) {
            $state = $service->tick(7, $this->run, $this->attempt);
        }
        $this->assertTrue($state['done']);
    }
}
