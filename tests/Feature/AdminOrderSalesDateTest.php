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

class AdminOrderSalesDateTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $number, ?string $marketplace, ?string $orderedAt, string $createdAt): Order
    {
        $order = Order::query()->create([
            'order_number' => $number, 'marketplace' => $marketplace, 'status' => 'processing',
            'currency' => 'PLN', 'subtotal' => 1300, 'shipping_total' => 0, 'total' => 1300,
            'customer_name' => 'Test klient', 'email' => 'fixture@example.test', 'phone' => '123456789',
            'address_line1' => 'Test 1', 'postal_code' => '00-001', 'city' => 'Warszawa', 'country' => 'PL',
            'ordered_at' => $orderedAt,
            'meta' => match ($marketplace) {
                null => ['source' => 'storefront', 'storefront_code' => 'gpswiss_pl', 'payment_provider' => 'payu'],
                'payu' => ['source' => 'storefront', 'payu' => ['provider' => 'payu', 'request' => ['continueUrl' => 'https://gpswiss.pl/checkout/return?order=698']]],
                default => [],
            },
        ]);
        $order->created_at = $createdAt;
        $order->save();

        return $order;
    }

    public static function shopChannels(): array
    {
        return ['current PL checkout' => [null], 'legacy PayU like 698' => ['payu']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('shopChannels')]
    public function test_all_shows_1246_shop_order_before_1225_allegro_with_pagination(?string $channel): void
    {
        $this->actingAsAdminUser();
        $shop = $this->order('GPS-2026-000006', $channel, null, '2026-10-09 10:46:00');
        $allegro = $this->order('MP-1225', 'allegro', '2026-10-09 12:25:00', '2026-10-09 14:00:00');
        foreach (range(1, 12) as $i) {
            $this->order('OLD-'.$i, 'allegro', '2026-10-08 12:00:00', '2026-10-09 15:00:00');
        }
        $component = Livewire::withQueryParams(['marketplace' => '', 'sort' => 'desc', 'per_page' => 10])->test(ListOrders::class);
        $page = $component->instance()->getOrdersProperty();
        $this->assertSame([$shop->id, $allegro->id], $page->pluck('id')->take(2)->all());
        $this->assertSame(14, $page->total());
        $this->assertSame(2, $page->lastPage());
        $this->assertSame(14, \App\Filament\Resources\OrderResource::getAllOrdersNavigationCount());
        $component->assertSeeInOrder(['GPS-2026-000006', '2026-10-09 12:46', 'MP-1225', '2026-10-09 12:25']);
        $component->set('marketplace', 'sklep');
        $this->assertSame([$shop->id], $component->instance()->getOrdersProperty()->pluck('id')->all());
        $component->set('marketplace', '')->set('sortDirection', 'asc');
        $this->assertNotContains($shop->id, $component->instance()->getOrdersProperty()->pluck('id')->all());
        $component->call('gotoPage', 2);
        $this->assertSame($shop->id, $component->instance()->getOrdersProperty()->last()->id);
        $component->set('marketplace', 'sklep');
        $this->assertSame(1, $component->instance()->getOrdersProperty()->currentPage());
        $this->assertSame([$shop->id], $component->instance()->getOrdersProperty()->pluck('id')->all());
    }

    public function test_marketplace_filters_still_isolate_their_orders(): void
    {
        $this->actingAsAdminUser();
        $this->order('SHOP', null, null, '2026-10-09 10:46:00');
        foreach (['allegro', 'ebay', 'ovoko'] as $marketplace) {
            $external = $this->order($marketplace, $marketplace, '2026-10-09 12:25:00', '2026-10-09 14:00:00');
            $component = Livewire::withQueryParams(['marketplace' => $marketplace])->test(ListOrders::class);
            $this->assertSame([$external->id], $component->instance()->getOrdersProperty()->pluck('id')->all());
        }
    }

    public function test_fallback_handles_winter_and_dst_boundaries_and_preserves_ordered_at(): void
    {
        $this->actingAsAdminUser();
        foreach ([
            ['2026-01-09 11:46:00', '2026-01-09 12:46:00'],
            ['2026-03-29 00:46:00', '2026-03-29 01:46:00'],
            ['2026-03-29 01:46:00', '2026-03-29 03:46:00'],
            ['2026-10-25 00:46:00', '2026-10-25 02:46:00'],
            ['2026-10-25 01:46:00', '2026-10-25 02:46:00'],
        ] as $i => [$utc, $local]) {
            Order::query()->delete();
            $shop = $this->order('SHOP-'.$i, null, null, $utc);
            $external = $this->order('EXTERNAL-'.$i, 'allegro', \Illuminate\Support\Carbon::parse($local)->subMinutes(21)->format('Y-m-d H:i:s'), '2026-12-31 23:00:00');
            $component = Livewire::test(ListOrders::class);
            $this->assertSame([$shop->id, $external->id], $component->instance()->getOrdersProperty()->pluck('id')->all());
            $component->assertSee(substr($local, 0, 16));
        }
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
