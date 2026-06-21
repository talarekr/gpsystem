<?php

namespace App\Services\Tools;

use App\Models\Part;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MarkGpsGmailPartsToListService
{
    public const CONFIRM = 'mark-gps-gmail-to-list';

    public function dryRun(): array
    {
        $matching = $this->matchingQuery();
        $already = (clone $matching)->where('needs_listing', true)->count();

        return [
            'ok' => true,
            'dry_run' => true,
            'parts_total' => Part::query()->count(),
            'matching_sku_count' => (clone $matching)->count(),
            'already_needs_listing_count' => $already,
            'would_mark_count' => (clone $matching)->where('needs_listing', false)->count(),
            'sample_parts' => $this->sample((clone $matching)->orderBy('id')),
        ];
    }

    public function live(string $confirm): array
    {
        if (! hash_equals(self::CONFIRM, $confirm)) {
            throw new InvalidArgumentException('Missing or invalid confirm token.');
        }

        return DB::transaction(function (): array {
            $matching = $this->matchingQuery();
            $already = (clone $matching)->where('needs_listing', true)->count();
            $toUpdate = (clone $matching)->where('needs_listing', false);
            $sampleUpdated = $this->sample((clone $toUpdate)->orderBy('id'));
            $updated = (clone $toUpdate)->update(['needs_listing' => true, 'updated_at' => now()]);

            return [
                'ok' => true,
                'dry_run' => false,
                'matching_sku_count' => (clone $matching)->count(),
                'updated_count' => $updated,
                'already_needs_listing_count' => $already,
                'needs_listing_count_after' => Part::query()->where('needs_listing', true)->count(),
                'sample_updated' => $sampleUpdated,
            ];
        });
    }

    public function matchingQuery(): Builder
    {
        return Part::query()->whereRaw('LOWER(COALESCE(sku, \'\')) LIKE ?', ['%gps-gmail%']);
    }

    private function sample(Builder $query): array
    {
        return $query->limit(10)->get(['id', 'sku', 'name', 'needs_listing'])->map(fn (Part $part): array => [
            'part_id' => $part->id,
            'sku' => $part->sku,
            'title' => $part->name,
            'name' => $part->name,
            'needs_listing' => (bool) $part->needs_listing,
        ])->values()->all();
    }
}
