<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SetOvokoCategoryMappingsBatchController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';

    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $confirm = (string) $request->query('confirm', '0') === '1';
        $replace = (string) $request->query('replace', '0') === '1';
        $pairs = $this->parseMappings((string) $request->query('mappings', ''));

        if ($pairs === []) {
            return response()->json($this->flags(false, false) + $this->emptyCounts() + [
                'ok' => false,
                'dry_run' => ! $confirm,
                'error_message' => 'Missing or empty mappings parameter.',
                'requested_count' => 0,
                'items' => [],
            ], 422);
        }

        $items = [];
        $seenLocalIds = [];

        foreach ($pairs as $pair) {
            $item = [
                'local_category_id' => $pair['local_category_id'],
                'local_category_name' => null,
                'ovoko_category_id' => $pair['ovoko_category_id'],
                'ovoko_category_name' => null,
                'ovoko_category_path' => null,
                'action' => null,
                'error' => null,
            ];

            if (isset($seenLocalIds[$pair['local_category_id']])) {
                $items[] = array_merge($item, ['action' => 'error', 'error' => 'duplicate_local_category_id']);
                continue;
            }
            $seenLocalIds[$pair['local_category_id']] = true;

            $local = $this->localCategory($pair['local_category_id']);
            if (! $local) {
                $items[] = array_merge($item, ['action' => 'error', 'error' => 'local_category_id_not_found']);
                continue;
            }

            $ovoko = $this->ovokoCategory($pair['ovoko_category_id']);
            $item['local_category_name'] = (string) ($local->name ?? '');
            if (! $ovoko) {
                $items[] = array_merge($item, ['action' => 'error', 'error' => 'ovoko_category_id_not_found']);
                continue;
            }

            $item['ovoko_category_name'] = (string) ($ovoko->name ?? '');
            $item['ovoko_category_path'] = (string) ($ovoko->full_path ?? $ovoko->name ?? '');

            $existing = $this->existingMapping($pair['local_category_id']);
            if ($existing && ! $replace) {
                $items[] = array_merge($item, ['action' => 'skipped_existing', 'error' => null]);
                continue;
            }

            if (! $confirm) {
                $items[] = array_merge($item, ['action' => $existing ? 'would_update' : 'would_create', 'error' => null]);
                continue;
            }

            // Re-check immediately before each local write to avoid duplicate Ovoko mappings.
            $existing = $this->existingMapping($pair['local_category_id']);
            if ($existing && ! $replace) {
                $items[] = array_merge($item, ['action' => 'skipped_existing', 'error' => null]);
                continue;
            }

            $this->saveMapping($local, $ovoko, $existing);
            $items[] = array_merge($item, ['action' => $existing ? 'updated' : 'created', 'error' => null]);
        }

        $counts = $this->counts($items);

        return response()->json($this->flags($confirm, ($counts['created_count'] + $counts['updated_count']) > 0) + $counts + [
            'ok' => true,
            'dry_run' => ! $confirm,
            'requested_count' => count($pairs),
            'items' => $items,
        ]);
    }

    private function parseMappings(string $raw): array
    {
        $pairs = [];
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $part) {
            if (! str_contains($part, ':')) {
                continue;
            }
            [$localId, $ovokoId] = array_map('trim', explode(':', $part, 2));
            if ($localId === '' || $ovokoId === '') {
                continue;
            }
            $pairs[] = ['local_category_id' => (int) $localId, 'ovoko_category_id' => (string) $ovokoId];
        }
        return $pairs;
    }

    private function localCategory(int $id): ?object
    {
        if (! Schema::hasTable('part_categories')) return null;
        return DB::table('part_categories')->where('id', $id)->first();
    }

    private function ovokoCategory(string $id): ?object
    {
        if (! Schema::hasTable('marketplace_categories')) return null;
        return DB::table('marketplace_categories')
            ->where('channel', 'ovoko')
            ->where('external_category_id', $id)
            ->first();
    }

    private function existingMapping(int $localCategoryId): ?object
    {
        if (! Schema::hasTable('marketplace_category_mappings')) return null;
        return DB::table('marketplace_category_mappings')->where('local_category_id', $localCategoryId)->where('channel', 'ovoko')->first();
    }

    private function saveMapping(object $local, object $ovoko, ?object $existing): void
    {
        $now = now();
        $row = [
            'local_category_id' => (int) $local->id,
            'channel' => 'ovoko',
            'external_category_id' => (string) $ovoko->external_category_id,
            'external_category_name' => (string) $ovoko->name,
            'external_category_path' => (string) ($ovoko->full_path ?? $ovoko->name),
            'local_category_name' => (string) ($local->name ?? ''),
            'local_category_path' => (string) ($local->category_path ?? ($local->full_slug_path ?? ($local->name ?? ''))),
            'source' => 'manual_review_batch',
            'confidence' => 'high',
            'metadata' => json_encode(['local_category_name' => (string) ($local->name ?? ''), 'category_path' => (string) ($local->category_path ?? ($local->full_slug_path ?? ($local->name ?? ''))), 'manual' => true, 'batch' => true]),
            'imported_at' => $now,
            'updated_at' => $now,
        ];

        $row = array_filter($row, fn ($value, $column) => Schema::hasColumn('marketplace_category_mappings', $column), ARRAY_FILTER_USE_BOTH);

        if ($existing) {
            DB::table('marketplace_category_mappings')->where('id', $existing->id)->update($row);
            return;
        }

        $row['created_at'] = $now;
        DB::table('marketplace_category_mappings')->insert($row);
    }

    private function counts(array $items): array
    {
        $counts = $this->emptyCounts();
        foreach ($items as $item) {
            $action = $item['action'] ?? '';
            if ($action === 'created') $counts['created_count']++;
            if ($action === 'updated') $counts['updated_count']++;
            if ($action === 'skipped_existing') $counts['skipped_existing_count']++;
            if ($action === 'error') $counts['errors_count']++;
        }
        return $counts;
    }

    private function emptyCounts(): array
    {
        return ['created_count' => 0, 'updated_count' => 0, 'skipped_existing_count' => 0, 'errors_count' => 0];
    }

    private function flags(bool $confirmedWrite, bool $changed): array
    {
        return ['read_only' => ! $confirmedWrite, 'local_update' => $confirmedWrite, 'ovoko_write' => false, 'allegro_write' => false, 'ebay_write' => false, 'products_changed' => false, 'offers_changed' => false, 'mappings_changed' => $confirmedWrite && $changed];
    }
}
