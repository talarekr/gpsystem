<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\CarResource;
use App\Filament\Resources\PartResource;
use App\Models\Car;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarModuleFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_car_dictionary_options_match_foundation_requirements(): void
    {
        $this->assertSame([
            'benzyna' => 'benzyna',
            'diesel' => 'diesel',
            'hybryda' => 'hybryda',
            'elektryczny' => 'elektryczny',
            'LPG' => 'LPG',
            'inne' => 'inne',
        ], Car::fuelTypeOptions());

        $this->assertSame([
            'manualna' => 'manualna',
            'automatyczna' => 'automatyczna',
        ], Car::gearboxTypeOptions());

        $this->assertSame([
            'lewa strona' => 'lewa strona',
            'prawa strona' => 'prawa strona',
        ], Car::steeringSideOptions());

        $this->assertSame([
            'przód' => 'przód',
            'tył' => 'tył',
            'AWD' => 'AWD',
            '4x4' => '4x4',
            'inne' => 'inne',
        ], Car::drivetrainOptions());

        $this->assertSame([
            'kupiony' => 'kupiony',
            'w demontażu' => 'w demontażu',
            'rozebrany' => 'rozebrany',
            'sprzedany' => 'sprzedany',
            'archiwalny' => 'archiwalny',
        ], Car::statusOptions());
    }


    public function test_car_search_phrase_matches_multiple_words_across_vehicle_fields(): void
    {
        $target = Car::query()->create([
            'make' => 'BMW',
            'model' => 'X4 F26',
            'model_variant' => 'X4 F26',
            'engine_code' => 'B47D20',
            'vin' => 'WBAX4F26000000001',
            'registration_number' => 'WX4F26',
        ]);

        Car::query()->create([
            'make' => 'Audi',
            'model' => 'A4',
            'model_variant' => 'B8',
        ]);

        $this->assertSame([$target->id], Car::query()->searchPhrase('bmw x4')->pluck('id')->all());
        $this->assertSame([$target->id], Car::query()->searchPhrase('x4 f26')->pluck('id')->all());
        $this->assertSame([$target->id], Car::query()->searchPhrase('B47 wx4')->pluck('id')->all());
    }


    public function test_visible_car_labels_hide_duplicate_variant(): void
    {
        $a4 = Car::query()->create([
            'make' => 'Audi',
            'model' => 'A4 S4 B6 8E 8H',
            'model_variant' => 'A4 S4 B6 8E 8H',
        ]);
        $rsq3 = Car::query()->create([
            'make' => 'Audi',
            'model' => 'RSQ3',
            'model_variant' => 'RSQ3',
        ]);

        $this->assertSame('Audi A4 S4 B6 8E 8H', PartResource::carLabel($a4));
        $this->assertSame('Audi RSQ3', PartResource::carLabel($rsq3));
    }

    public function test_car_picker_searches_variant_but_hides_it_from_label(): void
    {
        $car = Car::query()->create([
            'make' => 'Audi',
            'model' => 'A4',
            'model_variant' => 'rare variant token',
        ]);

        $options = PartResource::carPickerOptions('rare variant token');

        $this->assertArrayHasKey($car->id, $options);
        $this->assertStringContainsString('Audi A4', $options[$car->id]);
        $this->assertStringNotContainsString('rare variant token', $options[$car->id]);
    }

    public function test_car_images_keep_first_ordered_image_as_primary_thumbnail(): void
    {
        $car = Car::query()->create([
            'make' => 'BMW',
            'model' => '3',
        ]);

        $car->images()->createMany([
            [
                'path' => 'cars/photos/front.jpg',
                'sort_order' => 0,
                'is_primary' => true,
            ],
            [
                'path' => 'cars/photos/interior.jpg',
                'sort_order' => 1,
                'is_primary' => false,
            ],
        ]);

        $this->assertSame([
            'cars/photos/front.jpg',
            'cars/photos/interior.jpg',
        ], $car->fresh()->orderedImagePaths());
        $this->assertSame('cars/photos/front.jpg', $car->fresh()->primary_photo_path);
    }

    public function test_car_resource_permissions_are_simple_and_safe(): void
    {
        $this->assertSame([
            UserRole::OwnerAdmin->value,
            UserRole::Manager->value,
            UserRole::WarehouseProductStaff->value,
            UserRole::PricingStaff->value,
            UserRole::Viewer->value,
        ], CarResource::rolesWithViewAccess());

        $this->assertSame([
            UserRole::OwnerAdmin->value,
            UserRole::Manager->value,
            UserRole::WarehouseProductStaff->value,
        ], CarResource::rolesWithWriteAccess());

        $this->assertSame([
            UserRole::OwnerAdmin->value,
            UserRole::Manager->value,
        ], CarResource::rolesWithFullAccess());
    }

    public function test_risky_features_remain_disabled_for_car_foundation(): void
    {
        $this->assertFalse(config('product-hub.feature_flags.marketplace_publishing_enabled'));
        $this->assertFalse(config('product-hub.feature_flags.external_api_writes_enabled'));
        $this->assertFalse(config('product-hub.feature_flags.ebay_publishing_enabled'));
        $this->assertFalse(config('product-hub.feature_flags.allegro_integration_enabled'));
        $this->assertFalse(config('product-hub.feature_flags.ovoko_integration_enabled'));
    }
}
