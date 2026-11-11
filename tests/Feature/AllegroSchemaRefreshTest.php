<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceAccount;
use App\Models\User;
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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AllegroSchemaRefreshTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $run;

    private string $attempt;

    private string $parent;

    private string $dir;

    private bool $missingValue = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/schema-refresh-'.Str::uuid();
        config(['migration_export.root' => $this->root.'/exports']);
        Http::preventStrayRequests();
        foreach ([7, 8, 999] as $id) {
            DB::table('part_categories')->insert(['id' => $id, 'name' => 'Category '.$id]);
        }
        DB::table('cars')->insert(['id' => 8, 'uuid' => (string) Str::uuid(), 'make' => 'Audi', 'model' => 'A4', 'body_type' => 'sedan']);
        DB::table('parts')->insert(['id' => 1, 'name' => 'Part 1', 'sku' => '0001', 'part_number' => '0000123', 'category_id' => 7, 'car_id' => 8,
            'review_metadata' => json_encode(['marketplace_category_overrides' => ['allegro' => ['external_category_id' => '100']]])]);
        DB::table('parts')->insert(['id' => 2, 'name' => 'Part 2', 'sku' => '0002', 'part_number' => '0000456', 'category_id' => 8, 'car_id' => 8,
            'legacy_payload' => json_encode(['_allegro_category_id' => '400', 'parameters' => [['id' => 'historic', 'values' => ['old']]]])]);
        DB::table('parts')->insert(['id' => 3, 'name' => 'No category', 'sku' => '0003', 'car_id' => 8]);
        foreach ([[7, 'allegro_main', '500'], [8, 'allegro_main', '200'], [8, 'allegro', '299'], [999, 'allegro_main', '999']] as [$local, $channel, $remote]) {
            DB::table('marketplace_category_mappings')->insert(['local_category_id' => $local, 'channel' => $channel, 'external_category_id' => $remote]);
        }
        DB::table('allegro_parameter_selections')->insert(['part_id' => 1, 'allegro_category_id' => '100', 'parameter_id' => 'manual', 'value_id' => 'v1']);
        DB::table('allegro_parameter_mappings')->insert(['local_category_id' => 8, 'allegro_category_id' => '200', 'parameter_id' => 'manual', 'fixed_value_id' => 'v1', 'enabled' => true]);
        DB::table('marketplace_listings')->insert(['part_id' => 1, 'marketplace' => 'allegro_main', 'external_offer_id' => 'stored-1', 'raw_payload' => json_encode(['category' => ['id' => '300'], 'parameters' => [['id' => 'historic', 'values' => ['old']]]])]);
        DB::table('marketplace_listings')->insert(['part_id' => null, 'marketplace' => 'allegro', 'external_offer_id' => 'orphan', 'raw_payload' => json_encode(['category' => ['id' => '888']])]);
        DB::table('marketplace_listings')->insert(['part_id' => 1, 'marketplace' => 'ebay_de', 'external_offer_id' => 'ebay', 'raw_payload' => json_encode(['category' => ['id' => '666']])]);
        foreach (['100', '777'] as $category) {
            DB::table('allegro_category_parameters_cache')->insert(['allegro_category_id' => $category, 'fetched_at' => '2019-01-01 00:00:00', 'raw_response' => '{"parameters":[],"old":true}']);
        }
        MarketplaceAccount::query()->create(['marketplace' => 'allegro', 'code' => 'allegro_main', 'name' => 'Test account', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.pl', 'api_credentials' => ['access_token' => 'SYNTHETIC_ACCESS_TOKEN', 'refresh_token' => 'SYNTHETIC_REFRESH_TOKEN', 'access_token_expires_at' => now()->addDay()->toIso8601String()]]);
        $prepared = app(GpswissMigrationExport::class)->prepareBrowser(7);
        $this->run = $prepared['run_id'];
        $this->attempt = $prepared['snapshot_attempt_id'];
        $this->parent = $this->root.'/exports/'.$this->run;
        $this->dir = $this->root.'/exports/.allegro-schema-refresh/'.$this->run.'/'.$this->attempt;
        $engine = app(BrowserMigrationSnapshot::class);
        $status = $engine->index($this->parent, $this->attempt);
        for ($i = 0; $i < 300 && ! $status['done'] && ! $status['failed']; $i++) {
            $status = $engine->tick($this->parent, $this->attempt);
        }
        $this->assertTrue($status['done']);
        $this->provider();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function service(): AllegroSchemaRefresh
    {
        return app(AllegroSchemaRefresh::class);
    }

    private function definitions(bool $product): array
    {
        $oe = ['id' => '227345', 'name' => 'Numer katalogowy', 'type' => 'string', 'required' => $product, 'options' => ['describesProduct' => true, 'requiredForProduct' => true], 'restrictions' => ['minLength' => 1, 'maxLength' => 64]];
        $definitions = $product ? [$oe] : [
            ['id' => 'manual', 'name' => 'Wybór', 'type' => 'dictionary', 'required' => true, 'dictionary' => [['id' => 'v1', 'value' => 'Pierwszy']], 'options' => ['describesProduct' => false, 'futureOption' => ['full' => true]], 'restrictions' => ['multipleChoices' => false], 'unit' => 'kg', 'dependencies' => []], $oe,
            ['id' => '129591', 'name' => 'Typ samochodu', 'type' => 'dictionary', 'required' => false, 'options' => ['describesProduct' => false], 'dictionary' => [['id' => '129591_1', 'value' => 'Samochody osobowe']]],
        ];
        if ($this->missingValue) {
            $definitions[] = ['id' => $product ? 'missing-product' : 'missing-offer', 'name' => $product ? 'Brak produktu' : 'Brak oferty', 'type' => 'string', 'required' => true, 'options' => ['describesProduct' => $product]];
        }

        return ['parameters' => $definitions, 'futureResponseField' => ['retain' => ['all', 'values']]];
    }

    private function fakeProvider(callable $callback): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function provider(): void
    {
        $this->fakeProvider(fn ($r) => Http::response(json_encode($this->definitions(str_ends_with($r->url(), '/product-parameters')), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 200));
    }

    private function plan(): array
    {
        $state = $this->service()->plan(7, $this->run, $this->attempt);
        $this->dir = $this->root.'/exports/.allegro-schema-refresh/'.$this->run.'/'.$this->attempt.'/plans/'.$state['plan_id'];
        for ($i = 0; $i < 200 && in_array($state['phase'], ['planning', 'preview'], true); $i++) {
            $state = $this->service()->tick(7, $this->run, $this->attempt);
        }
        $this->assertContains($state['phase'], ['planned', 'fetch', 'blocked', 'completed']);

        return $state;
    }

    private function fetch(): array
    {
        $state = $this->plan();
        $state = $this->service()->start(7, $this->run, $this->attempt, $state['plan_digest'], true);
        for ($i = 0; $i < 200 && ! $state['done'] && $state['phase'] !== 'blocked'; $i++) {
            if ($state['wait_seconds'] > 0) {
                $this->travel($state['wait_seconds'] + 1)->seconds();
            }
            $state = $this->service()->tick(7, $this->run, $this->attempt);
        }

        return $state;
    }

    private function supplement(): array
    {
        $state = app(AllegroParametersSupplement::class)->start(7, $this->run, $this->attempt, requireFresh: true);
        for ($i = 0; $i < 300 && ! $state['done']; $i++) {
            $state = app(AllegroParametersSupplement::class)->tick(7, $this->run, $this->attempt);
        }
        $this->assertTrue($state['done']);

        return $state;
    }

    private function rows(string $dataset): array
    {
        $context = $this->service()->completed(7, $this->run, $this->attempt);
        $folder = $this->root.'/exports/.allegro-parameters-supplements/'.$this->run.'/'.$this->attempt.'/fresh-'.$context['plan_id'];

        return array_map(fn ($line) => json_decode($line, true), file($folder.'/'.$dataset.'.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    private function hashes(): array
    {
        $out = [];
        foreach (File::allFiles($this->parent) as $file) {
            $out[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
        }

        return $out;
    }

    public function test_preview_discovers_only_used_frozen_categories_and_never_calls_provider(): void
    {
        DB::table('parts')->where('id', 1)->update(['review_metadata' => '{"marketplace_category_overrides":{"allegro":{"external_category_id":"9999"}}}']);
        $before = $this->hashes();
        $queries = [];
        DB::listen(function ($e) use (&$queries) {
            $queries[] = $e->sql;
        });
        $plan = $this->plan();
        $this->assertSame(['100', '200', '300', '400'], array_column($plan['categories'], 'category_id'));
        $this->assertSame(8, $plan['expected_gets']);
        $this->assertSame(1, $plan['orphan_listings_excluded']);
        $this->assertTrue($plan['categories'][0]['local_cache_present']);
        $this->assertSame('2019-01-01 00:00:00', $plan['categories'][0]['local_fetched_at']);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^select\\b/i', $sql);
            $this->assertDoesNotMatchRegularExpression('/\\bfrom\\s+["`]?\\b(parts|cars|marketplace_listings|marketplace_category_mappings)\\b/i', $sql);
        }
        $this->assertSame($before, $this->hashes());
        Http::assertNothingSent();
        $this->assertSame($plan['plan_digest'], $this->service()->plan(7, $this->run, $this->attempt)['plan_digest']);
    }

    public function test_confirm_digest_is_required_and_plan_tick_cannot_fetch(): void
    {
        $plan = $this->plan();
        $this->service()->tick(7, $this->run, $this->attempt);
        Http::assertNothingSent();
        foreach ([[$plan['plan_digest'], false], [str_repeat('0', 64), true]] as [$digest, $confirm]) {
            try {
                $this->service()->start(7, $this->run, $this->attempt, $digest, $confirm);
                $this->fail('Unconfirmed plan started');
            } catch (MigrationExportException $e) {
                $this->assertSame('schema_plan_confirmation_mismatch', $e->reasonCode);
            }
        }
        Http::assertNothingSent();
    }

    public function test_both_get_endpoints_preserve_raw_response_and_parent_business_tables_unchanged(): void
    {
        $before = $this->hashes();
        $tables = [];
        foreach (['parts', 'cars', 'marketplace_listings', 'marketplace_accounts', 'allegro_category_parameters_cache', 'allegro_parameter_mappings'] as $table) {
            $tables[$table] = DB::table($table)->get()->toJson();
        }
        $result = $this->fetch();
        $this->assertTrue($result['done']);
        $this->assertSame(4, $result['successes']);
        Http::assertSentCount(8);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET' || ! preg_match('~/sale/categories/(100|200|300|400)/(parameters|product-parameters)$~', $r->url()));
        foreach ($result['categories'] as $category) {
            foreach (['parameters', 'product-parameters'] as $endpoint) {
                $receipt = $this->service()->receipt($this->dir, $category['category_id'], $endpoint);
                $raw = json_encode($this->definitions($endpoint === 'product-parameters'), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $this->assertSame($raw, $receipt['raw_response_text']);
                $this->assertSame(hash('sha256', $raw), $receipt['sha256']);
            }
        }
        $this->supplement();
        foreach ($tables as $table => $value) {
            $this->assertSame($value, DB::table($table)->get()->toJson(), $table);
        }
        $this->assertSame($before, $this->hashes());
        $cache = $this->rows('allegro_category_parameters_cache')[0];
        $this->assertSame('kg', $cache['raw_response']['parameters'][0]['unit']);
        $this->assertTrue($cache['parameters'][0]['options']['futureOption']['full']);
        $this->assertSame(['all', 'values'], $cache['raw_response']['futureResponseField']['retain']);
        $this->assertCount(4, $this->rows('allegro_product_parameters_cache'));
        $this->assertCount(1, $this->rows('allegro_parameter_selections'));
        $this->assertCount(8, $this->rows('schema_freshness'));
        $this->assertStringNotContainsString('SYNTHETIC_ACCESS_TOKEN', json_encode($result));
        Http::assertSentCount(8); // Supplement itself performs no provider calls.
    }

    public function test_complete_partial_required_names_product_counts_and_provenance(): void
    {
        $this->fetch();
        $this->supplement();
        $reports = collect($this->rows('completeness'));
        $report = $reports->first(fn ($r) => $r['part_id'] === '1' && $r['allegro_category_id'] === '100');
        $this->assertSame('COMPLETE', $report['status']);
        $this->assertSame(1, $report['required_parameter_count']);
        $this->assertSame(1, $report['required_resolved_count']);
        $this->assertSame(1, $report['product_required_count']);
        $this->assertSame(0, $report['product_required_missing']);
        $this->assertSame([], $report['missing_required_parameter_ids']);
        $sources = array_unique(array_column($this->rows('allegro_resolved_parameters'), 'provenance'));
        foreach (['manual_selection', 'existing_listing', 'local_mapping', 'derived_from_part', 'derived_from_car'] as $source) {
            $this->assertContains($source, $sources);
        }
        $this->assertSame('PARTIAL', $reports->first(fn ($r) => $r['allegro_category_id'] === '400')['status']);
    }

    public function test_unresolved_required_values_are_named_and_never_guessed(): void
    {
        $this->missingValue = true;
        $this->fetch();
        $this->supplement();
        $report = collect($this->rows('completeness'))->first(fn ($r) => $r['part_id'] === '1' && $r['allegro_category_id'] === '100');
        $this->assertSame('PARTIAL', $report['status']);
        $this->assertContains('missing-product', $report['missing_required_parameter_ids']);
        $this->assertContains('Brak produktu', $report['missing_required_parameter_names']);
        $this->assertSame(1, $report['product_required_missing']);
        foreach ($this->rows('allegro_resolved_parameters') as $row) {
            if (str_starts_with((string) $row['parameter_id'], 'missing-')) {
                $this->assertSame([], $row['values']);
                $this->assertSame([], $row['values_ids']);
                $this->assertContains($row['provenance'], ['category_schema', 'unresolved']);
            }
        }
    }

    public function test_product_404_is_missing_schema_not_stale_cache_and_other_categories_continue(): void
    {
        $this->fakeProvider(fn ($r) => str_contains($r->url(), '/100/product-parameters') ? Http::response(['message' => 'Do not export error'], 404) : Http::response($this->definitions(str_ends_with($r->url(), '/product-parameters')), 200));
        $state = $this->fetch();
        $this->assertSame(1, $state['not_found_404']);
        $this->assertSame(1, $state['missing_schemas']);
        $this->assertSame(3, $state['successes']);
        $this->supplement();
        $report = collect($this->rows('completeness'))->first(fn ($r) => $r['part_id'] === '1' && $r['allegro_category_id'] === '100');
        $this->assertSame('SCHEMA_UNKNOWN', $report['status']);
        $this->assertCount(3, $this->rows('allegro_product_parameters_cache'));
        Http::assertSentCount(8);
    }

    public function test_429_honors_retry_after_and_reload_does_not_repeat_confirmed_endpoints(): void
    {
        $calls = 0;
        $this->fakeProvider(function ($r) use (&$calls) {
            $calls++;

            return $calls === 1 ? Http::response([], 429, ['Retry-After' => '12']) : Http::response($this->definitions(str_ends_with($r->url(), '/product-parameters')), 200);
        });
        $plan = $this->plan();
        $this->service()->start(7, $this->run, $this->attempt, $plan['plan_digest'], true);
        $state = $this->service()->tick(7, $this->run, $this->attempt);
        $this->assertSame(12, $state['wait_seconds']);
        $this->service()->tick(7, $this->run, $this->attempt);
        $this->assertSame(1, $calls);
        $this->travel(13)->seconds();
        $state = $this->fetch();
        $this->assertTrue($state['done']);
        $this->assertSame(1, $state['provider_429']);
        $this->assertSame(9, $calls);
        $this->service()->tick(7, $this->run, $this->attempt);
        $this->service()->start(7, $this->run, $this->attempt, $plan['plan_digest'], true);
        $this->assertSame(9, $calls);
    }

    public function test_lost_checkpoint_adopts_durable_result_without_second_get(): void
    {
        $plan = $this->plan();
        $this->service()->start(7, $this->run, $this->attempt, $plan['plan_digest'], true);
        $before = file_get_contents($this->dir.'/checkpoint.json');
        $this->service()->tick(7, $this->run, $this->attempt);
        file_put_contents($this->dir.'/checkpoint.json', $before);
        $this->service()->tick(7, $this->run, $this->attempt);
        Http::assertSentCount(1);
        $this->assertTrue($this->fetch()['done']);
        Http::assertSentCount(8);
    }

    public function test_expired_token_blocks_without_oauth_post_then_same_plan_can_resume(): void
    {
        $account = MarketplaceAccount::query()->where('code', 'allegro_main')->first();
        $credentials = $account->api_credentials;
        $credentials['access_token_expires_at'] = now()->subHour()->toIso8601String();
        $account->api_credentials = $credentials;
        $account->save();
        $state = $this->fetch();
        $this->assertSame('blocked', $state['phase']);
        $this->assertSame('oauth_refresh_required', $state['blocked_reason']);
        Http::assertNothingSent();
        $credentials['access_token_expires_at'] = now()->addDay()->toIso8601String();
        $account->api_credentials = $credentials;
        $account->save();
        $resumed = $this->fetch();
        $this->assertSame($state['plan_id'], $resumed['plan_id']);
        $this->assertTrue($resumed['done']);
        Http::assertSentCount(8);
    }

    public function test_5xx_and_network_errors_have_bounded_retries_and_no_error_bodies_or_secrets(): void
    {
        $this->fakeProvider(fn ($r) => str_contains($r->url(), '/100/') ? Http::response(['secret' => 'NEVER_STORE_ERROR_BODY'], 503) : Http::response($this->definitions(str_ends_with($r->url(), '/product-parameters')), 200));
        $state = $this->fetch();
        $this->assertTrue($state['done']);
        $this->assertSame(1, $state['missing_schemas']);
        Http::assertSentCount(14); // Four attempts on each failed endpoint, six other successful endpoints.
        $all = '';
        foreach (File::allFiles($this->dir) as $file) {
            $all .= file_get_contents($file->getPathname());
        }
        $this->assertStringNotContainsString('NEVER_STORE_ERROR_BODY', $all);
        $this->assertStringNotContainsString('SYNTHETIC_ACCESS_TOKEN', $all);
    }

    public function test_network_timeout_and_unauthorized_never_refresh_oauth_or_write_provider(): void
    {
        $once = true;
        $this->fakeProvider(function ($r) use (&$once) {
            if ($once) {
                $once = false;
                throw new ConnectionException('SENSITIVE_NETWORK_MESSAGE');
            }

            return Http::response($this->definitions(str_ends_with($r->url(), '/product-parameters')), 200);
        });
        $state = $this->fetch();
        $this->assertTrue($state['done']);
        Http::assertSentCount(8); // Failed transport is not recorded as a response by Laravel fake.
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
        $this->assertGreaterThan(0, $state['errors']);
    }

    public function test_401_and_403_pause_the_same_plan_without_oauth_post(): void
    {
        foreach ([401, 403] as $status) {
            $this->fakeProvider(fn () => Http::response(['secret' => 'NEVER_EXPORT'], $status));
            $state = $this->fetch();
            $this->assertSame('blocked', $state['phase']);
            $this->assertSame($status === 401 ? 'oauth_refresh_required' : 'forbidden_403', $state['blocked_reason']);
            Http::assertSentCount(1);
            Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
        }
        $this->provider();
        $this->assertTrue($this->fetch()['done']);
        Http::assertSentCount(8);
    }

    public function test_new_explicit_refresh_plan_keeps_old_receipts_and_supplement_immutable(): void
    {
        $this->fetch();
        $this->supplement();
        $old = $this->service()->completed(7, $this->run, $this->attempt);
        $hashes = [];
        foreach (File::allFiles($old['directory']) as $file) {
            $hashes[$file->getPathname()] = hash_file('sha256', $file->getPathname());
        }
        $next = $this->service()->plan(7, $this->run, $this->attempt, newPlan: true);
        $this->assertNotSame($old['plan_id'], $next['plan_id']);
        $this->assertSame($old['plan_id'], $next['previous_plan_id']);
        Http::assertSentCount(8); // Preparing a new plan is still GET-free.
        $this->assertTrue($this->fetch()['done']);
        Http::assertSentCount(16);
        foreach ($hashes as $path => $hash) {
            $this->assertSame($hash, hash_file('sha256', $path));
        }
        $this->assertSame($old['plan_id'], json_decode(file_get_contents($this->root.'/exports/.allegro-parameters-supplements/'.$this->run.'/'.$this->attempt.'/fresh-'.$old['plan_id'].'/checkpoint.json'), true)['fresh_plan_id']);
    }

    public function test_malformed_schema_has_bounded_retries_and_cannot_fall_back_to_local_cache(): void
    {
        $this->fakeProvider(fn ($r) => str_contains($r->url(), '/100/') ? Http::response('NOT_JSON_DO_NOT_EXPORT', 200) : Http::response($this->definitions(str_ends_with($r->url(), '/product-parameters')), 200));
        $state = $this->fetch();
        $this->assertTrue($state['done']);
        $this->assertSame(1, $state['missing_schemas']);
        Http::assertSentCount(14);
        $this->supplement();
        $report = collect($this->rows('completeness'))->first(fn ($r) => $r['part_id'] === '1' && $r['allegro_category_id'] === '100');
        $this->assertSame('SCHEMA_UNKNOWN', $report['status']);
        $this->assertNull($report['required_parameter_count']);
    }

    public function test_part_without_category_is_unknown_not_zero_required_or_complete(): void
    {
        $this->fetch();
        $this->supplement();
        $report = collect($this->rows('completeness'))->first(fn ($r) => $r['part_id'] === '3');
        $this->assertSame('SCHEMA_UNKNOWN', $report['status']);
        $this->assertNull($report['allegro_category_id']);
        $this->assertNull($report['required_parameter_count']);
        $this->assertNull($report['product_required_count']);
    }

    public function test_fresh_supplement_does_not_invent_fixed_business_defaults(): void
    {
        $this->fakeProvider(function ($request) {
            $product = str_ends_with($request->url(), '/product-parameters');
            $body = $this->definitions($product);
            if (! $product) {
                $body['parameters'][] = ['id' => 'state', 'name' => 'Stan', 'type' => 'dictionary', 'required' => true, 'options' => ['describesProduct' => false], 'dictionary' => [['id' => 'used', 'value' => 'Używany']]];
            }

            return Http::response($body, 200);
        });
        $this->fetch();
        $this->supplement();
        $rows = array_values(array_filter($this->rows('allegro_resolved_parameters'), fn ($r) => $r['parameter_id'] === 'state'));
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame([], $row['values_ids']);
            $this->assertSame([], $row['values']);
            $this->assertSame('unresolved', $row['provenance']);
        }
        $report = collect($this->rows('completeness'))->first(fn ($r) => $r['part_id'] === '1' && $r['allegro_category_id'] === '100');
        $this->assertSame('PARTIAL', $report['status']);
        $this->assertContains('state', $report['missing_required_parameter_ids']);
    }

    public function test_plan_integrity_and_generation_pin_block_before_provider_or_mixed_download(): void
    {
        $state = $this->fetch();
        $this->supplement();
        $this->expectExceptionMessage('supplement_refresh_plan_changed');
        $this->service()->withCompletedPlan(7, $this->run, $this->attempt, (string) Str::uuid(), fn () => $this->fail('Wrong plan selected'));
    }

    public function test_plan_definition_corruption_is_a_blocker_not_an_automatic_new_plan(): void
    {
        $state = $this->plan();
        file_put_contents($this->dir.'/plan-definition.json', '{}');
        try {
            $this->service()->start(7, $this->run, $this->attempt, $state['plan_digest'], true);
            $this->fail('Corrupt plan accepted');
        } catch (MigrationExportException $e) {
            $this->assertSame('schema_plan_integrity_failed', $e->reasonCode);
        }
        Http::assertNothingSent();
    }

    public function test_provider_429_cooldown_applies_to_next_endpoint_after_retry_limit(): void
    {
        $this->fakeProvider(fn ($r) => str_ends_with($r->url(), '/100/parameters') ? Http::response([], 429, ['Retry-After' => '20']) : Http::response($this->definitions(str_ends_with($r->url(), '/product-parameters')), 200));
        $plan = $this->plan();
        $this->service()->start(7, $this->run, $this->attempt, $plan['plan_digest'], true);
        for ($i = 0; $i < 4; $i++) {
            $state = $this->service()->tick(7, $this->run, $this->attempt);
            if ($i < 3) {
                $this->travel(21)->seconds();
            }
        }
        Http::assertSentCount(4);
        $this->service()->tick(7, $this->run, $this->attempt);
        Http::assertSentCount(4);
        $this->assertGreaterThanOrEqual(20, $state['wait_seconds']);
        $this->travel(21)->seconds();
        $this->service()->tick(7, $this->run, $this->attempt);
        Http::assertSentCount(5);
    }

    public function test_completeness_csv_has_all_counts_names_and_unambiguous_category_context(): void
    {
        $this->fetch();
        $this->supplement();
        $context = $this->service()->completed(7, $this->run, $this->attempt);
        $file = $this->root.'/exports/.allegro-parameters-supplements/'.$this->run.'/'.$this->attempt.'/fresh-'.$context['plan_id'].'/completeness.csv';
        $stream = fopen($file, 'rb');
        $this->assertSame("\xEF\xBB\xBF", fread($stream, 3));
        $header = fgetcsv($stream, 0, ';', '"', '');
        foreach (['required_parameter_count', 'required_resolved_count', 'product_required_count', 'product_required_missing', 'missing_required_parameter_names', 'status', 'category_context', 'canonical_allegro_category_id'] as $column) {
            $this->assertContains($column, $header);
        }
        $rows = [];
        while (($row = fgetcsv($stream, 0, ';', '"', '')) !== false) {
            $rows[] = array_combine($header, $row);
        } fclose($stream);
        $canonical = array_values(array_filter($rows, fn ($r) => $r['category_context'] !== 'historical'));
        $this->assertCount(3, $canonical);
        $this->assertSame('100', $canonical[0]['canonical_allegro_category_id']);
        $this->assertSame('1', $canonical[0]['required_parameter_count']);
        $this->assertSame('COMPLETE', $canonical[0]['status']);
        $historic = array_values(array_filter($rows, fn ($r) => $r['allegro_category_id'] === '300'))[0];
        $this->assertSame('historical', $historic['category_context']);
        $this->assertSame('100', $historic['canonical_allegro_category_id']);
    }

    public function test_routes_require_owner_and_explicit_plan_confirmation(): void
    {
        $user = User::query()->forceCreate(['id' => 7, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'test']);
        Role::findOrCreate(UserRole::OwnerAdmin->value);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $url = route('admin.tools.gpswiss-migration-export.allegro-schema-refresh', ['run' => $this->run]);
        $this->actingAs($user)->postJson($url, ['parent_attempt_id' => $this->attempt, 'action' => 'plan'])->assertOk();
        Http::assertNothingSent();
        $plan = $this->plan();
        $this->postJson($url, ['parent_attempt_id' => $this->attempt, 'action' => 'start', 'plan_digest' => str_repeat('0', 64), 'confirm' => true])->assertStatus(409);
        $response = $this->getJson($url.'?parent_attempt_id='.$this->attempt)->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $other = User::query()->create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'test']);
        $other->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($other)->getJson($url.'?parent_attempt_id='.$this->attempt)->assertNotFound();
        $other->removeRole(UserRole::OwnerAdmin->value);
        $this->getJson($url.'?parent_attempt_id='.$this->attempt)->assertForbidden();
    }
}
