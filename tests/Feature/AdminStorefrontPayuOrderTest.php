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

class AdminStorefrontPayuOrderTest extends TestCase
{
    use RefreshDatabase;

    private function storefrontOrder(bool $legacy = false): Order
    {
        return Order::query()->create([
            'order_number' => 'GPS-REGRESSION-698'.($legacy ? '-legacy' : ''), 'marketplace' => $legacy ? 'payu' : null,
            'marketplace_order_id' => $legacy ? 'PAYU-698' : null,
            'status' => 'new', 'currency' => 'PLN', 'subtotal' => 1300,
            'shipping_total' => 0, 'total' => 1300, 'customer_name' => 'Test klient',
            'email' => 'fixture@example.test', 'phone' => '123456789',
            'address_line1' => 'Test 1', 'postal_code' => '00-001', 'city' => 'Warszawa',
            'country' => 'PL', 'payment_status' => 'pending',
            'meta' => ['source' => 'storefront', 'payu' => ['provider' => 'payu', 'order_id' => 'PAYU-698', 'request' => ['continueUrl' => 'https://gpswiss.pl/checkout/return?order=698']],
                ...($legacy ? [] : ['storefront_code' => 'gpswiss_pl', 'payment_provider' => 'payu'])],
        ]);
    }

    public function test_polish_checkout_stores_channel_separately_from_payu(): void
    {
        $cart = $this->mock(\App\Services\Storefront\CartService::class);
        $cart->shouldReceive('items')->once()->andReturn(collect([[
            'is_available' => true, 'name' => 'MERCEDES W177 fixture', 'currency' => 'PLN',
            'unit_price' => 1300, 'quantity' => 1, 'line_total' => 1300,
        ]]));
        $cart->shouldReceive('clear')->once();
        $payu = $this->mock(\App\Services\Payments\PayuService::class);
        $payu->shouldReceive('createOrder')->once()->andReturn([
            'response' => ['orderId' => 'PAYU-698', 'status' => ['statusCode' => 'SUCCESS']],
            'payload' => ['extOrderId' => 'EXT-698'], 'redirectUri' => 'https://secure.payu.com/test',
        ]);
        $request = \Illuminate\Http\Request::create('https://gpswiss.pl/checkout', 'POST', [
            'customer_type' => 'private', 'billing_first_name' => 'Test', 'billing_last_name' => 'Klient',
            'billing_street' => 'Test', 'billing_building_number' => '1', 'billing_postal_code' => '00-001',
            'billing_city' => 'Warszawa', 'billing_phone' => '123456789', 'billing_email' => 'fixture@example.test',
            'shipping_same_as_billing' => 1, 'shipping_method' => 'courier', 'payment_method' => 'payu', 'terms' => '1',
        ]);
        $request->setUserResolver(fn () => null);
        app(\App\Http\Controllers\Storefront\CheckoutController::class)->store($request);
        $order = Order::query()->sole();
        $this->assertNull($order->marketplace);
        $this->assertNull($order->marketplace_order_id);
        $this->assertSame('sklep', $order->salesChannel());
        $this->assertSame('gpswiss_pl', data_get($order->meta, 'storefront_code'));
        $this->assertSame('payu', data_get($order->meta, 'payment_provider'));
        $this->assertSame('PAYU-698', data_get($order->meta, 'payu.order_id'));
        $this->assertSame(1, $order->items()->count());
    }

    public function test_payu_notify_keeps_payment_state_out_of_marketplace_fields(): void
    {
        $order = $this->storefrontOrder();
        $payu = $this->mock(\App\Services\Payments\PayuService::class);
        $payu->shouldReceive('verifySignature')->once()->andReturnTrue();
        $request = \Illuminate\Http\Request::create('/payments/payu/notify', 'POST', [], [], [], [],
            json_encode(['order' => ['orderId' => 'PAYU-698', 'status' => 'COMPLETED']]));
        app(\App\Http\Controllers\Payments\PayuNotifyController::class)->__invoke($request, $payu);
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('processing', $order->status);
        $this->assertNull($order->marketplace);
        $this->assertNull($order->marketplace_status);
        $this->assertSame('COMPLETED', data_get($order->meta, 'payu.status'));
    }

    public function test_all_new_and_shop_filters_include_current_and_legacy_payu_orders(): void
    {
        $this->actingAsAdminUser();
        $current = $this->storefrontOrder();
        $legacy = $this->storefrontOrder(true);
        foreach ([[], ['status' => 'new'], ['marketplace' => 'sklep'], ['status' => 'new', 'marketplace' => 'sklep']] as $filters) {
            $component = Livewire::withQueryParams($filters)->test(ListOrders::class);
            $ids = $component->instance()->getOrdersProperty()->pluck('id')->all();
            $this->assertContains($current->id, $ids);
            $this->assertContains($legacy->id, $ids);
            $component->assertSee('GPS-REGRESSION-698')->assertSee('Sklep')->assertSee('PayU');
        }
        $this->assertSame(2, \App\Filament\Resources\OrderResource::getAllOrdersNavigationCount());
        $this->assertSame(2, \App\Filament\Resources\OrderResource::getNewOrdersNavigationCount());
    }

    public function test_details_and_sold_parts_keep_the_storefront_sale(): void
    {
        $this->actingAsAdminUser();
        $order = $this->storefrontOrder(true);
        $order->items()->create(['product_name' => 'MERCEDES W177 AMG A35 fixture', 'quantity' => 1,
            'unit_price' => 1300, 'line_total' => 1300]);
        $this->get(\App\Filament\Resources\OrderResource::getUrl('view', ['record' => $order]))
            ->assertOk()->assertSee('Sklep')->assertSee('PayU');
        $component = Livewire::test(\App\Filament\Resources\PartResource\Pages\SoldParts::class);
        $row = collect($component->instance()->getSoldPartsProperty()->items())->firstWhere('type', 'order_item');
        $this->assertSame('sklep', $row['source']);
        $this->assertEquals(1300, $row['price']);
    }

    public function test_marketplace_filters_are_unchanged_and_do_not_include_payu(): void
    {
        $this->actingAsAdminUser();
        $order = $this->storefrontOrder();
        $this->assertNull($order->marketplace);
        $this->assertSame('payu', $order->paymentProvider());
        foreach (['allegro', 'ebay', 'ovoko'] as $marketplace) {
            $external = $order->replicate();
            $external->marketplace = $marketplace;
            $external->order_number = 'EXT-'.$marketplace;
            $external->meta = [];
            $external->save();
            $component = Livewire::withQueryParams(['marketplace' => $marketplace])->test(ListOrders::class);
            $this->assertSame([$external->id], $component->instance()->getOrdersProperty()->pluck('id')->all());
        }
        $this->assertSame([$order->id], Order::query()->storefront()->pluck('id')->all());
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
