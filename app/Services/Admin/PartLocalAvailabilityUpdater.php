<?php

namespace App\Services\Admin;

use App\Models\Part;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PartLocalAvailabilityUpdater
{
    /**
     * @return array{ok: bool, part_id: int, old_status: ?string, new_status: ?string, old_availability: ?string, new_availability: ?string}
     */
    public function update(Part|int $part, int|string $availabilityFlag): array
    {
        if (! in_array((string) $availabilityFlag, ['0', '1'], true)) {
            throw new InvalidArgumentException('availability_flag must be 0 or 1.');
        }

        return DB::transaction(function () use ($part, $availabilityFlag): array {
            $locked = $part instanceof Part
                ? Part::query()->whereKey($part->getKey())->lockForUpdate()->firstOrFail()
                : Part::query()->whereKey($part)->lockForUpdate()->firstOrFail();

            $oldStatus = $locked->status;
            $oldAvailability = $locked->adminLocalAvailability();

            if ((string) $availabilityFlag === '0') {
                $locked->markSoldViaLocalSale();
            } else {
                $locked->forceFill([
                    'status' => 'ready',
                    'quantity' => max(1, (int) ($locked->quantity ?? 0)),
                    'is_visible_storefront' => true,
                    'needs_listing' => false,
                    'sale_source' => null,
                    'sold_at' => null,
                ]);
            }

            $locked->save();
            $fresh = $locked->fresh();

            return [
                'ok' => true,
                'part_id' => (int) $locked->id,
                'old_status' => $oldStatus,
                'new_status' => $fresh?->status,
                'old_availability' => $oldAvailability,
                'new_availability' => $fresh?->adminLocalAvailability(),
            ];
        });
    }
}
