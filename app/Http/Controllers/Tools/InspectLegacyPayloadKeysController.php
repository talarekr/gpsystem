<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class InspectLegacyPayloadKeysController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const MAX_SAMPLES = 10;

    public function __invoke(Request $request)
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json([
                'ok' => false,
                'error_message' => 'Invalid diagnostics token.',
            ], 403);
        }

        $query = mb_strtolower(trim((string) $request->query('query', '')));

        if ($query === '') {
            return response()->json([
                'ok' => false,
                'error_message' => 'Missing query parameter.',
            ], 422);
        }

        $coverage = [];
        $samplePayloads = [];
        $scannedParts = 0;

        Part::query()
            ->select(['id', 'legacy_payload'])
            ->whereNotNull('legacy_payload')
            ->orderBy('id')
            ->chunkById(500, function ($parts) use (&$coverage, &$samplePayloads, &$scannedParts, $query): void {
                foreach ($parts as $part) {
                    $payload = is_array($part->legacy_payload) ? $part->legacy_payload : [];

                    if ($payload === []) {
                        continue;
                    }

                    $scannedParts++;
                    $matchedInPayload = false;

                    foreach ($this->flattenKeys($payload) as $key => $value) {
                        if (! str_contains(mb_strtolower($key), $query)) {
                            continue;
                        }

                        $matchedInPayload = true;

                        if (! isset($coverage[$key])) {
                            $coverage[$key] = [
                                'key' => $key,
                                'count' => 0,
                                'non_empty_count' => 0,
                                'sample_values' => [],
                                'sample_part_ids' => [],
                            ];
                        }

                        $coverage[$key]['count']++;

                        if ($this->isNonEmptyValue($value)) {
                            $coverage[$key]['non_empty_count']++;

                            if (count($coverage[$key]['sample_values']) < self::MAX_SAMPLES) {
                                $sampleValue = $this->sampleValue($value);

                                if (! in_array($sampleValue, $coverage[$key]['sample_values'], true)) {
                                    $coverage[$key]['sample_values'][] = $sampleValue;
                                }
                            }
                        }

                        if (count($coverage[$key]['sample_part_ids']) < self::MAX_SAMPLES
                            && ! in_array($part->id, $coverage[$key]['sample_part_ids'], true)) {
                            $coverage[$key]['sample_part_ids'][] = $part->id;
                        }
                    }

                    if ($matchedInPayload && count($samplePayloads) < self::MAX_SAMPLES) {
                        $samplePayloads[] = [
                            'part_id' => $part->id,
                            'legacy_payload' => $payload,
                        ];
                    }
                }
            });

        ksort($coverage, SORT_NATURAL | SORT_FLAG_CASE);
        $keyCoverage = array_values($coverage);
        $matchingKeys = array_values(array_map(static fn (array $item): string => $item['key'], $keyCoverage));

        return response()->json([
            'ok' => true,
            'parts_total' => Part::query()->count(),
            'scanned_parts' => $scannedParts,
            'query' => $query,
            'matching_keys' => $matchingKeys,
            'matching_keys_count' => count($matchingKeys),
            'key_coverage' => $keyCoverage,
            'sample_payloads_with_matching_keys' => $samplePayloads,
            'command_availability' => [
                'marketplace:build-allegro-mappings-from-parts' => array_key_exists(
                    'marketplace:build-allegro-mappings-from-parts',
                    Artisan::all()
                ),
            ],
            'admin_url' => url('/admin'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function flattenKeys(array $payload, string $prefix = ''): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $result[$fullKey] = $value;

            if (is_array($value)) {
                $result += $this->flattenKeys($value, $fullKey);
            }
        }

        return $result;
    }

    private function isNonEmptyValue(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    private function sampleValue(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return json_decode(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
    }
}
