<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings\EbaySettings;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncLog;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EbayEncryptedNoteSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const REMOVED_HELPER_TEXT = 'Pole techniczne, szyfrowane w aplikacji. Nie jest używane do logowania do eBay.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        Http::fake();
    }

    public function test_note_is_encrypted_in_settings_and_decrypted_in_the_form(): void
    {
        $plainText = '  tymczasowa notatka  ';

        Livewire::actingAs($this->admin)
            ->test(EbaySettings::class)
            ->set('data.ebay_de.ebay_encrypted_note', $plainText)
            ->call('save')
            ->assertHasNoErrors();

        $account = MarketplaceAccount::query()->where('code', 'ebay_de')->firstOrFail();
        $encrypted = $account->api_settings[EbaySettings::ENCRYPTED_NOTE_KEY] ?? null;
        $rawSettings = (string) DB::table('marketplace_accounts')->where('id', $account->id)->value('api_settings');

        $this->assertIsString($encrypted);
        $this->assertNotSame(trim($plainText), $encrypted);
        $this->assertStringNotContainsString(trim($plainText), $rawSettings);
        $this->assertSame(trim($plainText), Crypt::decryptString($encrypted));

        $form = Livewire::actingAs($this->admin)
            ->test(EbaySettings::class)
            ->assertSet('data.ebay_de.ebay_encrypted_note', trim($plainText));

        $form->assertSee('Hasło')
            ->assertSeeHtml('type="password"')
            ->assertSeeHtml('autocomplete="new-password"')
            ->assertDontSee(self::REMOVED_HELPER_TEXT);
    }

    public function test_note_field_is_a_password_without_helper_text(): void
    {
        Livewire::actingAs($this->admin)
            ->test(EbaySettings::class)
            ->assertSee('Hasło')
            ->assertSeeHtml('type="password"')
            ->assertSeeHtml('autocomplete="new-password"')
            ->assertDontSee(self::REMOVED_HELPER_TEXT);
    }

    public function test_empty_note_removes_setting_and_invalid_ciphertext_is_treated_as_empty(): void
    {
        $account = MarketplaceAccount::query()->create([
            'marketplace' => 'ebay_de',
            'code' => 'ebay_de',
            'name' => 'eBay DE',
            'status' => 'active',
            'api_settings' => [EbaySettings::ENCRYPTED_NOTE_KEY => Crypt::encryptString('do usunięcia')],
        ]);

        Livewire::actingAs($this->admin)
            ->test(EbaySettings::class)
            ->set('data.ebay_de.ebay_encrypted_note', '   ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertArrayNotHasKey(EbaySettings::ENCRYPTED_NOTE_KEY, $account->fresh()->api_settings);

        $account->forceFill(['api_settings' => [EbaySettings::ENCRYPTED_NOTE_KEY => 'invalid-ciphertext']])->save();

        Livewire::actingAs($this->admin)
            ->test(EbaySettings::class)
            ->assertSet('data.ebay_de.ebay_encrypted_note', '');
    }

    public function test_note_is_not_exposed_by_diagnostics_status_coverage_or_sync_logs(): void
    {
        $secret = 'wartosc-nie-do-ujawnienia';
        MarketplaceAccount::query()->create([
            'marketplace' => 'ebay_de',
            'code' => 'ebay_de',
            'name' => 'eBay DE',
            'status' => 'active',
            'api_settings' => [EbaySettings::ENCRYPTED_NOTE_KEY => Crypt::encryptString($secret)],
        ]);

        $responses = [
            $this->getJson('/tools/check-ebay-api-settings?token=gps_images_import_2026'),
            $this->actingAs($this->admin)->getJson('/admin/tools/ebay/listing-status-sync/status'),
            $this->getJson('/tools/check-ebay-category-shipping-coverage'),
        ];

        foreach ($responses as $response) {
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertStringNotContainsString(EbaySettings::ENCRYPTED_NOTE_KEY, $response->getContent());
        }

        $this->assertFalse(MarketplaceSyncLog::query()->where('payload', 'like', '%'.$secret.'%')->exists());
    }
}
