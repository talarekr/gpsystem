<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Services\Marketplace\AllegroOfferExtractor;
use App\Services\Marketplace\OvokoPartIdExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class MarketplaceMappingGapsExportController extends Controller
{
    private const HEADER = [
        'local_part_id', 'ovoko_id', 'allegro_id', 'part_number', 'product_name', 'status', 'availability',
        'needs_listing', 'is_visible_storefront', 'missing_ovoko_id', 'missing_allegro_id', 'mapping_sources', 'admin_url',
    ];

    public function __invoke(Request $request, OvokoPartIdExtractor $ovokoExtractor, AllegroOfferExtractor $allegroExtractor): JsonResponse
    {
        abort_unless(Schema::hasTable('parts'), 500, 'Required table parts is missing.');

        $limit = max(0, (int) $request->integer('limit', 0));
        $status = trim((string) $request->query('status', ''));
        $format = strtolower((string) $request->query('format', 'json'));
        $visibleOnly = $request->boolean('visible_only');

        $exportDisk = Storage::disk('public');
        $diskName = 'public';
        $exportDisk->makeDirectory('exports/tools');
        $relativePath = 'exports/tools/marketplace_mapping_gaps_'.now()->format('Ymd_His').'.csv';
        $absolutePath = $exportDisk->path($relativePath);
        $handle = $format === 'csv' ? fopen($absolutePath, 'wb') : false;
        if ($format === 'csv' && $handle === false) {
            throw new \RuntimeException('Cannot open export file for writing: '.$absolutePath);
        }
        if ($handle !== false) fputcsv($handle, self::HEADER);

        $summary = ['ok' => true, 'candidate_ready_count' => 0, 'visible_ready_count' => 0, 'visible_only' => $visibleOnly, 'scanned_count' => 0, 'rows_count' => 0, 'missing_ovoko_count' => 0, 'missing_allegro_count' => 0, 'missing_both_count' => 0, 'preview' => []];
        $columns = $this->partColumns();
        $processed = 0;

        $candidateQuery = DB::table('parts');
        $this->applyForSaleScope($candidateQuery, $status, false);
        $summary['candidate_ready_count'] = (int) $candidateQuery->count();

        $visibleQuery = DB::table('parts');
        $this->applyForSaleScope($visibleQuery, $status, true);
        $summary['visible_ready_count'] = (int) $visibleQuery->count();

        $query = DB::table('parts')->select(array_values($columns))->orderBy('id');
        $this->applyForSaleScope($query, $status, $visibleOnly);

        $query->chunkById(500, function ($parts) use (&$summary, &$processed, $limit, $handle, $ovokoExtractor, $allegroExtractor): bool {
            $ids = $parts->pluck('id')->map(fn ($id) => (int) $id)->all();
            $listings = $this->listingsByPart($ids);

            foreach ($parts as $part) {
                if ($limit > 0 && $processed >= $limit) return false;
                $processed++;
                $summary['scanned_count']++;

                try {
                    $mapping = $this->resolveMappings($part, $listings[(int) $part->id] ?? [], $ovokoExtractor, $allegroExtractor);
                    $missingOvoko = $mapping['ovoko_id'] === null;
                    $missingAllegro = $mapping['allegro_id'] === null;
                    if (! $missingOvoko && ! $missingAllegro) continue;

                    $row = $this->csvRow($part, $mapping, $missingOvoko, $missingAllegro);
                    if ($handle !== false) fputcsv($handle, $row);
                    if (count($summary['preview']) < 20) $summary['preview'][] = array_combine(self::HEADER, $row);
                    $summary['rows_count']++;
                    if ($missingOvoko) $summary['missing_ovoko_count']++;
                    if ($missingAllegro) $summary['missing_allegro_count']++;
                    if ($missingOvoko && $missingAllegro) $summary['missing_both_count']++;
                } catch (\Throwable $e) {
                    // Defensive per-row guard: a malformed legacy/raw payload must not abort the export.
                    report($e);
                }
            }

            return true;
        }, 'id');

        if ($handle !== false) {
            fclose($handle);
            $summary['disk_used'] = $diskName;
            $summary['file_relative_path'] = $relativePath;
            $summary['file_exists_on_disk'] = $exportDisk->exists($relativePath);
            $summary['public_path_checked'] = public_path('storage/'.$relativePath);
            $summary['download_url'] = $exportDisk->url($relativePath);
            $summary['csv_url'] = $summary['download_url'];
            $summary['file'] = $absolutePath;
        }
        $summary['test_limit_50_url'] = url('/admin/tools/marketplace/mapping-gaps-export?format=csv&limit=50');
        $summary['full_export_csv_url'] = url('/admin/tools/marketplace/mapping-gaps-export?format=csv');
        $summary['full_export_visible_only_csv_url'] = url('/admin/tools/marketplace/mapping-gaps-export?format=csv&visible_only=1');

        return response()->json($summary);
    }

    private function applyForSaleScope($query, string $status, bool $visibleOnly = false): void
    {
        if ($status !== '' && $status !== 'all') $query->where('status', $status);
        else $query->whereIn('status', ['ready', 'published']);
        if (Schema::hasColumn('parts', 'quantity')) $query->where('quantity', '>', 0);
        if ($visibleOnly && Schema::hasColumn('parts', 'is_visible_storefront')) $query->where('is_visible_storefront', true);
    }

    private function partColumns(): array
    {
        $wanted = ['id', 'part_number', 'name', 'status', 'needs_listing', 'is_visible_storefront', 'quantity', 'source_system', 'external_id', 'legacy_payload'];
        return array_values(array_filter($wanted, fn ($c) => Schema::hasColumn('parts', $c)));
    }

    private function listingsByPart(array $partIds): array
    {
        if ($partIds === [] || ! Schema::hasTable('marketplace_listings')) return [];
        $cols = array_values(array_filter(['part_id', 'marketplace', 'external_offer_id', 'external_listing_id', 'external_inventory_id', 'raw_payload'], fn ($c) => Schema::hasColumn('marketplace_listings', $c)));
        return DB::table('marketplace_listings')->select($cols)->whereIn('part_id', $partIds)->whereIn('marketplace', ['ovoko', 'allegro'])->get()->groupBy('part_id')->all();
    }

    private function resolveMappings(object $part, iterable $listings, OvokoPartIdExtractor $ovokoExtractor, AllegroOfferExtractor $allegroExtractor): array
    {
        $result = ['ovoko_id' => null, 'allegro_id' => null, 'sources' => []];
        foreach ($listings as $listing) {
            $marketplace = (string) ($listing->marketplace ?? '');
            foreach (['external_offer_id', 'external_listing_id', 'external_inventory_id'] as $field) {
                $value = $this->clean($listing->$field ?? null);
                if ($value !== null && in_array($marketplace, ['ovoko', 'allegro'], true) && $result[$marketplace.'_id'] === null) {
                    $result[$marketplace.'_id'] = $value; $result['sources'][] = "marketplace_listings.$field";
                }
            }
            $raw = $this->payloadArray($listing->raw_payload ?? null);
            if ($marketplace === 'ovoko' && $result['ovoko_id'] === null) { $m = $ovokoExtractor->extractWithPath($raw ?: ($listing->raw_payload ?? null)); if ($m['id']) { $result['ovoko_id'] = $m['id']; $result['sources'][] = 'marketplace_listings.raw_payload.'.$m['path']; } }
            if ($marketplace === 'allegro' && $result['allegro_id'] === null) { $id = $this->extractAllegroId($raw ?: ($listing->raw_payload ?? null), $allegroExtractor); if ($id['id']) { $result['allegro_id'] = $id['id']; $result['sources'][] = 'marketplace_listings.raw_payload.'.$id['path']; } }
        }
        if ($result['ovoko_id'] === null && in_array(strtolower((string) ($part->source_system ?? '')), ['ovoko', 'rrr'], true) && $this->clean($part->external_id ?? null)) { $result['ovoko_id'] = $this->clean($part->external_id); $result['sources'][] = 'parts.source_system/external_id'; }
        if ($result['ovoko_id'] === null) { $m = $ovokoExtractor->extractWithPath($part->legacy_payload ?? null); if ($m['id']) { $result['ovoko_id'] = $m['id']; $result['sources'][] = 'parts.legacy_payload.'.$m['path']; } }
        if ($result['allegro_id'] === null) { $id = $this->extractAllegroId($part->legacy_payload ?? null, $allegroExtractor); if ($id['id']) { $result['allegro_id'] = $id['id']; $result['sources'][] = 'parts.legacy_payload.'.$id['path']; } }
        $result['sources'] = array_values(array_unique($result['sources']));
        return $result;
    }

    private function extractAllegroId(mixed $payload, AllegroOfferExtractor $extractor): array
    {
        $listings = $extractor->extract($payload); if (($listings[0]['offer_id'] ?? null)) return ['id' => (string) $listings[0]['offer_id'], 'path' => 'legacy_payload_json._allegro_offer_id'];
        $text = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (['_allegro_offer_id', 'allegro_offer_id', 'offer_id'] as $key) if (is_string($text) && preg_match('/["\']'.preg_quote($key, '/').'["\']\s*[:=]\s*["\']?([A-Za-z0-9_-]+)/i', $text, $m)) return ['id' => $m[1], 'path' => 'regex.'.$key];
        return ['id' => null, 'path' => null];
    }

    private function csvRow(object $part, array $mapping, bool $missingOvoko, bool $missingAllegro): array
    {
        $partModel = new Part((array) $part);
        return [(int) $part->id, $mapping['ovoko_id'], $mapping['allegro_id'], $part->part_number ?? null, $part->name ?? null, $part->status ?? null, $partModel->adminLocalAvailability(), (bool) ($part->needs_listing ?? false) ? 'true' : 'false', (bool) ($part->is_visible_storefront ?? false) ? 'true' : 'false', $missingOvoko ? 'true' : 'false', $missingAllegro ? 'true' : 'false', implode('|', $mapping['sources']), url('/admin/parts/'.(int) $part->id)];
    }

    private function payloadArray(mixed $payload): array { if (is_array($payload)) return $payload; if (is_string($payload) && $payload !== '') { $d = json_decode($payload, true); return is_array($d) ? $d : []; } return []; }
    private function clean(mixed $value): ?string { if (! is_scalar($value)) return null; $value = trim((string) $value); return $value === '' ? null : $value; }
}
