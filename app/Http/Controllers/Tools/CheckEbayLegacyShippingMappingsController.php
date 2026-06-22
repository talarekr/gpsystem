<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckEbayLegacyShippingMappingsController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const MAX_SAMPLES = 20;

    private const NEEDLES = [
        'shipping', 'shipment', 'delivery', 'fulfillment', 'fulfillment_policy',
        'fulfillment_policy_id', 'business_policy', 'policy', 'shipping_policy',
        'shipping_policy_id', 'postage', 'ebay_shipping', 'ebay_de', 'ebay_fr',
        'marketplace_mappings',
    ];

    private const POLICY_IDS = [
        '259264150013' => ['channel' => 'ebay_de', 'label' => 'Wysyłka 30 euro'],
        '259677066013' => ['channel' => 'ebay_de', 'label' => 'Wysyłka 50 euro'],
        '259636579013' => ['channel' => 'ebay_de', 'label' => 'Wysyłka 130 euro'],
        '260547694013' => ['channel' => 'ebay_fr', 'label' => 'Wysyłka 55 euro'],
        '260547464013' => ['channel' => 'ebay_fr', 'label' => 'Wysyłka 70 euro'],
        '260547754013' => ['channel' => 'ebay_fr', 'label' => 'Wysyłka 130 euro'],
    ];

    public function __invoke(Request $request)
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $blockers = [];
        $warnings = [
            'Read-only diagnostics: no eBay API calls, no listing publication, no price/stock sync, no live order import, and no database writes are performed.',
            'Samples expose only matching legacy payload paths plus short scalar values; full legacy_payload content is never returned.',
        ];

        if (! Schema::hasTable('part_categories')) {
            return response()->json($this->emptyResponse(['part_categories table is missing.'], $warnings));
        }

        if (! Schema::hasColumn('part_categories', 'legacy_payload')) {
            return response()->json($this->emptyResponse(['part_categories.legacy_payload column is missing.'], $warnings));
        }

        $partCounts = $this->partCounts();
        $usedCategoryIds = $partCounts->keys()->map(fn ($id) => (int) $id)->all();
        $usedWithShipping = [];
        $foundCategoryIds = [];
        $sampleFound = [];
        $sampleUsedMissing = [];
        $keySummary = [];
        $valueSummary = [];
        $policySummary = [];
        $categoriesTotal = (int) DB::table('part_categories')->count();
        $categoriesWithPayload = 0;
        $possibleDe = 0;
        $possibleFr = 0;

        DB::table('part_categories')
            ->select(['id', 'name', 'category_path', 'full_slug_path', 'legacy_payload'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$categoriesWithPayload, &$foundCategoryIds, &$usedWithShipping, &$sampleFound, &$keySummary, &$valueSummary, &$policySummary, &$possibleDe, &$possibleFr, $partCounts): void {
                foreach ($rows as $row) {
                    $payload = $this->decodePayload($row->legacy_payload ?? null);
                    if ($payload === []) {
                        continue;
                    }

                    $categoriesWithPayload++;
                    $matches = $this->matchingLegacyEntries($payload);
                    if ($matches === []) {
                        continue;
                    }

                    $categoryId = (int) $row->id;
                    $foundCategoryIds[] = $categoryId;
                    $partsCount = (int) ($partCounts[$categoryId] ?? 0);
                    if ($partsCount > 0) {
                        $usedWithShipping[] = $categoryId;
                    }

                    $detectedKeys = array_values(array_unique(array_map(fn ($m) => $m['key'], $matches)));
                    $detectedValues = array_values(array_unique(array_filter(array_map(fn ($m) => $m['safe_value'], $matches), fn ($v) => $v !== null && $v !== '')));
                    $paths = array_values(array_unique(array_map(fn ($m) => $m['path'], $matches)));

                    foreach ($detectedKeys as $key) {
                        $keySummary[$key] = ($keySummary[$key] ?? 0) + 1;
                    }
                    foreach (array_slice($detectedValues, 0, 20) as $value) {
                        $valueSummary[$value] = ($valueSummary[$value] ?? 0) + 1;
                    }

                    $policyHits = $this->policyHits($matches);
                    foreach ($policyHits as $policyId => $hit) {
                        $policySummary[$policyId] = $policySummary[$policyId] ?? ['policy_id' => $policyId, 'channel' => $hit['channel'], 'label' => $hit['label'], 'categories_count' => 0];
                        $policySummary[$policyId]['categories_count']++;
                    }

                    if ($this->hasChannelSignal($matches, 'ebay_de')) {
                        $possibleDe++;
                    }
                    if ($this->hasChannelSignal($matches, 'ebay_fr')) {
                        $possibleFr++;
                    }

                    if (count($sampleFound) < self::MAX_SAMPLES) {
                        $sampleFound[] = [
                            'local_category_id' => $categoryId,
                            'local_category_name' => $row->name,
                            'local_category_path' => $row->category_path ?: $row->full_slug_path,
                            'parts_count' => $partsCount,
                            'detected_keys' => $detectedKeys,
                            'detected_values_safe' => array_slice($detectedValues, 0, 20),
                            'legacy_payload_paths' => array_slice($paths, 0, 40),
                        ];
                    }
                }
            });

        $missingUsed = array_values(array_diff($usedCategoryIds, array_unique($foundCategoryIds)));
        if ($missingUsed !== []) {
            $sampleUsedMissing = DB::table('part_categories')
                ->whereIn('id', array_slice($missingUsed, 0, self::MAX_SAMPLES))
                ->get(['id as local_category_id', 'name as local_category_name', 'category_path as local_category_path'])
                ->map(fn ($row) => (array) $row + ['parts_count' => (int) ($partCounts[$row->local_category_id] ?? 0)])
                ->all();
        }

        arsort($keySummary);
        arsort($valueSummary);

        return response()->json([
            'ok' => true,
            'categories_total' => $categoriesTotal,
            'categories_with_legacy_payload' => $categoriesWithPayload,
            'categories_with_any_shipping_like_data' => count(array_unique($foundCategoryIds)),
            'categories_used_by_parts' => count($usedCategoryIds),
            'used_categories_with_shipping_like_data' => count(array_unique($usedWithShipping)),
            'detected_keys_summary' => array_slice($keySummary, 0, 100, true),
            'detected_values_summary' => array_slice($valueSummary, 0, 100, true),
            'detected_policy_ids_summary' => array_values($policySummary),
            'possible_ebay_de_shipping_mappings_count' => $possibleDe,
            'possible_ebay_fr_shipping_mappings_count' => $possibleFr,
            'sample_categories_with_shipping_like_data' => $sampleFound,
            'sample_used_categories_missing_shipping_like_data' => $sampleUsedMissing,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ]);
    }

    private function emptyResponse(array $blockers, array $warnings): array
    {
        return ['ok' => false, 'categories_total' => 0, 'categories_with_legacy_payload' => 0, 'categories_with_any_shipping_like_data' => 0, 'categories_used_by_parts' => 0, 'used_categories_with_shipping_like_data' => 0, 'detected_keys_summary' => [], 'detected_values_summary' => [], 'detected_policy_ids_summary' => [], 'possible_ebay_de_shipping_mappings_count' => 0, 'possible_ebay_fr_shipping_mappings_count' => 0, 'sample_categories_with_shipping_like_data' => [], 'sample_used_categories_missing_shipping_like_data' => [], 'blockers' => $blockers, 'warnings' => $warnings];
    }

    private function partCounts()
    {
        if (! Schema::hasTable('parts') || ! Schema::hasColumn('parts', 'category_id')) {
            return collect();
        }

        return DB::table('parts')->select('category_id', DB::raw('count(*) as c'))->whereNotNull('category_id')->groupBy('category_id')->pluck('c', 'category_id');
    }

    private function decodePayload($payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function matchingLegacyEntries(array $payload): array
    {
        $matches = [];
        foreach ($this->flatten($payload) as $path => $value) {
            $pathLower = strtolower($path);
            $valueString = is_scalar($value) ? (string) $value : null;
            $valueLower = strtolower((string) $valueString);
            if (! $this->isInteresting($pathLower, $valueLower)) {
                continue;
            }
            $matches[] = ['path' => 'legacy_payload.'.$path, 'key' => basename(str_replace('.', '/', $path)), 'safe_value' => $this->safeValue($valueString)];
        }

        return $matches;
    }

    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_string($value) && str_starts_with(trim($value), '{')) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : $value;
            }
            if (is_array($value)) {
                $out += $this->flatten($value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }

    private function isInteresting(string $pathLower, string $valueLower): bool
    {
        foreach (self::NEEDLES as $needle) {
            if (str_contains($pathLower, $needle) || str_contains($valueLower, $needle)) {
                return true;
            }
        }

        foreach (array_keys(self::POLICY_IDS) as $policyId) {
            if (str_contains($valueLower, $policyId)) {
                return true;
            }
        }

        return false;
    }

    private function safeValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, 120);
    }

    private function policyHits(array $matches): array
    {
        $hits = [];
        foreach ($matches as $match) {
            foreach (self::POLICY_IDS as $policyId => $meta) {
                if (str_contains((string) $match['safe_value'], $policyId)) {
                    $hits[$policyId] = ['channel' => $meta['channel'], 'label' => $meta['label']];
                }
            }
        }

        return $hits;
    }

    private function hasChannelSignal(array $matches, string $channel): bool
    {
        foreach ($matches as $match) {
            $text = strtolower($match['path'].' '.(string) $match['safe_value']);
            if (str_contains($text, $channel)) {
                return true;
            }
            foreach (self::POLICY_IDS as $policyId => $meta) {
                if ($meta['channel'] === $channel && str_contains($text, $policyId)) {
                    return true;
                }
            }
        }

        return false;
    }
}
