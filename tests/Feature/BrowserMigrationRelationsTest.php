<?php

namespace Tests\Feature;

use App\Services\MigrationExport\BrowserMigrationRelations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class BrowserMigrationRelationsTest extends TestCase
{
    private string $directory;

    private string $attempt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/browser-relations-'.Str::uuid();
        $this->attempt = (string) Str::uuid();
        mkdir($this->directory.'/logical-snapshot/'.$this->attempt, 0700, true);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_frozen_relation_audit_resumes_with_bounded_probes_and_preserves_orphan_records(): void
    {
        $items = [];
        for ($id = 1; $id <= 25; $id++) {
            $items[] = ['id' => (string) $id, 'order_id' => '40', 'part_id' => $id === 25 ? '999' : '1'];
        }
        $this->snapshot([
            'order_items' => $this->dataset('order_items', $items, ['order_id' => 'orders', 'part_id' => 'parts']),
            'orders' => $this->dataset('orders', [['id' => '40', 'customer_id' => '50', 'marketplace_account_id' => '60']], ['customer_id' => 'users', 'marketplace_account_id' => 'marketplace_accounts']),
            'parts' => $this->dataset('parts', [['id' => '1', 'car_id' => '10', 'part_category_id' => '20', 'storage_location_id' => '30']], ['car_id' => 'cars', 'part_category_id' => 'part_categories', 'storage_location_id' => 'storage_locations']),
            'cars' => $this->dataset('cars', [['id' => '10']]),
            'part_categories' => $this->dataset('part_categories', [['id' => '20']]),
            'storage_locations' => $this->dataset('storage_locations', [['id' => '30']]),
            'customers' => $this->dataset('customers', [['id' => '50']]),
            'marketplace_account_identities' => $this->dataset('marketplace_account_identities', [['id' => '60']]),
            'customer_returns' => $this->dataset('customer_returns', [['id' => '90', 'order_id' => '80']], ['order_id' => 'orders']),
            'shop_events' => $this->dataset('shop_events', [['id' => '80']]),
        ]);
        DB::connection()->beforeExecuting(fn () => throw new RuntimeException('No live database access allowed'));
        $first = app(BrowserMigrationRelations::class)->tick($this->directory, $this->attempt);
        $this->assertSame(20, $first['probes']);
        $this->assertSame(10, $first['processed']);
        // An interrupted journal append is discarded, preserving committed cursors and issue counts.
        file_put_contents($this->directory.'/relation-issues.jsonl', "{\"uncommitted\":true}\n", FILE_APPEND);
        $previous = $first;
        for ($i = 0; $i < 100 && ! $previous['done']; $i++) {
            $next = app(BrowserMigrationRelations::class)->tick($this->directory, $this->attempt);
            $this->assertLessThanOrEqual(20, $next['probes'] - $previous['probes']);
            $previous = $next;
        }
        $this->assertTrue($previous['done']);
        $this->assertSame(34, $previous['processed']);
        $this->assertSame(1, $previous['issues_count']);
        $issues = array_map(fn ($line) => json_decode($line, true), file($this->directory.'/relation-issues.jsonl', FILE_IGNORE_NEW_LINES));
        $this->assertCount(1, $issues);
        $this->assertSame('missing_source_reference', $issues[0]['reason_code']);
        $this->assertSame('25', $issues[0]['source_id']);
        $this->assertSame('999', $issues[0]['referenced_id']);
        $this->assertSame($previous, app(BrowserMigrationRelations::class)->tick($this->directory, $this->attempt));
        $this->assertSame($previous, app(BrowserMigrationRelations::class)->status($this->directory, $this->attempt));
        Http::assertNothingSent();
    }

    public function test_unknown_and_composite_targets_are_explicit_issues_and_bigint_lookup_is_exact(): void
    {
        $cars = [];
        for ($id = 1; $id <= 30; $id++) {
            $cars[] = ['id' => (string) $id];
        }
        $cars[] = ['id' => '18446744073709551614'];
        $this->snapshot([
            'parts' => $this->dataset('parts', [
                ['id' => '1', 'car_id' => '18446744073709551614', 'legacy_id' => '11', 'custom_id' => '12'],
                ['id' => '2', 'car_id' => '18446744073709551613', 'legacy_id' => null, 'custom_id' => null],
            ], ['car_id' => 'cars', 'legacy_id' => 'not_exported', 'custom_id' => 'composite']),
            'cars' => $this->dataset('cars', $cars),
            'composite' => $this->dataset('composite', [['id' => '12']], [], ['code', 'kind']),
        ]);
        for ($i = 0; $i < 30; $i++) {
            $state = app(BrowserMigrationRelations::class)->tick($this->directory, $this->attempt);
            if ($state['done']) {
                break;
            }
        }
        $this->assertTrue($state['done']);
        $this->assertSame(['unknown_target_dataset' => 1, 'unverifiable_target_key' => 1, 'missing_source_reference' => 1], $state['issue_counts']);
        $this->assertSame(3, $state['issues_count']);
    }

    public function test_empty_issue_file_exists_and_stale_attempt_cannot_reuse_checkpoint(): void
    {
        $datasets = ['parts' => $this->dataset('parts', [['id' => '1', 'car_id' => null]], ['car_id' => 'cars']), 'cars' => $this->dataset('cars', [])];
        $this->snapshot($datasets);
        for ($i = 0; $i < 10; $i++) {
            $state = app(BrowserMigrationRelations::class)->tick($this->directory, $this->attempt);
            if ($state['done']) {
                break;
            }
        }
        $this->assertTrue($state['done']);
        $this->assertSame(0, $state['issues_count']);
        $this->assertSame('', file_get_contents($this->directory.'/relation-issues.jsonl'));
        $this->expectExceptionMessage('relation_stale_snapshot_attempt');
        app(BrowserMigrationRelations::class)->status($this->directory, (string) Str::uuid());
    }

    public function test_corrupt_frozen_shard_is_rejected_without_committing_a_checkpoint(): void
    {
        $parts = $this->dataset('parts', [['id' => '1']]);
        $this->snapshot(['parts' => $parts]);
        file_put_contents($this->directory.'/'.$parts['shards'][0]['path'], '[{"id":"2"}]');
        try {
            app(BrowserMigrationRelations::class)->tick($this->directory, $this->attempt);
            $this->fail('Corrupt immutable shard must be rejected');
        } catch (RuntimeException $error) {
            $this->assertSame('relation_shard_missing_or_changed', $error->getMessage());
        }
        $this->assertFileDoesNotExist($this->directory.'/relation-checkpoint.json');
    }

    private function dataset(string $name, array $rows, array $relations = [], array $key = ['id']): array
    {
        $shards = [];
        foreach (array_chunk($rows, 25) as $number => $chunk) {
            $path = 'logical-snapshot/'.$this->attempt.'/'.sprintf('%s-%03d.json', $name, $number);
            $bytes = json_encode($chunk, JSON_THROW_ON_ERROR);
            file_put_contents($this->directory.'/'.$path, $bytes);
            $shards[] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'cursor_end' => ['id' => $chunk[count($chunk) - 1]['id']]];
        }

        return ['name' => $name, 'available' => true, 'key_columns' => $key, 'column_definitions' => ['id' => ['type' => 'bigint unsigned']],
            'processed' => count($rows), 'verified' => count($rows), 'total' => count($rows), 'relations' => $relations, 'shards' => $shards];
    }

    private function snapshot(array $datasets): void
    {
        file_put_contents($this->directory.'/logical-snapshot/'.$this->attempt.'/checkpoint.json', json_encode([
            'attempt_id' => $this->attempt, 'stage' => 'completed', 'dataset_names' => array_keys($datasets),
            'dataset_index' => count($datasets), 'datasets' => $datasets,
        ], JSON_THROW_ON_ERROR));
    }
}
