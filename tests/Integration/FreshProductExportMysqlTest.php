<?php

namespace Tests\Integration;

use App\Services\MigrationExport\FreshProductExport;
use App\Services\MigrationExport\PartPhotoStorageDiagnostic;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Run ONLY against a disposable database named fresh_export_test, never an application database. */
class FreshProductExportMysqlTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires disposable MySQL');
        }
        $this->assertSame('fresh_export_test', DB::connection()->getDatabaseName());
        $this->root = sys_get_temp_dir().'/mysql-export-'.Str::uuid();
        config(['migration_export.product_root' => $this->root, 'app.url' => 'https://gpswiss.pl']);
        foreach (['parts', 'cars', 'part_categories', 'storage_locations', 'part_images', 'marketplace_listings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('parts', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('status')->default('ready');
            $t->boolean('needs_listing')->default(false);
            foreach (['car_id', 'category_id', 'storage_location_id'] as $c) {
                $t->unsignedBigInteger($c)->nullable();
            }
            $t->longText('description')->nullable();
            $t->decimal('price', 12, 2)->nullable();
            $t->string('currency')->default('PLN');
        });
        foreach (['cars', 'part_categories', 'storage_locations'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->id();
                $t->string('name')->nullable();
            });
        }
        Schema::create('part_images', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->unsignedBigInteger('part_id')->index();
            $t->string('path')->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_primary')->default(false);
        });
        Schema::create('marketplace_listings', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->unsignedBigInteger('part_id')->index();
            $t->string('marketplace');
            $t->unsignedBigInteger('marketplace_account_id')->nullable();
            $t->decimal('price', 12, 2)->nullable();
            $t->string('currency')->nullable();
            $t->string('url')->nullable();
            $t->json('raw_payload')->nullable();
        });
        for ($id = 1; $id <= 120; $id++) {
            DB::table('parts')->insert(['id' => $id, 'name' => 'Part '.$id, 'description' => '<p>Test</p>', 'price' => '10.01']);
        }
        DB::table('part_images')->insert(['part_id' => 1, 'path' => 'parts/1/test.jpg', 'is_primary' => true]);
        DB::table('marketplace_listings')->insert(['part_id' => 1, 'marketplace' => 'ebay_de', 'price' => '5.50', 'currency' => 'EUR', 'url' => 'https://www.ebay.de/itm/1']);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \LogicException('No provider calls'));
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_mysql_sample_five_readonly_capture_and_package(): void
    {
        // Optional tables deliberately absent; NULL/orphan relations must not break capture.
        $queries = [];
        DB::listen(function ($e) use (&$queries) {
            $queries[] = $e->sql;
        });
        $service = app(FreshProductExport::class);
        $s = $service->prepare(7, 'czesci', 'sample');
        $s = $service->action(7, $s['export_id'], 'capture');
        $this->assertSame('ready', $s['state']);
        $this->assertSame(5, $s['total']);
        $this->assertSame(5, $s['capture_processed']);
        $this->assertCount(5, file($this->root.'/'.$s['export_id'].'/capture.jsonl'));
        $this->assertCount(5, $s['sample_part_ids']);
        foreach (file($this->root.'/'.$s['export_id'].'/capture.jsonl') as $line) {
            $this->assertContains((string) json_decode($line, true)['part']['id'], $s['sample_part_ids']);
        }
        $s = $service->action(7, $s['export_id'], 'tick');
        $this->assertSame('done', $s['state']);
        foreach (['czesci.csv', 'manifest.json'] as $name) {
            $this->assertFileExists($service->download(7, $s['export_id'], $name));
        }
        $this->assertSame(['czesci.csv', 'manifest.json'], array_keys($s['files']));
        $this->assertCount(1, glob($this->root.'/'.$s['export_id'].'/*.csv'));
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^select\b/i', $sql);
        }
        $this->assertStringContainsString('limit 100', implode('\n', $queries));
        $this->assertStringContainsString('limit 5', implode('\n', $queries));
        Http::assertNothingSent();
    }

    public function test_exact_transaction_syntax_order_enforces_read_only(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $pdo->beginTransaction();
        try {
            $pdo->exec("UPDATE parts SET name='must not change' WHERE id=1");
            $this->fail('MySQL accepted a write in READ ONLY transaction');
        } catch (\PDOException $e) {
            $this->assertSame(1792, $e->errorInfo[1]);
        } finally {
            $pdo->rollBack();
        }
        $this->assertSame('Part 1', DB::table('parts')->where('id', 1)->value('name'));
    }

    public function test_original_engine_projection_reproduces_mysql_failure(): void
    {
        $this->expectException(\ErrorException::class);
        // Exact #1536 expression: MySQL returns ENGINE, not engine, without an alias.
        DB::table('information_schema.tables')->where('table_schema', DB::connection()->getDatabaseName())->whereIn('table_name', ['parts'])->pluck('engine');
    }

    public function test_photo_audit_uses_real_mysql_rows_without_optional_payload_column(): void
    {
        DB::table('part_images')->insert(['id' => 20081, 'part_id' => 1, 'path' => 'parts/photos/imported/mysql-audit/original.jpg']);
        $queries = [];
        DB::listen(function ($e) use (&$queries) {
            $queries[] = $e->sql;
        });
        $data = (new PartPhotoStorageDiagnostic)->inspect();
        $this->assertSame('selected', $data['sample_status']);
        $this->assertSame(['20081', '1'], array_column($data['sample_images'], 'image_id'));
        $this->assertSame('parts/photos/imported/mysql-audit/original.jpg', $data['sample_images'][0]['db_path']);
        $this->assertNull($data['sample_images'][0]['legacy_presentation']['product_path']);
        $this->assertSame('image_id_hint_only_not_verified', $data['known_product']['source_match']);
        $this->assertNull($data['known_product']['original']);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^select\b/i', $sql);
        }
        Http::assertNothingSent();
    }
}
