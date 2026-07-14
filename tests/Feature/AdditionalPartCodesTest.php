<?php

namespace Tests\Feature;

use App\Filament\Resources\PartResource;
use App\Filament\Resources\PartResource\Pages\CreatePart;
use App\Filament\Resources\PartResource\Pages\EditPart;
use App\Models\Part;
use App\Models\StorageLocation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdditionalPartCodesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RoleSeeder::class);
    }

    public function test_workshop_product_saves_only_main_code_without_additional_codes(): void
    {
        $part = $this->storeWorkshopPart(['part_number' => '9321075']);

        $this->assertSame('9321075', $part->part_number);
        $this->assertNull($part->additional_part_codes);
    }

    public function test_workshop_product_saves_one_and_two_additional_codes(): void
    {
        $one = $this->storeWorkshopPart(['part_number' => '9321075', 'additional_part_codes' => [' 9321076 ']]);
        $two = $this->storeWorkshopPart(['part_number' => '9321078', 'additional_part_codes' => ['9321079', '9321080']]);

        $this->assertSame(['9321076'], $one->additional_part_codes);
        $this->assertSame(['9321079', '9321080'], $two->additional_part_codes);
    }

    public function test_workshop_rejects_third_duplicate_and_main_code_duplicate_additional_codes(): void
    {
        $this->post(route('workshop.quick-part-create.store'), $this->payload(['additional_part_codes' => ['A', 'B', 'C']]))->assertSessionHasErrors('additional_part_codes');
        $this->post(route('workshop.quick-part-create.store'), $this->payload(['additional_part_codes' => ['A', 'A']]))->assertSessionHasErrors('additional_part_codes');
        $this->post(route('workshop.quick-part-create.store'), $this->payload(['part_number' => 'A', 'additional_part_codes' => ['A']]))->assertSessionHasErrors('additional_part_codes');
    }

    public function test_workshop_omits_empty_values_and_trims_whitespace(): void
    {
        $part = $this->storeWorkshopPart(['additional_part_codes' => [' 9321076 ', ' ']]);

        $this->assertSame(['9321076'], $part->additional_part_codes);
    }

    public function test_admin_edit_hides_additional_part_codes_section_for_null_empty_and_blank_values(): void
    {
        $admin = $this->actingAdmin();

        foreach ([null, [], ['']] as $codes) {
            $part = Part::query()->create(['name' => 'Old', 'part_number' => 'MAIN', 'additional_part_codes' => $codes]);

            $this->assertFalse(PartResource::shouldShowAdditionalPartCodesRepeater($part));

            Livewire::actingAs($admin)
                ->test(EditPart::class, ['record' => $part->getKey()])
                ->assertDontSee('Dodatkowe kody części')
                ->assertDontSee('+ Dodaj kod części')
                ->assertSee('Główny kod części');
        }
    }

    public function test_admin_edit_shows_only_saved_additional_part_code_fields(): void
    {
        $admin = $this->actingAdmin();

        $one = Part::query()->create(['name' => 'One', 'part_number' => 'MAIN', 'additional_part_codes' => ['AAA111']]);
        Livewire::actingAs($admin)
            ->test(EditPart::class, ['record' => $one->getKey()])
            ->assertSee('Dodatkowe kody części')
            ->assertSee('+ Dodaj kod części')
            ->assertSee('AAA111')
            ->assertSet('data.additional_part_codes', ['AAA111']);

        $two = Part::query()->create(['name' => 'Two', 'part_number' => 'MAIN', 'additional_part_codes' => ['AAA111', 'BBB222']]);
        Livewire::actingAs($admin)
            ->test(EditPart::class, ['record' => $two->getKey()])
            ->assertSee('Dodatkowe kody części')
            ->assertSee('AAA111')
            ->assertSee('BBB222')
            ->assertDontSee('+ Dodaj kod części')
            ->assertSet('data.additional_part_codes', ['AAA111', 'BBB222']);
    }

    public function test_admin_edit_without_additional_codes_does_not_show_add_button_and_create_hides_section(): void
    {
        $admin = $this->actingAdmin();
        $part = Part::query()->create(['name' => 'Old', 'part_number' => 'MAIN', 'additional_part_codes' => null]);

        Livewire::actingAs($admin)
            ->test(EditPart::class, ['record' => $part->getKey()])
            ->assertDontSee('+ Dodaj kod części')
            ->assertDontSee('Dodatkowe kody części');

        Livewire::actingAs($admin)
            ->test(CreatePart::class)
            ->assertDontSee('+ Dodaj kod części')
            ->assertDontSee('Dodatkowe kody części');
    }

    public function test_admin_edit_with_one_code_can_add_second_but_not_third(): void
    {
        $admin = $this->actingAdmin();
        $part = Part::query()->create(['name' => 'Part', 'part_number' => 'MAIN', 'additional_part_codes' => ['AAA111']]);

        Livewire::actingAs($admin)
            ->test(EditPart::class, ['record' => $part->getKey()])
            ->set('data.additional_part_codes', ['AAA111', 'BBB222'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['AAA111', 'BBB222'], $part->fresh()->additional_part_codes);

        Livewire::actingAs($admin)
            ->test(EditPart::class, ['record' => $part->getKey()])
            ->set('data.additional_part_codes', ['AAA111', 'BBB222', 'CCC333'])
            ->call('save')
            ->assertHasErrors(['additional_part_codes']);

        $this->assertSame(['AAA111', 'BBB222'], $part->fresh()->additional_part_codes);
    }

    public function test_admin_edit_removing_existing_code_persists_and_next_load_uses_old_layout(): void
    {
        $admin = $this->actingAdmin();
        $part = Part::query()->create(['name' => 'Part', 'part_number' => 'MAIN', 'additional_part_codes' => ['AAA111']]);

        Livewire::actingAs($admin)
            ->test(EditPart::class, ['record' => $part->getKey()])
            ->set('data.additional_part_codes', [])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($part->fresh()->additional_part_codes);

        Livewire::actingAs($admin)
            ->test(EditPart::class, ['record' => $part->getKey()])
            ->assertDontSee('Dodatkowe kody części')
            ->assertDontSee('+ Dodaj kod części')
            ->assertSee('Główny kod części');
    }

    public function test_admin_edit_hydrates_adds_removes_and_preserves_legacy_payload(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $part = Part::query()->create(['name' => 'Part', 'part_number' => 'MAIN', 'additional_part_codes' => ['A'], 'legacy_payload' => ['keep' => 'me']]);

        Livewire::test(EditPart::class, ['record' => $part->getKey()])
            ->assertSet('data.additional_part_codes', ['A'])
            ->set('data.additional_part_codes', ['A', 'B'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['A', 'B'], $part->fresh()->additional_part_codes);
        $this->assertSame(['keep' => 'me'], $part->fresh()->legacy_payload);

        Livewire::test(EditPart::class, ['record' => $part->getKey()])
            ->set('data.additional_part_codes', ['B'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['B'], $part->fresh()->additional_part_codes);
    }

    public function test_old_product_with_null_additional_codes_hydrates_without_empty_items(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $part = Part::query()->create(['name' => 'Old', 'part_number' => 'MAIN', 'additional_part_codes' => null]);

        Livewire::test(EditPart::class, ['record' => $part->getKey()])
            ->assertSet('data.additional_part_codes', null);
    }

    public function test_admin_edit_accepts_filament_simple_repeater_array_item_shape_without_marketplace_publish(): void
    {
        $admin = $this->actingAdmin();
        $part = Part::query()->create(['name' => 'Part', 'part_number' => 'MAIN', 'additional_part_codes' => ['AAA111']]);

        Livewire::actingAs($admin)
            ->test(EditPart::class, ['record' => $part->getKey()])
            ->set('data.additional_part_codes', [['code' => ' AAA111 '], ['code' => ' BBB222 ']])
            ->call('save')
            ->assertHasNoErrors();

        $part->refresh();

        $this->assertSame(['AAA111', 'BBB222'], $part->additional_part_codes);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->getKey()]);
        $this->assertDatabaseMissing('marketplace_sync_logs', ['part_id' => $part->getKey()]);
    }

    public function test_admin_edit_saves_part_with_only_main_code_without_marketplace_publish(): void
    {
        $admin = $this->actingAdmin();
        $part = Part::query()->create(['name' => 'Part', 'part_number' => 'MAIN', 'additional_part_codes' => null]);

        Livewire::actingAs($admin)
            ->test(EditPart::class, ['record' => $part->getKey()])
            ->set('data.name', 'Part updated')
            ->call('save')
            ->assertHasNoErrors();

        $part->refresh();

        $this->assertSame('Part updated', $part->name);
        $this->assertNull($part->additional_part_codes);
        $this->assertDatabaseMissing('marketplace_listings', ['part_id' => $part->getKey()]);
        $this->assertDatabaseMissing('marketplace_sync_logs', ['part_id' => $part->getKey()]);
    }

    private function actingAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }

    private function storeWorkshopPart(array $overrides = []): Part
    {
        Storage::fake('public');
        $this->post(route('workshop.quick-part-create.store'), $this->payload($overrides))->assertRedirect();

        return Part::query()->latest('id')->firstOrFail();
    }

    private function payload(array $overrides = []): array
    {
        $location = StorageLocation::query()->first() ?: StorageLocation::query()->create(['name' => 'A1', 'is_active' => true]);

        return array_merge([
            'photos' => [UploadedFile::fake()->image('part.jpg')],
            'storage_location' => $location->name,
            'storage_location_id' => $location->id,
            'part_number' => '9321075',
            'send_email_copy' => '0',
        ], $overrides);
    }
}
