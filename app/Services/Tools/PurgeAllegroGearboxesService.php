<?php

namespace App\Services\Tools;

use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Models\PartImage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PurgeAllegroGearboxesService
{
    public const CONFIRMATION = 'purge-allegro-gearboxes';

    private const EXPECTED_MATCHED_COUNT = 1456;
    private const MAX_SAMPLES = 20;
    private const BLOCKED_DEPENDENCIES = ['order_items', 'marketplace_listings'];
    private const PROTECTED_TABLES = ['parts', 'migrations', 'failed_jobs', 'jobs', 'job_batches', 'cache', 'cache_locks', 'sessions'];

    /** @return array<string, mixed> */
    public function dryRun(): array
    {
        $plan = $this->buildPlan();
        unset($plan['matched_part_ids'], $plan['part_image_files']);

        return ['ok' => true, 'dry_run' => true] + $plan;
    }

    /** @return array<string, mixed> */
    public function live(string $confirm): array
    {
        if (! hash_equals(self::CONFIRMATION, $confirm)) {
            throw new RuntimeException('Invalid confirmation token. Pass --confirm='.self::CONFIRMATION.'.');
        }

        $plan = $this->buildPlan();
        $this->assertSafeForLive($plan);

        $partIds = $plan['matched_part_ids'];
        $partsTotalBefore = $plan['parts_total_before'];
        $deletedDependencies = [];
        $deletedFilesCount = 0;
        $skippedSharedFilesCount = 0;
        $purgedPartsCount = 0;
        $auditLogId = null;
        $now = Carbon::now();

        DB::transaction(function () use ($partIds, $plan, $now, &$deletedDependencies, &$deletedFilesCount, &$skippedSharedFilesCount, &$purgedPartsCount, &$auditLogId): void {
            foreach ($this->deletableDependencies($plan['dependency_counts']) as $dependency) {
                if ($dependency['count'] <= 0) {
                    continue;
                }

                $deletedDependencies[$dependency['key']] = DB::table($dependency['table'])
                    ->whereIn($dependency['column'], $partIds)
                    ->delete();
            }

            [$deletedFilesCount, $skippedSharedFilesCount] = $this->deletePartImageFiles($plan['part_image_files'], $partIds);

            $purgedPartsCount = Part::query()->whereIn('id', $partIds)->delete();

            $audit = MarketplaceSyncLog::query()->create([
                'marketplace' => 'allegro',
                'action' => 'purge_allegro_gearboxes_parts',
                'status' => 'completed',
                'message' => 'Purged Allegro Gearboxes imported parts and local dependent records from the Laravel database only; no external APIs, Allegro auction ending, Ovoko, eBay, or primary Allegro actions were called.',
                'payload' => [
                    'purged_parts_count' => $purgedPartsCount,
                    'deleted_dependencies' => $deletedDependencies,
                    'deleted_files_count' => $deletedFilesCount,
                    'skipped_shared_files_count' => $skippedSharedFilesCount,
                    'part_ids_sample' => $plan['will_purge_part_ids_sample'],
                    'titles_sample' => $plan['will_purge_titles_sample'],
                    'secondary_offer_ids_sample' => $plan['secondary_offer_ids_sample'],
                    'dependency_counts' => $plan['dependency_counts'],
                    'safety_checks' => $plan['safety_checks'],
                ],
                'created_at' => $now,
            ]);

            $auditLogId = $audit->id;
        });

        return [
            'ok' => true,
            'dry_run' => false,
            'purged_parts_count' => $purgedPartsCount,
            'parts_total_before' => $partsTotalBefore,
            'parts_total_after' => Part::query()->count(),
            'deleted_dependencies' => $deletedDependencies,
            'deleted_files_count' => $deletedFilesCount,
            'skipped_shared_files_count' => $skippedSharedFilesCount,
            'audit_log_id' => $auditLogId,
            'safety_checks' => $plan['safety_checks'],
        ];
    }

    /** @return array<string, mixed> */
    private function buildPlan(): array
    {
        $summary = [
            'parts_total_before' => Part::query()->count(),
            'matched_count' => 0,
            'will_purge_parts_count' => 0,
            'will_purge_part_ids_sample' => [],
            'will_purge_titles_sample' => [],
            'secondary_offer_ids_sample' => [],
            'dependency_counts' => [],
            'blocked_dependency_counts' => [],
            'safety_checks' => [
                'matched_count_is_expected' => false,
                'secondary_signature_mismatches' => 0,
                'with_primary_allegro_offer_id' => 0,
                'with_both_primary_and_secondary_offer_ids' => 0,
                'order_items' => 0,
                'marketplace_listings' => 0,
                'dependency_detection_complete' => false,
            ],
            'blockers' => [],
            'purge_allowed' => false,
            'matched_part_ids' => [],
            'part_image_files' => [],
        ];

        Part::query()
            ->select(['id', 'name', 'legacy_payload'])
            ->whereNotNull('legacy_payload')
            ->orderBy('id')
            ->chunkById(500, function ($parts) use (&$summary): void {
                foreach ($parts as $part) {
                    $data = $this->legacyPayloadJson(is_array($part->legacy_payload) ? $part->legacy_payload : []);
                    $primaryOfferId = $this->clean(data_get($data, '_allegro_offer_id'));
                    $secondaryOfferId = $this->clean(data_get($data, '_secondary_allegro_offer_id'));

                    $hasSecondary = $secondaryOfferId !== null;
                    $matchesSignature = $hasSecondary
                        && $this->clean(data_get($data, '_secondary_allegro_account')) === 'allegro_gearboxes'
                        && $this->clean(data_get($data, '_channel_allegro_gearboxes_enabled')) === 'yes'
                        && $this->clean(data_get($data, '_channel_allegro_main_enabled')) === 'no'
                        && $this->clean(data_get($data, '_imported_from_secondary_allegro')) === 'yes';

                    if ($hasSecondary && ! $matchesSignature) {
                        $summary['safety_checks']['secondary_signature_mismatches']++;
                    }

                    if (! $matchesSignature) {
                        continue;
                    }

                    $summary['matched_count']++;
                    $summary['will_purge_parts_count']++;
                    $summary['matched_part_ids'][] = $part->id;
                    $this->pushSample($summary['will_purge_part_ids_sample'], $part->id);
                    $this->pushSample($summary['will_purge_titles_sample'], $part->name);
                    $this->pushSample($summary['secondary_offer_ids_sample'], $secondaryOfferId);

                    if ($primaryOfferId !== null) {
                        $summary['safety_checks']['with_primary_allegro_offer_id']++;
                        $summary['safety_checks']['with_both_primary_and_secondary_offer_ids']++;
                    }
                }
            });

        $summary['safety_checks']['matched_count_is_expected'] = $summary['matched_count'] === self::EXPECTED_MATCHED_COUNT;
        $summary['dependency_counts'] = $this->dependencyCounts($summary['matched_part_ids']);
        $summary['part_image_files'] = $this->partImageFiles($summary['matched_part_ids']);
        $summary['safety_checks']['order_items'] = (int) ($summary['dependency_counts']['order_items'] ?? 0);
        $summary['safety_checks']['marketplace_listings'] = (int) ($summary['dependency_counts']['marketplace_listings'] ?? 0);
        $summary['safety_checks']['dependency_detection_complete'] = $this->canDetectDependencies();
        $summary['blocked_dependency_counts'] = $this->blockedDependencyCounts($summary['dependency_counts']);
        $summary['blockers'] = $this->blockers($summary);
        $summary['purge_allowed'] = $summary['blockers'] === [];

        return $summary;
    }

    /** @param array<int, int> $partIds @return array<string, int> */
    private function dependencyCounts(array $partIds): array
    {
        $counts = [];

        foreach ($this->partReferences() as $reference) {
            $counts[$reference['key']] = DB::table($reference['table'])
                ->whereIn($reference['column'], $partIds)
                ->count();
        }

        ksort($counts);

        return $counts;
    }

    /** @return array<int, array{table: string, column: string, key: string}> */
    private function partReferences(): array
    {
        $references = [];

        foreach ($this->databaseTables() as $table) {
            if (Schema::hasColumn($table, 'part_id')) {
                $references[$table.'.part_id'] = [
                    'table' => $table,
                    'column' => 'part_id',
                    'key' => $table,
                ];
            }
        }

        foreach ($this->foreignKeyReferences() as $table => $columns) {
            foreach ($columns as $column) {
                $key = $column === 'part_id' ? $table : $table.'.'.$column;
                $references[$table.'.'.$column] = [
                    'table' => $table,
                    'column' => $column,
                    'key' => $key,
                ];
            }
        }

        return array_values($references);
    }

    /** @return array<int, string> */
    private function databaseTables(): array
    {
        if (! $this->canDetectDependencies()) {
            return [];
        }

        return collect(Schema::getTables())
            ->map(fn (array $table) => $table['name'] ?? null)
            ->filter(fn ($table) => is_string($table) && ! in_array($table, self::PROTECTED_TABLES, true))
            ->values()
            ->all();
    }

    /** @return array<string, array<int, string>> */
    private function foreignKeyReferences(): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $database = DB::getDatabaseName();

            return collect(DB::select(
                'select table_name, column_name from information_schema.key_column_usage where table_schema = ? and referenced_table_name = ?',
                [$database, 'parts']
            ))->reject(fn ($row) => $row->table_name === 'parts' || in_array($row->table_name, self::PROTECTED_TABLES, true))
                ->groupBy('table_name')
                ->map(fn ($rows) => $rows->pluck('column_name')->values()->all())
                ->all();
        }

        if ($driver === 'sqlite') {
            $references = [];

            foreach ($this->databaseTables() as $table) {
                foreach (DB::select('PRAGMA foreign_key_list('.$table.')') as $foreignKey) {
                    if (($foreignKey->table ?? null) === 'parts') {
                        $references[$table][] = $foreignKey->from;
                    }
                }
            }

            return $references;
        }

        return [];
    }

    private function canDetectDependencies(): bool
    {
        return method_exists(Schema::getFacadeRoot(), 'getTables');
    }

    /** @param array<string, int> $dependencyCounts @return array<string, int> */
    private function blockedDependencyCounts(array $dependencyCounts): array
    {
        return array_filter(
            $dependencyCounts,
            fn (int $count, string $table) => $count > 0 && in_array($table, self::BLOCKED_DEPENDENCIES, true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /** @param array<string, int> $dependencyCounts @return array<int, array{table: string, column: string, key: string, count: int}> */
    private function deletableDependencies(array $dependencyCounts): array
    {
        return collect($this->partReferences())
            ->map(fn (array $reference) => $reference + ['count' => (int) ($dependencyCounts[$reference['key']] ?? 0)])
            ->filter(fn (array $reference) => $reference['count'] > 0 && ! in_array($reference['key'], self::BLOCKED_DEPENDENCIES, true))
            ->values()
            ->all();
    }

    /** @param array<int, int> $partIds @return array<int, array{id: int, part_id: int, path: string|null, shared_with_other_parts: bool, safe_to_delete_file: bool}> */
    private function partImageFiles(array $partIds): array
    {
        if (! Schema::hasTable('part_images')) {
            return [];
        }

        return PartImage::query()
            ->select(['id', 'part_id', 'path'])
            ->whereIn('part_id', $partIds)
            ->orderBy('id')
            ->get()
            ->map(function (PartImage $image) use ($partIds): array {
                $path = $this->clean($image->path);
                $shared = $path !== null && PartImage::query()
                    ->where('path', $path)
                    ->whereNotIn('part_id', $partIds)
                    ->exists();

                return [
                    'id' => $image->id,
                    'part_id' => $image->part_id,
                    'path' => $path,
                    'shared_with_other_parts' => $shared,
                    'safe_to_delete_file' => $path !== null && ! $shared && $this->isLocalImagePath($path),
                ];
            })
            ->all();
    }

    /** @param array<int, array{id: int, part_id: int, path: string|null, shared_with_other_parts: bool, safe_to_delete_file: bool}> $files @param array<int, int> $partIds @return array{0: int, 1: int} */
    private function deletePartImageFiles(array $files, array $partIds): array
    {
        $deleted = 0;
        $skippedShared = 0;
        $seen = [];

        foreach ($files as $file) {
            $path = $file['path'];

            if ($path === null || isset($seen[$path])) {
                continue;
            }

            $seen[$path] = true;

            $isSharedNow = PartImage::query()
                ->where('path', $path)
                ->whereNotIn('part_id', $partIds)
                ->exists();

            if ($file['shared_with_other_parts'] || $isSharedNow) {
                $skippedShared++;
                continue;
            }

            if ($file['safe_to_delete_file'] && $this->deleteLocalImageFile($path)) {
                $deleted++;
            }
        }

        return [$deleted, $skippedShared];
    }

    private function isLocalImagePath(string $path): bool
    {
        return ! Str::startsWith($path, ['http://', 'https://', '//']);
    }

    private function deleteLocalImageFile(string $path): bool
    {
        $path = ltrim($path, '/');

        if (Str::startsWith($path, 'storage/')) {
            $publicPath = Str::after($path, 'storage/');
            if (Storage::disk('public')->exists($publicPath)) {
                return Storage::disk('public')->delete($publicPath);
            }
        }

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->delete($path);
        }

        $absolutePublicPath = public_path($path);
        if (is_file($absolutePublicPath)) {
            return unlink($absolutePublicPath);
        }

        return false;
    }

    /** @param array<string, mixed> $plan @return array<int, string> */
    private function blockers(array $plan): array
    {
        $blockers = [];

        if (! $plan['safety_checks']['dependency_detection_complete']) {
            $blockers[] = 'Dependency detection could not be confirmed for this database driver.';
        }

        if ($plan['matched_count'] !== self::EXPECTED_MATCHED_COUNT) {
            $blockers[] = 'matched_count is '.$plan['matched_count'].', expected '.self::EXPECTED_MATCHED_COUNT.'.';
        }

        foreach (['secondary_signature_mismatches', 'with_primary_allegro_offer_id', 'with_both_primary_and_secondary_offer_ids', 'order_items', 'marketplace_listings'] as $check) {
            if (($plan['safety_checks'][$check] ?? 0) > 0) {
                $blockers[] = $check.' is '.$plan['safety_checks'][$check].'.';
            }
        }

        foreach ($plan['blocked_dependency_counts'] as $table => $count) {
            $blockers[] = 'blocked dependency records exist in '.$table.': '.$count.'.';
        }

        return array_values(array_unique($blockers));
    }

    /** @param array<string, mixed> $plan */
    private function assertSafeForLive(array $plan): void
    {
        if (! $plan['purge_allowed']) {
            throw new RuntimeException('Safety stop: purge is blocked: '.implode(' ', $plan['blockers']));
        }
    }

    /** @return array<string, mixed> */
    private function legacyPayloadJson(array $payload): array
    {
        $data = data_get($payload, 'legacy_payload_json');
        return is_array($data) ? $data : $payload;
    }

    private function clean(mixed $value): ?string
    {
        if (is_array($value) || is_object($value) || $value === null) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /** @param array<int, mixed> $samples */
    private function pushSample(array &$samples, mixed $value): void
    {
        if (count($samples) < self::MAX_SAMPLES) $samples[] = $value;
    }
}
