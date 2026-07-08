<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartImage;
use App\Services\Marketplace\MarketplaceImageSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketplaceImageSelectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_limit_uses_more_than_five_images_and_reports_diagnostics(): void
    {
        config([
            'app.url' => 'https://gpswiss.test',
            'filesystems.disks.public.url' => 'https://gpswiss.test/storage',
            'marketplace.allegro_max_images' => 16,
            'marketplace.ebay_max_images' => 24,
        ]);
        Storage::fake('public');

        $part = Part::query()->create(['name' => 'Test part', 'price' => 100, 'quantity' => 1]);

        PartImage::withoutEvents(function () use ($part): void {
            foreach (range(1, 11) as $i) {
                PartImage::query()->create([
                    'part_id' => $part->id,
                    'path' => "parts/photos/{$part->id}/image-{$i}.jpg",
                    'is_primary' => $i === 7,
                    'sort_order' => $i,
                ]);
            }
        });

        $allegro = app(MarketplaceImageSelectionService::class)->selectForPart($part->refresh(), 0, false, 'allegro_main');
        $ebay = app(MarketplaceImageSelectionService::class)->selectForPart($part->refresh(), 0, false, 'ebay_de');

        $this->assertCount(11, $allegro['urls']);
        $this->assertCount(11, $ebay['urls']);
        $this->assertSame(16, $allegro['diagnostics']['marketplace_image_limit_used']);
        $this->assertSame(24, $ebay['diagnostics']['marketplace_image_limit_used']);
        $this->assertSame(11, $allegro['diagnostics']['part_images_count']);
        $this->assertSame(11, $allegro['diagnostics']['eligible_images_count']);
        $this->assertSame(0, $allegro['diagnostics']['skipped_images_count']);
        $this->assertSame(7, $allegro['diagnostics']['selected_images'][0]['sort_order']);
        $this->assertTrue($allegro['diagnostics']['main_image_preserved_as_first']);
    }
}
