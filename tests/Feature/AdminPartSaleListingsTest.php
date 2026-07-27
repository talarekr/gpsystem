<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\PartResource;
use App\Filament\Resources\PartResource\Pages\ListParts;
use App\Filament\Resources\PartResource\Pages\ListSoldParts;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminPartSaleListingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::query()->create([
            'name' => 'Warehouse User',
            'email' => 'sale-listings@example.test',
            'password' => 'password',
        ]);
        $user->assignRole(UserRole::WarehouseProductStaff->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_active_and_sold_listings_keep_their_canonical_scopes_during_search_and_filters(): void
    {
        $active = $this->part('Wyszukiwana aktywna', 'ready', 1);
        $sold = $this->part('Wyszukiwana sprzedana', 'sold', 0);
        $draft = $this->part('Wyszukiwany szkic', 'draft', 1);

        $activeRows = Livewire::test(ListParts::class)->get('parts');
        $this->assertSame([$active->id], collect($activeRows->items())->pluck('id')->all());

        $soldComponent = Livewire::test(ListSoldParts::class)
            ->set('search', 'Wyszukiwana')
            ->set('missingImages', '1');
        $soldIds = collect($soldComponent->get('parts')->items())->pluck('id')->all();

        $this->assertSame([$sold->id], $soldIds);
        $this->assertNotContains($active->id, $soldIds);
        $this->assertNotContains($draft->id, $soldIds);

        $this->assertSame(1, PartResource::getAllPartsNavigationCount());
        $this->assertSame(1, PartResource::getSoldPartsNavigationCount());
    }

    public function test_sold_listing_reuses_sorting_pagination_actions_and_eager_loading(): void
    {
        $older = $this->part('Starsza sprzedana', 'sold', 0);
        $newer = $this->part('Nowsza sprzedana', 'sold', 0);

        $component = Livewire::test(ListSoldParts::class)
            ->set('sort', 'id_asc')
            ->set('perPage', '1');

        $parts = $component->get('parts');
        $this->assertSame(2, $parts->total());
        $this->assertSame($older->id, $parts->items()[0]->id);
        $this->assertTrue($parts->items()[0]->relationLoaded('images'));
        $this->assertTrue($parts->items()[0]->relationLoaded('marketplaceListings'));

        $component
            ->assertSee(PartResource::getUrl('edit', ['record' => $older]))
            ->call('gotoPage', 2);
        $this->assertSame($newer->id, $component->get('parts')->items()[0]->id);
    }

    public function test_navigation_order_routes_and_legacy_sold_page_stay_intact(): void
    {
        $items = collect(PartResource::getNavigationItems())->sortBy(fn ($item) => $item->getSort())->values();

        $this->assertSame(
            ['Części (0)', 'Do wystawienia (0)', 'Sprzedane (0)', 'Dodaj część', 'Sprzedane części'],
            $items->map(fn ($item) => $item->getLabel())->all(),
        );
        $this->assertSame('/admin/parts/sold-listing', parse_url(PartResource::getUrl('sold-listing'), PHP_URL_PATH));
        $this->assertSame('/admin/parts/create', parse_url(PartResource::getUrl('create'), PHP_URL_PATH));
        $this->assertSame('/admin/parts/sold', parse_url(PartResource::getUrl('sold'), PHP_URL_PATH));

        $this->get(PartResource::getUrl('sold-listing'))->assertOk()->assertSeeLivewire(ListSoldParts::class);
        $this->get(PartResource::getUrl('sold'))->assertOk();
    }

    private function part(string $name, string $status, int $quantity): Part
    {
        return Part::query()->create([
            'name' => $name,
            'status' => $status,
            'quantity' => $quantity,
            'price' => 100,
            'needs_listing' => false,
            'needs_review' => false,
        ]);
    }
}
