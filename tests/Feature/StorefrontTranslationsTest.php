<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\PartResource\Pages\EditPart;
use App\Models\CategoryTranslation;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StorefrontTranslationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['frontend-maintenance.enabled' => false]);
    }

    private function fixture(?string $status): Part
    {
        $category = PartCategory::create(['name' => 'Silniki PL', 'slug' => 'silniki-pl', 'full_slug_path' => 'silniki-pl', 'description' => 'Opis kategorii PL', 'is_visible' => true]);
        $part = Part::create(['name' => 'Silnik PL', 'slug' => 'silnik-pl', 'sku' => 'TEST-FR', 'category_id' => $category->id,
            'short_description' => 'Krótki opis PL', 'description' => 'Pełny opis PL', 'condition_notes' => 'Stan PL',
            'status' => 'ready', 'price' => 100, 'currency' => 'PLN', 'quantity' => 2, 'needs_listing' => false, 'needs_review' => false]);
        if ($status !== null) {
            $part->translations()->create(['locale' => 'fr', 'status' => $status, 'name' => 'Moteur FR',
                'short_description' => 'Résumé FR', 'description' => 'Description FR', 'condition_notes' => 'État FR', 'source_hash' => $part->translationSourceHash()]);
            $category->translations()->create(['locale' => 'fr', 'status' => $status, 'name' => 'Moteurs FR', 'description' => 'Catégorie FR', 'source_hash' => $category->translationSourceHash()]);
        }

        return $part;
    }

    public static function statuses(): array
    {
        return [['translated', true], ['reviewed', true], [null, false], ['missing', false], ['queued', false], ['failed', false], ['needs_update', false]];
    }

    #[DataProvider('statuses')]
    public function test_fr_reads_only_ready_translations_and_preserves_urls(?string $status, bool $ready): void
    {
        $part = $this->fixture($status);
        $name = $ready ? 'Moteur FR' : 'Silnik PL';
        $categoryName = $ready ? 'Moteurs FR' : 'Silniki PL';
        $this->get('https://gpswiss.fr/czesci')->assertOk()->assertSee($name)->assertSee('/produkt/silnik-pl');
        $this->get('https://gpswiss.fr/produkt/silnik-pl')->assertOk()
            ->assertSee($name)->assertSee($categoryName)->assertSee($ready ? 'Description FR' : 'Pełny opis PL')
            ->assertSee($ready ? 'État FR' : 'Stan PL')
            ->assertViewHas('metaTitle', $name.' - GPSwiss')
            ->assertViewHas('metaDescription', $ready ? 'Résumé FR' : 'Krótki opis PL');
        $this->get('https://gpswiss.fr/kategoria-produktu/silniki-pl')->assertOk()->assertSee($categoryName)
            ->assertViewHas('metaTitle', $categoryName.' - GPSwiss')
            ->assertSee($ready ? 'Catégorie FR' : 'Opis kategorii PL');
        $this->assertSame('Silnik PL', $part->fresh()->name);
    }

    public function test_pl_and_raw_admin_marketplace_fields_remain_base_even_with_fr_session(): void
    {
        $part = $this->fixture('reviewed');
        $original = $part->fresh()->getRawOriginal();
        $this->withSession(['locale' => 'fr'])->get('https://gpswiss.pl/czesci')->assertOk()->assertSee('Silnik PL')->assertDontSee('Moteur FR');
        $this->get('https://gpswiss.pl/produkt/silnik-pl')->assertOk()->assertSee('Pełny opis PL')->assertDontSee('Description FR');
        $this->get('https://gpswiss.pl/kategoria-produktu/silniki-pl')->assertOk()->assertSee('Silniki PL')->assertDontSee('Moteurs FR');
        app()->setLocale('fr');
        $raw = $part->fresh()->load('translations');
        $this->assertSame('Silnik PL', $raw->name);
        $this->assertSame('Pełny opis PL', $raw->toArray()['description']);
        $this->assertSame('Silniki PL', $raw->category->public_name);
        $this->assertSame($original, $raw->getRawOriginal());
    }

    public function test_admin_on_fr_host_still_renders_base_product_fields(): void
    {
        $part = $this->fixture('reviewed');
        $this->seed(RoleSeeder::class);
        $user = User::create(['name' => 'Warehouse', 'email' => 'fr-admin@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::WarehouseProductStaff->value);
        $this->actingAs($user)->withSession(['locale' => 'fr'])
            ->get('https://gpswiss.fr/admin/parts/'.$part->id.'/edit')
            ->assertOk()->assertSee('Silnik PL')
            ->assertDontSee('Moteur FR')->assertDontSee('Description FR');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(EditPart::class, ['record' => $part->getRouteKey()])
            ->assertFormSet(['name' => 'Silnik PL', 'description' => 'Pełny opis PL']);
        Http::assertNothingSent();
    }

    public function test_empty_fields_fall_back_individually_and_descriptions_are_cleaned(): void
    {
        $part = $this->fixture('translated');
        $part->translations()->update(['name' => ' ', 'description' => null, 'short_description' => 'Résumé FR', 'condition_notes' => null]);
        $part = $part->fresh();
        $this->assertSame('Silnik PL', $part->storefrontNameForLocale('fr'));
        $this->assertSame('Pełny opis PL', $part->storefrontDescriptionForLocale('fr'));
        $this->assertSame('Résumé FR', $part->storefrontShortDescriptionForLocale('fr'));
        $this->assertSame('Stan PL', $part->storefrontConditionNotesForLocale('fr'));
        $part->translations()->first()->update(['description' => '<b>Description FR</b>']);
        $this->assertSame('Description FR', $part->fresh()->storefrontDescriptionForLocale('fr'));
        $part->category->translations()->update(['name' => null, 'description' => '']);
        $category = $part->category->fresh();
        $this->assertSame('Silniki PL', $category->storefrontNameForLocale('fr'));
        $this->assertSame('Opis kategorii PL', $category->storefrontDescriptionForLocale('fr'));
    }

    public function test_missing_schema_still_renders_fr_with_base_data(): void
    {
        $this->fixture(null);
        Schema::drop('part_translations');
        Schema::drop('category_translations');
        $this->get('https://gpswiss.fr/czesci')->assertOk()->assertSee('Silnik PL');
        $this->get('https://gpswiss.fr/produkt/silnik-pl')->assertOk()->assertSee('Pełny opis PL');
    }

    public static function uniqueTypes(): array
    {
        return [['part'], ['category']];
    }

    #[DataProvider('uniqueTypes')]
    public function test_only_one_translation_per_record_and_locale(string $type): void
    {
        $part = $this->fixture('translated');
        $model = $type === 'part' ? $part : $part->category;
        $model->translations()->create(['locale' => 'de']);
        $this->assertSame(2, $model->translations()->count());
        $this->expectException(QueryException::class);
        $model->translations()->create(['locale' => 'fr']);
    }

    public function test_source_hash_depends_only_on_translated_source_fields(): void
    {
        $part = $this->fixture(null);
        $hash = $part->translationSourceHash();
        foreach (['name', 'short_description', 'description', 'condition_notes'] as $field) {
            $copy = clone $part;
            $copy->$field = 'Changed';
            $this->assertNotSame($hash, $copy->translationSourceHash());
        }
        $part->forceFill(['price' => 250, 'quantity' => 9, 'sku' => 'NEW', 'status' => 'sold', 'marketplace_status' => 'changed']);
        $part->setRelation('images', collect(['changed']));
        $this->assertSame($hash, $part->translationSourceHash());
        $category = $part->category;
        $categoryHash = $category->translationSourceHash();
        foreach (['name', 'description'] as $field) {
            $copy = clone $category;
            $copy->$field = 'Changed';
            $this->assertNotSame($categoryHash, $copy->translationSourceHash());
        }
        $category->slug = 'new';
        $this->assertSame($categoryHash, $category->translationSourceHash());
    }

    public function test_error_messages_are_sanitized_and_dates_cast(): void
    {
        $translation = new CategoryTranslation(['error_message' => '<b>Error</b> Bearer secret123 api_key=abc token=def password=ghi', 'translated_at' => '2026-10-07', 'attempts' => 2]);
        foreach (['secret123', 'abc', 'def', 'ghi', '<b>'] as $secret) {
            $this->assertStringNotContainsString($secret, $translation->error_message);
        }
        $this->assertSame('2026-10-07', $translation->translated_at->toDateString());
        $this->assertSame(2, $translation->attempts);
    }
}
