<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\JarekFrProduct;
use App\Models\JarekGearbox;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartImage;
use App\Models\User;
use App\Services\Images\JarekImagePresentation;
use App\Services\Images\PartImagePresentationService;
use App\Services\Storefront\FrenchMixedCatalog;
use App\Services\Storefront\JarekCatalogProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FrenchCatalogPolishTest extends TestCase
{
    use RefreshDatabase;

    private JarekFrProduct $product;

    private PartCategory $leaf;

    private const IMAGES = 'https://gpswiss.pl/admin/tools/storefront/fr-translations/jarek-local-images';

    private const DIAGNOSTICS = 'https://gpswiss.pl/admin/tools/storefront/fr-translations/category-diagnostics';

    protected function setUp(): void
    {
        parent::setUp();
        config(['frontend-maintenance.enabled' => false]);
        Http::preventStrayRequests();
        Storage::fake('public');
        Cache::put('nbp_table_a_rate:EUR:latest', ['ok' => true, 'currency' => 'EUR', 'source' => 'NBP_TABLE_A',
            'rate' => 4, 'effective_date' => today()->toDateString(), 'table_no' => 'fixture'], 3600);
        $root = PartCategory::create(['name' => 'Układ napędowy', 'slug' => 'uklad-napedowy', 'full_slug_path' => 'uklad-napedowy', 'source_system' => 'woo', 'is_visible' => true]);
        $branch = PartCategory::create(['name' => 'Skrzynie biegów i inne elementy', 'slug' => 'skrzynie-biegow-i-inne-elementy', 'full_slug_path' => $root->slug.'/skrzynie-biegow-i-inne-elementy', 'parent_id' => $root->id, 'source_system' => 'woo', 'is_visible' => true]);
        $this->leaf = PartCategory::create(['name' => 'Manualna 6-biegowa skrzynia biegów', 'slug' => 'manualna-6-biegowa-skrzynia-biegow', 'full_slug_path' => FrenchMixedCatalog::CATEGORY_PATH, 'parent_id' => $branch->id, 'source_system' => 'woo', 'is_visible' => true]);
        foreach ([$root, $branch, $this->leaf] as $i => $category) {
            $category->translations()->create(['locale' => 'fr', 'name' => ['Transmission', 'Boîtes de vitesses et accessoires', 'Boîte manuelle à 6 rapports'][$i], 'status' => 'reviewed']);
        }
        $gearbox = JarekGearbox::create(['allegro_offer_id' => '17413813163', 'title' => 'Boîte fournisseur fixture', 'price' => 1000, 'currency' => 'PLN', 'quantity' => 0, 'main_image_url' => 'https://remote.test/original.jpg']);
        $this->product = JarekFrProduct::create(['jarek_gearbox_id' => $gearbox->id, 'is_active' => true]);
    }

    public function test_technical_supplier_sku_is_hidden_on_all_visible_fr_product_surfaces_but_kept_internally(): void
    {
        foreach (['/czesci', '/kategoria-produktu/'.FrenchMixedCatalog::CATEGORY_PATH, '/boites-jarek/'.$this->product->id, '/'] as $path) {
            $this->get('https://gpswiss.fr'.$path)->assertOk()->assertSee('Boîte fournisseur fixture')->assertDontSee('JAREK-17413813163');
        }
        $this->post('https://gpswiss.fr/boites-jarek/'.$this->product->id.'/panier')->assertRedirect();
        $this->get('https://gpswiss.fr/panier')->assertOk()->assertDontSee('JAREK-17413813163');
        $this->assertSame('JAREK-17413813163', session('storefront.cart.items')['jarek:'.$this->product->id]['sku']);
        $part = Part::create(['name' => 'Normal fixture', 'slug' => 'normal-fixture', 'sku' => 'NORMAL-OEM-123', 'price' => 100, 'currency' => 'EUR', 'quantity' => 1, 'status' => 'ready']);
        $this->get('https://gpswiss.fr/czesci')->assertOk()->assertSee('NORMAL-OEM-123');
        $this->get('https://gpswiss.pl/produkt/'.$part->slug)->assertOk()->assertSee('NORMAL-OEM-123');
        Http::assertNothingSent();
    }

    public function test_fr_home_gearbox_section_uses_manual_category_and_normal_carousel_while_pl_keeps_automatic(): void
    {
        $response = $this->get('https://gpswiss.fr/')->assertOk()->assertSee('/kategoria-produktu/'.FrenchMixedCatalog::CATEGORY_PATH)
            ->assertSee('sf-product-carousel', false)->assertSee('sf-product-card__image', false)->assertDontSee('Boîtes de vitesses — Jarek');
        $this->assertCount(1, $response->viewData('sections')['Boîtes de vitesses']);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $links = (new \DOMXPath($dom))->query('//section[contains(@class,"sf-product-carousel")][.//h2[text()="Boîtes de vitesses"]]//div[contains(@class,"sf-section__head")]/a');
        $this->assertSame('https://gpswiss.fr/kategoria-produktu/'.FrenchMixedCatalog::CATEGORY_PATH, $links->item(0)->getAttribute('href'));
        $this->get('https://gpswiss.pl/')->assertOk()->assertSee('/kategoria-produktu/uklad-napedowy/skrzynie-biegow-i-inne-elementy/automatyczna-skrzynia-biegow')->assertDontSee('Boîte fournisseur fixture');
        Http::assertNothingSent();
    }

    public function test_home_limits_combined_gearbox_sources_to_eight(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $source = JarekGearbox::create(['allegro_offer_id' => 'home-'.$i, 'title' => 'Home gearbox '.$i, 'price' => 1000, 'currency' => 'PLN', 'main_image_url' => 'https://remote.test/'.$i.'.jpg']);
            JarekFrProduct::create(['jarek_gearbox_id' => $source->id, 'is_active' => true]);
        }
        $response = $this->get('https://gpswiss.fr/?q=should-not-filter-home')->assertOk();
        $this->assertCount(8, $response->viewData('sections')['Boîtes de vitesses']);
        Http::assertNothingSent();
    }

    public function test_local_derivatives_use_identical_pixel_processor_and_canvas_as_normal_images(): void
    {
        $this->localImage();
        $before = $this->product->gearbox->fresh()->getAttributes();
        $normal = new PartImage(['path' => 'jarek-gearboxes/17413813163/01.jpg']);
        $normal->id = 999;
        $expected = app(PartImagePresentationService::class)->process($normal)['presentation'];
        $result = app(JarekImagePresentation::class)->batch(0, 0, true);
        $this->assertSame('prepared', $result['results'][0]['status']);
        $actual = app(JarekImagePresentation::class)->variants('https://gpswiss.pl/storage/jarek-gearboxes/17413813163/01.jpg');
        $this->assertTrue($actual['ready']);
        foreach (['listing', 'product'] as $kind) {
            $path = $this->relative($actual[$kind]);
            $file = Storage::disk('public')->path($path);
            $this->assertSame(hash_file('sha256', Storage::disk('public')->path($expected[$kind.'_path'])), hash_file('sha256', $file));
            $dimensions = getimagesize($file);
            $this->assertSame($kind === 'listing' ? [522, 336] : [900, 675], [$dimensions[0], $dimensions[1]]);
        }
        $this->assertGreaterThan(0.8, $expected['listing_fill_width_ratio']);
        $this->get('https://gpswiss.fr/czesci')->assertOk()->assertSee('parts/photos/presentation/listing/jarek-', false)->assertSee('sf-product-card__image', false);
        $this->get('https://gpswiss.fr/boites-jarek/'.$this->product->id)->assertOk()->assertViewIs('storefront.parts.show')->assertSee('parts/photos/presentation/product/jarek-', false)->assertSee('data-product-gallery', false);
        $this->assertSame($before, $this->product->gearbox->fresh()->getAttributes());
        $this->assertDatabaseCount('parts', 0);
        $this->assertDatabaseCount('part_images', 0);
        Http::assertNothingSent();
    }

    public function test_supplier_and_normal_products_share_best_listing_photo_ranking(): void
    {
        $this->localImage(1, true);
        $this->localImage(2);
        $images = app(JarekImagePresentation::class);
        $images->batch(0, 0, true);
        $normalImages = collect();
        foreach ([1, 2] as $index) {
            $image = new PartImage(['path' => 'jarek-gearboxes/17413813163/0'.$index.'.jpg', 'is_primary' => $index === 1, 'sort_order' => $index - 1]);
            $image->id = $index;
            $image->legacy_payload = app(PartImagePresentationService::class)->process($image);
            $normalImages->push($image);
        }
        // The normal method and supplier adapter call the same selector and use the same stored scores.
        $normal = new Part;
        $normal->setRelation('images', $normalImages);
        $adapter = new JarekCatalogProduct($this->product->fresh('gearbox'));
        $this->assertSame(1, $adapter->listingImage()->sort_order);
        $this->assertSame($normal->listingImage()->sort_order, $adapter->listingImage()->sort_order);
        $this->assertSame($normal->listingImage()->listingScore(), $adapter->listingImage()->listingScore());
        $this->assertDatabaseCount('part_images', 0);
        Http::assertNothingSent();
    }

    public function test_preview_and_public_requests_never_generate_images_or_fetch_remote_sources(): void
    {
        $this->localImage();
        $this->partialMock(PartImagePresentationService::class)->shouldNotReceive('process');
        $before = Storage::disk('public')->allFiles();
        $result = app(JarekImagePresentation::class)->batch();
        $this->assertSame('would_prepare', $result['results'][0]['status']);
        $this->get('https://gpswiss.fr/czesci')->assertOk();
        $this->get('https://gpswiss.fr/boites-jarek/'.$this->product->id)->assertOk();
        $this->assertSame($before, Storage::disk('public')->allFiles());
        $remote = app(JarekImagePresentation::class)->variants('https://remote.test/photo.jpg');
        $this->assertSame(['listing' => 'https://remote.test/photo.jpg', 'product' => 'https://remote.test/photo.jpg', 'ready' => false, 'listing_score' => null], $remote);
        Http::assertNothingSent();
    }

    public function test_image_batch_is_bounded_resumable_and_idempotent_and_supports_gallery_variants(): void
    {
        foreach ([1, 2, 3] as $number) {
            $this->localImage($number);
        }
        $images = app(JarekImagePresentation::class);
        $first = $images->batch(0, 0, true);
        $this->assertCount(2, $first['results']);
        $this->assertFalse($first['done']);
        $second = $images->batch($first['cursor']['after_id'], $first['cursor']['after_image'], true);
        $this->assertCount(1, $second['results']);
        $this->assertTrue($second['done']);
        $this->assertSame('prepared', $second['results'][0]['status']);
        $before = Storage::disk('public')->allFiles();
        $this->assertSame('already_ready', $images->batch(0, 0, true)['results'][0]['status']);
        $this->assertSame($before, Storage::disk('public')->allFiles());
        $this->get('https://gpswiss.fr/boites-jarek/'.$this->product->id)->assertOk()->assertSee('data-gallery-thumb', false)->assertSee('data-gallery-lightbox', false)->assertSee('parts/photos/presentation/listing/jarek-', false)->assertSee('parts/photos/presentation/product/jarek-', false);
        Http::assertNothingSent();
    }

    public function test_stale_manifest_falls_back_and_preparation_generates_a_new_variant_url(): void
    {
        $this->localImage();
        $images = app(JarekImagePresentation::class);
        $images->batch(0, 0, true);
        $url = 'https://gpswiss.pl/storage/jarek-gearboxes/17413813163/01.jpg';
        $first = $images->variants($url);
        file_put_contents(Storage::disk('public')->path('jarek-gearboxes/17413813163/01.jpg'), 'changed-size', FILE_APPEND);
        $this->assertFalse($images->variants($url)['ready']);
        $images->batch(0, 0, true);
        $new = $images->variants($url);
        $this->assertTrue($new['ready']);
        $this->assertNotSame($first['listing'], $new['listing']);
        Http::assertNothingSent();
    }

    public function test_remote_only_or_unreadable_images_are_reported_without_source_writes(): void
    {
        $images = app(JarekImagePresentation::class);
        $this->assertSame('no_local_images', $images->batch(0, 0, true)['results'][0]['status']);
        Storage::disk('public')->put('jarek-gearboxes/17413813163/01.jpg', 'invalid-image');
        $this->assertSame('unreadable_or_too_large', $images->batch(0, 0, true)['results'][0]['code']);
        Http::assertNothingSent();
    }

    public function test_mirror_failure_does_not_publish_a_ready_image_manifest(): void
    {
        $this->localImage();
        $listing = 'parts/photos/presentation/listing/jarek-0123456789abcdef-0123456789ab.jpg';
        $product = 'parts/photos/presentation/product/jarek-0123456789abcdef-0123456789ab.jpg';
        Storage::disk('public')->put($listing, 'fixture');
        Storage::disk('public')->put($product, 'fixture');
        $this->partialMock(PartImagePresentationService::class)->shouldReceive('process')->once()->andReturn(['presentation' => [
            'listing_path' => $listing, 'product_path' => $product, 'warnings' => ['Listing image: Presentation file could not be mirrored to public_html storage.'],
        ]]);
        $images = app(JarekImagePresentation::class);
        $this->assertSame('public_mirror_unavailable', $images->batch(0, 0, true)['results'][0]['code']);
        $this->assertFalse($images->variants('https://gpswiss.pl/storage/jarek-gearboxes/17413813163/01.jpg')['ready']);
        Http::assertNothingSent();
    }

    public function test_image_admin_requires_owner_confirmation_and_disallows_fr_host(): void
    {
        $this->localImage();
        $this->get(self::IMAGES)->assertRedirect();
        $this->owner();
        $this->get(self::IMAGES)->assertOk();
        $this->postJson(self::IMAGES, ['action' => 'prepare'])->assertUnprocessable();
        $before = Storage::disk('public')->allFiles();
        $this->postJson(self::IMAGES, ['action' => 'preview'])->assertOk()->assertJsonPath('results.0.status', 'would_prepare');
        $this->assertSame($before, Storage::disk('public')->allFiles());
        $this->postJson(self::IMAGES, ['action' => 'prepare', 'confirm' => 'PREPARE-JAREK-FR-LOCAL-IMAGES'])->assertOk()->assertJsonPath('results.0.status', 'prepared');
        $this->get(str_replace('gpswiss.pl', 'gpswiss.fr', self::IMAGES))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_diagnostics_reads_exact_live_fields_and_missing_blank_nonpublic_reasons_without_mutations(): void
    {
        $this->owner();
        $missing = PartCategory::create(['name' => 'Części nadwozia i karoserii', 'slug' => 'body', 'full_slug_path' => 'body', 'is_visible' => true]);
        $blank = PartCategory::create(['name' => 'Blank source', 'slug' => 'blank', 'full_slug_path' => 'blank', 'is_visible' => true]);
        $blank->translations()->create(['locale' => 'fr', 'status' => 'reviewed', 'name' => ' ']);
        $failed = PartCategory::create(['name' => 'Failed source', 'slug' => 'failed', 'full_slug_path' => 'failed', 'is_visible' => true]);
        $failed->translations()->create(['locale' => 'fr', 'status' => 'failed', 'name' => 'Non public']);
        $before = DB::table('category_translations')->orderBy('id')->get()->toJson();
        $this->get(self::DIAGNOSTICS)->assertOk()->assertSee('ready_fr_name')->assertSee('missing_translation')->assertSee('empty_fr_name')->assertSee('nonpublic_status')->assertSee(FrenchMixedCatalog::CATEGORY_PATH)->assertSee('Transmission');
        $this->get(self::DIAGNOSTICS.'?q=body')->assertOk()->assertSee('Części nadwozia i karoserii')->assertDontSee('Blank source');
        $this->assertSame($before, DB::table('category_translations')->orderBy('id')->get()->toJson());
        $missing->translations()->create(['locale' => 'fr', 'status' => 'translated', 'name' => 'Carrosserie']);
        $this->get(self::DIAGNOSTICS.'?q=body')->assertOk()->assertSee('Carrosserie')->assertDontSee('missing_translation</code>', false);
        $this->get(str_replace('gpswiss.pl', 'gpswiss.fr', self::DIAGNOSTICS))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_manager_can_read_category_diagnostics_but_cannot_prepare_image_files(): void
    {
        Role::findOrCreate(UserRole::Manager->value, 'web');
        $user = User::create(['name' => 'Manager', 'email' => 'polish-manager@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::Manager->value);
        $this->actingAs($user);
        $this->get(self::DIAGNOSTICS)->assertOk();
        $this->get(self::IMAGES)->assertForbidden();
        $this->postJson(self::IMAGES, ['action' => 'prepare', 'confirm' => 'PREPARE-JAREK-FR-LOCAL-IMAGES'])->assertForbidden();
        $this->assertSame([], Storage::disk('public')->allFiles());
        Http::assertNothingSent();
    }

    public function test_diagnostics_reports_missing_translation_table_and_keeps_source_fallback(): void
    {
        $this->owner();
        Schema::drop('category_translations');
        $this->get(self::DIAGNOSTICS)->assertOk()->assertSee('missing_translation_table')->assertSee('Manualna 6-biegowa skrzynia biegów');
        Http::assertNothingSent();
    }

    private function localImage(int $number = 1, bool $vertical = false): void
    {
        $image = imagecreatetruecolor(1600, 1200);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        if ($vertical) {
            imagefilledrectangle($image, 750, 300, 850, 900, imagecolorallocate($image, 30, 30, 30));
        } else {
            imagefilledrectangle($image, 650, 500, 950, 700, imagecolorallocate($image, 30, 30, 30));
        }
        $path = 'jarek-gearboxes/17413813163/'.str_pad((string) $number, 2, '0', STR_PAD_LEFT).'.jpg';
        Storage::disk('public')->makeDirectory(dirname($path));
        imagejpeg($image, Storage::disk('public')->path($path));
        imagedestroy($image);
    }

    private function relative(string $url): string
    {
        return preg_replace('#^/storage/#', '', parse_url($url, PHP_URL_PATH));
    }

    private function owner(): void
    {
        Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');
        $user = User::create(['name' => 'Operator', 'email' => 'polish-owner@example.test', 'password' => 'password']);
        $user->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($user);
    }
}
