<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategory;
use App\Services\Marketplace\Api\MarketplaceApiManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MarketplaceCategoryTreeImportService
{
    public const CHANNELS = ['ovoko', 'allegro_main', 'ebay_de'];
    private const ALLEGRO_WANTED_ROOT = 'Motoryzacja > Części samochodowe';
    private const EBAY_DE_WANTED_ROOT = 'Motors / Autoteile & Zubehör';

    public function previewOrImport(bool $write): array
    {
        $warnings = [];
        $channels = [];
        foreach (self::CHANNELS as $channel) {
            try { $rows = $this->fetch($channel); }
            catch (\Throwable $e) { $rows = []; $warnings[] = $channel.': '.$e->getMessage(); }
            $existing = Schema::hasTable('marketplace_categories') ? MarketplaceCategory::query()->where('channel', $channel)->get()->keyBy('external_category_id') : collect();
            $create = $update = [];
            foreach ($rows as $row) {
                $old = $existing->get($row['external_category_id']);
                if (! $old) $create[] = $row;
                elseif ($this->changed($old, $row)) $update[] = $row;
            }
            if ($write && Schema::hasTable('marketplace_categories')) $this->upsertRows($rows);
            $channels[$channel] = array_filter([
                'root' => $channel === 'allegro_main' ? self::ALLEGRO_WANTED_ROOT : ($channel === 'ebay_de' ? self::EBAY_DE_WANTED_ROOT : null),
                'fetched_count' => count($rows),
                'would_create_count' => count($create),
                'would_update_count' => count($update),
                'sample_would_create' => array_slice($create, 0, 10),
            ], fn ($v) => $v !== null);
        }
        return ['ok' => true, 'dry_run' => ! $write, 'local_update' => $write, 'ovoko_write' => false, 'allegro_write' => false, 'ebay_write' => false, 'channels' => $channels, 'warnings' => $warnings];
    }

    public function debugFetch(bool $verbose = false): array
    {
        $warnings = [];
        return [
            'ok' => true,
            'dry_run' => true,
            'local_update' => false,
            'ovoko_write' => false,
            'allegro_write' => false,
            'ebay_write' => false,
            'channels' => [
                'ovoko' => $this->debugOvoko($warnings),
                'allegro_main' => $this->debugAllegro($warnings, $verbose),
                'ebay_de' => $this->debugEbayDe($warnings, $verbose),
            ],
            'warnings' => $warnings,
        ];
    }


    public function startAutorun(string $channel = 'all', int $batchSize = 200, bool $includeRawPayload = false, int $timeLimit = 10): array
    {
        $channel = $this->normalizeAutorunChannel($channel);
        $batchSize = $this->normalizeBatchSize($batchSize);
        $startedAt = now()->toIso8601String();
        $runId = 'marketplace_category_tree_import_'.now()->format('YmdHis').'_'.Str::lower(Str::random(8));
        $channels = $channel === 'all' ? self::CHANNELS : [$channel];
        $warnings = [];
        $worklist = [];

        foreach ($channels as $currentChannel) {
            try {
                foreach ($this->buildWorklist($currentChannel, $includeRawPayload) as $row) {
                    $worklist[] = $row;
                }
            } catch (\Throwable $e) {
                $warnings[] = $currentChannel.': '.$e->getMessage();
            }
        }

        $state = [
            'run_id' => $runId,
            'stage' => 'import',
            'status' => 'started',
            'channel' => $channel,
            'channels' => $channels,
            'batch_size' => $batchSize,
            'include_raw_payload' => $includeRawPayload,
            'time_limit' => max(1, min(30, $timeLimit)),
            'processed_count' => 0,
            'total_count' => count($worklist),
            'created_count' => 0,
            'updated_count' => 0,
            'failed_count' => 0,
            'sample_errors' => [],
            'warnings' => $warnings,
            'started_at' => $startedAt,
            'updated_at' => $startedAt,
            'completed_at' => null,
            'worklist' => $worklist,
        ];

        $this->putAutorunState($runId, $state);
        Cache::put($this->latestAutorunCacheKey(), $runId, now()->addHours(24));

        return $this->publicAutorunState($state, 'started');
    }

    public function tickAutorun(string $runId): array
    {
        $state = $this->getAutorunState($runId);
        if (! $state) return ['ok' => false, 'error_message' => 'Autorun not found or expired.', 'run_id' => $runId];
        if (($state['status'] ?? null) === 'complete') return $this->publicAutorunState($state, 'complete');

        $started = microtime(true);
        $limit = (float) ($state['time_limit'] ?? 10);
        $batchSize = (int) ($state['batch_size'] ?? 200);
        $worklist = $state['worklist'] ?? [];
        $total = count($worklist);
        $processedThisTick = 0;
        $state['status'] = 'running';
        $state['stage'] = 'import';

        while (($state['processed_count'] ?? 0) < $total && $processedThisTick < $batchSize && (microtime(true) - $started) < $limit) {
            $row = $worklist[(int) $state['processed_count']];
            try {
                $exists = MarketplaceCategory::query()
                    ->where('channel', $row['channel'])
                    ->where('external_category_id', $row['external_category_id'])
                    ->exists();
                MarketplaceCategory::query()->updateOrCreate(
                    ['channel' => $row['channel'], 'external_category_id' => $row['external_category_id']],
                    [
                        'parent_external_category_id' => $row['parent_external_category_id'],
                        'level' => $row['level'],
                        'name' => $row['name'],
                        'full_path' => $row['full_path'],
                        'raw_payload' => $row['raw_payload'] ?? null,
                        'active' => $row['active'] ?? true,
                        'imported_at' => $row['imported_at'] ?? now(),
                    ]
                );
                $exists ? $state['updated_count']++ : $state['created_count']++;
            } catch (\Throwable $e) {
                $state['failed_count']++;
                if (count($state['sample_errors']) < 20) {
                    $state['sample_errors'][] = [
                        'channel' => $row['channel'] ?? null,
                        'external_category_id' => $row['external_category_id'] ?? null,
                        'error_message' => Str::limit($e->getMessage(), 500),
                    ];
                }
            }
            $state['processed_count']++;
            $processedThisTick++;
        }

        if (($state['processed_count'] ?? 0) >= $total) {
            $state['status'] = 'complete';
            $state['stage'] = 'complete';
            $state['completed_at'] = now()->toIso8601String();
            unset($state['worklist']);
        }
        $state['updated_at'] = now()->toIso8601String();
        $this->putAutorunState($runId, $state);

        return $this->publicAutorunState($state, $state['status'], ['processed_this_tick' => $processedThisTick]);
    }

    public function statusAutorun(string $runId): array
    {
        $state = $this->getAutorunState($runId);
        return $state ? $this->publicAutorunState($state, $state['status'] ?? 'unknown') : ['ok' => false, 'error_message' => 'Autorun not found or expired.', 'run_id' => $runId];
    }

    public function resetAutorun(string $runId): array
    {
        Cache::forget($this->autorunCacheKey($runId));
        if (Cache::get($this->latestAutorunCacheKey()) === $runId) Cache::forget($this->latestAutorunCacheKey());
        return ['ok' => true, 'run_id' => $runId, 'status' => 'reset'];
    }

    public function resultsAutorun(string $runId): array
    {
        return $this->statusAutorun($runId) + ['results' => true];
    }

    public function latestAutorun(): ?array
    {
        $runId = Cache::get($this->latestAutorunCacheKey());
        return is_string($runId) && $runId !== '' ? $this->getAutorunState($runId) : null;
    }

    public function debugAutorun(): array
    {
        $columns = Schema::hasTable('marketplace_categories') ? Schema::getColumnListing('marketplace_categories') : [];
        $sample = null; $canBuild = false; $estimated = 0; $error = null;
        try {
            foreach (self::CHANNELS as $channel) {
                $rows = $this->buildWorklist($channel, false);
                $estimated += count($rows);
                $sample ??= $rows[0] ?? null;
            }
            $canBuild = true;
        } catch (\Throwable $e) { $error = $e->getMessage(); }
        $cacheKey = 'marketplace_category_tree_import_debug_'.Str::random(8);
        Cache::put($cacheKey, ['ok' => true], now()->addMinutes(5));
        $cacheOk = (bool) data_get(Cache::get($cacheKey), 'ok');
        Cache::forget($cacheKey);
        return [
            'ok' => true,
            'table_exists' => Schema::hasTable('marketplace_categories'),
            'columns' => $columns,
            'model_ok' => class_exists(MarketplaceCategory::class),
            'can_build_worklist' => $canBuild,
            'estimated_total_count' => $estimated,
            'sample_record' => $sample,
            'sample_record_size_bytes' => $sample ? strlen(json_encode($sample)) : 0,
            'cache_driver' => config('cache.default'),
            'cache_write_test' => $cacheOk,
            'error_message' => $error,
        ];
    }

    public function backfillEbayDe(bool $write): array
    {
        $channels = ['ebay_de', 'ebay'];
        $mappings = DB::table('marketplace_category_mappings')->whereIn('channel', $channels)->whereNotNull('external_category_id')->get();
        $tree = MarketplaceCategory::query()->where('channel', 'ebay_de')->get()->keyBy('external_category_id');
        $account = MarketplaceAccount::query()->where('code','ebay_de')->first();
        [$treeId] = $this->ebayTree($account);
        $apiLookup = $this->lookupEbayCategoryIds($mappings->pluck('external_category_id')->map(fn ($id) => (string) $id)->all(), $treeId, $account);
        $would = []; $missing = [];
        foreach ($mappings as $m) {
            $cat = $tree->get((string) $m->external_category_id);
            $lookup = $apiLookup[(string) $m->external_category_id] ?? null;
            $name = $cat?->name ?: data_get($lookup, 'name');
            $path = $cat?->full_path ?: data_get($lookup, 'path');
            if (! $cat && ! data_get($lookup, 'ok')) { $missing[] = ['mapping_id' => $m->id, 'channel' => $m->channel, 'local_category_id' => $m->local_category_id, 'external_category_id' => $m->external_category_id, 'api_lookup_status' => data_get($lookup, 'http_status'), 'safe_error_message' => data_get($lookup, 'safe_error_message')]; continue; }
            if (blank($m->external_category_name) || blank($m->external_category_path)) {
                $row = ['mapping_id' => $m->id, 'channel' => $m->channel, 'local_category_id' => $m->local_category_id, 'external_category_id' => $m->external_category_id, 'external_category_name' => $name, 'external_category_path' => $path, 'source' => $cat ? 'local_tree' : 'ebay_taxonomy_lookup'];
                $would[] = $row;
                if ($write) DB::table('marketplace_category_mappings')->where('id', $m->id)->update(['external_category_name' => $name, 'external_category_path' => $path, 'updated_at' => now()]);
            }
        }
        return ['ok' => true, 'dry_run' => ! $write, 'local_update' => $write, 'category_tree_id' => $treeId, 'mapping_count' => $mappings->count(), 'would_backfill_count' => count($would), 'not_found_in_tree_count' => count($missing), 'sample_would_backfill' => array_slice($would, 0, 20), 'sample_not_found' => array_slice($missing, 0, 20)];
    }


    public function normalizeAutorunChannel(string $channel): string
    {
        return in_array($channel, array_merge(['all'], self::CHANNELS), true) ? $channel : 'all';
    }

    public function normalizeBatchSize(int $batchSize): int
    {
        return in_array($batchSize, [50, 100, 200], true) ? $batchSize : 200;
    }

    private function buildWorklist(string $channel, bool $includeRawPayload): array
    {
        return array_map(function (array $row) use ($includeRawPayload) {
            return [
                'channel' => (string) $row['channel'],
                'external_category_id' => (string) $row['external_category_id'],
                'parent_external_category_id' => filled($row['parent_external_category_id'] ?? null) ? (string) $row['parent_external_category_id'] : null,
                'level' => (int) ($row['level'] ?? 0),
                'name' => (string) ($row['name'] ?? $row['external_category_id']),
                'full_path' => (string) ($row['full_path'] ?? $row['name'] ?? $row['external_category_id']),
                'active' => (bool) ($row['active'] ?? true),
                'imported_at' => now(),
                'raw_payload' => $includeRawPayload ? $this->cleanRawPayload($row['raw_payload'] ?? []) : null,
            ];
        }, $this->fetch($channel));
    }

    private function cleanRawPayload(array $payload): array
    {
        unset($payload['childCategoryTreeNodes']);
        foreach ($payload as $key => $value) {
            if (is_array($value)) $payload[$key] = $this->limitedRaw($value);
            if (is_string($value)) $payload[$key] = Str::limit($value, 1000)->value();
        }
        return $payload;
    }

    private function autorunCacheKey(string $runId): string
    {
        return 'marketplace_category_tree_import_autorun:'.$runId;
    }

    private function latestAutorunCacheKey(): string
    {
        return 'marketplace_category_tree_import_autorun:latest';
    }

    private function putAutorunState(string $runId, array $state): void
    {
        Cache::put($this->autorunCacheKey($runId), $state, now()->addHours(24));
    }

    private function getAutorunState(string $runId): ?array
    {
        $state = Cache::get($this->autorunCacheKey($runId));
        return is_array($state) ? $state : null;
    }

    private function publicAutorunState(array $state, string $status, array $extra = []): array
    {
        $total = (int) ($state['total_count'] ?? 0);
        $processed = (int) ($state['processed_count'] ?? 0);
        $elapsed = isset($state['started_at']) ? max(0, now()->diffInSeconds(\Carbon\Carbon::parse($state['started_at']))) : null;
        $nextUrl = $status === 'complete' ? null : url('/tools/run-marketplace-category-tree-import-autorun').'?token=gps_images_import_2026&run_id='.urlencode((string) $state['run_id']);
        return array_merge([
            'ok' => true,
            'local_update' => true,
            'ovoko_write' => false,
            'allegro_write' => false,
            'ebay_write' => false,
            'run_id' => $state['run_id'],
            'stage' => $state['stage'] ?? 'import',
            'status' => $status,
            'channel' => $state['channel'] ?? 'all',
            'batch_size' => (int) ($state['batch_size'] ?? 200),
            'processed_count' => $processed,
            'total_count' => $total,
            'created_count' => (int) ($state['created_count'] ?? 0),
            'updated_count' => (int) ($state['updated_count'] ?? 0),
            'failed_count' => (int) ($state['failed_count'] ?? 0),
            'progress_percent' => $total > 0 ? round(($processed / $total) * 100, 2) : 100,
            'sample_errors' => $state['sample_errors'] ?? [],
            'warnings' => $state['warnings'] ?? [],
            'next_url' => $nextUrl,
            'elapsed' => $elapsed,
            'time_spent' => $elapsed,
            'started_at' => $state['started_at'] ?? null,
            'updated_at' => $state['updated_at'] ?? null,
            'completed_at' => $state['completed_at'] ?? null,
        ], $extra);
    }

    private function fetch(string $channel): array
    {
        return match ($channel) {
            'ovoko' => $this->normalizeOvoko(app(MarketplaceApiManager::class)->client('ovoko')->fetchCategories(60)['categories'] ?? []),
            'allegro_main' => $this->fetchAllegro(),
            'ebay_de' => $this->fetchEbayDe(),
        };
    }

    private function debugOvoko(array &$warnings): array
    {
        $base = ['configured' => false, 'fetch_ok' => false, 'raw_count' => 0, 'empty_response' => false, 'sample_roots' => [], 'sample_level_2' => [], 'sample_level_3' => [], 'error' => null];
        try {
            $account = MarketplaceAccount::query()->where('code', 'ovoko_main')->first();
            $base['configured'] = $this->accountConfigured($account);
            $rows = $this->normalizeOvoko(app(MarketplaceApiManager::class)->client('ovoko')->fetchCategories(60)['categories'] ?? []);
            $base['raw_count'] = count($rows); $base['empty_response'] = count($rows) === 0; $base['fetch_ok'] = count($rows) > 0;
            $base['sample_roots'] = $this->sampleRows($rows, fn ($r) => (int) $r['level'] === 0);
            $base['sample_level_2'] = $this->sampleRows($rows, fn ($r) => (int) $r['level'] === 1);
            $base['sample_level_3'] = $this->sampleRows($rows, fn ($r) => (int) $r['level'] === 2);
        } catch (\Throwable $e) { $base['error'] = $e->getMessage(); $warnings[] = 'ovoko: '.$e->getMessage(); }
        return $base;
    }

    private function debugAllegro(array &$warnings, bool $verbose = false): array
    {
        $base = ['configured' => false, 'fetch_ok' => false, 'raw_count' => 0, 'empty_response' => false, 'root_candidates' => [], 'wanted_root' => self::ALLEGRO_WANTED_ROOT, 'wanted_root_found' => false, 'children_count' => 0, 'sample_children' => [], 'error' => null];
        try {
            $account = MarketplaceAccount::query()->where('code', 'allegro_main')->first();
            $base['configured'] = $this->accountConfigured($account);
            $base['token_present'] = filled(data_get($account, 'api_credentials.access_token'));
            $diag = $this->allegroRootAndSubtreeDiagnostics($account, $verbose);
            [$roots, $children] = [$diag['roots'], $diag['children']];
            $rows = $this->fetchAllegro();
            $base = array_merge($base, $diag['meta']);
            $base['raw_count'] = count($rows); $base['root_candidates'] = $roots;
            $base['empty_response'] = count($rows) === 0 || (int) ($base['root_raw_count'] ?? 0) === 0;
            $base['fetch_ok'] = ! $base['empty_response'] && (int) ($base['http_status'] ?? 0) >= 200 && (int) ($base['http_status'] ?? 0) < 300;
            $base['wanted_root_found'] = count($rows) > 0; $base['children_count'] = count($children); $base['sample_children'] = array_slice($children, 0, 20);
            if ($base['empty_response']) $warnings[] = 'allegro_main: empty_response';
        } catch (\Throwable $e) { $base['error'] = $e->getMessage(); $base['safe_error_message'] = $e->getMessage(); $warnings[] = 'allegro_main: '.$e->getMessage(); }
        return $base;
    }

    private function debugEbayDe(array &$warnings, bool $verbose = false): array
    {
        $base = ['configured' => false, 'fetch_ok' => false, 'taxonomy_ok' => false, 'raw_count' => 0, 'empty_response' => false, 'root_candidates' => [], 'wanted_root' => self::EBAY_DE_WANTED_ROOT, 'wanted_root_found' => false, 'children_count' => 0, 'sample_children' => [], 'sample_existing_mapping_ids_lookup' => [], 'error' => null];
        try {
            $account = MarketplaceAccount::query()->where('code', 'ebay_de')->first(); $base['configured'] = $this->accountConfigured($account); $base['token_present'] = filled(data_get($account, 'api_credentials.access_token'));
            [$treeId, $root, $rootCandidates, $meta] = $this->ebayTree($account, $verbose);
            $base = array_merge($base, $meta); $base['category_tree_id'] = $treeId; $base['taxonomy_ok'] = filled($treeId) && is_array($root); $base['root_candidates'] = $rootCandidates;
            $rows = is_array($root) ? $this->flattenEbayWantedSubtree([$root]) : [];
            $base['raw_count'] = count($rows); $base['empty_response'] = count($rows) === 0;
            $base['fetch_ok'] = $base['taxonomy_ok'] && ! $base['empty_response']; $base['wanted_root_found'] = count($rows) > 0;
            $base['children_count'] = max(0, count($rows) - 1); $base['sample_children'] = array_slice(array_map(fn ($r) => $this->rowSummary($r), $rows), 0, 20);
            $base['sample_existing_mapping_ids_lookup'] = $this->lookupExistingEbayMappings($rows, $treeId, $account);
            if ($base['empty_response'] || ! $base['taxonomy_ok']) $warnings[] = 'ebay_de: empty_response_or_taxonomy_unavailable';
        } catch (\Throwable $e) { $base['error'] = $e->getMessage(); $base['safe_error_message'] = $e->getMessage(); $warnings[] = 'ebay_de: '.$e->getMessage(); }
        return $base;
    }

    private function normalizeOvoko(array $rows): array
    {
        $base = collect($rows)->map(function (array $r) {
            $id = (string) ($r['id'] ?? $r['category_id'] ?? $r['categoryId'] ?? '');
            $parent = $r['parent_id'] ?? $r['parentId'] ?? $r['parent_category_id'] ?? null;
            $name = (string) ($r['pl'] ?? $r['name'] ?? $r['category_name'] ?? $r['en'] ?? $id);
            return ['channel'=>'ovoko','external_category_id'=>$id,'parent_external_category_id'=>filled($parent) && (string)$parent !== '0' ? (string)$parent : null,'level'=>0,'name'=>$name,'full_path'=>(string)($r['category_title_path'] ?? $r['category_path'] ?? $r['path'] ?? $name),'raw_payload'=>$r,'active'=>true,'imported_at'=>now()];
        })->filter(fn($r)=>filled($r['external_category_id']))->values();
        return $this->withLevels($base->all());
    }

    private function fetchAllegro(): array
    {
        $account = MarketplaceAccount::query()->where('code','allegro_main')->first();
        $diag = $this->allegroRootAndSubtreeDiagnostics($account);
        $roots = $diag['roots'];
        $wantedRoot = collect($roots)->first(fn ($r) => $this->isAllegroMotoryzacjaRoot((string) $r['name']));
        if (! $wantedRoot) return [];
        $rootPath = (string) $wantedRoot['name'];
        $rootRow = $this->marketplaceRow('allegro_main', (string) $wantedRoot['id'], null, 0, $rootPath, $rootPath, $wantedRoot['raw'] ?? []);
        $firstLevel = $this->fetchAllegroChildren((string) $wantedRoot['id'], $rootPath, 1);
        $partsRoot = collect($firstLevel)->first(fn ($row) => $this->isAllegroPartsRoot((string) $row['name'], (string) $row['full_path']));
        if (! $partsRoot) return [$rootRow, ...$firstLevel];
        return array_merge([$rootRow, $partsRoot], $this->fetchAllegroDescendants((string) $partsRoot['external_category_id'], (string) $partsRoot['full_path'], 2));
    }

    private function allegroRootAndSubtreeDiagnostics(?MarketplaceAccount $account, bool $verbose = false): array
    {
        $token = (string) data_get($account, 'api_credentials.access_token');
        $base = rtrim((string) $account?->api_base_url, '/');
        $endpoint = $base === '' ? null : $base.'/sale/categories';
        $meta = ['endpoint_used' => $endpoint, 'http_status' => null, 'raw_response_keys' => [], 'root_raw_count' => 0, 'empty_response' => true, 'safe_error_message' => null];
        if ($base === '' || $token === '') return ['roots' => [], 'children' => [], 'meta' => $meta];
        $res = Http::withToken($token)->accept('application/vnd.allegro.public.v1+json')->timeout(30)->get($endpoint);
        $json = $res->json() ?: [];
        $meta['http_status'] = $res->status();
        $meta['raw_response_keys'] = is_array($json) ? array_keys($json) : [];
        $meta['safe_error_message'] = $this->safeErrorMessage($json);
        if ($verbose) $meta['sample_raw_response_limited'] = $this->limitedRaw($json);
        $roots = [];
        foreach (($json['categories'] ?? []) as $c) if (is_array($c)) $roots[] = ['id' => (string)($c['id'] ?? ''), 'name' => (string)($c['name'] ?? ''), 'leaf' => (bool)($c['leaf'] ?? false), 'raw' => $c];
        $meta['root_raw_count'] = count($roots); $meta['empty_response'] = count($roots) === 0;
        $wanted = collect($roots)->first(fn ($r) => $this->isAllegroMotoryzacjaRoot((string) $r['name']));
        $children = $wanted ? array_map(fn ($r) => $this->rowSummary($r), $this->fetchAllegroChildren((string) $wanted['id'], (string) $wanted['name'], 1)) : [];
        return ['roots' => array_map(fn ($r) => array_diff_key($r, ['raw' => true]), $roots), 'children' => $children, 'meta' => $meta];
    }

    private function fetchAllegroChildren(string $parentId, string $path, int $level): array
    {
        $account = MarketplaceAccount::query()->where('code','allegro_main')->first(); $token = (string) data_get($account, 'api_credentials.access_token'); $base = rtrim((string) $account?->api_base_url, '/');
        if ($base === '' || $token === '') return [];
        $res = Http::withToken($token)->accept('application/vnd.allegro.public.v1+json')->timeout(30)->get($base.'/sale/categories', ['parent.id' => $parentId]);
        $out = [];
        foreach (($res->json('categories') ?: []) as $c) {
            if (! is_array($c)) continue; $id = (string)($c['id'] ?? ''); if ($id === '') continue;
            $name = (string)($c['name'] ?? $id); $out[] = $this->marketplaceRow('allegro_main', $id, $parentId, $level, $name, $path.' > '.$name, $c);
        }
        return $out;
    }

    private function fetchAllegroDescendants(string $parentId, string $path, int $level): array
    {
        $account = MarketplaceAccount::query()->where('code','allegro_main')->first(); $token = (string) data_get($account, 'api_credentials.access_token'); $base = rtrim((string) $account?->api_base_url, '/');
        if ($base === '' || $token === '') return [];
        $out = []; $queue = [[$parentId, $path, $level]];
        while ($queue) {
            [$parent, $parentPath, $lvl] = array_shift($queue);
            $res = Http::withToken($token)->accept('application/vnd.allegro.public.v1+json')->timeout(30)->get($base.'/sale/categories', ['parent.id' => $parent]);
            foreach (($res->json('categories') ?: []) as $c) {
                if (! is_array($c)) continue; $id = (string)($c['id'] ?? ''); if ($id === '') continue;
                $name = (string)($c['name'] ?? $id); $full = $parentPath.' > '.$name;
                $row = $this->marketplaceRow('allegro_main', $id, $parent, $lvl, $name, $full, $c); $out[] = $row;
                if (! ($c['leaf'] ?? false)) $queue[] = [$id, $full, $lvl + 1];
            }
        }
        return $out;
    }

    private function fetchEbayDe(): array
    {
        $account = MarketplaceAccount::query()->where('code','ebay_de')->first();
        [, $root] = $this->ebayTree($account);
        return is_array($root) ? $this->flattenEbayWantedSubtree([$root]) : [];
    }

    private function ebayTree(?MarketplaceAccount $account, bool $verbose = false): array
    {
        $token=(string)data_get($account,'api_credentials.access_token'); $base=rtrim((string)$account?->api_base_url,'/');
        $marketplaceId = 'EBAY_DE';
        $meta = ['marketplace_id' => $marketplaceId, 'endpoint_used' => null, 'taxonomy_endpoint' => null, 'http_status' => null, 'raw_response_keys' => [], 'get_default_category_tree_id_result' => null, 'safe_error_message' => null];
        if ($base===''||$token==='') return [null, null, [], $meta];
        $headers=['X-EBAY-C-MARKETPLACE-ID'=>$marketplaceId];
        $defaultEndpoint = $base.'/commerce/taxonomy/v1/get_default_category_tree_id';
        $meta['endpoint_used'] = $defaultEndpoint;
        $default = Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(30)->get($defaultEndpoint,['marketplace_id'=>$marketplaceId]);
        $defaultJson = $default->json() ?: [];
        $tree = $defaultJson['categoryTreeId'] ?? null;
        $meta['http_status'] = $default->status(); $meta['raw_response_keys'] = is_array($defaultJson) ? array_keys($defaultJson) : [];
        $meta['get_default_category_tree_id_result'] = $this->limitedRaw($defaultJson); $meta['safe_error_message'] = $this->safeErrorMessage($defaultJson);
        if (!$tree) return [null, null, [], $meta];
        $treeEndpoint = $base.'/commerce/taxonomy/v1/category_tree/'.$tree;
        $meta['taxonomy_endpoint'] = $treeEndpoint; $meta['endpoint_used'] = $treeEndpoint;
        $treeRes=Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(60)->get($treeEndpoint);
        $json=$treeRes->json() ?: [];
        $meta['http_status'] = $treeRes->status(); $meta['raw_response_keys'] = is_array($json) ? array_keys($json) : [];
        $meta['safe_error_message'] = $this->safeErrorMessage($json) ?: $meta['safe_error_message'];
        if ($verbose) $meta['sample_raw_response_limited'] = $this->limitedRaw($json);
        $root=$json['rootCategoryNode'] ?? null;
        $candidates = is_array($root) ? array_merge([['category_tree_id' => (string) $tree] + $this->ebayNodeSummary($root)], array_slice(array_map(fn ($n) => ['category_tree_id' => (string) $tree] + $this->ebayNodeSummary($n), $root['childCategoryTreeNodes'] ?? []), 0, 50)) : [['category_tree_id' => (string) $tree]];
        return [(string) $tree, $root, $candidates, $meta];
    }

    private function flattenEbayWantedSubtree(array $nodes, ?string $parent = null, string $path = '', int $level = 0, bool $insideWanted = false): array
    {
        $out = [];
        foreach ($nodes as $n) {
            $cat=$n['category']??[]; $id=(string)($cat['categoryId']??''); if($id==='') continue;
            $name=(string)($cat['categoryName']??$id); $full=trim($path===''?$name:$path.' > '.$name);
            $wanted = $insideWanted || $this->isEbayDeAutomotiveRoot($name, $full);
            $row=$this->marketplaceRow('ebay_de', $id, $parent, $level, $name, $full, $n);
            if ($wanted) $out[]=$row;
            $children=$n['childCategoryTreeNodes']??[];
            if(is_array($children)) $out=array_merge($out,$this->flattenEbayWantedSubtree($children,$id,$full,$level+1,$wanted));
        }
        return $out;
    }

    private function lookupExistingEbayMappings(array $rows, ?string $treeId = null, ?MarketplaceAccount $account = null): array
    {
        $lookupIds = ['33578', '33588', '9887'];
        if (Schema::hasTable('marketplace_category_mappings')) {
            $mappingIds = DB::table('marketplace_category_mappings')->whereIn('channel', ['ebay', 'ebay_de'])->whereNotNull('external_category_id')->select('external_category_id')->distinct()->limit(20)->pluck('external_category_id')->map(fn ($id) => (string) $id)->all();
            $lookupIds = array_values(array_unique(array_merge($lookupIds, $mappingIds)));
        }
        $byId = collect($rows)->keyBy('external_category_id');
        $apiLookup = $this->lookupEbayCategoryIds($lookupIds, $treeId, $account);
        if (! Schema::hasTable('marketplace_category_mappings')) {
            return array_map(fn ($id) => ['external_category_id' => $id, 'found_in_fetched_tree' => $byId->has($id), 'api_lookup' => $apiLookup[$id] ?? null], $lookupIds);
        }
        return DB::table('marketplace_category_mappings')->whereIn('channel', ['ebay', 'ebay_de'])->whereNotNull('external_category_id')->select('id','channel','local_category_id','external_category_id')->distinct()->limit(20)->get()->map(function ($m) use ($byId, $apiLookup) {
            $id = (string) $m->external_category_id; $cat = $byId->get($id);
            return ['mapping_id' => $m->id, 'channel' => $m->channel, 'local_category_id' => $m->local_category_id, 'external_category_id' => $id, 'found_in_fetched_tree' => $cat !== null, 'name' => $cat['name'] ?? data_get($apiLookup, $id.'.name'), 'path' => $cat['full_path'] ?? data_get($apiLookup, $id.'.path'), 'api_lookup_ok' => (bool) data_get($apiLookup, $id.'.ok', false), 'api_lookup_status' => data_get($apiLookup, $id.'.http_status')];
        })->all();
    }

    private function lookupEbayCategoryIds(array $ids, ?string $treeId, ?MarketplaceAccount $account): array
    {
        $token=(string)data_get($account,'api_credentials.access_token'); $base=rtrim((string)$account?->api_base_url,'/');
        if (!$treeId || $base==='' || $token==='') return [];
        $out = []; $headers=['X-EBAY-C-MARKETPLACE-ID'=>'EBAY_DE'];
        foreach (array_slice(array_values(array_unique($ids)), 0, 25) as $id) {
            $endpoint = $base.'/commerce/taxonomy/v1/category_tree/'.$treeId.'/get_category_subtree';
            $res = Http::withToken($token)->withHeaders($headers)->acceptJson()->timeout(20)->get($endpoint, ['category_id' => $id]);
            $json = $res->json() ?: []; $node = $json['categorySubtreeNode'] ?? null; $cat = is_array($node) ? ($node['category'] ?? []) : [];
            $out[(string) $id] = ['ok' => $res->successful() && is_array($cat) && filled($cat['categoryId'] ?? null), 'http_status' => $res->status(), 'endpoint_used' => $endpoint, 'name' => $cat['categoryName'] ?? null, 'path' => $cat['categoryName'] ?? null, 'safe_error_message' => $this->safeErrorMessage($json)];
        }
        return $out;
    }


    private function safeErrorMessage(array $json): ?string
    {
        $message = $json['message'] ?? $json['error_description'] ?? $json['error'] ?? null;
        if (!$message && isset($json['errors']) && is_array($json['errors'])) $message = collect($json['errors'])->pluck('message')->filter()->implode(' | ');
        return filled($message) ? str($message)->limit(500)->value() : null;
    }

    private function limitedRaw(array $json, int $depth = 0): array
    {
        $out = [];
        foreach (array_slice($json, 0, 20, true) as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $depth >= 2 ? ['_truncated_count' => count($value)] : $this->limitedRaw($value, $depth + 1);
            } elseif (is_string($value)) {
                $out[$key] = str($value)->limit(500)->value();
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }
    private function marketplaceRow(string $channel, string $id, ?string $parent, int $level, string $name, string $fullPath, array $raw): array { return ['channel'=>$channel,'external_category_id'=>$id,'parent_external_category_id'=>$parent,'level'=>$level,'name'=>$name,'full_path'=>$fullPath,'raw_payload'=>$raw,'active'=>true,'imported_at'=>now()]; }
    private function accountConfigured(?MarketplaceAccount $account): bool { return (bool) ($account && $account->api_enabled && filled($account->api_base_url) && filled(data_get($account, 'api_credentials.access_token'))); }
    private function normalizeText(string $value): string { return str($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->value(); }
    private function isAllegroMotoryzacjaRoot(string $name): bool { $n = $this->normalizeText($name); return str_contains($n, 'motoryzacja') || str_contains($n, 'samochod'); }
    private function isAllegroPartsRoot(string $name, string $path): bool { $n = $this->normalizeText($name.' '.$path); return str_contains($n, 'czesci samochodowe') || (str_contains($n, 'czesci') && str_contains($n, 'auto')); }
    private function isEbayDeAutomotiveRoot(string $name, string $path): bool { $n = $this->normalizeText($name.' '.$path); return (str_contains($n, 'auto') || str_contains($n, 'motor') || str_contains($n, 'fahrzeug')) && (str_contains($n, 'teile') || str_contains($n, 'zubehor') || str_contains($n, 'parts')); }
    private function rowSummary(array $r): array { return ['id' => (string)($r['external_category_id'] ?? $r['id'] ?? ''), 'parent_id' => $r['parent_external_category_id'] ?? null, 'name' => (string)($r['name'] ?? ''), 'path' => (string)($r['full_path'] ?? $r['path'] ?? $r['name'] ?? ''), 'level' => (int)($r['level'] ?? 0)]; }
    private function ebayNodeSummary(array $n): array { $cat = $n['category'] ?? []; return ['id' => (string)($cat['categoryId'] ?? ''), 'name' => (string)($cat['categoryName'] ?? ''), 'children_count' => count($n['childCategoryTreeNodes'] ?? [])]; }
    private function sampleRows(array $rows, callable $filter): array { return array_slice(array_values(array_map(fn ($r) => $this->rowSummary($r), array_filter($rows, $filter))), 0, 20); }
    private function withLevels(array $rows): array { $by=collect($rows)->keyBy('external_category_id'); return array_map(function($r)use($by){$level=0;$p=$r['parent_external_category_id'];while($p&&$by->has($p)&&$level<20){$level++;$p=$by[$p]['parent_external_category_id'];}$r['level']=$level;return $r;},$rows); }
    private function changed(MarketplaceCategory $old, array $row): bool { return $old->parent_external_category_id !== $row['parent_external_category_id'] || (int)$old->level !== (int)$row['level'] || $old->name !== $row['name'] || $old->full_path !== $row['full_path']; }
    private function upsertRows(array $rows): void { foreach(array_chunk($rows,500) as $chunk) MarketplaceCategory::query()->upsert(array_map(fn($r)=>array_merge($r,['created_at'=>now(),'updated_at'=>now()]),$chunk), ['channel','external_category_id'], ['parent_external_category_id','level','name','full_path','raw_payload','active','imported_at','updated_at']); }
}
