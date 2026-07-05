<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\StorageLocationResource;
use App\Filament\Resources\StorageLocationResource\Pages\CreateStorageLocation;
use App\Filament\Resources\StorageLocationResource\Pages\ListStorageLocations;
use App\Filament\Resources\StorageLocationResource\Pages\ViewStorageLocation;
use App\Models\StorageLocation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StorageLocationModuleFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_storage_locations_table_migration_exists_with_foundation_columns(): void
    {
        $this->assertTrue(Schema::hasTable('storage_locations'));
        $this->assertTrue(Schema::hasColumns('storage_locations', [
            'id',
            'name',
            'description',
            'is_active',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_storage_location_model_can_create_foundation_record(): void
    {
        $location = StorageLocation::query()->create([
            'name' => '1K3-1',
            'description' => 'KASTRA 1K3',
        ]);

        $this->assertSame('1K3-1', $location->name);
        $this->assertSame('KASTRA 1K3', $location->description);
        $this->assertTrue($location->fresh()->is_active);
    }

    public function test_storage_location_resource_can_create_record(): void
    {
        $this->actingAsWarehouseUser();

        Livewire::test(CreateStorageLocation::class)
            ->fillForm([
                'name' => '8KNS-1',
                'description' => 'KONTENER 8KNS',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('storage_locations', [
            'name' => '8KNS-1',
            'description' => 'KONTENER 8KNS',
            'is_active' => true,
        ]);
    }

    public function test_storage_location_list_modal_create_action_creates_record_through_livewire(): void
    {
        $this->actingAsWarehouseUser();

        Livewire::test(ListStorageLocations::class)
            ->mountAction('create')
            ->setActionData([
                'name' => 'MODAL-1',
                'description' => 'Utworzone z modala listy',
                'is_active' => true,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSee('MODAL-1');

        $this->assertDatabaseHas('storage_locations', [
            'name' => 'MODAL-1',
            'description' => 'Utworzone z modala listy',
            'is_active' => true,
        ]);
    }

    public function test_storage_location_name_is_required(): void
    {
        $this->actingAsWarehouseUser();

        Livewire::test(CreateStorageLocation::class)
            ->fillForm([
                'name' => null,
                'description' => 'KASTRA 1K3',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);
    }

    public function test_storage_location_name_is_unique(): void
    {
        $this->actingAsWarehouseUser();

        StorageLocation::query()->create([
            'name' => 'GTR8',
            'description' => 'Istniejące miejsce',
        ]);

        Livewire::test(CreateStorageLocation::class)
            ->fillForm([
                'name' => 'GTR8',
                'description' => 'Duplikat',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);
    }

    public function test_storage_location_resource_navigation_table_and_detail_are_configured(): void
    {
        $this->assertSame('Magazynowanie', StorageLocationResource::getNavigationGroup());
        $this->assertSame('Miejsca składowania', StorageLocationResource::getNavigationLabel());
        $this->assertArrayHasKey('view', StorageLocationResource::getPages());

        $this->actingAsWarehouseUser();

        StorageLocation::query()->create([
            'name' => '1K3-1',
            'description' => 'KASTRA 1K3',
        ]);

        Livewire::test(ListStorageLocations::class)
            ->searchTable('KASTRA')
            ->assertCanSeeTableRecords(StorageLocation::query()->where('name', '1K3-1')->get());

        Livewire::test(ViewStorageLocation::class, ['record' => StorageLocation::query()->first()->getRouteKey()])
            ->assertSee('ID')
            ->assertSee('Nazwa')
            ->assertSee('Aktywne')
            ->assertDontSee('Opis')
            ->assertDontSee('Utworzono')
            ->assertDontSee('Zaktualizowano')
            ->assertSee('Części w tym miejscu')
            ->assertDontSee('Moduł części zostanie dodany w kolejnym etapie.');
    }



    public function test_storage_location_list_status_uses_plain_text_styles(): void
    {
        $this->actingAsWarehouseUser();

        StorageLocation::query()->create(['name' => 'ACTIVE-1', 'is_active' => true]);
        StorageLocation::query()->create(['name' => 'INACTIVE-1', 'is_active' => false]);

        Livewire::test(ListStorageLocations::class)
            ->assertSee('ACTIVE-1')
            ->assertSee('INACTIVE-1')
            ->assertSee('gps-status gps-ok', false)
            ->assertSee('gps-status gps-no', false)
            ->assertDontSee('gps-badge', false)
            ->assertDontSee('background:#dcfce7', false)
            ->assertDontSee('background:#fee2e2', false);
    }

    public function test_import_source_description_is_hidden_in_storage_location_ui(): void
    {
        $this->actingAsWarehouseUser();

        $location = StorageLocation::query()->create([
            'name' => '2D3',
            'description' => StorageLocation::ALLEGRO_IMPORT_DESCRIPTION,
        ]);

        $this->assertNull($location->publicDescription());

        Livewire::test(ListStorageLocations::class)
            ->assertSee('2D3')
            ->assertDontSee(StorageLocation::ALLEGRO_IMPORT_DESCRIPTION);

        Livewire::test(ViewStorageLocation::class, ['record' => $location->getRouteKey()])
            ->assertSee('2D3')
            ->assertDontSee(StorageLocation::ALLEGRO_IMPORT_DESCRIPTION);
    }

    public function test_no_risky_parts_import_or_external_integration_flags_were_enabled(): void
    {
        $this->assertFalse(Schema::hasTable('parts'));
        $this->assertFalse(config('product-hub.feature_flags.marketplace_publishing_enabled'));
        $this->assertFalse(config('product-hub.feature_flags.external_api_writes_enabled'));
        $this->assertFalse(config('product-hub.feature_flags.ebay_publishing_enabled'));
        $this->assertFalse(config('product-hub.feature_flags.allegro_integration_enabled'));
        $this->assertFalse(config('product-hub.feature_flags.ovoko_integration_enabled'));
    }

    private function actingAsWarehouseUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::query()->create([
            'name' => 'Warehouse User',
            'email' => 'warehouse@example.test',
            'password' => 'password',
        ]);

        $user->assignRole(UserRole::WarehouseProductStaff->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }
}
