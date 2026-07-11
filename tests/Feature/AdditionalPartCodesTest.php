<?php

namespace Tests\Feature;

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
