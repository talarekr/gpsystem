<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Car;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Models\PartImage;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AllegroDescriptionUpdateToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_builds_payload_without_api_write(): void
    {
        Http::fake();
        $part = $this->readyPart();
        $this->actingAsAdminUser();

        $this->getJson('/admin/tools/allegro/offers/description-update-dry-run?part_id='.$part->id)
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('part_id', $part->id)
            ->assertJsonPath('offer_id', 'offer-123')
            ->assertJsonPath('description_source', 'allegro_gp_swiss_template')
            ->assertJsonPath('description_template', 'text_image_50_50')
            ->assertJsonPath('main_image_url', 'https://gpswiss.pl/storage/parts/main.jpg')
            ->assertJsonPath('blockers', []);

        Http::assertNothingSent();
    }

    public function test_apply_requires_confirm(): void
    {
        $part = $this->readyPart();
        $this->actingAsAdminUser();

        $this->getJson('/admin/tools/allegro/offers/description-update-apply?part_id='.$part->id)
            ->assertStatus(422)
            ->assertJsonPath('applied', false);
    }

    public function test_apply_sends_only_description_and_logs_metadata(): void
    {
        $part = $this->readyPart();
        $this->actingAsAdminUser();
        Http::fake(['https://api.allegro.test/sale/product-offers/offer-123' => Http::response(['id' => 'offer-123'], 200)]);

        $this->getJson('/admin/tools/allegro/offers/description-update-apply?part_id='.$part->id.'&confirm=allegro-description-update')
            ->assertOk()
            ->assertJsonPath('applied', true);

        Http::assertSent(function ($request): bool {
            $data = $request->data();
            return $request->method() === 'PATCH'
                && $request->url() === 'https://api.allegro.test/sale/product-offers/offer-123'
                && array_keys($data) === ['description']
                && is_array($data['description']);
        });

        $log = MarketplaceSyncLog::query()->where('action', 'allegro_description_update')->firstOrFail();
        $this->assertSame('offer-123', $log->payload['offer_id']);
        $this->assertSame($part->id, $log->payload['part_id']);
        $this->assertSame('allegro_gp_swiss_template', $log->payload['description_source']);
        $this->assertSame('text_image_50_50', $log->payload['description_template']);
    }

    /** @dataProvider optionalVehicleFieldCases */
    public function test_missing_optional_vehicle_field_does_not_block_update(string $field, string $label): void
    {
        $part = $this->readyPart();
        $part->car->forceFill([$field => null])->save();
        $this->actingAsAdminUser();

        $this->getJson('/admin/tools/allegro/offers/description-update-dry-run?part_id='.$part->id)
            ->assertOk()
            ->assertJsonMissing(['missing_donor_vehicle_field:'.$label])
            ->assertJsonPath('vehicle_diagnostics.description_sections_count', 1)
            ->assertJsonPath('vehicle_diagnostics.optional_donor_vehicle_fields_missing', [$label])
            ->assertJsonPath('blockers', []);
    }

    public static function optionalVehicleFieldCases(): array
    {
        return [
            ['engine_code', 'Oznaczenie silnika'],
            ['engine_power_kw', 'Moc silnika'],
            ['production_year', 'Rok'],
        ];
    }

    private function readyPart(?int $enginePower = 110): Part
    {
        $account = MarketplaceAccount::query()->create(['code' => 'allegro_main', 'marketplace' => 'allegro', 'name' => 'Allegro', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.test', 'api_credentials' => ['access_token' => 'token']]);
        $car = Car::query()->create(['make' => 'AUDI', 'model' => 'A4', 'production_year' => 2018, 'engine_code' => 'CJSA', 'engine_power_kw' => $enginePower]);
        $part = Part::query()->create(['name' => 'Sterownik silnika', 'category_id' => 77, 'price' => 100, 'quantity' => 1, 'description' => 'Sterownik silnika 1.8 TFSI', 'car_id' => $car->id, 'is_visible_storefront' => true]);
        PartImage::query()->create(['part_id' => $part->id, 'path' => 'parts/main.jpg', 'is_primary' => true, 'sort_order' => 1]);
        MarketplaceListing::query()->create(['marketplace' => 'allegro', 'marketplace_account_id' => $account->id, 'part_id' => $part->id, 'external_offer_id' => 'offer-123', 'raw_payload' => ['description' => ['sections' => [['items' => [['type' => 'TEXT', 'content' => '<p>Old description</p>']]]]]]]);

        return $part;
    }

    private function actingAsAdminUser(): void
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::query()->create(['name' => 'Owner Admin', 'email' => 'owner'.uniqid().'@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }
}
