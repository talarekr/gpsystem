<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class JarekImageCheckpointMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = '2026_10_14_000004_create_jarek_image_runner_checkpoints';

    private const RUNS = 'storefront_jarek_image_runs';

    private const ITEMS = 'storefront_jarek_image_items';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_clean_schema_and_repeated_migrate_complete_without_duplicate_tables_or_indexes(): void
    {
        $this->removeTables();
        $this->forgetLedger();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertTrue(Schema::hasTable(self::RUNS));
        $this->assertTrue(Schema::hasTable(self::ITEMS));
        $this->assertTrue(Schema::hasIndex(self::RUNS, ['run_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex(self::ITEMS, ['run_id', 'source_id', 'image_number'], 'unique'));
        $before = Schema::getIndexes(self::ITEMS);
        $this->migration()->up();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertSame($before, Schema::getIndexes(self::ITEMS));
        Http::assertNothingSent();
    }

    public function test_partial_deploy_existing_run_table_and_checkpoint_without_ledger_are_preserved(): void
    {
        Schema::drop(self::ITEMS);
        $this->insertRun();
        $before = DB::table(self::RUNS)->sole();
        $this->forgetLedger();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertEquals($before, DB::table(self::RUNS)->sole());
        $this->assertTrue(Schema::hasTable(self::ITEMS));
        $this->assertDatabaseHas('migrations', ['migration' => self::FILE]);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_both_existing_tables_with_rows_survive_retry_and_non_destructive_rollback(): void
    {
        $this->insertRun();
        DB::table(self::ITEMS)->insert(['run_id' => 'existing-run', 'source_id' => 42, 'image_number' => 1, 'classification' => 'missing', 'status' => 'pending']);
        $before = [DB::table(self::RUNS)->get()->toJson(), DB::table(self::ITEMS)->get()->toJson()];
        $this->forgetLedger();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->migration()->down();
        $this->assertTrue(Schema::hasTable(self::RUNS));
        $this->assertTrue(Schema::hasTable(self::ITEMS));
        $this->migration()->up();
        $this->assertSame($before, [DB::table(self::RUNS)->get()->toJson(), DB::table(self::ITEMS)->get()->toJson()]);
        Http::assertNothingSent();
    }

    public function test_missing_columns_and_indexes_are_added_without_overwriting_existing_values(): void
    {
        $this->removeTables();
        Schema::create(self::RUNS, function (Blueprint $table) {
            $table->id();
            $table->uuid('run_id');
            $table->json('checkpoint');
        });
        Schema::create(self::ITEMS, function (Blueprint $table) {
            $table->id();
            $table->uuid('run_id');
            $table->unsignedBigInteger('source_id');
        });
        DB::table(self::RUNS)->insert(['run_id' => 'partial-run', 'checkpoint' => '{"processed":18,"remaining":1500}']);
        DB::table(self::ITEMS)->insert(['run_id' => 'partial-run', 'source_id' => 18]);
        $this->forgetLedger();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertSame('{"processed":18,"remaining":1500}', DB::table(self::RUNS)->sole()->checkpoint);
        $this->assertSame(18, DB::table(self::ITEMS)->sole()->source_id);
        foreach (['actor_id', 'status', 'phase', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn(self::RUNS, $column));
        }
        foreach (['image_number', 'source_name', 'local_url', 'fingerprint', 'classification', 'status', 'result_code', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn(self::ITEMS, $column));
        }
        $this->assertNull(DB::table(self::RUNS)->sole()->status);
        $this->assertTrue(Schema::hasIndex(self::RUNS, ['run_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex(self::RUNS, ['actor_id', 'id']));
        $this->assertTrue(Schema::hasIndex(self::ITEMS, ['run_id', 'source_id', 'image_number'], 'unique'));
        $this->assertTrue(Schema::hasIndex(self::ITEMS, ['run_id', 'status', 'id']));
        $indexes = Schema::getIndexes(self::ITEMS);
        $this->migration()->up();
        $this->assertSame($indexes, Schema::getIndexes(self::ITEMS));
        Http::assertNothingSent();
    }

    public function test_equivalent_legacy_indexes_are_reused_and_wrong_index_name_shape_does_not_collide(): void
    {
        Schema::table(self::ITEMS, function (Blueprint $t) {
            $t->dropUnique('sji_items_image_uq');
            $t->unique(['run_id', 'source_id', 'image_number'], 'legacy_image_unique');
        });
        $before = Schema::getIndexes(self::ITEMS);
        $this->migration()->up();
        $this->assertSame($before, Schema::getIndexes(self::ITEMS));
        Schema::table(self::ITEMS, function (Blueprint $t) {
            $t->dropUnique('legacy_image_unique');
            $t->index('source_name', 'sji_items_image_uq');
        });
        $this->migration()->up();
        $this->assertTrue(Schema::hasIndex(self::ITEMS, ['run_id', 'source_id', 'image_number'], 'unique'));
        $this->assertTrue(Schema::hasIndex(self::ITEMS, 'sji_items_image_uq_1', 'unique'));
        $indexes = Schema::getIndexes(self::ITEMS);
        $this->migration()->up();
        $this->assertSame($indexes, Schema::getIndexes(self::ITEMS));
        Http::assertNothingSent();
    }

    public function test_duplicate_legacy_rows_are_never_deleted_to_force_a_unique_index(): void
    {
        Schema::table(self::RUNS, fn (Blueprint $t) => $t->dropUnique('sji_runs_uuid_uq'));
        $this->insertRun();
        $this->insertRun();
        try {
            $this->migration()->up();
            $this->fail('Duplicate legacy UUIDs must not be silently repaired.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('UNIQUE', strtoupper($e->getMessage()));
        }
        $this->assertDatabaseCount(self::RUNS, 2);
        Http::assertNothingSent();
    }

    public function test_mysql_ddl_identifiers_fit_mysql_limit_without_contacting_a_server(): void
    {
        $original = Schema::getFacadeRoot();
        $connection = new MySqlConnection(DB::connection()->getPdo(), 'offline_fixture', '', ['version' => '8.0.36']);
        try {
            Schema::swap($connection->getSchemaBuilder());
            $queries = $connection->pretend(fn () => $this->migration()->up());
        } finally {
            Schema::swap($original);
        }
        $ddl = implode("\n", array_column($queries, 'query'));
        $this->assertStringContainsString('create table `storefront_jarek_image_runs`', $ddl);
        $this->assertStringContainsString('create table `storefront_jarek_image_items`', $ddl);
        preg_match_all('/(?:constraint|unique|index)\s+`([^`]+)`/i', $ddl, $matches);
        $this->assertGreaterThanOrEqual(6, count($matches[1]));
        foreach ($matches[1] as $identifier) {
            $this->assertLessThanOrEqual(64, strlen($identifier), $identifier);
        }
        $this->assertStringNotContainsString('storefront_jarek_image_items_run_id_source_id_image_number_unique', $ddl);
        Http::assertNothingSent();
    }

    private function insertRun(): void
    {
        $actor = User::create(['name' => 'Migration fixture', 'email' => 'migration-'.User::count().'@example.test', 'password' => 'password']);
        DB::table(self::RUNS)->insert(['run_id' => 'existing-run', 'actor_id' => $actor->id, 'status' => 'paused', 'phase' => 'prepare', 'checkpoint' => '{"processed":18,"remaining":1500}']);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::FILE.'.php');
    }

    private function forgetLedger(): void
    {
        DB::table('migrations')->where('migration', self::FILE)->delete();
    }

    private function removeTables(): void
    {
        Schema::dropIfExists(self::ITEMS);
        Schema::dropIfExists(self::RUNS);
    }
}
