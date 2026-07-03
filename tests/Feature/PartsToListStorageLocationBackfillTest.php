<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PartsToListStorageLocationBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const CSV = 'imports/gps-gmail-import-audit-full-20260611-102746.csv';

    public function test_dry_run_reports_would_update_and_apply_writes_storage_location(): void
    {
        $part = $this->part('GPS-GMAIL-61060');
        $this->csv([['61060', '1H7']]);

        $this->actingAs(User::factory()->create())->getJson('/admin/tools/parts-to-list/storage-location-backfill-dry-run?limit=100')
            ->assertOk()
            ->assertJsonPath('metrics.would_update_count', 1)
            ->assertJsonPath('would_update.0.local_part_id', $part->id)
            ->assertJsonPath('would_update.0.new_storage_location', '1H7');

        $this->actingAs(User::factory()->create())->getJson('/admin/tools/parts-to-list/storage-location-backfill-apply?confirm=parts-to-list-storage-backfill&limit=10')
            ->assertOk()
            ->assertJsonPath('updated_count', 1)
            ->assertJsonPath('updated.0.new_storage_location', '1H7');

        $part->refresh();
        $this->assertSame('1H7', $part->storageLocation?->name);
    }

    public function test_apply_strips_mail_prefix_from_storage_location(): void
    {
        $part = $this->part('GPS-GMAIL-61638');
        $this->csv([['61638', 'Fwd: AB1']]);

        $this->actingAs(User::factory()->create())->getJson('/admin/tools/parts-to-list/storage-location-backfill-apply?confirm=parts-to-list-storage-backfill&limit=10')
            ->assertOk()
            ->assertJsonPath('updated.0.new_storage_location', 'AB1');

        $part->refresh();
        $this->assertSame('AB1', $part->storageLocation?->name);
    }

    public function test_existing_storage_is_skipped_and_not_overwritten(): void
    {
        $existing = StorageLocation::query()->create(['name' => 'OLD', 'is_active' => true]);
        $part = $this->part('GPS-GMAIL-61060', ['storage_location_id' => $existing->id]);
        $this->csv([['61060', '1H7']]);

        $this->actingAs(User::factory()->create())->getJson('/admin/tools/parts-to-list/storage-location-backfill-apply?confirm=parts-to-list-storage-backfill&limit=10')
            ->assertOk()
            ->assertJsonPath('updated_count', 0)
            ->assertJsonPath('metrics.already_has_storage_count', 1);

        $part->refresh();
        $this->assertSame('OLD', $part->storageLocation?->name);
    }

    public function test_duplicate_csv_staging_id_is_blocked_ambiguous(): void
    {
        $this->part('GPS-GMAIL-61060');
        $this->csv([['61060', '1H7'], ['61060', '2A']]);

        $this->actingAs(User::factory()->create())->getJson('/admin/tools/parts-to-list/storage-location-backfill-dry-run?limit=100')
            ->assertOk()
            ->assertJsonPath('metrics.ambiguous_count', 1)
            ->assertJsonPath('blocked.0.reason', 'ambiguous');
    }

    public function test_non_gps_gmail_number_is_skipped(): void
    {
        $this->part('ABC-61060');
        $this->csv([['61060', '1H7']]);

        $this->actingAs(User::factory()->create())->getJson('/admin/tools/parts-to-list/storage-location-backfill-dry-run?limit=100')
            ->assertOk()
            ->assertJsonPath('metrics.skipped_non_gps_gmail_number_count', 1)
            ->assertJsonPath('metrics.would_update_count', 0);
    }

    public function test_empty_storage_after_normalization_is_skipped(): void
    {
        $this->part('GPS-GMAIL-61060');
        $this->csv([['61060', ' Fwd:  ']]);

        $this->actingAs(User::factory()->create())->getJson('/admin/tools/parts-to-list/storage-location-backfill-dry-run?limit=100')
            ->assertOk()
            ->assertJsonPath('metrics.skipped_empty_storage_count', 1)
            ->assertJsonPath('blocked.0.reason', 'empty_storage_after_normalization');
    }

    private function part(string $number, array $extra = []): Part
    {
        return Part::query()->create($extra + ['name' => 'Test', 'part_number' => $number, 'needs_listing' => true]);
    }

    private function csv(array $rows): void
    {
        Storage::disk('local')->put(self::CSV, "staging_item_id,gmail_message_id,gmail_subject,storage_location,part_code,image_count,readiness_status,blocking_reasons,suggested_action,created_product_id,notes\n");
        foreach ($rows as [$id, $storage]) {
            Storage::disk('local')->append(self::CSV, $id.',,,"'.$storage.'",,,,,,,');
        }
    }
}
