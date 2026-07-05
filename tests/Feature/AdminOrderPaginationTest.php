<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminOrderPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_orders_default_to_thirty_per_page_without_requested_limit(): void
    {
        $this->actingAsAdminUser();

        $component = Livewire::withQueryParams(['status' => 'new'])
            ->test(ListOrders::class);

        $component->assertSet('status', 'new')
            ->assertSet('perPage', 30);
    }

    public function test_new_orders_respect_requested_per_page(): void
    {
        $this->actingAsAdminUser();

        $component = Livewire::withQueryParams(['status' => 'new', 'per_page' => 50])
            ->test(ListOrders::class);

        $component->assertSet('status', 'new')
            ->assertSet('perPage', 50);
    }

    public function test_orders_pagination_summary_is_rendered_in_polish(): void
    {
        $this->actingAsAdminUser();

        foreach (range(1, 14) as $number) {
            Order::query()->create([
                'order_number' => 'ORD-'.$number,
                'marketplace' => 'allegro',
                'marketplace_order_id' => 'MP-'.$number,
                'status' => 'new',
                'currency' => 'PLN',
                'subtotal' => 100,
                'shipping_total' => 10,
                'total' => 110,
                'customer_name' => 'Klient '.$number,
                'email' => 'client'.$number.'@example.test',
                'phone' => '123456789',
                'address_line1' => 'Ulica 1',
                'postal_code' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
                'ordered_at' => now()->subMinutes($number),
            ]);
        }

        Livewire::withQueryParams(['per_page' => 10])
            ->test(ListOrders::class)
            ->assertSee('Wyświetlono')
            ->assertSee('10')
            ->assertSee('14')
            ->assertDontSee('Showing 1 to 10 of 14 results');
    }

    private function actingAsAdminUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::query()->create([
            'name' => 'Owner Admin',
            'email' => 'owner'.uniqid().'@example.test',
            'password' => 'password',
        ]);

        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }
}
