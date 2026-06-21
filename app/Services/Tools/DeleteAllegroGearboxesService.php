<?php

namespace App\Services\Tools;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\OrderItem;
use App\Models\Part;
use App\Models\PartImage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DeleteAllegroGearboxesService
{
    public const CONFIRMATION = 'delete-allegro-gearboxes';
    private const EXPECTED_MATCHED_COUNT = 1456;
    private const MAX_SAMPLES = 20;

    /** @return array<string, mixed> */
    public function dryRun(): array
    {
        $plan = $this->buildPlan();
        unset($plan['matched_part_ids']);

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
        $archivedAt = Carbon::now();

        DB::transaction(function () use ($partIds, $plan, $archivedAt): void {
            Part::query()
                ->whereIn('id', $partIds)
                ->update([
                    'status' => 'archived',
                    'is_visible_storefront' => false,
                    'quantity' => 0,
                    'updated_at' => $archivedAt,
                ]);

            MarketplaceSyncLog::query()->create([
                'marketplace' => 'allegro',
                'action' => 'delete_allegro_gearboxes_parts',
                'status' => 'completed',
                'message' => 'Archived Allegro Gearboxes imported parts in Laravel store only; no external APIs or synchronization were called.',
                'payload' => [
                    'deleted_strategy' => $plan['delete_strategy'],
                    'matched_count' => $plan['matched_count'],
                    'part_ids_sample' => $plan['will_delete_part_ids_sample'],
                    'titles_sample' => $plan['will_delete_titles_sample'],
                    'secondary_offer_ids_sample' => $plan['secondary_offer_ids_sample'],
                    'safety_checks' => $plan['safety_checks'],
                    'related_counts' => $plan['related_counts'],
                ],
                'created_at' => $archivedAt,
            ]);
        });

        unset($plan['matched_part_ids']);

        return ['ok' => true, 'dry_run' => false, 'deleted_count' => count($partIds)] + $plan;
    }

    /** @return array<string, mixed> */
    private function buildPlan(): array
    {
        $summary = [
            'parts_total' => Part::query()->count(),
            'matched_count' => 0,
            'will_delete_count' => 0,
            'will_delete_part_ids_sample' => [],
            'will_delete_titles_sample' => [],
            'secondary_offer_ids_sample' => [],
            'safety_checks' => [
                'secondary_signature_mismatches' => 0,
                'with_primary_allegro_offer_id' => 0,
                'with_both_primary_and_secondary_offer_ids' => 0,
            ],
            'matched_part_ids' => [],
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

                    if ($matchesSignature) {
                        $summary['matched_count']++;
                        $summary['will_delete_count']++;
                        $summary['matched_part_ids'][] = $part->id;
                        $this->pushSample($summary['will_delete_part_ids_sample'], $part->id);
                        $this->pushSample($summary['will_delete_titles_sample'], $part->name);
                        $this->pushSample($summary['secondary_offer_ids_sample'], $secondaryOfferId);

                        if ($primaryOfferId !== null) {
                            $summary['safety_checks']['with_primary_allegro_offer_id']++;
                            $summary['safety_checks']['with_both_primary_and_secondary_offer_ids']++;
                        }
                    }
                }
            });

        $summary['related_counts'] = $this->relatedCounts($summary['matched_part_ids']);
        $summary['delete_strategy'] = [
            'strategy' => 'archive_parts',
            'reason' => 'parts has an archived status but no SoftDeletes column; hard delete is intentionally avoided because images cascade and order_items/marketplace_listings may keep historical nullable references.',
            'live_action' => 'Set matched parts status=archived, is_visible_storefront=false and quantity=0, then write a marketplace_sync_logs audit row. No API, sync, publication, or Allegro auction action is performed.',
            'hard_delete_allowed' => false,
        ];

        return $summary;
    }

    /** @param array<int, int> $partIds @return array<string, mixed> */
    private function relatedCounts(array $partIds): array
    {
        return [
            'part_images' => Schema::hasTable('part_images') ? PartImage::query()->whereIn('part_id', $partIds)->count() : null,
            'marketplace_listings' => Schema::hasTable('marketplace_listings') ? MarketplaceListing::query()->whereIn('part_id', $partIds)->count() : null,
            'order_items' => Schema::hasTable('order_items') ? OrderItem::query()->whereIn('part_id', $partIds)->count() : null,
        ];
    }

    /** @param array<string, mixed> $plan */
    private function assertSafeForLive(array $plan): void
    {
        if ($plan['matched_count'] !== self::EXPECTED_MATCHED_COUNT) {
            throw new RuntimeException("Safety stop: matched_count is {$plan['matched_count']}, expected ".self::EXPECTED_MATCHED_COUNT.'.');
        }

        foreach ($plan['safety_checks'] as $name => $count) {
            if ($count > 0) {
                throw new RuntimeException("Safety stop: {$name} is {$count}.");
            }
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
