<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\PartResource;
use App\Filament\Resources\PartResource\Pages\EditPart;
use App\Filament\Resources\PartResource\Pages\ViewPart;
use App\Models\Car;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartImage;
use App\Models\StorageLocation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PartModuleFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_part_tables_and_category_suggestion_fields_exist(): void
    {
        $this->assertTrue(Schema::hasTable('parts'));
        $this->assertTrue(Schema::hasTable('part_images'));
        $this->assertTrue(Schema::hasColumns('parts', [
            'sku','name','slug','part_number','oem_number','manufacturer_code','short_description','description','condition_notes',
            'category_id','suggested_category_id','category_confidence','category_suggestion_reason','category_needs_review',
            'car_id','vehicle_snapshot','storage_location_id','price','currency','allegro_price','ebay_price','quantity','status','is_visible_storefront','created_by',
        ]));
    }

    public function test_part_marketplace_price_search_links_use_encoded_query(): void
    {
        $links = PartResource::marketplacePriceSearchLinks('HED 123');

        $this->assertSame('https://allegro.pl/listing?string=HED%20123', $links['allegro']);
        $this->assertSame('https://www.ovoko.pl/pl/search?q=HED%20123', $links['ovoko']);
        $this->assertSame('https://www.ebay.com/sch/i.html?_nkw=HED%20123', $links['ebay']);
    }

    public function test_part_marketplace_price_search_links_are_disabled_without_query(): void
    {
        $this->assertSame([], PartResource::marketplacePriceSearchLinks(''));
    }

    public function test_admin_part_channel_prices_use_part_form_price_fields_not_marketplace_listing_prices_or_fallbacks(): void
    {
        $part = Part::query()->create([
            'name' => 'Część #5502',
            'price' => 1250,
            'ovoko_price' => 1300,
            'quantity' => 1,
            'status' => 'ready',
        ]);

        MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'ovoko',
            'external_offer_id' => 'OVOKO-5502',
            'price' => 1250,
            'currency' => 'PLN',
        ]);

        $rows = app(\App\Services\Admin\PartMarketplaceStatusResolver::class)
            ->rowsForPart($part->fresh('marketplaceListings'));

        $this->assertSame(['Sklep', 'Allegro', 'Ovoko', 'eBay'], collect($rows)->pluck('label')->all());

        $pricesByKey = collect($rows)->pluck('price', 'key');

        $this->assertSame('1 250,00 zł', $pricesByKey['storefront']);
        $this->assertSame('1 250,00 zł', $pricesByKey['allegro']);
        $this->assertSame('1 300,00 zł', $pricesByKey['ovoko']);
        $this->assertSame('1 562,50 zł', $pricesByKey['ebay']);
    }

    public function test_allegro_channel_shows_offer_link_while_publication_is_pending(): void
    {
        $part = Part::query()->create([
            'name' => 'Część #7866',
            'price' => 1250,
            'allegro_price' => 1250,
            'quantity' => 1,
            'status' => 'ready',
        ]);

        MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'allegro',
            'external_offer_id' => '18723793233',
            'external_listing_id' => '18723793233',
            'price' => 1250,
            'currency' => 'PLN',
            'status' => 'publication_pending',
            'url' => 'https://allegro.pl/oferta/18723793233',
        ]);

        $rows = app(\App\Services\Admin\PartMarketplaceStatusResolver::class)
            ->rowsForPart($part->fresh('marketplaceListings'));

        $allegro = collect($rows)->firstWhere('key', 'allegro');

        $this->assertTrue($allegro['listed']);
        $this->assertSame('18723793233', $allegro['external_offer_id']);
        $this->assertSame('https://allegro.pl/oferta/18723793233', $allegro['url']);
    }

    public function test_ovoko_channel_uses_listing_url_and_external_listing_id_when_offer_id_is_empty(): void
    {
        $part = Part::query()->create([
            'name' => 'Część #7897',
            'price' => 1250,
            'ovoko_price' => 1250,
            'quantity' => 1,
            'status' => 'ready',
        ]);

        MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'ovoko',
            'external_offer_id' => null,
            'external_listing_id' => '11703',
            'price' => 1250,
            'currency' => 'PLN',
            'status' => 'published',
            'url' => 'https://ovoko.pl/czesci-samochodowe/hgf11703',
        ]);

        $rows = app(\App\Services\Admin\PartMarketplaceStatusResolver::class)
            ->rowsForPart($part->fresh('marketplaceListings'));

        $ovoko = collect($rows)->firstWhere('key', 'ovoko');

        $this->assertTrue($ovoko['listed']);
        $this->assertSame('11703', $ovoko['external_offer_id']);
        $this->assertSame('https://ovoko.pl/czesci-samochodowe/hgf11703', $ovoko['url']);
    }

    public function test_ovoko_channel_diagnostics_explain_missing_url_for_listing_id_only_records(): void
    {
        $part = Part::query()->create([
            'name' => 'Część #7897',
            'price' => 1250,
            'ovoko_price' => 1250,
            'quantity' => 1,
            'status' => 'ready',
        ]);

        MarketplaceListing::query()->create([
            'part_id' => $part->id,
            'marketplace' => 'ovoko',
            'external_offer_id' => null,
            'external_listing_id' => '11703',
            'price' => 1250,
            'currency' => 'PLN',
            'status' => 'published',
            'url' => null,
        ]);

        $diagnostics = app(\App\Services\Admin\PartMarketplaceStatusResolver::class)
            ->diagnosticsForPartChannel($part->fresh('marketplaceListings'), 'ovoko');

        $this->assertTrue($diagnostics['resolved_is_listed']);
        $this->assertNull($diagnostics['resolved_url']);
        $this->assertFalse($diagnostics['link_visible']);
        $this->assertSame('missing_marketplace_listings_url', $diagnostics['link_hidden_reason']);
    }

    public function test_part_can_be_created_with_required_fields_and_safe_defaults(): void
    {
        $part = Part::query()->create(['name' => 'Reflektor lewy']);

        $this->assertNotNull($part->id);
        $this->assertSame('draft', $part->status);
        $this->assertFalse($part->is_visible_storefront);
        $this->assertSame(1, $part->quantity);
        $this->assertSame('PLN', $part->currency);
    }

    public function test_part_category_picker_hides_uncategorized_option_and_keeps_real_categories(): void
    {
        PartCategory::query()->create(['id' => 10, 'name' => 'Bez kategorii', 'sort_order' => 1]);
        $parent = PartCategory::query()->create(['id' => 20, 'name' => 'Silnik', 'sort_order' => 2]);
        PartCategory::query()->create(['id' => 21, 'parent_id' => $parent->id, 'name' => 'Alternatory', 'sort_order' => 1]);

        $categories = PartResource::categoryPickerCategories();
        $renderedPicker = view('filament.forms.category-picker', ['categories' => $categories])->render();

        $this->assertStringNotContainsString('Bez kategorii', $renderedPicker);
        $this->assertStringContainsString('Alternatory', $renderedPicker);

        $alternators = collect($categories)->firstWhere('name', 'Alternatory');

        $this->assertNotNull($alternators);
        $this->assertSame(21, $alternators['id']);
        $this->assertFalse($alternators['has_children']);
    }

    public function test_part_name_is_required_by_database(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Part::query()->create([]);
    }

    public function test_nullable_car_and_storage_relationships_work_when_provided(): void
    {
        $car = Car::query()->create(['make' => 'BMW', 'model' => '3', 'model_variant' => 'E91', 'production_year' => 2011, 'fuel_type' => 'diesel', 'gearbox_type' => 'manualna', 'engine_capacity_cm3' => 1995, 'engine_code' => 'N47', 'color' => 'czarny', 'steering_side' => 'lewa strona']);
        $location = StorageLocation::query()->create(['name' => '1K3-1', 'description' => 'KASTRA 1K3']);

        $part = Part::query()->create(['name' => 'Lampa tył', 'car_id' => $car->id, 'storage_location_id' => $location->id]);

        $this->assertTrue($part->car->is($car));
        $this->assertTrue($part->storageLocation->is($location));
        $this->assertSame('BMW', $part->vehicle_snapshot['make']);
        $this->assertSame('N47', $part->vehicle_snapshot['engine_code']);
    }

    public function test_part_photos_can_be_associated_and_first_image_becomes_primary(): void
    {
        $part = Part::query()->create(['name' => 'Zderzak przód']);

        $first = PartImage::query()->create(['part_id' => $part->id, 'path' => 'parts/photos/front.jpg', 'sort_order' => 0]);
        PartImage::query()->create(['part_id' => $part->id, 'path' => 'parts/photos/side.jpg', 'sort_order' => 1]);

        $this->assertTrue($first->fresh()->is_primary);
        $this->assertSame('parts/photos/front.jpg', $part->fresh()->primary_image_path);
    }

    public function test_part_photo_public_url_uses_storage_url_for_public_disk_file(): void
    {
        Storage::disk('public')->put('parts/photos/example.jpg', 'fake image');
        $image = new PartImage(['path' => 'parts/photos/example.jpg']);

        $this->assertSame(Storage::disk('public')->url('parts/photos/example.jpg'), $image->publicUrl());
        $this->assertStringEndsWith('/storage/parts/photos/example.jpg', $image->publicUrl());
    }

    public function test_imported_part_photo_storefront_urls_use_existing_presentation_variants(): void
    {
        Storage::disk('public')->put('parts/photos/imported/2083/example.jpg', 'fake image');
        Storage::disk('public')->put('parts/photos/presentation/listing/example.jpg', 'fake listing variant');
        Storage::disk('public')->put('parts/photos/presentation/product/example.jpg', 'fake product variant');

        $image = new PartImage([
            'path' => 'parts/photos/imported/2083/example.jpg',
            'legacy_payload' => ['presentation' => [
                'listing_path' => 'parts/photos/presentation/listing/example.jpg',
                'product_path' => 'parts/photos/presentation/product/example.jpg',
            ]],
        ]);

        $this->assertTrue($image->isImportedPhoto());
        $this->assertStringEndsWith('/storage/parts/photos/presentation/listing/example.jpg', $image->listingUrl());
        $this->assertStringEndsWith('/storage/parts/photos/presentation/product/example.jpg', $image->productUrl());
    }

    public function test_imported_part_photo_storefront_urls_fall_back_to_original_without_presentation_variants(): void
    {
        Storage::disk('public')->put('parts/photos/imported/2083/fallback.jpg', 'fake image');

        $image = new PartImage([
            'path' => 'parts/photos/imported/2083/fallback.jpg',
        ]);

        $this->assertTrue($image->isImportedPhoto());
        $this->assertStringEndsWith('/storage/parts/photos/imported/2083/fallback.jpg', $image->listingUrl());
        $this->assertStringEndsWith('/storage/parts/photos/imported/2083/fallback.jpg', $image->productUrl());
    }

    public function test_manual_part_photo_storefront_urls_still_use_existing_presentation_variants(): void
    {
        Storage::disk('public')->put('parts/photos/manual/example.jpg', 'fake image');
        Storage::disk('public')->put('parts/photos/presentation/listing/manual-example.jpg', 'fake listing variant');
        Storage::disk('public')->put('parts/photos/presentation/product/manual-example.jpg', 'fake product variant');

        $image = new PartImage([
            'path' => 'parts/photos/manual/example.jpg',
            'legacy_payload' => ['presentation' => [
                'listing_path' => 'parts/photos/presentation/listing/manual-example.jpg',
                'product_path' => 'parts/photos/presentation/product/manual-example.jpg',
            ]],
        ]);

        $this->assertFalse($image->isImportedPhoto());
        $this->assertStringEndsWith('/storage/parts/photos/presentation/listing/manual-example.jpg', $image->listingUrl());
        $this->assertStringEndsWith('/storage/parts/photos/presentation/product/manual-example.jpg', $image->productUrl());
    }

    public function test_part_photo_public_url_uses_public_path_when_file_exists_in_public_directory(): void
    {
        $publicFile = public_path('legacy-parts/example.jpg');
        if (! is_dir(dirname($publicFile))) {
            mkdir(dirname($publicFile), 0755, true);
        }
        file_put_contents($publicFile, 'fake image');

        try {
            $image = new PartImage(['path' => 'legacy-parts/example.jpg']);

            $this->assertSame(asset('legacy-parts/example.jpg'), $image->publicUrl());
            $this->assertStringEndsWith('/legacy-parts/example.jpg', $image->publicUrl());
        } finally {
            @unlink($publicFile);
            @rmdir(dirname($publicFile));
        }
    }

    public function test_listing_score_penalizes_narrow_images_and_prefers_wide_fill(): void
    {
        $narrow = PartImage::calculateListingScore(0.318, 1.0, 1.0);
        $wide = PartImage::calculateListingScore(1.0, 0.875, 1.0);

        $this->assertGreaterThan($narrow, $wide);
    }

    public function test_proposed_listing_score_penalizes_edge_touching_closeups(): void
    {
        $closeup = PartImage::calculateProposedListingScore([
            'listing_fill_width_ratio' => 1.0,
            'listing_fill_height_ratio' => 0.875,
            'listing_dominant_ratio' => 1.0,
            'selected_crop_pass' => 'aggressive',
            'metrics' => ['original' => ['width' => 1000, 'height' => 750]],
            'selected_crops' => ['listing' => ['box' => ['x' => 0, 'y' => 8, 'width' => 960, 'height' => 650]]],
        ]);
        $wholeObject = PartImage::calculateProposedListingScore([
            'listing_fill_width_ratio' => 0.94,
            'listing_fill_height_ratio' => 0.72,
            'listing_dominant_ratio' => 0.94,
            'selected_crop_pass' => 'normal',
            'metrics' => ['original' => ['width' => 1000, 'height' => 750]],
            'selected_crops' => ['listing' => ['box' => ['x' => 70, 'y' => 75, 'width' => 820, 'height' => 520]]],
        ]);

        $this->assertGreaterThan($closeup, $wholeObject);
    }

    public function test_part_listing_image_uses_best_presentation_metrics_when_images_are_eager_loaded(): void
    {
        $part = Part::query()->create(['name' => 'SEAT EXEO zwrotnica', 'status' => 'ready']);

        $primaryNarrow = PartImage::query()->create(['part_id' => $part->id, 'sort_order' => 0, 'is_primary' => true]);
        $primaryNarrow->forceFill([
            'path' => 'parts/photos/vertical.jpg',
            'legacy_payload' => ['presentation' => [
                'listing_path' => 'parts/photos/presentation/listing/vertical.jpg',
                'listing_fill_width_ratio' => 0.318,
                'listing_fill_height_ratio' => 1.0,
                'listing_dominant_ratio' => 1.0,
            ]],
        ])->saveQuietly();

        $wide = PartImage::query()->create(['part_id' => $part->id, 'sort_order' => 1, 'is_primary' => false]);
        $wide->forceFill([
            'path' => 'parts/photos/wide.jpg',
            'legacy_payload' => ['presentation' => [
                'listing_path' => 'parts/photos/presentation/listing/wide.jpg',
                'listing_fill_width_ratio' => 1.0,
                'listing_fill_height_ratio' => 0.875,
                'listing_dominant_ratio' => 1.0,
            ]],
        ])->saveQuietly();

        $part = Part::query()->with('images')->findOrFail($part->id);

        $this->assertTrue($part->primaryImage()->is($primaryNarrow));
        $this->assertTrue($part->listingImage()->is($wide));
    }

    public function test_part_listing_image_falls_back_to_primary_sort_order_when_metrics_are_missing(): void
    {
        $part = Part::query()->create(['name' => 'Brak metryk']);

        $fallback = PartImage::query()->create(['part_id' => $part->id, 'sort_order' => 5, 'is_primary' => true]);
        $fallback->forceFill(['path' => 'parts/photos/primary.jpg'])->saveQuietly();
        $secondary = PartImage::query()->create(['part_id' => $part->id, 'sort_order' => 0, 'is_primary' => false]);
        $secondary->forceFill(['path' => 'parts/photos/secondary.jpg'])->saveQuietly();

        $part = Part::query()->with('images')->findOrFail($part->id);

        $this->assertTrue($part->listingImage()->is($fallback));
    }

    public function test_internal_category_suggestion_marks_uncertain_or_assigns_known_mapping(): void
    {
        $known = Part::query()->create(['name' => 'Alternator kompletny', 'oem_number' => 'ALT-001']);
        $unknown = Part::query()->create(['name' => 'Nietypowy element']);

        $this->assertSame('Elektryka', $known->category->name);
        $this->assertFalse($known->category_needs_review);
        $this->assertTrue($unknown->category_needs_review);
    }

    public function test_part_form_hides_sku_field_and_sets_create_defaults_for_condition_and_steering_side(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/PartResource.php'));

        $this->assertStringNotContainsString("TextInput::make('sku')->label('SKU / kod wewnętrzny')", $source);
        $this->assertStringContainsString("Hidden::make('sku')", $source);
        $this->assertStringContainsString("->label('Jakość')", $source);
        $this->assertStringContainsString("->default('Używany')", $source);
        $this->assertStringContainsString("->label('Kierownica po stronie')", $source);
        $this->assertStringContainsString("->default('po lewej')", $source);
    }

    public function test_part_edit_preserves_existing_condition_and_steering_side_values_in_form_configuration(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/PartResource.php'));

        $this->assertStringNotContainsString("condition_notes' => 'Używany'", $source);
        $this->assertStringContainsString("->default('Używany')", $source);
        $this->assertStringContainsString("->default('po lewej')", $source);
    }

    public function test_part_storage_location_picker_hides_allegro_import_description(): void
    {
        $location = StorageLocation::query()->create([
            'name' => '2D3',
            'description' => StorageLocation::ALLEGRO_IMPORT_DESCRIPTION,
        ]);

        $method = new \ReflectionMethod(PartResource::class, 'storageLocationLabel');
        $method->setAccessible(true);

        $this->assertSame('2D3', $method->invoke(null, $location));
        $this->assertStringNotContainsString(StorageLocation::ALLEGRO_IMPORT_DESCRIPTION, $method->invoke(null, $location));
    }

    public function test_part_navigation_counts_use_real_parts_and_needs_listing_queue(): void
    {
        Part::query()->create(['name' => 'Część bez wystawienia']);
        Part::query()->create(['name' => 'Część do wystawienia 1', 'needs_listing' => true]);
        Part::query()->create(['name' => 'Część do wystawienia 2', 'needs_listing' => true]);

        $this->assertSame(1, PartResource::getAllPartsNavigationCount());
        $this->assertSame(2, PartResource::getPartsToListNavigationCount());
    }

    public function test_parts_to_list_diagnostics_reports_admin_view_split(): void
    {
        Part::query()->create(['name' => 'Część bez wystawienia']);
        Part::query()->create(['name' => 'Część do wystawienia 1', 'needs_listing' => true]);
        Part::query()->create(['name' => 'Część do wystawienia 2', 'needs_listing' => true]);

        $this->getJson('/tools/check-parts-to-list?token=gps_images_import_2026')
            ->assertOk()
            ->assertJsonPath('admin_all_parts_count', 1)
            ->assertJsonPath('admin_parts_to_list_count', 2)
            ->assertJsonPath('needs_listing_count', 2)
            ->assertJsonPath('admin_all_excludes_needs_listing', true)
            ->assertJsonPath('samples_needs_listing_in_admin_all', []);
    }


    public function test_part_admin_view_and_edit_share_existing_images_and_safe_preview_actions(): void
    {
        $this->actingAsWarehouseUser();

        $part = Part::query()->create([
            'name' => 'Widoczny reflektor',
            'slug' => 'widoczny-reflektor',
            'price' => 199,
            'quantity' => 2,
            'status' => 'ready',
            'needs_listing' => false,
            'needs_review' => false,
        ]);

        $frontImage = PartImage::query()->create(['part_id' => $part->id, 'path' => 'parts/photos/front.jpg', 'sort_order' => 1]);
        PartImage::query()->create(['part_id' => $part->id, 'path' => 'parts/photos/side.jpg', 'sort_order' => 2]);

        $expectedImagePaths = ['parts/photos/front.jpg', 'parts/photos/side.jpg'];

        $this->assertSame($expectedImagePaths, PartResource::partImagePaths($part->fresh('images')));

        Livewire::test(ViewPart::class, ['record' => $part->getRouteKey()])
            ->assertSee($expectedImagePaths[0])
            ->assertSee($expectedImagePaths[1])
            ->assertDontSee('Przetwórz zdjęcia produktu');

        Livewire::test(EditPart::class, ['record' => $part->getRouteKey()])
            ->assertFormSet(['part_photo_paths' => []])
            ->assertDontSee('Zdjęcie kodu części')
            ->assertSee($expectedImagePaths[0])
            ->assertSee($expectedImagePaths[1])
            ->assertSee('Usuń zdjęcie części')
            ->assertSeeHtml('style="right: 0.25rem; color: var(--gps-admin-navy);"')
            ->assertSee(route('storefront.product', $part->slug))
            ->assertSeeHtml('target="_blank"')
            ->assertDontSee('Przetwórz zdjęcia produktu')
            ->fillForm(['name' => 'Widoczny reflektor po edycji'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($expectedImagePaths, PartResource::partImagePaths($part->fresh('images')));

        Livewire::test(EditPart::class, ['record' => $part->getRouteKey()])
            ->call('deletePartImage', $frontImage->getKey())
            ->assertHasNoErrors();

        $this->assertSame(['parts/photos/side.jpg'], PartResource::partImagePaths($part->fresh('images')));
        $this->assertDatabaseMissing('part_images', ['id' => $frontImage->getKey()]);
        $this->assertDatabaseHas('part_images', ['part_id' => $part->getKey(), 'path' => 'parts/photos/side.jpg']);
        $this->assertSame(route('storefront.product', $part->slug), PartResource::publicProductUrl($part->fresh()));

        $draftPart = Part::query()->create(['name' => 'Robocza część', 'slug' => null, 'quantity' => 0, 'status' => 'draft']);

        $this->assertNull(PartResource::publicProductUrl($draftPart));
    }


    public function test_admin_part_photo_upload_paths_are_moved_to_part_scoped_public_storage_and_render_in_gallery(): void
    {
        Storage::fake('public');

        $part = Part::query()->create([
            'name' => 'Admin upload test',
            'slug' => 'admin-upload-test',
            'price' => 150,
            'quantity' => 1,
            'status' => 'ready',
            'needs_listing' => false,
            'needs_review' => false,
        ]);

        Storage::disk('public')->put('parts/photos/admin-upload.png', 'fake admin image');

        PartResource::syncPartImages($part, ['parts/photos/admin-upload.png']);

        $image = $part->fresh('images')->images->first();

        $this->assertNotNull($image);
        $this->assertSame('parts/photos/admin/'.$part->id.'/admin-upload.png', $image->path);
        $this->assertSame('/storage/parts/photos/admin/'.$part->id.'/admin-upload.png', $image->relativePublicUrl());
        $this->assertStringEndsWith('/storage/parts/photos/admin/'.$part->id.'/admin-upload.png', $image->absolutePublicUrl());
        Storage::disk('public')->assertExists($image->path);
        Storage::disk('public')->assertMissing('parts/photos/admin-upload.png');
        $this->assertDatabaseHas('part_images', [
            'part_id' => $part->getKey(),
            'path' => 'parts/photos/admin/'.$part->id.'/admin-upload.png',
        ]);

        $renderedGallery = view('filament.resources.parts.part-images-gallery', [
            'part' => $part->fresh('images'),
            'editable' => true,
        ])->render();

        $this->assertStringContainsString('/storage/parts/photos/admin/'.$part->id.'/admin-upload.png', $renderedGallery);
        $this->assertStringNotContainsString('/storage/parts/photos/admin-upload.png', $renderedGallery);
    }

    public function test_imported_part_photo_paths_still_render_without_being_rewritten(): void
    {
        Storage::fake('public');

        $part = Part::query()->create([
            'name' => 'Imported upload test',
            'slug' => 'imported-upload-test',
            'price' => 150,
            'quantity' => 1,
            'status' => 'ready',
            'needs_listing' => false,
            'needs_review' => false,
        ]);
        $importedPath = 'parts/photos/imported/'.$part->id.'/imported-photo.jpg';

        Storage::disk('public')->put($importedPath, 'fake imported image');

        PartResource::syncPartImages($part, [$importedPath]);

        $image = $part->fresh('images')->images->first();

        $this->assertNotNull($image);
        $this->assertSame($importedPath, $image->path);
        $this->assertSame('/storage/'.$importedPath, $image->relativePublicUrl());
        Storage::disk('public')->assertExists($importedPath);

        $renderedGallery = view('filament.resources.parts.part-images-gallery', [
            'part' => $part->fresh('images'),
            'editable' => true,
        ])->render();

        $this->assertStringContainsString('/storage/'.$importedPath, $renderedGallery);
    }


    public function test_admin_part_position_saves_to_review_metadata_and_hydrates_on_edit(): void
    {
        $this->actingAsWarehouseUser();

        $part = Part::query()->create([
            'name' => 'Błotnik do testu pozycji',
            'quantity' => 1,
            'status' => 'draft',
        ]);

        Livewire::test(EditPart::class, ['record' => $part->getRouteKey()])
            ->assertFormSet(['part_position' => null])
            ->fillForm(['part_position' => 'Lewa strona'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Lewa strona', $part->fresh()->review_metadata['part_position'] ?? null);

        Livewire::test(EditPart::class, ['record' => $part->getRouteKey()])
            ->assertFormSet(['part_position' => 'Lewa strona'])
            ->fillForm(['part_position' => 'Przód strona prawa'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Przód strona prawa', $part->fresh()->review_metadata['part_position'] ?? null);

        Livewire::test(EditPart::class, ['record' => $part->getRouteKey()])
            ->assertFormSet(['part_position' => 'Przód strona prawa']);
    }

    public function test_part_resource_navigation_and_labels_are_polish_and_iconless_children(): void
    {
        $this->assertSame('Części', PartResource::getNavigationGroup());
        $this->assertSame('Wszystkie części', PartResource::getNavigationLabel());
        $this->assertNull(PartResource::getNavigationIcon());
    }

    private function actingAsWarehouseUser(): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::query()->create([
            'name' => 'Warehouse User',
            'email' => 'warehouse-part@example.test',
            'password' => 'password',
        ]);

        $user->assignRole(UserRole::WarehouseProductStaff->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }
    public function test_admin_can_move_existing_part_images_and_primary_follows_first_sort_order(): void
    {
        $this->actingAsWarehouseUser();

        $part = Part::query()->create(['name' => 'Sortowane zdjęcia', 'quantity' => 1]);
        $front = PartImage::query()->create(['part_id' => $part->id, 'path' => 'parts/photos/front.jpg', 'sort_order' => 0, 'is_primary' => true]);
        $side = PartImage::query()->create(['part_id' => $part->id, 'path' => 'parts/photos/side.jpg', 'sort_order' => 1, 'is_primary' => false]);

        Livewire::test(EditPart::class, ['record' => $part->getRouteKey()])
            ->assertSee('Główne')
            ->call('movePartImage', $side->getKey(), 'left')
            ->assertHasNoErrors();

        $this->assertSame(['parts/photos/side.jpg', 'parts/photos/front.jpg'], PartResource::partImagePaths($part->fresh('images')));
        $this->assertDatabaseHas('part_images', ['id' => $side->getKey(), 'sort_order' => 0, 'is_primary' => true]);
        $this->assertDatabaseHas('part_images', ['id' => $front->getKey(), 'sort_order' => 1, 'is_primary' => false]);
    }

    public function test_admin_can_reorder_existing_part_images_by_drag_drop_and_marketplace_preview_uses_saved_order(): void
    {
        $this->actingAsWarehouseUser();

        $part = Part::query()->create([
            'name' => 'Sortowane zdjęcia drag',
            'description' => 'Opis części.',
            'price' => 100,
            'quantity' => 1,
        ]);
        $a = PartImage::query()->create(['part_id' => $part->id, 'path' => 'parts/photos/a.jpg', 'sort_order' => 0, 'is_primary' => true]);
        $b = PartImage::query()->create(['part_id' => $part->id, 'path' => 'parts/photos/b.jpg', 'sort_order' => 1, 'is_primary' => false]);
        $c = PartImage::query()->create(['part_id' => $part->id, 'path' => 'parts/photos/c.jpg', 'sort_order' => 2, 'is_primary' => false]);

        Livewire::test(EditPart::class, ['record' => $part->getRouteKey()])
            ->call('reorderPartImages', [$c->getKey(), $a->getKey(), $b->getKey()])
            ->assertHasNoErrors();

        $this->assertDatabaseHas('part_images', ['id' => $c->getKey(), 'sort_order' => 0, 'is_primary' => true]);
        $this->assertDatabaseHas('part_images', ['id' => $a->getKey(), 'sort_order' => 1, 'is_primary' => false]);
        $this->assertDatabaseHas('part_images', ['id' => $b->getKey(), 'sort_order' => 2, 'is_primary' => false]);
        $this->assertSame(['parts/photos/c.jpg', 'parts/photos/a.jpg', 'parts/photos/b.jpg'], PartResource::partImagePaths($part->fresh('images')));

        $readiness = app(\App\Services\Marketplace\MarketplaceListingReadinessService::class)->checkPartReadiness($part->fresh(), 'storefront');

        $this->assertSame(
            ['c.jpg', 'a.jpg', 'b.jpg'],
            array_map('basename', $readiness['prepared_payload_preview_safe']['image_urls']),
        );
    }

    public function test_ovoko_dimensions_fields_are_fillable_and_visible_in_admin_form_source(): void
    {
        $part = Part::query()->create([
            'name' => 'Część z wymiarami',
            'weight_kg' => 2.345,
            'length_cm' => 40.50,
            'width_cm' => 30.25,
            'height_cm' => 20.75,
        ]);

        $this->assertSame('2.345', (string) $part->fresh()->weight_kg);
        $source = file_get_contents(app_path('Filament/Resources/PartResource.php'));
        $this->assertStringContainsString("Section::make('Wymiary')", $source);
        $this->assertStringContainsString("->label('Waga, kg')", $source);
        $this->assertLessThan(strpos($source, "RichEditor::make('description')"), strpos($source, "Section::make('Wymiary')"));
    }

}
