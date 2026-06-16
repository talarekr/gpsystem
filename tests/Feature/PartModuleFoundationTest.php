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

    public function test_internal_category_suggestion_marks_uncertain_or_assigns_known_mapping(): void
    {
        $known = Part::query()->create(['name' => 'Alternator kompletny', 'oem_number' => 'ALT-001']);
        $unknown = Part::query()->create(['name' => 'Nietypowy element']);

        $this->assertSame('Elektryka', $known->category->name);
        $this->assertFalse($known->category_needs_review);
        $this->assertTrue($unknown->category_needs_review);
    }

    public function test_part_resource_navigation_and_labels_are_polish_and_iconless_children(): void
    {
        $this->assertSame('Części', PartResource::getNavigationGroup());
        $this->assertSame('Wszystkie części', PartResource::getNavigationLabel());
        $this->assertNull(PartResource::getNavigationIcon());
    }
}
