<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\CarResource;
use App\Models\Car;
use Tests\TestCase;

class CarModuleFoundationTest extends TestCase
{
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
