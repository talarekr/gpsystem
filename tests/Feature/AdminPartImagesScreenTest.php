<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\PartResource;
use App\Filament\Resources\PartResource\Pages\EditPart;
use App\Models\Part;
use App\Models\PartImage;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminPartImagesScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_and_edit_show_same_existing_part_images_and_safe_header_actions(): void
    {
        $this->actingAsWarehouseUser();

        $part = Part::query()->create([
            'name' => 'Lampa testowa',
            'slug' => 'lampa-testowa',
            'price' => 123.45,
            'quantity' => 2,
            'is_visible_storefront' => true,
        ]);

        PartImage::query()->create(['part_id' => $part->id, 'path' => 'parts/photos/first.jpg', 'sort_order' => 0, 'alt_text' => 'Pierwsze zdjęcie']);
        PartImage::query()->create(['part_id' => $part->id, 'path' => 'parts/photos/second.jpg', 'sort_order' => 1, 'alt_text' => 'Drugie zdjęcie']);

        $viewHtml = $this->get(PartResource::getUrl('view', ['record' => $part]))
            ->assertOk()
            ->assertSee('parts/photos/first.jpg')
            ->assertSee('parts/photos/second.jpg')
            ->assertDontSee('Przetwórz zdjęcia produktu')
            ->getContent();

        $editHtml = $this->get(PartResource::getUrl('edit', ['record' => $part]))
            ->assertOk()
            ->assertSee('parts/photos/first.jpg')
            ->assertSee('parts/photos/second.jpg')
            ->assertDontSee('Przetwórz zdjęcia produktu')
            ->assertSee(route('storefront.product', 'lampa-testowa'), false)
            ->assertSee('target="_blank"', false)
            ->getContent();

        $this->assertSame(substr_count($viewHtml, 'parts/photos/'), substr_count($editHtml, 'parts/photos/'));

        Livewire::test(EditPart::class, ['record' => $part->getRouteKey()])
            ->fillForm(['name' => 'Lampa testowa po edycji'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            ['parts/photos/first.jpg', 'parts/photos/second.jpg'],
            $part->fresh()->images()->orderBy('sort_order')->pluck('path')->all(),
        );
    }

    public function test_storefront_preview_is_hidden_when_part_has_no_public_slug(): void
    {
        $this->actingAsWarehouseUser();

        $part = Part::query()->create(['name' => 'Część bez slug', 'quantity' => 1]);

        $this->get(PartResource::getUrl('edit', ['record' => $part]))
            ->assertOk()
            ->assertDontSee('Przetwórz zdjęcia produktu')
            ->assertDontSee('Podgląd');
    }

    private function actingAsWarehouseUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::query()->create([
            'name' => 'Warehouse User',
            'email' => 'warehouse-admin-part-images@example.test',
            'password' => 'password',
        ]);

        $user->assignRole(UserRole::WarehouseProductStaff->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }
}
