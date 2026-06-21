<?php

namespace Tests\Feature;

use App\Filament\Resources\PartResource;
use App\Models\Car;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartImage;
use App\Models\StorageLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PartModuleFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_part_tables_and_category_suggestion_fields_exist(): void
    {
        $this->assertTrue(Schema::hasTable('parts'));
        $this->assertTrue(Schema::hasTable('part_images'));
        $this->assertTrue(Schema::hasColumns('parts', [
            'sku','name','slug','part_number','oem_number','manufacturer_code','short_description','description','condition_notes',
            'category_id','suggested_category_id','category_confidence','category_suggestion_reason','category_needs_review',
            'car_id','vehicle_snapshot','storage_location_id','price','currency','allegro_price','ebay_price','quantity','status','is_visible_storefront','created_by',
        ]));
    }

    public function test_part_can_be_created_with_required_fields_and_safe_defaults(): void
    {
        $part = Part::query()->create(['name' => 'Reflektor lewy']);

        $this->assertNotNull($part->id);
        $this->assertSame('draft', $part->status);
        $this->assertFalse($part->is_visible_storefront);
        $this->assertSame(1, $part->quantity);
        $this->assertSame('PLN', $part->currency);
    }

    public function test_part_name_is_required_by_database(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Part::query()->create([]);
    }

    public function test_nullable_car_and_storage_relationships_work_when_provided(): void
    {
        $car = Car::query()->create(['make' => 'BMW', 'model' => '3', 'model_variant' => 'E91', 'production_year' => 2011, 'fuel_type' => 'diesel', 'gearbox_type' => 'manualna', 'engine_capacity_cm3' => 1995, 'engine_code' => 'N47', 'color' => 'czarny', 'steering_side' => 'lewa strona']);
        $location = StorageLocation::query()->create(['name' => '1K3-1', 'description' => 'KASTRA 1K3']);

        $part = Part::query()->create(['name' => 'Lampa tył', 'car_id' => $car->id, 'storage_location_id' => $location->id]);

        $this->assertTrue($part->car->is($car));
        $this->assertTrue($part->storageLocation->is($location));
        $this->assertSame('BMW', $part->vehicle_snapshot['make']);
        $this->assertSame('N47', $part->vehicle_snapshot['engine_code']);
    }

    public function test_part_photos_can_be_associated_and_first_image_becomes_primary(): void
    {
        $part = Part::query()->create(['name' => 'Zderzak przód']);

        $first = PartImage::query()->create(['part_id' => $part->id, 'path' => 'parts/photos/front.jpg', 'sort_order' => 0]);
        PartImage::query()->create(['part_id' => $part->id, 'path' => 'parts/photos/side.jpg', 'sort_order' => 1]);

        $this->assertTrue($first->fresh()->is_primary);
        $this->assertSame('parts/photos/front.jpg', $part->fresh()->primary_image_path);
    }

    public function test_part_photo_public_url_uses_storage_url_for_public_disk_file(): void
    {
        Storage::disk('public')->put('parts/photos/example.jpg', 'fake image');
        $image = new PartImage(['path' => 'parts/photos/example.jpg']);

        $this->assertSame(Storage::disk('public')->url('parts/photos/example.jpg'), $image->publicUrl());
        $this->assertStringEndsWith('/storage/parts/photos/example.jpg', $image->publicUrl());
    }

    public function test_imported_part_photo_storefront_urls_use_existing_presentation_variants(): void
    {
        Storage::disk('public')->put('parts/photos/imported/2083/example.jpg', 'fake image');
        Storage::disk('public')->put('parts/photos/presentation/listing/example.jpg', 'fake listing variant');
        Storage::disk('public')->put('parts/photos/presentation/product/example.jpg', 'fake product variant');

        $image = new PartImage([
            'path' => 'parts/photos/imported/2083/example.jpg',
            'legacy_payload' => ['presentation' => [
                'listing_path' => 'parts/photos/presentation/listing/example.jpg',
                'product_path' => 'parts/photos/presentation/product/example.jpg',
            ]],
        ]);

        $this->assertTrue($image->isImportedPhoto());
        $this->assertStringEndsWith('/storage/parts/photos/presentation/listing/example.jpg', $image->listingUrl());
        $this->assertStringEndsWith('/storage/parts/photos/presentation/product/example.jpg', $image->productUrl());
    }

    public function test_imported_part_photo_storefront_urls_fall_back_to_original_without_presentation_variants(): void
    {
        Storage::disk('public')->put('parts/photos/imported/2083/fallback.jpg', 'fake image');

        $image = new PartImage([
            'path' => 'parts/photos/imported/2083/fallback.jpg',
        ]);

        $this->assertTrue($image->isImportedPhoto());
        $this->assertStringEndsWith('/storage/parts/photos/imported/2083/fallback.jpg', $image->listingUrl());
        $this->assertStringEndsWith('/storage/parts/photos/imported/2083/fallback.jpg', $image->productUrl());
    }

    public function test_manual_part_photo_storefront_urls_still_use_existing_presentation_variants(): void
    {
        Storage::disk('public')->put('parts/photos/manual/example.jpg', 'fake image');
        Storage::disk('public')->put('parts/photos/presentation/listing/manual-example.jpg', 'fake listing variant');
        Storage::disk('public')->put('parts/photos/presentation/product/manual-example.jpg', 'fake product variant');

        $image = new PartImage([
            'path' => 'parts/photos/manual/example.jpg',
            'legacy_payload' => ['presentation' => [
                'listing_path' => 'parts/photos/presentation/listing/manual-example.jpg',
                'product_path' => 'parts/photos/presentation/product/manual-example.jpg',
            ]],
        ]);

        $this->assertFalse($image->isImportedPhoto());
        $this->assertStringEndsWith('/storage/parts/photos/presentation/listing/manual-example.jpg', $image->listingUrl());
        $this->assertStringEndsWith('/storage/parts/photos/presentation/product/manual-example.jpg', $image->productUrl());
    }

    public function test_part_photo_public_url_uses_public_path_when_file_exists_in_public_directory(): void
    {
        $publicFile = public_path('legacy-parts/example.jpg');
        if (! is_dir(dirname($publicFile))) {
            mkdir(dirname($publicFile), 0755, true);
        }
        file_put_contents($publicFile, 'fake image');

        try {
            $image = new PartImage(['path' => 'legacy-parts/example.jpg']);

            $this->assertSame(asset('legacy-parts/example.jpg'), $image->publicUrl());
            $this->assertStringEndsWith('/legacy-parts/example.jpg', $image->publicUrl());
        } finally {
            @unlink($publicFile);
            @rmdir(dirname($publicFile));
        }
    }

    public function test_listing_score_penalizes_narrow_images_and_prefers_wide_fill(): void
    {
        $narrow = PartImage::calculateListingScore(0.318, 1.0, 1.0);
        $wide = PartImage::calculateListingScore(1.0, 0.875, 1.0);

        $this->assertGreaterThan($narrow, $wide);
    }

    public function test_proposed_listing_score_penalizes_edge_touching_closeups(): void
    {
        $closeup = PartImage::calculateProposedListingScore([
            'listing_fill_width_ratio' => 1.0,
            'listing_fill_height_ratio' => 0.875,
            'listing_dominant_ratio' => 1.0,
            'selected_crop_pass' => 'aggressive',
            'metrics' => ['original' => ['width' => 1000, 'height' => 750]],
            'selected_crops' => ['listing' => ['box' => ['x' => 0, 'y' => 8, 'width' => 960, 'height' => 650]]],
        ]);
        $wholeObject = PartImage::calculateProposedListingScore([
            'listing_fill_width_ratio' => 0.94,
            'listing_fill_height_ratio' => 0.72,
            'listing_dominant_ratio' => 0.94,
            'selected_crop_pass' => 'normal',
            'metrics' => ['original' => ['width' => 1000, 'height' => 750]],
            'selected_crops' => ['listing' => ['box' => ['x' => 70, 'y' => 75, 'width' => 820, 'height' => 520]]],
        ]);

        $this->assertGreaterThan($closeup, $wholeObject);
    }

    public function test_part_listing_image_uses_best_presentation_metrics_when_images_are_eager_loaded(): void
    {
        $part = Part::query()->create(['name' => 'SEAT EXEO zwrotnica', 'status' => 'ready']);

        $primaryNarrow = PartImage::query()->create(['part_id' => $part->id, 'sort_order' => 0, 'is_primary' => true]);
        $primaryNarrow->forceFill([
            'path' => 'parts/photos/vertical.jpg',
            'legacy_payload' => ['presentation' => [
                'listing_path' => 'parts/photos/presentation/listing/vertical.jpg',
                'listing_fill_width_ratio' => 0.318,
                'listing_fill_height_ratio' => 1.0,
                'listing_dominant_ratio' => 1.0,
            ]],
        ])->saveQuietly();

        $wide = PartImage::query()->create(['part_id' => $part->id, 'sort_order' => 1, 'is_primary' => false]);
        $wide->forceFill([
            'path' => 'parts/photos/wide.jpg',
            'legacy_payload' => ['presentation' => [
                'listing_path' => 'parts/photos/presentation/listing/wide.jpg',
                'listing_fill_width_ratio' => 1.0,
                'listing_fill_height_ratio' => 0.875,
                'listing_dominant_ratio' => 1.0,
            ]],
        ])->saveQuietly();

        $part = Part::query()->with('images')->findOrFail($part->id);

        $this->assertTrue($part->primaryImage()->is($primaryNarrow));
        $this->assertTrue($part->listingImage()->is($wide));
    }

    public function test_part_listing_image_falls_back_to_primary_sort_order_when_metrics_are_missing(): void
    {
        $part = Part::query()->create(['name' => 'Brak metryk']);

        $fallback = PartImage::query()->create(['part_id' => $part->id, 'sort_order' => 5, 'is_primary' => true]);
        $fallback->forceFill(['path' => 'parts/photos/primary.jpg'])->saveQuietly();
        $secondary = PartImage::query()->create(['part_id' => $part->id, 'sort_order' => 0, 'is_primary' => false]);
        $secondary->forceFill(['path' => 'parts/photos/secondary.jpg'])->saveQuietly();

        $part = Part::query()->with('images')->findOrFail($part->id);

        $this->assertTrue($part->listingImage()->is($fallback));
    }

    public function test_internal_category_suggestion_marks_uncertain_or_assigns_known_mapping(): void
    {
        $known = Part::query()->create(['name' => 'Alternator kompletny', 'oem_number' => 'ALT-001']);
        $unknown = Part::query()->create(['name' => 'Nietypowy element']);

        $this->assertSame('Elektryka', $known->category->name);
        $this->assertFalse($known->category_needs_review);
        $this->assertTrue($unknown->category_needs_review);
    }

    public function test_part_navigation_counts_use_real_parts_and_needs_listing_queue(): void
    {
        Part::query()->create(['name' => 'Część bez wystawienia']);
        Part::query()->create(['name' => 'Część do wystawienia 1', 'needs_listing' => true]);
        Part::query()->create(['name' => 'Część do wystawienia 2', 'needs_listing' => true]);

        $this->assertSame(3, PartResource::getAllPartsNavigationCount());
        $this->assertSame(2, PartResource::getPartsToListNavigationCount());
    }

    public function test_part_resource_navigation_and_labels_are_polish_and_iconless_children(): void
    {
        $this->assertSame('Części', PartResource::getNavigationGroup());
        $this->assertSame('Wszystkie części', PartResource::getNavigationLabel());
        $this->assertNull(PartResource::getNavigationIcon());
    }
}
