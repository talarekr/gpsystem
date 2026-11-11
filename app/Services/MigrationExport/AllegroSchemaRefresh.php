<?php

namespace App\Services\MigrationExport;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Operator-planned GET capture into private files. SQL is SELECT only; frozen parent files are never mutated. */
final class AllegroSchemaRefresh
{
    private const STAGES = ['parts', 'marketplace_category_mappings', 'mapped_parts', 'allegro_parameter_selections', 'marketplace_listings'];

    public function __construct(private GpswissMigrationExport $parents, private BrowserMigrationSnapshot $snapshots, private AllegroSupplementResolver $resolver, private AllegroSchemaReadClient $client) {}

    public function plan(int $owner, string $run, string $attempt, bool $newPlan = false): array
    {
        $parent = $this->parents->supplementParent($owner, $run, $attempt);
        $root = $this->dir($parent, $attempt);
        $this->mkdir($root);

        return $this->locked($root, function () use ($owner, $parent, $root, $run, $attempt, $newPlan) {
            $previous = null;
            if (is_file($root.'/current.json')) {
                [, $previousDir, $existing] = $this->context($owner, $run, $attempt);
                if (! $newPlan) {
                    return $this->reply($previousDir, $existing);
                }
                if ($existing['phase'] !== 'completed') {
                    throw new MigrationExportException('schema_previous_plan_not_complete');
                }
                $previous = $existing;
            }
            $planId = (string) Str::uuid();
            $dir = $root.'/plans/'.$planId;
            $this->mkdir($dir);
            $index = $this->snapshots->index($parent, $attempt);
            if (! $index['done'] || $index['failed']) {
                throw new MigrationExportException('schema_parent_capture_not_sealed');
            }
            $this->write($dir.'/parent-index.json', $index);
            $state = ['plan_id' => $planId, 'previous_plan_id' => $previous['plan_id'] ?? null, 'previous_completed_at' => $previous['completed_at'] ?? null, 'parent_run_id' => $run, 'parent_attempt_id' => $attempt, 'phase' => 'planning', 'stage' => 0, 'shard' => 0, 'row' => 0,
                'categories' => [], 'invalid_category_ids' => 0, 'orphan_listings_excluded' => 0, 'preview_offset' => 0, 'category_offset' => 0, 'endpoint_offset' => 0,
                'parent_sha256' => hash_file('sha256', $parent.'/logical-snapshot/'.$attempt.'/checkpoint.json'), 'index_sha256' => hash_file('sha256', $dir.'/parent-index.json'), 'created_at' => now()->toIso8601String(), 'blocked_reason' => null];
            $this->write($dir.'/checkpoint.json', $state);
            $this->write($root.'/current.json', ['plan_id' => $planId]);

            return $this->reply($dir, $state);
        });
    }

    public function start(int $owner, string $run, string $attempt, string $digest, bool $confirm): array
    {
        [$parent, $dir, $state] = $this->context($owner, $run, $attempt);

        return $this->locked($dir, function () use ($dir, $digest, $confirm) {
            $state = $this->read($dir.'/checkpoint.json');
            if (! isset($state['plan_digest']) || ! in_array($state['phase'], ['planned', 'fetch', 'blocked', 'completed'], true)) {
                throw new MigrationExportException('schema_plan_not_ready');
            }
            if (! $confirm || ! hash_equals($state['plan_digest'] ?? '', $digest)) {
                throw new MigrationExportException('schema_plan_confirmation_mismatch');
            }
            if ($state['phase'] === 'planned') {
                $state['phase'] = 'fetch';
                $state['started_at'] = now()->toIso8601String();
            } elseif ($state['phase'] === 'blocked') {
                $state['phase'] = 'fetch';
                $state['blocked_reason'] = null;
            }
            $this->write($dir.'/checkpoint.json', $state);

            return $this->reply($dir, $state);
        });
    }

    public function status(int $owner, string $run, string $attempt): array
    {
        [, $dir, $state] = $this->context($owner, $run, $attempt);

        return $this->reply($dir, $state);
    }

    public function tick(int $owner, string $run, string $attempt): array
    {
        [$parent, $dir] = $this->context($owner, $run, $attempt);

        return $this->locked($dir, function () use ($parent, $dir) {
            $state = $this->read($dir.'/checkpoint.json');
            if ($state['phase'] === 'planning') {
                $this->discover($parent, $dir, $state);
            } elseif ($state['phase'] === 'preview') {
                $this->preview($dir, $state);
            } elseif ($state['phase'] === 'fetch') {
                $this->fetch($dir, $state);
            }
            $this->write($dir.'/checkpoint.json', $state);

            return $this->reply($dir, $state);
        });
    }

    /** A completed refresh is immutable and bound to this parent. Failed endpoints remain explicitly missing. */
    public function completed(int $owner, string $run, string $attempt): array
    {
        [, $dir, $state] = $this->context($owner, $run, $attempt);
        if ($state['phase'] !== 'completed') {
            throw new MigrationExportException('supplement_schema_refresh_required');
        }

        return ['directory' => $dir, 'plan_id' => $state['plan_id'], 'categories' => array_keys($state['categories']), 'summary' => $this->reply($dir, $state)];
    }

    /** Hold only the refresh pointer lock while selecting/working on a supplement generation. */
    public function withCompletedPlan(int $owner, string $run, string $attempt, ?string $expected, \Closure $work): mixed
    {
        $parent = $this->parents->supplementParent($owner, $run, $attempt);
        $root = $this->dir($parent, $attempt);
        if (! is_dir($root)) {
            throw new MigrationExportException('supplement_schema_refresh_required');
        }

        return $this->locked($root, function () use ($owner, $run, $attempt, $expected, $work) {
            try {
                $context = $this->completed($owner, $run, $attempt);
            } catch (MigrationExportException $e) {
                if ($e->reasonCode === 'schema_plan_not_started') {
                    throw new MigrationExportException('supplement_schema_refresh_required');
                }
                throw $e;
            }
            if ($expected !== null && $context['plan_id'] !== $expected) {
                throw new MigrationExportException('supplement_refresh_plan_changed');
            }

            return $work();
        });
    }

    public function receipt(string $dir, string $category, string $endpoint): ?array
    {
        if (! preg_match('/\A[0-9]{1,30}\z/D', $category) || ! in_array($endpoint, AllegroSchemaReadClient::ENDPOINTS, true)) {
            throw new MigrationExportException('schema_receipt_identity_invalid');
        }
        $file = $dir.'/receipts/'.hash('sha256', $category.':'.$endpoint).'.json';
        if (! is_file($file)) {
            return null;
        }
        $envelope = $this->read($file);
        if (! hash_equals($envelope['sha256'] ?? '', hash('sha256', json_encode($envelope['data'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)))) {
            throw new MigrationExportException('schema_receipt_corrupt');
        }

        return $envelope['data'];
    }

    private function discover(string $parent, string $dir, array &$state): void
    {
        $stage = self::STAGES[$state['stage']] ?? null;
        if ($stage === null) {
            ksort($state['categories'], SORT_STRING);
            $state['phase'] = 'preview';

            return;
        }
        $source = new AllegroSupplementSource($parent, $this->read($dir.'/parent-index.json'));
        $dataset = $stage === 'mapped_parts' ? 'parts' : $stage;
        $deadline = microtime(true) + 1.5;
        for ($n = 0, $shards = 0; $n < 100 && $shards < 25 && microtime(true) < $deadline;) {
            $rows = $source->shard($dataset, $state['shard']);
            if ($rows === null) {
                $state['stage']++;
                $state['shard'] = $state['row'] = 0;
                break;
            }
            for (; isset($rows[$state['row']]) && $n < 100 && microtime(true) < $deadline; $state['row']++, $n++) {
                $row = $rows[$state['row']];
                if ($stage === 'parts') {
                    $this->put($dir, 'parts', (string) $row['id'], ['present' => true]);
                    if (isset($row['category_id'])) {
                        $this->put($dir, 'local_categories', (string) $row['category_id'], ['used' => true]);
                    }
                    foreach (['review_metadata', 'legacy_payload'] as $field) {
                        $this->payloadCategories($state, (array) ($row[$field] ?? []), $field);
                    }
                    $this->add($state, data_get($row, 'review_metadata.marketplace_category_overrides.allegro.external_category_id'), 'part_override');
                    $this->add($state, $row['allegro_category_id'] ?? null, 'part_allegro_category_id');
                } elseif ($stage === 'marketplace_category_mappings' && is_scalar($row['external_category_id'] ?? null) && trim((string) $row['external_category_id']) !== '' && in_array($row['channel'] ?? null, ['allegro', 'allegro_main'], true) && $this->get($dir, 'local_categories', (string) ($row['local_category_id'] ?? '')) !== null) {
                    $key = (string) $row['local_category_id'];
                    $old = $this->get($dir, 'mapping', $key);
                    $rank = fn ($r) => [($r['channel'] === 'allegro_main' ? 0 : 1), strlen((string) $r['id']), (string) $r['id']];
                    if ($old === null || $rank($row) < $rank($old)) {
                        $this->put($dir, 'mapping', $key, $row);
                    }
                } elseif ($stage === 'mapped_parts') {
                    $override = data_get($row, 'review_metadata.marketplace_category_overrides.allegro.external_category_id');
                    if (! is_scalar($override) || trim((string) $override) === '') {
                        $mapping = $this->get($dir, 'mapping', (string) ($row['category_id'] ?? ''));
                        $this->add($state, $mapping['external_category_id'] ?? null, 'canonical_category_mapping');
                    }
                } elseif ($stage === 'allegro_parameter_selections' && $this->get($dir, 'parts', (string) ($row['part_id'] ?? '')) !== null) {
                    $this->add($state, $row['allegro_category_id'] ?? null, 'manual_selection');
                } elseif ($stage === 'marketplace_listings' && in_array($row['marketplace'] ?? null, ['allegro', 'allegro_main'], true)) {
                    if ($this->get($dir, 'parts', (string) ($row['part_id'] ?? '')) === null) {
                        $state['orphan_listings_excluded']++;

                        continue;
                    }
                    $payload = (array) ($row['raw_payload'] ?? []);
                    $this->add($state, data_get($payload, 'category.id'), 'existing_listing');
                    $this->payloadCategories($state, $payload, 'existing_listing');
                }
            }
            if ($state['row'] >= count($rows)) {
                $state['row'] = 0;
                $state['shard']++;
                $shards++;
            }
        }
    }

    private function payloadCategories(array &$state, array $payload, string $source): void
    {
        foreach (['_allegro_category_id', 'allegro_category_id'] as $key) {
            $this->add($state, $payload[$key] ?? null, $source);
        }
        foreach ($this->resolver->historical($payload, $source, null, null, null, null) as $candidate) {
            $this->add($state, $candidate['allegro_category_id'], $source);
        }
    }

    private function add(array &$state, mixed $id, string $source): void
    {
        if ($id === null || $id === '') {
            return;
        }
        if (! is_scalar($id) || ! preg_match('/\A[0-9]{1,30}\z/D', (string) $id)) {
            $state['invalid_category_ids']++;

            return;
        }
        $state['categories'][(string) $id] ??= ['category_id' => (string) $id, 'sources' => [], 'refresh_required' => true, 'endpoints' => []];
        $state['categories'][(string) $id]['sources'][$source] = true;
    }

    private function preview(string $dir, array &$state): void
    {
        $ids = array_keys($state['categories']);
        for ($n = 0; $n < 5 && isset($ids[$state['preview_offset']]); $n++, $state['preview_offset']++) {
            $id = $ids[$state['preview_offset']];
            $cache = null;
            if (Schema::hasTable('allegro_category_parameters_cache')) {
                $key = Schema::hasColumn('allegro_category_parameters_cache', 'allegro_category_id') ? 'allegro_category_id' : 'category_id';
                $cache = DB::table('allegro_category_parameters_cache')->where($key, (string) $id)->first([$key, 'fetched_at']);
            }
            $state['categories'][$id]['local_cache_present'] = $cache !== null;
            $state['categories'][$id]['local_fetched_at'] = $cache->fetched_at ?? null;
        }
        if ($state['preview_offset'] === count($ids)) {
            $state['phase'] = 'planned';
            $this->write($dir.'/plan-definition.json', ['plan_id' => $state['plan_id'], 'parent_run_id' => $state['parent_run_id'], 'parent_attempt_id' => $state['parent_attempt_id'], 'parent_sha256' => $state['parent_sha256'], 'categories' => array_values($state['categories']), 'endpoints' => AllegroSchemaReadClient::ENDPOINTS]);
            $state['plan_digest'] = hash_file('sha256', $dir.'/plan-definition.json');
        }
    }

    private function fetch(string $dir, array &$state): void
    {
        $ids = array_keys($state['categories']);
        $id = isset($ids[$state['category_offset']]) ? (string) $ids[$state['category_offset']] : null;
        if ($id === null) {
            $state['phase'] = 'completed';
            $state['completed_at'] = now()->toIso8601String();

            return;
        }
        $cooldown = dirname($dir, 4).'/allegro-main-cooldown.json';
        $globalWait = is_file($cooldown) ? (int) ($this->read($cooldown)['not_before'] ?? 0) : 0;
        if (max($state['next_get_at'] ?? 0, $globalWait) > now()->timestamp) {
            return;
        }
        $endpoint = AllegroSchemaReadClient::ENDPOINTS[$state['endpoint_offset']];
        $prior = $this->receipt($dir, $id, $endpoint);
        if ($prior !== null && ($prior['confirmed'] ?? false)) {
            $this->advance($state, $id, $endpoint, $prior);

            return;
        }
        if (($prior['retry_at'] ?? 0) > now()->timestamp) {
            return;
        }
        $result = $this->client->get($id, $endpoint);
        $result['attempts'] = ($prior['attempts'] ?? 0) + 1;
        $result['provider_gets'] = ($prior['provider_gets'] ?? 0) + (int) (isset($result['http_status']) || in_array($result['reason_code'], ['network_error', 'invalid_schema_response'], true));
        $result['count_429'] = ($prior['count_429'] ?? 0) + (int) (($result['http_status'] ?? null) === 429);
        $result['count_errors'] = ($prior['count_errors'] ?? 0) + (int) (! $result['ok'] && ($result['http_status'] ?? null) !== 404);
        $result['observed_at'] = now()->toIso8601String();
        $result['confirmed'] = $result['ok'] || (! ($result['blocked'] ?? false) && (! ($result['retryable'] ?? false) || $result['attempts'] >= 4));
        if (($result['retryable'] ?? false) && ! ($result['blocked'] ?? false)) {
            $result['retry_at'] = now()->timestamp + max(min(30, 2 ** $result['attempts']), $result['retry_after_seconds'] ?? 0);
        }
        if (($result['retry_after_seconds'] ?? 0) > 0) {
            $this->write($cooldown, ['not_before' => max($globalWait, now()->timestamp + $result['retry_after_seconds'])]);
        }
        $this->mkdir($dir.'/receipts');
        $this->write($dir.'/receipts/'.hash('sha256', $id.':'.$endpoint).'.json', ['data' => $result, 'sha256' => hash('sha256', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))]);
        $this->advance($state, $id, $endpoint, $result);
        if ($result['blocked'] ?? false) {
            $state['phase'] = 'blocked';
            $state['blocked_reason'] = $result['reason_code'];
        }
    }

    private function advance(array &$state, string $id, string $endpoint, array $result): void
    {
        $safe = array_intersect_key($result, array_flip(['provider_gets', 'ok', 'http_status', 'reason_code', 'fetched_at', 'observed_at', 'attempts', 'count_429', 'count_errors', 'retry_at', 'confirmed', 'sha256']));
        $state['categories'][$id]['endpoints'][$endpoint] = $safe;
        $state['next_get_at'] = max($state['next_get_at'] ?? 0, $result['retry_at'] ?? 0);
        if ($result['confirmed']) {
            $state['endpoint_offset']++;
            if ($state['endpoint_offset'] === count(AllegroSchemaReadClient::ENDPOINTS)) {
                $state['endpoint_offset'] = 0;
                $state['category_offset']++;
            }
        }
    }

    private function reply(string $dir, array $state): array
    {
        $success = $missing = $notFound = $rate = $errors = $gets = 0;
        $cooldown = dirname($dir, 4).'/allegro-main-cooldown.json';
        $globalWait = is_file($cooldown) ? (int) ($this->read($cooldown)['not_before'] ?? 0) : 0;
        $wait = max($state['next_get_at'] ?? 0, $globalWait) - now()->timestamp;
        foreach ($state['categories'] as $category) {
            $ok = count($category['endpoints']) === 2;
            foreach ($category['endpoints'] as $r) {
                $ok = $ok && $r['ok'];
                $gets += $r['provider_gets'] ?? 0;
                $notFound += (int) (($r['http_status'] ?? null) === 404);
                $rate += $r['count_429'] ?? 0;
                $errors += $r['count_errors'] ?? 0;
                if (! ($r['confirmed'] ?? false)) {
                    $wait = max($wait, ($r['retry_at'] ?? 0) - now()->timestamp);
                }
            }
            $success += (int) $ok;
            if ($state['phase'] === 'completed' && ! $ok) {
                $missing++;
            }
        }

        return array_intersect_key($state, array_flip(['previous_plan_id', 'previous_completed_at', 'plan_id', 'parent_run_id', 'parent_attempt_id', 'phase', 'stage', 'shard', 'row', 'plan_digest', 'created_at', 'started_at', 'completed_at', 'blocked_reason', 'invalid_category_ids', 'orphan_listings_excluded'])) + [
            'categories' => array_values($state['categories']), 'total' => count($state['categories']), 'processed' => $state['category_offset'], 'expected_gets' => count($state['categories']) * 2, 'performed_gets' => $gets,
            'successes' => $success, 'not_found_404' => $notFound, 'provider_429' => $rate, 'errors' => $errors, 'missing_schemas' => $missing, 'wait_seconds' => max(0, $wait), 'done' => $state['phase'] === 'completed',
            'provider_calls' => $gets > 0, 'business_writes' => false, 'database_writes' => false, 'oauth_refresh' => false];
    }

    private function context(int $owner, string $run, string $attempt): array
    {
        $parent = $this->parents->supplementParent($owner, $run, $attempt);
        $root = $this->dir($parent, $attempt);
        if (! is_file($root.'/current.json')) {
            throw new MigrationExportException('schema_plan_not_started', 404);
        }
        $pointer = $this->read($root.'/current.json');
        if (! Str::isUuid($pointer['plan_id'] ?? null)) {
            throw new MigrationExportException('schema_plan_pointer_invalid');
        }
        $dir = $root.'/plans/'.$pointer['plan_id'];
        if (! is_file($dir.'/checkpoint.json')) {
            throw new MigrationExportException('schema_plan_not_started', 404);
        }
        $state = $this->read($dir.'/checkpoint.json');
        if ($state['plan_id'] !== $pointer['plan_id'] || $state['parent_run_id'] !== $run || $state['parent_attempt_id'] !== $attempt || ! hash_equals($state['parent_sha256'], (string) @hash_file('sha256', $parent.'/logical-snapshot/'.$attempt.'/checkpoint.json')) || ! hash_equals($state['index_sha256'], (string) @hash_file('sha256', $dir.'/parent-index.json'))) {
            throw new MigrationExportException('schema_parent_identity_changed');
        }

        if (isset($state['plan_digest'])) {
            if (! hash_equals($state['plan_digest'], (string) @hash_file('sha256', $dir.'/plan-definition.json'))) {
                throw new MigrationExportException('schema_plan_integrity_failed');
            }
            $definition = $this->read($dir.'/plan-definition.json');
            if ($definition['endpoints'] !== AllegroSchemaReadClient::ENDPOINTS || $definition['plan_id'] !== $state['plan_id'] || array_map('strval', array_column($definition['categories'], 'category_id')) !== array_map('strval', array_keys($state['categories']))) {
                throw new MigrationExportException('schema_plan_integrity_failed');
            }
        }

        return [$parent, $dir, $state];
    }

    private function dir(string $parent, string $attempt): string
    {
        return dirname($parent).'/.allegro-schema-refresh/'.basename($parent).'/'.$attempt;
    }

    private function mkdir(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new MigrationExportException('schema_directory_unavailable');
        }
    }

    private function put(string $dir, string $bucket, string $key, array $data): void
    {
        $this->mkdir($dir.'/'.$bucket);
        $this->write($dir.'/'.$bucket.'/'.hash('sha256', $key).'.json', $data);
    }

    private function get(string $dir, string $bucket, string $key): ?array
    {
        $file = $dir.'/'.$bucket.'/'.hash('sha256', $key).'.json';

        return is_file($file) ? $this->read($file) : null;
    }

    private function read(string $file): array
    {
        try {
            $v = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);

            return is_array($v) ? $v : throw new \RuntimeException;
        } catch (\Throwable) {
            throw new MigrationExportException('schema_checkpoint_invalid');
        }
    }

    private function write(string $file, array $value): void
    {
        $bytes = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($file.'.pending', $bytes, LOCK_EX) !== strlen($bytes) || ! chmod($file.'.pending', 0600) || ! rename($file.'.pending', $file)) {
            throw new MigrationExportException('schema_checkpoint_write_failed');
        }
    }

    private function locked(string $dir, callable $work): mixed
    {
        $f = fopen($dir.'/lock', 'c');
        chmod($dir.'/lock', 0600);
        if (! flock($f, LOCK_EX | LOCK_NB)) {
            fclose($f);
            throw new MigrationExportException('schema_refresh_busy');
        } try {
            return $work();
        } finally {
            flock($f, LOCK_UN);
            fclose($f);
        }
    }
}
