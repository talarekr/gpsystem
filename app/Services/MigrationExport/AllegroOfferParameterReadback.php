<?php

namespace App\Services\MigrationExport;

use Illuminate\Support\Str;

/** Frozen listing plan -> operator-confirmed GET -> private immutable receipts. No business SQL writes. */
final class AllegroOfferParameterReadback
{
    private const STAGES = ['marketplace_account_identities', 'parts', 'marketplace_listings'];

    public function __construct(private GpswissMigrationExport $parents, private BrowserMigrationSnapshot $snapshots, private AllegroSchemaReadClient $client) {}

    public function plan(int $owner, string $run, string $attempt, bool $newPlan = false): array
    {
        $parent = $this->parents->supplementParent($owner, $run, $attempt);
        $root = $this->root($parent, $attempt);
        $this->mkdir($root);

        return $this->locked($root, function () use ($owner, $run, $attempt, $parent, $root, $newPlan) {
            $previous = null;
            if (is_file($root.'/current.json')) {
                [, $dir, $previous] = $this->context($owner, $run, $attempt);
                if (! $newPlan) {
                    return $this->reply($previous);
                }
                if ($previous['phase'] !== 'completed') {
                    throw new MigrationExportException('offer_previous_plan_not_complete');
                }
            }
            $index = $this->snapshots->index($parent, $attempt);
            if (! $index['done'] || $index['failed']) {
                throw new MigrationExportException('offer_parent_capture_not_sealed');
            }
            $plan = (string) Str::uuid();
            $dir = $root.'/plans/'.$plan;
            $this->mkdir($dir);
            $this->write($dir.'/parent-index.json', $index);
            $state = ['plan_id' => $plan, 'previous_plan_id' => $previous['plan_id'] ?? null, 'parent_run_id' => $run, 'parent_attempt_id' => $attempt,
                'parent_sha256' => hash_file('sha256', $parent.'/logical-snapshot/'.$attempt.'/checkpoint.json'), 'index_sha256' => hash_file('sha256', $dir.'/parent-index.json'),
                'phase' => 'planning', 'stage' => 0, 'shard' => 0, 'row' => 0, 'offset' => 0, 'accounts' => [], 'parts_with_allegro' => [], 'offers' => [], 'outcomes' => [],
                'orphan_listings_excluded' => 0, 'invalid_offer_ids' => 0, 'conflicting_id_fields' => 0, 'unsupported_accounts' => 0, 'duplicate_offer_ids' => 0, 'blocked_reason' => null, 'created_at' => now()->toIso8601String()];
            $this->write($dir.'/checkpoint.json', $state);
            $this->write($root.'/current.json', ['plan_id' => $plan]);

            return $this->reply($state);
        });
    }

    public function start(int $owner, string $run, string $attempt, string $digest, bool $confirm): array
    {
        [, $dir] = $this->context($owner, $run, $attempt);

        return $this->locked($dir, function () use ($dir, $digest, $confirm) {
            $state = $this->read($dir.'/checkpoint.json');
            if (! isset($state['plan_digest']) || ! in_array($state['phase'], ['planned', 'fetch', 'blocked', 'completed'], true)) {
                throw new MigrationExportException('offer_plan_not_ready');
            }
            if (! $confirm || ! hash_equals($state['plan_digest'], $digest)) {
                throw new MigrationExportException('offer_plan_confirmation_mismatch');
            }
            if ($state['phase'] !== 'completed') {
                $state['phase'] = 'fetch';
                $state['started_at'] ??= now()->toIso8601String();
                $state['blocked_reason'] = null;
            }
            $this->write($dir.'/checkpoint.json', $state);

            return $this->reply($state);
        });
    }

    public function status(int $owner, string $run, string $attempt): array
    {
        [, $dir, $state] = $this->context($owner, $run, $attempt);
        $lock = @fopen($dir.'/lock', 'r');
        $busy = false;
        if ($lock) {
            $busy = ! flock($lock, LOCK_EX | LOCK_NB);
            if (! $busy) {
                flock($lock, LOCK_UN);
            } fclose($lock);
        }

        return $this->reply($state) + ['busy' => $busy];
    }

    public function tick(int $owner, string $run, string $attempt): array
    {
        [$parent, $dir] = $this->context($owner, $run, $attempt);

        return $this->locked($dir, function () use ($parent, $dir) {
            $state = $this->read($dir.'/checkpoint.json');
            if ($state['phase'] === 'planning') {
                $this->discover($parent, $dir, $state);
            } elseif ($state['phase'] === 'fetch') {
                $this->fetch($dir, $state);
            }
            $this->write($dir.'/checkpoint.json', $state);

            return $this->reply($state);
        });
    }

    public function completed(int $owner, string $run, string $attempt): array
    {
        [, $dir, $state] = $this->context($owner, $run, $attempt);
        if ($state['phase'] !== 'completed') {
            throw new MigrationExportException('supplement_offer_readback_required');
        }

        return ['directory' => $dir, 'plan_id' => $state['plan_id'], 'offers' => array_values($state['offers']), 'summary' => $this->reply($state)];
    }

    public function withCompletedPlan(int $owner, string $run, string $attempt, ?string $expected, \Closure $work): mixed
    {
        $parent = $this->parents->supplementParent($owner, $run, $attempt);
        $root = $this->root($parent, $attempt);
        if (! is_dir($root)) {
            throw new MigrationExportException('supplement_offer_readback_required');
        }

        return $this->locked($root, function () use ($owner, $run, $attempt, $expected, $work) {
            $context = $this->completed($owner, $run, $attempt);
            if ($expected !== null && $expected !== $context['plan_id']) {
                throw new MigrationExportException('supplement_offer_plan_changed');
            }

            return $work();
        });
    }

    public function receipt(string $dir, string $offer): ?array
    {
        if (! preg_match('/\A[0-9]{1,30}\z/D', $offer)) {
            throw new MigrationExportException('offer_receipt_identity_invalid');
        }
        $path = $dir.'/receipts/'.hash('sha256', $offer).'.json';
        if (! is_file($path)) {
            return null;
        }
        $envelope = $this->read($path);
        if (! isset($envelope['data']) || ! hash_equals($envelope['sha256'] ?? '', hash('sha256', $this->json($envelope['data']))) || ($envelope['data']['offer_id'] ?? null) !== $offer) {
            throw new MigrationExportException('offer_receipt_corrupt');
        }

        return $envelope['data'];
    }

    private function discover(string $parent, string $dir, array &$state): void
    {
        $dataset = self::STAGES[$state['stage']] ?? null;
        if ($dataset === null) {
            ksort($state['offers'], SORT_STRING);
            $state['phase'] = 'planned';
            $definition = ['plan_id' => $state['plan_id'], 'parent_run_id' => $state['parent_run_id'], 'parent_attempt_id' => $state['parent_attempt_id'], 'parent_sha256' => $state['parent_sha256'], 'endpoint' => 'GET /sale/product-offers/{offerId}', 'account_code' => 'allegro_main', 'offers' => array_values($state['offers'])];
            $this->write($dir.'/plan-definition.json', $definition);
            $state['plan_digest'] = hash_file('sha256', $dir.'/plan-definition.json');

            return;
        }
        $source = new AllegroSupplementSource($parent, $this->read($dir.'/parent-index.json'));
        $deadline = microtime(true) + 1.5;
        for ($n = 0, $shards = 0; $n < 100 && $shards < 25 && microtime(true) < $deadline;) {
            $rows = $source->shard($dataset, $state['shard']);
            if ($rows === null) {
                $state['stage']++;
                $state['shard'] = $state['row'] = 0;
                break;
            }
            for (; isset($rows[$state['row']]) && $n < 100 && microtime(true) < $deadline; $n++, $state['row']++) {
                $row = $rows[$state['row']];
                if ($dataset === 'marketplace_account_identities') {
                    $state['accounts'][(string) $row['id']] = $row['code'] ?? null;
                } elseif ($dataset === 'parts') {
                    $this->mkdir($dir.'/parts');
                    $this->write($dir.'/parts/'.hash('sha256', (string) $row['id']).'.json', ['id' => (string) $row['id']]);
                } elseif (in_array($row['marketplace'] ?? null, ['allegro', 'allegro_main'], true)) {
                    $part = isset($row['part_id']) ? (string) $row['part_id'] : '';
                    if ($part === '' || ! is_file($dir.'/parts/'.hash('sha256', $part).'.json')) {
                        $state['orphan_listings_excluded']++;

                        continue;
                    }
                    $state['parts_with_allegro'][$part] = true;
                    if (isset($row['marketplace_account_id']) && ($state['accounts'][(string) $row['marketplace_account_id']] ?? null) !== 'allegro_main') {
                        $state['unsupported_accounts']++;

                        continue;
                    }
                    $ids = [];
                    foreach (['external_offer_id', 'external_listing_id'] as $field) {
                        $id = $row[$field] ?? null;
                        if (is_scalar($id) && preg_match('/\A[0-9]{1,30}\z/D', (string) $id)) {
                            $ids[] = (string) $id;
                        }
                    }
                    $ids = array_values(array_unique($ids));
                    if (count($ids) !== 1) {
                        $state['invalid_offer_ids']++;
                        $state['conflicting_id_fields'] += (int) (count($ids) > 1);

                        continue;
                    }
                    $offer = $ids[0];
                    if (isset($state['offers'][$offer])) {
                        $state['duplicate_offer_ids']++;
                    }
                    $state['offers'][$offer] ??= ['offer_id' => $offer, 'account_code' => 'allegro_main', 'links' => []];
                    $state['offers'][$offer]['links'][(string) $row['id']] = ['part_id' => $part, 'listing_id' => (string) $row['id'], 'marketplace' => $row['marketplace'], 'marketplace_account_id' => isset($row['marketplace_account_id']) ? (string) $row['marketplace_account_id'] : null, 'account_source' => isset($row['marketplace_account_id']) ? 'frozen_account_identity' : 'single_account_frozen_channel_default'];
                }
            }
            if ($state['row'] >= count($rows)) {
                $state['row'] = 0;
                $state['shard']++;
                $shards++;
            }
        }
    }

    private function fetch(string $dir, array &$state): void
    {
        $offers = array_values($state['offers']);
        $offer = $offers[$state['offset']]['offer_id'] ?? null;
        if ($offer === null) {
            $state['phase'] = 'completed';
            $state['completed_at'] = now()->toIso8601String();

            return;
        }
        $cooldown = dirname($dir, 5).'/.allegro-schema-refresh/allegro-main-cooldown.json';
        $globalWait = is_file($cooldown) ? (int) ($this->read($cooldown)['not_before'] ?? 0) : 0;
        $state['next_get_at'] = max($globalWait, $state['next_get_at'] ?? 0);
        if ($state['next_get_at'] > now()->timestamp) {
            return;
        }
        $prior = $this->receipt($dir, $offer);
        if ($prior['confirmed'] ?? false) {
            $this->advance($state, $offer, $prior);

            return;
        }
        if (($prior['retry_at'] ?? 0) > now()->timestamp) {
            return;
        }
        $accountIds = array_values(array_filter(array_column($offers[$state['offset']]['links'], 'marketplace_account_id'), fn ($id) => $id !== null));
        $result = $this->client->getOffer($offer, $accountIds[0] ?? null);
        $result['offer_id'] = $offer;
        $result['attempts'] = ($prior['attempts'] ?? 0) + 1;
        $result['provider_gets'] = ($prior['provider_gets'] ?? 0) + (int) (isset($result['http_status']) || in_array($result['reason_code'], ['network_error', 'invalid_offer_response'], true));
        $result['count_429'] = ($prior['count_429'] ?? 0) + (int) (($result['http_status'] ?? null) === 429);
        $result['count_errors'] = ($prior['count_errors'] ?? 0) + (int) (! $result['ok'] && ($result['http_status'] ?? null) !== 404);
        $result['observed_at'] = now()->toIso8601String();
        $result['confirmed'] = $result['ok'] || (! ($result['blocked'] ?? false) && (! ($result['retryable'] ?? false) || $result['attempts'] >= 4));
        if (($result['retryable'] ?? false) && ! ($result['blocked'] ?? false)) {
            $result['retry_at'] = now()->timestamp + max(min(30, 2 ** $result['attempts']), $result['retry_after_seconds'] ?? 0);
        }
        if (($result['retry_after_seconds'] ?? 0) > 0) {
            $this->mkdir(dirname($cooldown));
            $this->write($cooldown, ['not_before' => max($globalWait, now()->timestamp + $result['retry_after_seconds'])]);
        }
        $this->mkdir($dir.'/receipts');
        $this->write($dir.'/receipts/'.hash('sha256', $offer).'.json', ['data' => $result, 'sha256' => hash('sha256', $this->json($result))]);
        $this->advance($state, $offer, $result);
        if ($result['blocked'] ?? false) {
            $state['phase'] = 'blocked';
            $state['blocked_reason'] = $result['reason_code'];
        }
    }

    private function advance(array &$state, string $offer, array $result): void
    {
        if ($result['ok']) {
            $state['last_success'] = $result['fetched_at'];
        }
        $state['outcomes'][$offer] = array_intersect_key($result, array_flip(['ok', 'http_status', 'reason_code', 'fetched_at', 'attempts', 'provider_gets', 'count_429', 'count_errors', 'confirmed', 'retry_at']));
        $state['next_get_at'] = max($state['next_get_at'] ?? 0, $result['retry_at'] ?? 0);
        if ($result['confirmed']) {
            $state['offset']++;
        }
    }

    private function reply(array $state): array
    {
        $partOffers = [];
        $ambiguous = 0;
        foreach ($state['offers'] as $offer) {
            $parts = [];
            foreach ($offer['links'] as $link) {
                $partOffers[$link['part_id']][$offer['offer_id']] = true;
                $parts[$link['part_id']] = true;
            }
            $ambiguous += (int) (count($parts) > 1);
        }
        $sum = fn ($key) => array_sum(array_column($state['outcomes'], $key));

        return array_intersect_key($state, array_flip(['plan_id', 'previous_plan_id', 'parent_run_id', 'parent_attempt_id', 'phase', 'stage', 'shard', 'row', 'plan_digest', 'created_at', 'started_at', 'completed_at', 'last_success', 'blocked_reason', 'orphan_listings_excluded', 'invalid_offer_ids', 'conflicting_id_fields', 'unsupported_accounts', 'duplicate_offer_ids'])) + [
            'parts_with_allegro' => count($state['parts_with_allegro']), 'unique_offer_ids' => count($state['offers']), 'expected_gets' => count($state['offers']), 'parts_with_multiple_offers' => count(array_filter($partOffers, fn ($ids) => count($ids) > 1)), 'offers_linked_to_multiple_parts' => $ambiguous,
            'processed' => $state['offset'], 'total' => count($state['offers']), 'performed_gets' => $sum('provider_gets'), 'successes' => count(array_filter($state['outcomes'], fn ($r) => $r['ok'])), 'not_found_404' => count(array_filter($state['outcomes'], fn ($r) => ($r['http_status'] ?? null) === 404)), 'provider_429' => $sum('count_429'), 'errors' => $sum('count_errors'),
            'wait_seconds' => max(0, ($state['next_get_at'] ?? 0) - now()->timestamp), 'done' => $state['phase'] === 'completed', 'business_writes' => false, 'database_writes' => false, 'oauth_refresh' => false, 'provider_calls' => $sum('provider_gets') > 0];
    }

    private function context(int $owner, string $run, string $attempt): array
    {
        $parent = $this->parents->supplementParent($owner, $run, $attempt);
        $root = $this->root($parent, $attempt);
        if (! is_file($root.'/current.json')) {
            throw new MigrationExportException('offer_plan_not_started', 404);
        }
        $pointer = $this->read($root.'/current.json');
        if (! Str::isUuid($pointer['plan_id'] ?? null)) {
            throw new MigrationExportException('offer_plan_pointer_invalid');
        }
        $dir = $root.'/plans/'.$pointer['plan_id'];
        $state = $this->read($dir.'/checkpoint.json');
        if ($state['plan_id'] !== $pointer['plan_id'] || $state['parent_run_id'] !== $run || $state['parent_attempt_id'] !== $attempt || ! hash_equals($state['parent_sha256'], (string) @hash_file('sha256', $parent.'/logical-snapshot/'.$attempt.'/checkpoint.json')) || ! hash_equals($state['index_sha256'], (string) @hash_file('sha256', $dir.'/parent-index.json'))) {
            throw new MigrationExportException('offer_parent_identity_changed');
        }
        if (isset($state['plan_digest'])) {
            if (! hash_equals($state['plan_digest'], (string) @hash_file('sha256', $dir.'/plan-definition.json'))) {
                throw new MigrationExportException('offer_plan_integrity_failed');
            }
            $definition = $this->read($dir.'/plan-definition.json');
            if ($definition['plan_id'] !== $state['plan_id'] || $definition['offers'] !== array_values($state['offers'])) {
                throw new MigrationExportException('offer_plan_integrity_failed');
            }
        }

        return [$parent, $dir, $state];
    }

    private function root(string $parent, string $attempt): string
    {
        return dirname($parent).'/.allegro-offer-readback/'.basename($parent).'/'.$attempt;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function mkdir(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new MigrationExportException('offer_directory_unavailable');
        }
    }

    private function read(string $path): array
    {
        try {
            $v = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);

            return is_array($v) ? $v : throw new \RuntimeException;
        } catch (\Throwable) {
            throw new MigrationExportException('offer_checkpoint_invalid');
        }
    }

    private function write(string $path, array $value): void
    {
        $bytes = $this->json($value);
        if (file_put_contents($path.'.pending', $bytes, LOCK_EX) !== strlen($bytes) || ! chmod($path.'.pending', 0600) || ! rename($path.'.pending', $path)) {
            throw new MigrationExportException('offer_checkpoint_write_failed');
        }
    }

    private function locked(string $dir, callable $work): mixed
    {
        $lock = fopen($dir.'/lock', 'c');
        if (! $lock) {
            throw new MigrationExportException('offer_lock_unavailable');
        }
        chmod($dir.'/lock', 0600);
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new MigrationExportException('offer_readback_busy');
        }
        try {
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
