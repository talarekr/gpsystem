<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Part;
use Illuminate\Http\Request;

class CheckAllegroChannelsController extends Controller
{
    private const TOKEN = 'gps_images_import_2026';
    private const MAX_SAMPLES = 20;

    public function __invoke(Request $request)
    {
        if (! hash_equals(self::TOKEN, (string) $request->query('token', ''))) {
            return response()->json([
                'ok' => false,
                'error_message' => 'Invalid diagnostics token.',
            ], 403);
        }

        $summary = [
            'parts_total' => Part::query()->count(),
            'with_secondary_allegro_offer_id' => 0,
            'secondary_matches_gearboxes_signature' => 0,
            'secondary_signature_mismatches' => 0,
            'secondary_account_values' => [],
            'with_primary_allegro_offer_id' => 0,
            'primary_account_values' => [],
            'primary_channel_values' => [],
            'primary_enabled_values' => [],
            'with_both_primary_and_secondary_offer_ids' => 0,
            'samples_secondary' => [],
            'samples_secondary_mismatch' => [],
            'samples_both_primary_and_secondary' => [],
            'mapping_proposal' => [
                'primary' => [
                    'marketplace' => 'allegro',
                    'account_code' => 'allegro_main',
                    'legacy_offer_id_key' => 'legacy_payload_json._allegro_offer_id',
                ],
                'secondary' => [
                    'marketplace' => 'allegro',
                    'account_code' => 'allegro_gearboxes',
                    'legacy_offer_id_key' => 'legacy_payload_json._secondary_allegro_offer_id',
                ],
                'when_both_ids_exist' => 'create two marketplace_listings rows for the same part, one per Allegro account/channel',
            ],
        ];

        Part::query()
            ->select(['id', 'sku', 'name', 'legacy_payload'])
            ->whereNotNull('legacy_payload')
            ->orderBy('id')
            ->chunkById(500, function ($parts) use (&$summary): void {
                foreach ($parts as $part) {
                    $payload = is_array($part->legacy_payload) ? $part->legacy_payload : [];
                    $data = $this->legacyPayloadJson($payload);

                    $primaryOfferId = $this->clean(data_get($data, '_allegro_offer_id'));
                    $secondaryOfferId = $this->clean(data_get($data, '_secondary_allegro_offer_id'));

                    if ($primaryOfferId !== null) {
                        $summary['with_primary_allegro_offer_id']++;
                        $this->countValue($summary['primary_account_values'], $this->clean(data_get($data, '_source_account')) ?? '(empty)');
                        $this->countValue($summary['primary_channel_values'], $this->clean(data_get($data, '_source_channel')) ?? '(empty)');
                        $this->countValue($summary['primary_enabled_values'], $this->clean(data_get($data, '_channel_allegro_main_enabled')) ?? '(empty)');
                    }

                    if ($secondaryOfferId !== null) {
                        $summary['with_secondary_allegro_offer_id']++;

                        $secondaryAccount = $this->clean(data_get($data, '_secondary_allegro_account'));
                        $gearboxesEnabled = $this->clean(data_get($data, '_channel_allegro_gearboxes_enabled'));
                        $mainEnabled = $this->clean(data_get($data, '_channel_allegro_main_enabled'));
                        $importedFromSecondary = $this->clean(data_get($data, '_imported_from_secondary_allegro'));

                        $this->countValue($summary['secondary_account_values'], $secondaryAccount ?? '(empty)');

                        $sample = [
                            'part_id' => $part->id,
                            'sku' => $part->sku,
                            'name' => $part->name,
                            '_allegro_offer_id' => $primaryOfferId,
                            '_secondary_allegro_offer_id' => $secondaryOfferId,
                            '_secondary_allegro_account' => $secondaryAccount,
                            '_channel_allegro_gearboxes_enabled' => $gearboxesEnabled,
                            '_channel_allegro_main_enabled' => $mainEnabled,
                            '_imported_from_secondary_allegro' => $importedFromSecondary,
                        ];

                        $this->pushSample($summary['samples_secondary'], $sample);

                        $matches = $secondaryAccount === 'allegro_gearboxes'
                            && $gearboxesEnabled === 'yes'
                            && $mainEnabled === 'no'
                            && $importedFromSecondary === 'yes';

                        if ($matches) {
                            $summary['secondary_matches_gearboxes_signature']++;
                        } else {
                            $summary['secondary_signature_mismatches']++;
                            $this->pushSample($summary['samples_secondary_mismatch'], $sample);
                        }
                    }

                    if ($primaryOfferId !== null && $secondaryOfferId !== null) {
                        $summary['with_both_primary_and_secondary_offer_ids']++;
                        $this->pushSample($summary['samples_both_primary_and_secondary'], [
                            'part_id' => $part->id,
                            'sku' => $part->sku,
                            'name' => $part->name,
                            '_allegro_offer_id' => $primaryOfferId,
                            '_secondary_allegro_offer_id' => $secondaryOfferId,
                        ]);
                    }
                }
            });

        arsort($summary['secondary_account_values']);
        arsort($summary['primary_account_values']);
        arsort($summary['primary_channel_values']);
        arsort($summary['primary_enabled_values']);

        $summary['conclusions'] = [
            'secondary_allegro_is_allegro_gearboxes' => $summary['with_secondary_allegro_offer_id'] > 0
                && $summary['secondary_signature_mismatches'] === 0
                && array_keys($summary['secondary_account_values']) === ['allegro_gearboxes'],
            'primary_allegro_is_allegro_main_confidence' => $this->primaryMainConfidence($summary),
        ];

        return response()->json(['ok' => true] + $summary);
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

    /** @param array<string, int> $values */
    private function countValue(array &$values, string $value): void
    {
        $values[$value] = ($values[$value] ?? 0) + 1;
    }

    /** @param array<int, array<string, mixed>> $samples @param array<string, mixed> $sample */
    private function pushSample(array &$samples, array $sample): void
    {
        if (count($samples) < self::MAX_SAMPLES) $samples[] = $sample;
    }

    /** @param array<string, mixed> $summary */
    private function primaryMainConfidence(array $summary): string
    {
        if (($summary['with_primary_allegro_offer_id'] ?? 0) === 0) return 'unknown_no_primary_ids';
        if (($summary['primary_enabled_values']['yes'] ?? 0) === $summary['with_primary_allegro_offer_id']) return 'strong_channel_allegro_main_enabled_yes';
        return 'needs_manual_review_of_primary samples/account/channel values';
    }
}
