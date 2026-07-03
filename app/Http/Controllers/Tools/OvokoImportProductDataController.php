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
        $fetched = $updated = $skipped = $failed = $wouldUpdate = 0;
        $productsWithPrice = $productsWithCategory = $productsWithCar = $productsWithDimensions = 0;

        foreach ($ids as $id) {
            $listing = $listings[$id] ?? null;
            $part = $listing?->part;
            $item = ['ovoko_product_id' => $id, 'local_part_id' => $part?->id, 'status' => 'skipped', 'changes' => [], 'skipped_fields' => self::SKIPPED_FIELDS, 'errors' => []];

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
            $item['changes'] = $this->readableChanges($part, $changes);
            if ($this->debugRequested($request, $id)) {
                $item['ovoko_debug'] = $this->debugPayload($remote['part'], $changes);
            }
            if ($this->hasReadableValue($part, $changes, ['ovoko_price'])) $productsWithPrice++;
            if ($this->hasReadableValue($part, $changes, ['category_id'])) $productsWithCategory++;
            if ($this->hasReadableValue($part, $changes, ['car_id'])) $productsWithCar++;
            if ($this->hasReadableValue($part, $changes, ['length_cm', 'width_cm', 'height_cm'])) $productsWithDimensions++;

            if ($changes['new'] === []) {
                $item['status'] = 'no_changes';
                $skipped++; $items[] = $item; continue;
            }

            if ($dryRun) {
                $item['status'] = $this->dryRunItemStatus($changes['new']);
                $item['change_groups'] = $this->changeGroups($changes['new']);
                $wouldUpdate++;
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

        $payload = ['requested_count' => count($ids), 'mapped_count' => count($listings), 'fetched_count' => $fetched, 'would_update_count' => $wouldUpdate, 'updated_count' => $updated, 'skipped_count' => $skipped, 'failed_count' => $failed, 'products_with_price_count' => $productsWithPrice, 'products_missing_price_count' => max(0, $fetched - $productsWithPrice), 'products_with_category_count' => $productsWithCategory, 'products_with_car_count' => $productsWithCar, 'products_with_dimensions_count' => $productsWithDimensions, 'dry_run' => $dryRun, 'local_update' => ! $dryRun, 'marketplace_write' => false, 'items' => $items];
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
        $matches = array_values(array_filter($rows, fn (array $row): bool => (string) $this->first($row, ['id','part_id','ovoko_id','ovoko_part_id','rrr_id','external_id']) === $id));
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
        foreach (['data.item','data.part','data.parts','data.items','data.result','data.list','item','part','parts','items','result','list','data'] as $key) {
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
        $mainCode = $this->text($this->first($ovoko, ['manufacturer_code','manufacturerCode','main_part_code','mainPartCode','code','part_code','partCode','main_code','mainCode','oem_code','oemCode','visible_code','sku']));
        $partPosition = $this->text($this->first($ovoko, ['part_position','partPosition','position','side','place','location']));
        $shopPrice = $this->priceFromNotes($ovoko, ['shop_price','shopPrice','store_price','storePrice'], ['shop', 'sklep']);
        $allegroPrice = $this->priceFromNotes($ovoko, ['allegro_price','allegroPrice'], ['allegro']);
        $ovokoPrice = $this->decimal($this->first($ovoko, ['price','price.amount','sell_price','sellPrice','sell_price.amount','sell_price.seller.amount','seller_price','sellerPrice','ovoko_price','ovokoPrice']));
        $title = $this->text($this->first($ovoko, ['description','desc','content','body','text','name','title','part_name','partName']));
        $newLegacy = array_replace_recursive($legacy, ['ovoko_import_product_data' => ['part_position' => $partPosition, 'raw_selected_fields' => $this->selectedFields($ovoko)]]);
        $candidate = [
            'part_number' => $mainCode,
            'category_id' => $this->categoryId($ovoko, $allowCreateCategory),
            'car_id' => $this->int($this->first($ovoko, ['car_id','carId','vehicle_id','vehicleId','car.id','vehicle.id'])),
            'name' => $title,
            'weight_kg' => $this->decimal($this->first($ovoko, ['weight_kg','weightKg','weight','package.weight'])),
            'length_cm' => $this->decimal($this->first($ovoko, ['length_cm','lengthCm','length','package.length'])),
            'width_cm' => $this->decimal($this->first($ovoko, ['width_cm','widthCm','width','package.width'])),
            'height_cm' => $this->decimal($this->first($ovoko, ['height_cm','heightCm','height','package.height'])),
            'ovoko_price' => $ovokoPrice,
            'legacy_payload' => $newLegacy,
        ];
        $candidateAll = $candidate;
        $candidateAll['price'] = $shopPrice;
        $candidateAll['allegro_price'] = $allegroPrice;
        $candidateAll['review_metadata'] = $partPosition !== null
            ? array_replace($review, ['part_position' => $partPosition])
            : null;
        if ((bool) $part->needs_listing) $candidate['needs_listing'] = false;
        if ($part->status === 'draft') $candidate['status'] = 'ready';
        if ($shopPrice !== null) $candidate['price'] = $shopPrice;
        if ($allegroPrice !== null) $candidate['allegro_price'] = $allegroPrice;
        if (is_array($candidateAll['review_metadata'])) $candidate['review_metadata'] = $candidateAll['review_metadata'];
        $candidate = array_filter($candidate, fn ($v) => $v !== null);
        $diff = $this->diff($part, $candidate);
        if (array_keys($diff['new']) === ['legacy_payload']) {
            $diff = ['old' => [], 'new' => []];
        }

        return $diff + ['candidate' => $candidate, 'candidate_all' => $candidateAll, 'ovoko_readable' => $this->ovokoReadableValues($ovoko, $candidateAll)];
    }

    private function readableChanges(Part $part, array $changes): array
    {
        $fields = [
            'part_number' => ['field' => 'main_part_code', 'label' => 'Główny kod części'],
            'category_id' => ['field' => 'category', 'label' => 'Kategoria'],
            'review_metadata' => ['field' => 'part_position', 'label' => 'Pozycja części'],
            'car_id' => ['field' => 'car_id', 'label' => 'Przypisany samochód'],
            'price' => ['field' => 'shop_price', 'label' => 'Cena sklep'],
            'allegro_price' => ['field' => 'allegro_price', 'label' => 'Cena Allegro'],
            'ovoko_price' => ['field' => 'ovoko_price', 'label' => 'Cena Ovoko'],
            'name' => ['field' => 'title', 'label' => 'Tytuł produktu'],
            'weight_kg' => ['field' => 'weight_kg', 'label' => 'Waga kg'],
            'length_cm' => ['field' => 'length_cm', 'label' => 'Długość'],
            'width_cm' => ['field' => 'width_cm', 'label' => 'Szerokość'],
            'height_cm' => ['field' => 'height_cm', 'label' => 'Wysokość'],
            'needs_listing' => ['field' => 'needs_listing', 'label' => 'Do wystawienia'],
            'status' => ['field' => 'status', 'label' => 'Status'],
        ];

        $candidateAll = $changes['candidate_all'] ?? [];
        $ovokoReadable = $changes['ovoko_readable'] ?? [];
        $newValues = $changes['new'] ?? [];
        $rows = [];
        foreach ($fields as $modelField => $meta) {
            $hasOvokoValue = (array_key_exists($modelField, $candidateAll) && $candidateAll[$modelField] !== null)
                || (array_key_exists($modelField, $ovokoReadable) && $ovokoReadable[$modelField] !== null && $ovokoReadable[$modelField] !== '');
            $hasPlannedValue = array_key_exists($modelField, $newValues);
            $newValue = $hasPlannedValue ? $newValues[$modelField] : ($candidateAll[$modelField] ?? null);
            $displayValue = array_key_exists($modelField, $ovokoReadable) ? $ovokoReadable[$modelField] : $this->readableValue($part, $modelField, $newValue);
            $row = [
                'field' => $meta['field'],
                'label' => $meta['label'],
                'old_value' => $this->readableValue($part, $modelField, $part->getAttribute($modelField)),
                'new_value' => $displayValue,
                'will_update' => $hasPlannedValue,
            ];
            if (! $hasOvokoValue && ! in_array($modelField, ['needs_listing', 'status'], true)) {
                $row['new_value'] = null;
                $row['will_update'] = false;
                $row['reason'] = 'missing_from_ovoko';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function readableValue(Part $part, string $field, mixed $value): mixed
    {
        if ($field === 'category_id') return $this->categoryName($value);
        if ($field === 'review_metadata') return is_array($value) ? ($value['part_position'] ?? null) : data_get($part->review_metadata, 'part_position');
        if (in_array($field, ['price', 'allegro_price', 'ovoko_price', 'weight_kg', 'length_cm', 'width_cm', 'height_cm'], true)) return $value === null || $value === '' ? '' : number_format((float) $value, 2, '.', '');
        if ($field === 'needs_listing') return (bool) $value;

        return $value ?? '';
    }


    private function dryRunItemStatus(array $newValues): string
    {
        $fields = array_keys($newValues);
        $listingOnly = $fields !== [] && collect($fields)->every(fn (string $field): bool => in_array($field, ['needs_listing', 'status'], true));

        return $listingOnly ? 'would_update_listing_state_only' : 'would_update';
    }

    private function changeGroups(array $newValues): array
    {
        $groups = [];
        if (array_intersect(array_keys($newValues), ['needs_listing', 'status'])) $groups[] = 'listing_state';
        if (array_intersect(array_keys($newValues), ['price', 'allegro_price', 'ovoko_price'])) $groups[] = 'prices';
        if (array_key_exists('category_id', $newValues)) $groups[] = 'category';
        if (array_key_exists('car_id', $newValues)) $groups[] = 'car';
        if (array_intersect(array_keys($newValues), ['weight_kg', 'length_cm', 'width_cm', 'height_cm'])) $groups[] = 'dimensions';
        if (array_intersect(array_keys($newValues), ['part_number', 'name', 'review_metadata'])) $groups[] = 'descriptive_fields';

        return $groups;
    }

    private function categoryName(mixed $id): string
    {
        if (! $id) return '';

        return (string) (PartCategory::query()->whereKey($id)->value('name') ?? $id);
    }

    private function hasReadableValue(Part $part, array $changes, array $fields): bool
    {
        $candidate = $changes['candidate'] ?? [];
        $candidateAll = $changes['candidate_all'] ?? [];
        $readable = $changes['ovoko_readable'] ?? [];
        foreach ($fields as $field) {
            $value = $candidate[$field] ?? $candidateAll[$field] ?? $readable[$field] ?? null;
            if ($value !== null && $value !== '') return true;
        }

        return false;
    }

    private function categoryId(array $ovoko, bool $allowCreate): ?int
    {
        $external = $this->text($this->first($ovoko, ['category_id','categoryId','part_category_id','partCategoryId','category.id','category.category_id','category.categoryId']));
        $name = $this->text($this->first($ovoko, ['category_name','categoryName','category_title','categoryTitle','category.name','category.category_name','category.pl','category.en','category']));
        if ($external) $cat = PartCategory::query()->where('external_id', $external)->whereIn('source_system', ['ovoko','rrr','ovoko_old'])->first();
        if (isset($cat)) return $cat->id;
        if ($name && $allowCreate) return PartCategory::query()->firstOrCreate(['source_system' => 'ovoko', 'external_id' => $external ?: 'ovoko-name-'.md5($name)], ['name' => $name, 'slug' => Str::slug($name), 'category_path' => $name])->id;
        return null;
    }

    private function ovokoReadableValues(array $ovoko, array $candidateAll): array
    {
        $category = $this->text($this->first($ovoko, ['category_name','categoryName','category_title','categoryTitle','category_path','categoryPath','category.name','category.category_name','category.pl','category.en','category']))
            ?? $this->text($this->first($ovoko, ['category_id','categoryId','part_category_id','partCategoryId','category.id']));

        return array_filter([
            'part_number' => $candidateAll['part_number'] ?? null,
            'category_id' => $category,
            'review_metadata' => is_array($candidateAll['review_metadata'] ?? null) ? ($candidateAll['review_metadata']['part_position'] ?? null) : null,
            'car_id' => $candidateAll['car_id'] ?? null,
            'price' => $this->readableNumber($candidateAll['price'] ?? null),
            'allegro_price' => $this->readableNumber($candidateAll['allegro_price'] ?? null),
            'ovoko_price' => $this->readableNumber($candidateAll['ovoko_price'] ?? null),
            'name' => $candidateAll['name'] ?? null,
            'weight_kg' => $this->readableNumber($candidateAll['weight_kg'] ?? null),
            'length_cm' => $this->readableNumber($candidateAll['length_cm'] ?? null),
            'width_cm' => $this->readableNumber($candidateAll['width_cm'] ?? null),
            'height_cm' => $this->readableNumber($candidateAll['height_cm'] ?? null),
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function priceFromNotes(array $ovoko, array $directKeys, array $labels): ?float
    {
        $direct = $this->decimal($this->first($ovoko, $directKeys));
        if ($direct !== null) return $direct;
        $notes = $this->text($this->first($ovoko, ['notes','internal_notes','internalNotes','internal_note','internalNote','comment','comments']));
        if ($notes === null) return null;
        foreach ($labels as $label) {
            if (preg_match('/'.preg_quote($label, '/').'.{0,30}?(\d+(?:[,.]\d{1,2})?)/iu', $notes, $m) === 1) {
                return $this->decimal($m[1]);
            }
        }
        return null;
    }

    private function selectedFields(array $ovoko): array
    {
        return Arr::only($ovoko, ['id','part_id','ovoko_part_id','rrr_id','external_id','manufacturer_code','manufacturerCode','optional_codes','category_id','categoryId','category_name','categoryName','category','position','part_position','car_id','carId','vehicle_id','notes','internal_notes','description','name','title','weight','weight_kg','length','length_cm','width','width_cm','height','height_cm','price','sell_price','user_token']);
    }

    private function debugRequested(Request $request, string $id): bool
    {
        return $request->boolean('include_raw') || (string) $request->query('debug_id', '') === $id;
    }

    private function debugPayload(array $ovoko, array $changes): array
    {
        return [
            'raw_excerpt_sanitized' => $this->sanitizeDebug($this->selectedFields($ovoko)),
            'top_level_keys' => array_values(array_slice(array_keys($ovoko), 0, 80)),
            'parser_candidate_all' => $this->sanitizeDebug($changes['candidate_all'] ?? []),
            'parser_readable_values' => $this->sanitizeDebug($changes['ovoko_readable'] ?? []),
            'field_sources_tried' => [
                'main_part_code' => ['manufacturer_code','manufacturerCode','main_part_code','code','part_code','main_code','oem_code','visible_code','sku'],
                'category' => ['category_id','categoryId','part_category_id','category_name','categoryName','category.name','category'],
                'part_position' => ['part_position','partPosition','position','side','place','location'],
                'car_id' => ['car_id','carId','vehicle_id','vehicleId','car.id','vehicle.id'],
                'shop_price' => ['shop_price','shopPrice','store_price','notes/internal_notes label sklep/shop'],
                'allegro_price' => ['allegro_price','allegroPrice','notes/internal_notes label allegro'],
                'ovoko_price' => ['price','price.amount','sell_price','sell_price.seller.amount','ovoko_price'],
                'title' => ['description','desc','content','body','name','title','part_name'],
                'dimensions' => ['weight_kg/weightKg/weight','length_cm/lengthCm/length','width_cm/widthCm/width','height_cm/heightCm/height'],
            ],
        ];
    }

    private function sanitizeDebug(mixed $value): mixed
    {
        if (is_array($value)) return collect($value)->map(fn ($v, $k) => preg_match('/password|token|email|phone|address|name_surname/i', (string) $k) ? '***' : $this->sanitizeDebug($v))->all();
        if (is_string($value)) return Str::limit($value, 300);
        return $value;
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
    private function readableNumber(mixed $v): ?string { return $v === null || $v === '' ? null : number_format((float) $v, 2, '.', ''); }
    private function responseShape(array $payload): array { return collect($payload)->map(fn ($v) => is_array($v) ? (Arr::isAssoc($v) ? 'object:'.implode(',', array_slice(array_keys($v), 0, 8)) : 'list:'.count($v)) : gettype($v))->all(); }

    private function writeLog(array $payload, string $status): void
    {
        if (! Schema::hasTable('marketplace_sync_logs')) return;
        MarketplaceSyncLog::query()->create(['marketplace' => 'ovoko', 'action' => 'ovoko_import_product_data', 'status' => $status, 'message' => 'Local-only Ovoko product data import; marketplace_write=false.', 'payload' => $payload, 'created_at' => now()]);
    }
}
