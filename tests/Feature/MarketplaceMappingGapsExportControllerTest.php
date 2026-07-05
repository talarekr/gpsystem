<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketplaceMappingGapsExportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_export_scans_for_sale_parts_without_storefront_visibility_filter(): void
    {
        $this->withoutMiddleware();

        DB::table('parts')->insert([
            $this->partRow(1, 'ready', 1, false),
            $this->partRow(2, 'ready', 1, true),
            $this->partRow(3, 'published', 2, false),
            $this->partRow(4, 'sold', 1, true),
            $this->partRow(5, 'draft', 1, true),
            $this->partRow(6, 'archived', 1, true),
            $this->partRow(7, 'ready', 0, true),
        ]);

        $this->getJson('/admin/tools/marketplace/mapping-gaps-export?format=json')
            ->assertOk()
            ->assertJsonPath('candidate_ready_count', 3)
            ->assertJsonPath('visible_ready_count', 1)
            ->assertJsonPath('visible_only', false)
            ->assertJsonPath('scanned_count', 3);
    }

    public function test_visible_only_parameter_filters_to_storefront_visible_parts(): void
    {
        $this->withoutMiddleware();

        DB::table('parts')->insert([
            $this->partRow(1, 'ready', 1, false),
            $this->partRow(2, 'ready', 1, true),
            $this->partRow(3, 'published', 1, false),
        ]);

        $this->getJson('/admin/tools/marketplace/mapping-gaps-export?format=json&visible_only=1')
            ->assertOk()
            ->assertJsonPath('candidate_ready_count', 3)
            ->assertJsonPath('visible_ready_count', 1)
            ->assertJsonPath('visible_only', true)
            ->assertJsonPath('scanned_count', 1);
    }

    public function test_csv_export_is_written_to_public_storage_for_download_url(): void
    {
        Storage::fake('public');
        $this->withoutMiddleware();

        DB::table('parts')->insert([
            $this->partRow(1, 'ready', 1, true),
        ]);

        $response = $this->getJson('/admin/tools/marketplace/mapping-gaps-export?format=csv');

        $response->assertOk()
            ->assertJsonPath('csv_url', url('/storage/'.$response->json('file_relative_path')));

        Storage::disk('public')->assertExists($response->json('file_relative_path'));
    }

    private function partRow(int $id, string $status, int $quantity, bool $visible): array
    {
        $now = now();

        return [
            'id' => $id,
            'name' => 'Part '.$id,
            'part_number' => 'PN-'.$id,
            'status' => $status,
            'quantity' => $quantity,
            'is_visible_storefront' => $visible,
            'needs_listing' => false,
            'needs_review' => false,
            'currency' => 'PLN',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
