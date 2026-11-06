<?php

namespace Tests\Feature;

use App\Services\MigrationExport\BrowserMigrationSnapshot;
use App\Services\MigrationExport\GpswissSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class BrowserMigrationSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    private string $attempt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = sys_get_temp_dir().'/browser-snapshot-'.Str::uuid();
        $this->attempt = (string) Str::uuid();
        mkdir($this->folder, 0700);
        config(['migration_export.browser_snapshot_rows' => 2]);
        foreach ([1, 2, 3] as $id) {
            DB::table('parts')->insert(['id' => $id, 'name' => 'Part '.$id, 'sku' => '000'.$id, 'price' => '123.45']);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        parent::tearDown();
    }

    public function test_transient_backend_exception_replays_same_checkpoint_and_records_safe_trace(): void
    {
        $engine = app(BrowserMigrationSnapshot::class);
        $index = $engine->begin($this->folder, $this->attempt);
        while ($index['stage'] === 'boundaries') {
            $index = $engine->tick($this->folder, $this->attempt);
        }
        $frozen = $index['datasets']['parts']['max_key'];
        $once = true;
        DB::listen(function ($event) use (&$once): void {
            if ($once && str_contains($event->sql, 'snapshot_row_bytes')) {
                $once = false;
                throw new \RuntimeException('PRIVATE_SECRET must never be reported');
            }
        });
        $failed = $engine->tick($this->folder, $this->attempt);
        $this->assertFalse($failed['failed']);
        $this->assertSame(0, $failed['datasets']['parts']['processed']);
        $trace = file_get_contents($this->folder.'/last-tick.json');
        $this->assertStringNotContainsString('PRIVATE_SECRET', $trace);
        $this->assertSame('RuntimeException', json_decode($trace, true)['backend_exception_class']);
        $next = $engine->tick($this->folder, $this->attempt);
        $this->assertSame(2, $next['datasets']['parts']['processed']);
        $this->assertSame($frozen, $next['datasets']['parts']['max_key']);
        $this->assertSame($this->attempt, $next['attempt_id']);
        $this->assertNull($next['last_error']);
        $this->assertCount(1, $next['datasets']['parts']['shards']);
        $trace = json_decode(file_get_contents($this->folder.'/last-tick.json'), true);
        $this->assertSame(2, $trace['records_attempted']);
        $this->assertSame(2, $trace['records_completed']);
    }

    public function test_bounded_shards_resume_verify_without_duplicates_or_late_ids(): void
    {
        $engine = app(BrowserMigrationSnapshot::class);
        $index = $engine->begin($this->folder, $this->attempt);
        for ($i = 0; $i < 200 && $index['stage'] === 'boundaries'; $i++) {
            $index = $engine->tick($this->folder, $this->attempt);
        }
        $this->assertSame('export', $index['stage']);
        DB::table('parts')->insert(['id' => 9000, 'name' => 'New after max', 'sku' => 'NEW']);
        for ($i = 0; $i < 300 && ! $index['done']; $i++) {
            $index = (new BrowserMigrationSnapshot(app(GpswissSnapshot::class)))->tick($this->folder, $this->attempt);
            $this->assertFalse($index['failed'], json_encode($index['last_error']));
        }
        $this->assertTrue($index['done']);
        $rows = iterator_to_array($engine->rows($this->folder, 'parts', $this->attempt));
        $this->assertSame(['1', '2', '3'], array_column($rows, 'id'));
        $this->assertCount(2, $index['datasets']['parts']['shards']);
        $this->assertSame(['1', '2', '3'], array_column(iterator_to_array($engine->rows($this->folder, 'part_car_relations', $this->attempt)), 'part_id'));
        $this->assertContains('no_exact_global_new_record_exclusion_guarantee', $index['limitations']);
        $this->assertTrue($engine->lookup($this->folder, 'parts', '2', $this->attempt)['found']);
        $this->assertFalse($engine->lookup($this->folder, 'parts', '9000', $this->attempt)['found']);
        foreach ($index['datasets']['parts']['shards'] as $shard) {
            $this->assertSame($shard['sha256'], hash_file('sha256', $this->folder.'/'.$shard['path']));
            $this->assertSame($shard['csv_sha256'], hash_file('sha256', $this->folder.'/'.$shard['csv_path']));
        }
        $this->assertFileExists($this->folder.'/snapshot.json');
    }

    public function test_changed_captured_row_fails_validation_without_replacing_frozen_data(): void
    {
        foreach ([8, 9] as $car) {
            DB::table('cars')->insert(['id' => $car, 'uuid' => (string) Str::uuid(), 'make' => 'Make', 'model' => 'Model']);
        }
        DB::table('parts')->where('id', 1)->update(['car_id' => 8]);
        $engine = app(BrowserMigrationSnapshot::class);
        $index = $engine->begin($this->folder, $this->attempt);
        for ($i = 0; $i < 200 && $index['stage'] === 'boundaries'; $i++) {
            $index = $engine->tick($this->folder, $this->attempt);
        }
        $index = $engine->tick($this->folder, $this->attempt);
        $this->assertSame(2, $index['datasets']['parts']['processed']);
        $first = $index['datasets']['parts']['shards'][0];
        DB::table('parts')->where('id', 1)->update(['price' => '999.99', 'car_id' => 9]);
        for ($i = 0; $i < 300 && ! $index['failed'] && ! $index['done']; $i++) {
            $index = $engine->tick($this->folder, $this->attempt);
        }
        $relations = iterator_to_array($engine->rows($this->folder, 'part_car_relations', $this->attempt));
        $this->assertSame('8', $relations[0]['car_id']);
        $this->assertTrue($index['failed']);
        $this->assertSame('snapshot_source_changed', $index['last_error']);
        $this->assertSame($first['sha256'], hash_file('sha256', $this->folder.'/'.$first['path']));
        $this->assertFileDoesNotExist($this->folder.'/snapshot.json');
    }

    public function test_late_out_of_boundary_order_and_listing_cannot_pull_unrelated_private_profiles(): void
    {
        foreach ([5, 6] as $id) {
            DB::table('users')->insert(['id' => $id, 'name' => 'Private customer '.$id, 'email' => 'private'.$id.'@example.test', 'password' => 'not-exported']);
        }
        foreach ([1, 2] as $id) {
            DB::table('marketplace_accounts')->insert(['id' => $id, 'marketplace' => 'allegro', 'code' => 'account-'.$id, 'name' => 'Account '.$id]);
        }
        $order = ['id' => 1, 'order_number' => 'before', 'customer_id' => 5, 'status' => 'new', 'currency' => 'PLN', 'subtotal' => '1.00', 'shipping_total' => '0.00', 'total' => '1.00', 'customer_name' => 'Customer', 'email' => 'order@example.test', 'phone' => '0001', 'address_line1' => 'Private address', 'postal_code' => '00001', 'city' => 'City', 'country' => 'PL'];
        DB::table('orders')->insert($order);
        DB::table('marketplace_listings')->insert(['id' => 1, 'part_id' => 1, 'marketplace' => 'allegro', 'marketplace_account_id' => 1, 'external_offer_id' => 'before']);
        $engine = app(BrowserMigrationSnapshot::class);
        $index = $engine->begin($this->folder, $this->attempt);
        for ($i = 0; $i < 200 && $index['current_dataset'] !== 'customers'; $i++) {
            $index = $engine->tick($this->folder, $this->attempt);
        }
        $this->assertSame('customers', $index['current_dataset']);
        $this->assertSame(['id' => 1], $index['datasets']['orders']['max_key']);
        DB::table('orders')->insert(array_replace($order, ['id' => 9000, 'order_number' => 'after', 'customer_id' => 6]));
        DB::table('marketplace_listings')->insert(['id' => 9000, 'part_id' => 1, 'marketplace' => 'allegro', 'marketplace_account_id' => 2, 'external_offer_id' => 'after']);
        for ($i = 0; $i < 400 && ! $index['done'] && ! $index['failed']; $i++) {
            $index = $engine->tick($this->folder, $this->attempt);
        }
        $this->assertFalse($index['failed'], (string) $index['last_error']);
        $this->assertTrue($index['done']);
        $this->assertSame(['1'], array_column(iterator_to_array($engine->rows($this->folder, 'orders', $this->attempt)), 'id'));
        $this->assertSame(['5'], array_column(iterator_to_array($engine->rows($this->folder, 'customers', $this->attempt)), 'id'));
        $this->assertSame(['1'], array_column(iterator_to_array($engine->rows($this->folder, 'marketplace_account_identities', $this->attempt)), 'id'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_short_private_write_preserves_prior_checkpoint_and_removes_partial_file(): void
    {
        eval('namespace App\\Services\\MigrationExport; function file_put_contents($file, $bytes, $flags = 0) { return \\file_put_contents($file, str_contains($file, "force-short-write") ? substr($bytes, 0, 2) : $bytes, $flags); }');
        $file = $this->folder.'/force-short-write.json';
        file_put_contents($file, 'original-checkpoint');
        $method = new \ReflectionMethod(BrowserMigrationSnapshot::class, 'atomicBytes');
        try {
            $method->invoke(app(BrowserMigrationSnapshot::class), $file, 'new-checkpoint-that-cannot-fit');
            $this->fail('Expected a rejected short write');
        } catch (\RuntimeException $exception) {
            $this->assertSame('snapshot_private_write_failed', $exception->getMessage());
        }
        $this->assertSame('original-checkpoint', file_get_contents($file));
        $this->assertSame([], glob($file.'.pending-*'));
    }
}
