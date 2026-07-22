<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\CarResource;
use App\Filament\Resources\PartResource;
use App\Models\Car;
use App\Models\OvokoCarDictionaryEntry;
use App\Models\Part;
use Illuminate\Support\Facades\DB;
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

    public function test_car_edit_normalization_preserves_existing_ovoko_mapping_ids_when_edit_form_omits_them(): void
    {
        foreach ([
            ['fuel', '2', 'Benzyna'],
            ['gearbox_type', '1', 'Automatyczny'],
            ['body_type', '1', 'Sedan'],
            ['wheel_drive', '3', 'AWD'],
        ] as [$dictionary, $ovokoId, $name]) {
            OvokoCarDictionaryEntry::query()->create([
                'dictionary' => $dictionary,
                'ovoko_id' => $ovokoId,
                'name' => $name,
            ]);
        }

        $existingLegacyPayload = [
            'ovoko_fuel_id' => '2',
            'ovoko_gearbox_type_id' => '1',
            'ovoko_body_type_id' => '1',
            'ovoko_wheel_drive_id' => '3',
            'ovoko_car_id' => 'RRR-502',
            'untouched_key' => 'keep-me',
        ];

        $normalized = CarResource::normalizeOvokoLocalMappingData([
            'legacy_payload' => [
                'ovoko_fuel_id' => null,
                'ovoko_gearbox_type_id' => null,
                'ovoko_body_type_id' => null,
                'ovoko_wheel_drive_id' => null,
                'ovoko_status_id' => '1',
            ],
            'fuel_type' => 'Benzyna',
            'gearbox_type' => 'Automatyczny',
            'body_type' => 'Sedan',
            'drivetrain' => 'AWD',
            'status' => 'kupiony',
        ], $existingLegacyPayload);

        $this->assertSame('2', data_get($normalized, 'legacy_payload.ovoko_fuel_id'));
        $this->assertSame('1', data_get($normalized, 'legacy_payload.ovoko_gearbox_type_id'));
        $this->assertSame('1', data_get($normalized, 'legacy_payload.ovoko_body_type_id'));
        $this->assertSame('3', data_get($normalized, 'legacy_payload.ovoko_wheel_drive_id'));
        $this->assertSame('RRR-502', data_get($normalized, 'legacy_payload.ovoko_car_id'));
        $this->assertSame('keep-me', data_get($normalized, 'legacy_payload.untouched_key'));
        $this->assertSame('Benzyna', $normalized['fuel_type']);
        $this->assertSame('Automatyczny', $normalized['gearbox_type']);
        $this->assertSame('Sedan', $normalized['body_type']);
        $this->assertSame('AWD', $normalized['drivetrain']);
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


    public function test_recent_car_tiles_use_four_newest_cars_that_have_parts(): void
    {
        foreach ([506 => 16, 505 => 0, 504 => 0, 501 => 0, 500 => 0, 499 => 0, 498 => 10, 497 => 33, 496 => 5] as $id => $partsCount) {
            $this->createCarWithParts($id, $partsCount);
        }

        $this->assertSame([506, 498, 497, 496], PartResource::recentCarPickerCars()->pluck('id')->all());

        $this->createCarWithParts(507, 0);

        $this->assertSame([506, 498, 497, 496], PartResource::recentCarPickerCars()->pluck('id')->all());

        Part::query()->create(['name' => 'Part 507', 'car_id' => 507]);

        $this->assertSame([507, 506, 498, 497], PartResource::recentCarPickerCars()->pluck('id')->all());
    }

    public function test_recent_car_tiles_handle_zero_two_exactly_four_and_more_than_four_cars_with_parts(): void
    {
        $this->createCarWithParts(10, 0);
        $this->assertSame([], PartResource::recentCarPickerCars()->pluck('id')->all());

        $this->createCarWithParts(11, 1);
        $this->createCarWithParts(12, 1);
        $this->assertSame([12, 11], PartResource::recentCarPickerCars()->pluck('id')->all());

        $this->createCarWithParts(13, 1);
        $this->createCarWithParts(14, 1);
        $this->assertSame([14, 13, 12, 11], PartResource::recentCarPickerCars()->pluck('id')->all());

        $this->createCarWithParts(15, 1);
        $this->assertSame([15, 14, 13, 12], PartResource::recentCarPickerCars()->pluck('id')->all());
    }

    public function test_recent_car_tiles_query_is_limited_and_does_not_use_n_plus_one_counts(): void
    {
        foreach ([1, 2, 3, 4, 5] as $id) {
            $this->createCarWithParts($id, 2);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $cars = PartResource::recentCarPickerCars();
        $html = PartResource::recentCarsHtml(null);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(4, $cars);
        $this->assertStringContainsString('setPartCarFromPicker(5)', $html);
        $this->assertStringNotContainsString('setPartCarFromPicker(1)', $html);
        $this->assertLessThanOrEqual(2, count($queries));
        $this->assertStringContainsString('limit 4', strtolower($queries[0]['query']));
    }

    public function test_recent_car_tiles_are_hidden_after_car_selection_but_modal_picker_still_lists_all_cars_and_car_id_is_saved(): void
    {
        $carWithoutParts = $this->createCarWithParts(20, 0);
        $carWithParts = $this->createCarWithParts(21, 1);

        $this->assertSame('', PartResource::recentCarsHtml($carWithParts->id));

        $options = PartResource::carPickerOptions('Modal');
        $this->assertArrayHasKey($carWithoutParts->id, $options);
        $this->assertArrayHasKey($carWithParts->id, $options);

        $data = [];
        $this->assertTrue(PartResource::selectCarInFormData($data, $carWithParts->id));
        $this->assertSame($carWithParts->id, $data['car_id']);
    }

    private function createCarWithParts(int $id, int $partsCount): Car
    {
        $car = Car::query()->create([
            'id' => $id,
            'make' => 'Modal',
            'model' => 'Car '.$id,
        ]);

        for ($index = 1; $index <= $partsCount; $index++) {
            Part::query()->create([
                'name' => 'Part '.$id.'-'.$index,
                'car_id' => $car->id,
            ]);
        }

        return $car;
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
