<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class OvokoImportProductDataController extends Controller
{
    private const DEFAULT_IDS = [11691,11690,11689,11688,11687,11686,11685,11684,11683,11682,11681,11680,11679,11678,11677,11675,11674,11673,11672,11671,11670,11669,11668,11664,11656,11653,11649,11647,11645,11644,11640,11639,11638,11636,11635,11633,11631,11630,11629,11628,11627,11626,11625,11624,11623,11621,11619,11618,11617,11613,11612,11609,11572,11552,11543,11538,11502,11485,11196,11191,11068,11061,11060,11059,11058,11057];
    private const SKIPPED_FIELDS = ['photos', 'quality', 'steering_side', 'storage_location'];

    public function __invoke(Request $request): JsonResponse
    {
        $ids = $this->requestedIds($request);
        $apply = $request->boolean('apply');
        $dryRun = ! $apply || $request->boolean('dry_run');
        $listings = $this->mappedListings($ids);
        $items = [];
        $fetched = $updated = $skipped = $failed = 0;

        foreach ($ids as $id) {
            $listing = $listings[$id] ?? null;
            $part = $listing?->part;
            $item = ['ovoko_product_id' => $id, 'local_part_id' => $part?->id, 'status' => 'skipped', 'changed_fields' => [], 'old_values' => [], 'new_values' => [], 'skipped_fields' => self::SKIPPED_FIELDS, 'errors' => []];

            if (! $listing || ! $part) {
                $item['status'] = 'failed';
                $item['errors'][] = 'missing_confirmed_marketplace_listing_mapping';
                $failed++; $items[] = $item; continue;
            }

            $remote = $this->fetchOvokoPart($id);
            if (! ($remote['ok'] ?? false)) {
                $item['status'] = 'failed';
                $item['errors'][] = $remote['error'] ?? $remote['blocker'] ?? 'ovoko_fetch_failed';
                $item['ovoko_diagnostics'] = Arr::only($remote, ['http_status', 'ovoko_status_code', 'ovoko_message', 'response_excerpt', 'response_shape']);
                $failed++; $items[] = $item; continue;
            }
            $fetched++;

            $changes = $this->plannedChanges($part, $remote['part'], ! $dryRun);
            $item['changed_fields'] = array_keys($changes['new']);
            $item['old_values'] = $changes['old'];
            $item['new_values'] = $changes['new'];

            if ($changes['new'] === []) {
                $item['status'] = 'no_change';
                $skipped++; $items[] = $item; continue;
            }

            if ($dryRun) {
                $item['status'] = 'would_update';
                $skipped++; $items[] = $item; continue;
            }

            try {
                DB::transaction(function () use ($part, $changes): void {
                    $part->forceFill($changes['new'])->save();
                });
                $item['status'] = 'updated';
                $updated++;
            } catch (Throwable $e) {
                $item['status'] = 'failed';
                $item['errors'][] = 'local_update_failed: '.$e->getMessage();
                $failed++;
            }
            $items[] = $item;
        }

        $payload = ['requested_count' => count($ids), 'mapped_count' => count($listings), 'fetched_count' => $fetched, 'updated_count' => $updated, 'skipped_count' => $skipped, 'failed_count' => $failed, 'dry_run' => $dryRun, 'local_update' => ! $dryRun, 'marketplace_write' => false, 'items' => $items];
        $this->writeLog($payload, $failed > 0 ? 'warning' : 'success');

        return response()->json($payload);
    }

    private function requestedIds(Request $request): array
    {
        $raw = (string) $request->query('ids', '');
        $ids = $raw === '' ? self::DEFAULT_IDS : preg_split('/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        return collect($ids)->map(fn ($id) => (string) $id)->filter()->unique()->values()->all();
    }

    private function mappedListings(array $ids): array
    {
        $rows = MarketplaceListing::query()->with('part')->where('marketplace', 'ovoko')->where(function ($q) use ($ids): void {
            $q->whereIn('external_offer_id', $ids)->orWhereIn('external_listing_id', $ids);
        })->get();
        $out = [];
        foreach ($rows as $row) foreach (['external_offer_id','external_listing_id'] as $field) if (in_array((string) $row->{$field}, $ids, true) && ! isset($out[(string) $row->{$field}])) $out[(string) $row->{$field}] = $row;
        return $out;
    }

    private function fetchOvokoPart(string $id): array
    {
        $account = MarketplaceAccount::query()->where('code', 'ovoko_main')->first();
        if (! $account || ! $account->api_enabled || blank($account->api_base_url)) return ['ok' => false, 'blocker' => 'ovoko_api_not_configured'];
        $credentials = is_array($account->api_credentials) ? Arr::only($account->api_credentials, ['username', 'password', 'user_token']) : [];
        if (count(array_filter($credentials, fn ($v) => filled($v))) < 3) return ['ok' => false, 'blocker' => 'ovoko_api_credentials_missing'];

        $response = Http::asForm()->acceptJson()->timeout(30)->post(rtrim((string) $account->api_base_url, '/').'/get/part/'.rawurlencode($id), $credentials);
        $payload = $response->json();
        $rows = $this->extractRows(is_array($payload) ? $payload : []);
        $matches = array_values(array_filter($rows, fn (array $row): bool => (string) $this->first($row, ['id','part_id','ovoko_id','rrr_id']) === $id));
        $apiOk = $this->ovokoResponseOk($response->status(), is_array($payload) ? $payload : null);
        $part = $matches[0] ?? ($apiOk && count($rows) === 1 && $this->looksLikeProduct($rows[0]) ? $rows[0] : null);

        return $apiOk && $part !== null
            ? ['ok' => true, 'part' => $part]
            : [
                'ok' => false,
                'error' => $response->successful() ? 'missing_ovoko_product' : 'ovoko_api_failed',
                'http_status' => $response->status(),
                'ovoko_status_code' => is_array($payload) ? data_get($payload, 'status_code') : null,
                'ovoko_message' => is_array($payload) ? $this->text($this->first($payload, ['msg','message','error'])) : null,
                'response_excerpt' => Str::limit($response->body(), 500),
                'response_shape' => is_array($payload) ? $this->responseShape($payload) : gettype($payload),
            ];
    }

    private function extractRows(array $payload): array
    {
        foreach (['data.item','data.part','data.parts','data.items','data.result','data.list','data','item','part','parts','items','result','list'] as $key) {
            $value = $payload[$key] ?? null;
            if (str_contains($key, '.')) $value = data_get($payload, $key);
            if (is_array($value) && Arr::isAssoc($value)) return [$value];
            if (is_array($value)) return array_values(array_filter($value, 'is_array'));
        }
        return Arr::isAssoc($payload) ? [$payload] : array_values(array_filter($payload, 'is_array'));
    }

    private function ovokoResponseOk(int $httpStatus, ?array $payload): bool
    {
        if ($httpStatus < 200 || $httpStatus >= 300) return false;
        $statusCode = $payload ? (string) data_get($payload, 'status_code', '') : '';
        return $statusCode === '' || in_array(strtoupper($statusCode), ['R200', '200', 'OK', 'SUCCESS'], true);
    }

    private function looksLikeProduct(array $row): bool
    {
        return Arr::except($row, ['status_code', 'msg', 'message', 'error', 'errors']) !== [];
    }

    private function plannedChanges(Part $part, array $ovoko, bool $allowCreateCategory): array
    {
        $review = is_array($part->review_metadata) ? $part->review_metadata : [];
        $legacy = is_array($part->legacy_payload) ? $part->legacy_payload : [];
        $newLegacy = array_replace_recursive($legacy, ['ovoko_import_product_data' => ['part_position' => $this->text($this->first($ovoko, ['part_position','position','side','place'])), 'raw_selected_fields' => Arr::only($ovoko, ['id','part_id','manufacturer_code','optional_codes','category_id','category_name','category','position','car_id','notes','description','name','weight','length','width','height','price'])]]);
        $candidate = [
            'part_number' => $this->text($this->first($ovoko, ['manufacturer_code','code','part_code','main_code','oem_code'])),
            'category_id' => $this->categoryId($ovoko, $allowCreateCategory),
            'car_id' => $this->int($this->first($ovoko, ['car_id','vehicle_id'])),
            'name' => $this->text($this->first($ovoko, ['description','desc','content','name','title'])),
            'weight_kg' => $this->decimal($this->first($ovoko, ['weight_kg','weight'])),
            'length_cm' => $this->decimal($this->first($ovoko, ['length_cm','length'])),
            'width_cm' => $this->decimal($this->first($ovoko, ['width_cm','width'])),
            'height_cm' => $this->decimal($this->first($ovoko, ['height_cm','height'])),
            'ovoko_price' => $this->decimal($this->first($ovoko, ['price','sell_price'])),
            'legacy_payload' => $newLegacy,
        ];
        if ((bool) $part->needs_listing) $candidate['needs_listing'] = false;
        if ($part->status === 'draft') $candidate['status'] = 'ready';
        $notePrice = $this->decimal($this->first($ovoko, ['notes','internal_notes','internal_note']));
        if ($notePrice !== null) $candidate['price'] = $candidate['allegro_price'] = $notePrice;
        if ($pos = $this->text($this->first($ovoko, ['part_position','position','side','place']))) $candidate['review_metadata'] = array_replace($review, ['part_position' => $pos]);
        return $this->diff($part, array_filter($candidate, fn ($v) => $v !== null));
    }

    private function categoryId(array $ovoko, bool $allowCreate): ?int
    {
        $external = $this->text($this->first($ovoko, ['category_id','category.id']));
        $name = $this->text($this->first($ovoko, ['category_name','category.name','category']));
        if ($external) $cat = PartCategory::query()->where('external_id', $external)->whereIn('source_system', ['ovoko','rrr','ovoko_old'])->first();
        if (isset($cat)) return $cat->id;
        if ($name && $allowCreate) return PartCategory::query()->firstOrCreate(['source_system' => 'ovoko', 'external_id' => $external ?: 'ovoko-name-'.md5($name)], ['name' => $name, 'slug' => Str::slug($name), 'category_path' => $name])->id;
        return null;
    }

    private function diff(Part $part, array $candidate): array
    {
        $old = $new = [];
        foreach ($candidate as $field => $value) if ($part->getAttribute($field) != $value) { $old[$field] = $part->getAttribute($field); $new[$field] = $value; }
        return ['old' => $old, 'new' => $new];
    }

    private function first(array $data, array $keys): mixed { foreach ($keys as $key) if (($v = data_get($data, $key)) !== null && $v !== '') return $v; return null; }
    private function text(mixed $v): ?string { return filled($v) ? trim((string) $v) : null; }
    private function int(mixed $v): ?int { return is_numeric($v) ? (int) $v : null; }
    private function decimal(mixed $v): ?float { if (! filled($v)) return null; preg_match('/-?\d+(?:[,.]\d+)?/', (string) $v, $m); return isset($m[0]) ? round((float) str_replace(',', '.', $m[0]), 2) : null; }
    private function responseShape(array $payload): array { return collect($payload)->map(fn ($v) => is_array($v) ? (Arr::isAssoc($v) ? 'object:'.implode(',', array_slice(array_keys($v), 0, 8)) : 'list:'.count($v)) : gettype($v))->all(); }

    private function writeLog(array $payload, string $status): void
    {
        if (! Schema::hasTable('marketplace_sync_logs')) return;
        MarketplaceSyncLog::query()->create(['marketplace' => 'ovoko', 'action' => 'ovoko_import_product_data', 'status' => $status, 'message' => 'Local-only Ovoko product data import; marketplace_write=false.', 'payload' => $payload, 'created_at' => now()]);
    }
}
