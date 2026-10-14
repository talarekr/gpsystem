<?php

namespace Tests\Feature;

use App\Models\CategoryTranslation;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Storefront\CategoryTreeService;
use App\Services\Storefront\FrenchCategoryDiagnostics;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FrenchCategoryUrlsTest extends TestCase
{
    use RefreshDatabase;

    private array $categories;

    private Part $part;

    private const OLD = 'drzwi-i-inne-elementy/drzwi-boczne-przesuwne-i-inne-elementy/drzwi-boczne-przesuwne';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['frontend-maintenance.enabled' => false]);
        $parent = null;
        $path = '';
        foreach ([['Drzwi i inne elementy', 'drzwi-i-inne-elementy', 'Portes et autres éléments'],
            ['Drzwi boczne przesuwne i inne elementy', 'drzwi-boczne-przesuwne-i-inne-elementy', 'Portes latérales/coulissantes et autres composants'],
            ['Drzwi boczne przesuwne', 'drzwi-boczne-przesuwne', 'Portes latérales/coulissantes']] as [$pl,$slug,$fr]) {
            $path = ltrim($path.'/'.$slug, '/');
            $category = PartCategory::create(['name' => $pl, 'slug' => $slug, 'full_slug_path' => $path, 'parent_id' => $parent?->id, 'source_system' => 'woo', 'is_visible' => true]);
            $category->translations()->create(['locale' => 'fr', 'name' => $fr, 'status' => 'reviewed']);
            $this->categories[] = $category;
            $parent = $category;
        }
        $this->part = Part::create(['name' => 'Drzwi fixture', 'sku' => 'FR-URL', 'price' => 100, 'currency' => 'EUR', 'quantity' => 1, 'status' => 'ready', 'category_id' => $parent->id]);
        $this->part->translations()->create(['locale' => 'fr', 'name' => 'Porte fixture', 'status' => 'translated']);
    }

    public function test_fr_slug_path_legacy_301_and_pl_unchanged_use_the_same_category(): void
    {
        $new = $this->fr();
        $this->assertStringContainsString('portes-et-autres-elements-', $new);
        $this->assertStringContainsString('portes-laterales-coulissantes-', $new);
        $this->get('https://gpswiss.fr/kategoria-produktu/'.self::OLD)->assertStatus(301)->assertRedirect($new);
        $this->get($new)->assertOk()->assertSee('Portes latérales/coulissantes')->assertSee('Porte fixture')->assertDontSee('Drzwi boczne przesuwne');
        $this->get('https://gpswiss.pl/kategoria-produktu/'.self::OLD)->assertOk()->assertSee('Drzwi boczne przesuwne');
        Http::assertNothingSent();
    }

    public function test_canonical_hreflang_and_pagination_point_at_localized_counterparts_on_both_hosts(): void
    {
        $pl = 'https://gpswiss.pl/kategoria-produktu/'.self::OLD;
        $fr = $this->fr();
        foreach ([[$fr, $fr], [$pl, $pl]] as [$url,$canonical]) {
            $this->get($url)->assertOk()->assertSee('<link rel="canonical" href="'.$canonical.'">', false)
                ->assertSee('hreflang="pl" href="'.$pl.'"', false)->assertSee('hreflang="fr" href="'.$fr.'"', false);
        }
        $this->get($fr.'?page=2')->assertOk()->assertSee('href="'.$fr.'?page=2"', false)->assertSee('hreflang="pl" href="'.$pl.'?page=2"', false);
        $redirect = $this->get('https://gpswiss.fr/kategoria-produktu/'.self::OLD.'?sort=price_asc&price_min=10&page=2')->assertStatus(301);
        $this->assertSame($fr, strtok($redirect->headers->get('Location'), '?'));
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(['page' => '2', 'price_min' => '10', 'sort' => 'price_asc'], $query);
        Http::assertNothingSent();
    }

    public function test_menu_subcategories_product_breadcrumbs_search_and_sitemap_share_fr_urls(): void
    {
        $urls = array_map(fn ($c) => $this->fr($c), $this->categories);
        $home = $this->get('https://gpswiss.fr')->assertOk();
        foreach ($urls as $url) {
            $home->assertSee('href="'.$url.'"', false);
        }
        $this->get($urls[1])->assertOk()->assertSee('href="'.$urls[2].'"', false);
        $product = $this->get('https://gpswiss.fr/produkt/'.$this->part->slug)->assertOk();
        foreach ($urls as $url) {
            $product->assertSee('href="'.$url.'"', false);
        }
        $this->get('https://gpswiss.fr/czesci?q=Porte&category='.urlencode($this->categories[2]->translations()->first()->name))->assertOk()->assertSee('Porte fixture');
        $map = $this->get('https://gpswiss.fr/sitemaps/pages.xml')->assertOk();
        foreach ($urls as $url) {
            $map->assertSee('<loc>'.$url.'</loc>', false);
        }
        $map->assertDontSee('https://gpswiss.fr/kategoria-produktu/'.self::OLD, false);
        Http::assertNothingSent();
    }

    public function test_missing_ancestor_translation_is_controlled_segment_fallback_and_diagnosed(): void
    {
        $this->categories[0]->translations()->delete();
        $url = $this->fr();
        $this->assertStringContainsString('/drzwi-i-inne-elementy/', $url);
        $this->get('https://gpswiss.fr/kategoria-produktu/'.self::OLD)->assertStatus(301)->assertRedirect($url);
        $this->get($url)->assertOk();
        $report = app(FrenchCategoryDiagnostics::class)->report();
        $root = $report['rows']->first(fn ($row) => $row['category']->id === $this->categories[0]->id);
        $leaf = $report['rows']->first(fn ($row) => $row['category']->id === $this->categories[2]->id);
        $this->assertSame('missing_translation', $root['fr_slug_reason']);
        $this->assertTrue($leaf['fr_path_has_fallback']);
        $this->assertSame($url, $leaf['fr_public_url']);
        Http::assertNothingSent();
    }

    public function test_nonpublic_and_blank_names_fallback_without_serving_a_stale_french_canonical(): void
    {
        $old = $this->fr();
        $this->categories[2]->translations()->first()->update(['status' => 'failed']);
        $fallback = $this->fr();
        $this->assertStringEndsWith('/drzwi-boczne-przesuwne', $fallback);
        $this->get($old)->assertStatus(301)->assertRedirect($fallback);
        $this->get($fallback)->assertOk();
        $this->categories[2]->translations()->first()->update(['status' => 'reviewed', 'name' => ' ']);
        $row = app(FrenchCategoryDiagnostics::class)->report()['rows']->first(fn ($r) => $r['category']->id === $this->categories[2]->id);
        $this->assertSame('empty_fr_name', $row['fr_slug_reason']);
        Http::assertNothingSent();
    }

    public function test_identical_names_and_transliteration_collisions_have_stable_distinct_urls(): void
    {
        $first = $this->categories[2];
        $first->translations()->first()->update(['name' => 'Portes']);
        $second = PartCategory::create(['name' => 'Inne drzwi', 'slug' => 'inne-drzwi', 'full_slug_path' => $this->categories[1]->full_slug_path.'/inne-drzwi', 'parent_id' => $this->categories[1]->id, 'source_system' => 'woo', 'is_visible' => true]);
        $second->translations()->create(['locale' => 'fr', 'name' => 'Portes', 'status' => 'translated']);
        Part::create(['name' => 'Second fixture', 'sku' => 'SECOND-URL', 'price' => 100, 'currency' => 'EUR', 'quantity' => 1, 'status' => 'ready', 'category_id' => $second->id]);
        $this->assertNotSame($this->fr($first), $this->fr($second));
        $this->assertStringEndsWith('portes-'.$first->id, $this->fr($first));
        $this->get($this->fr($first))->assertOk()->assertSee('Porte fixture')->assertDontSee('Second fixture');
        $this->get($this->fr($second))->assertOk()->assertSee('Second fixture')->assertDontSee('Porte fixture');
        $this->assertSame($this->fr($first), $this->fr($first));
        Http::assertNothingSent();
    }

    public function test_translation_changes_keep_old_french_paths_as_301_aliases_including_ancestor_changes(): void
    {
        $old = $this->fr();
        $oldLeaf = $this->categories[2]->translations()->first()->slug;
        $this->categories[0]->translations()->first()->update(['name' => 'Portes et accessoires']);
        $this->categories[2]->translations()->first()->update(['name' => 'Portes coulissantes']);
        $new = $this->fr();
        $this->assertNotSame($old, $new);
        $this->get($old)->assertStatus(301)->assertRedirect($new);
        $this->get('https://gpswiss.fr/kategoria-produktu/'.self::OLD)->assertRedirect($new);
        $this->get($new)->assertOk()->assertSee('Portes coulissantes');
        $this->assertContains($oldLeaf, $this->categories[2]->translations()->first()->slug_history);
        Http::assertNothingSent();
    }

    public function test_ambiguous_legacy_paths_and_wrong_prefixes_do_not_guess_a_category(): void
    {
        $first = $this->categories[2];
        $second = PartCategory::create(['name' => 'Duplicate source', 'slug' => 'duplicate-source-slug', 'full_slug_path' => $first->full_slug_path, 'parent_id' => $first->parent_id, 'source_system' => 'woo', 'is_visible' => true]);
        $second->translations()->create(['locale' => 'fr', 'name' => 'Portes latérales/coulissantes', 'status' => 'translated']);
        Part::create(['name' => 'Duplicate fixture', 'sku' => 'DUP-URL', 'price' => 100, 'currency' => 'EUR', 'quantity' => 1, 'status' => 'ready', 'category_id' => $second->id]);
        $this->get('https://gpswiss.fr/kategoria-produktu/'.self::OLD)->assertNotFound();
        $this->get('https://gpswiss.fr/kategoria-produktu/wrong-prefix/'.$first->slug)->assertNotFound();
        $this->get($this->fr($first))->assertOk();
        $this->get($this->fr($second))->assertOk();
        Http::assertNothingSent();
    }

    public function test_slug_migration_backfills_metadata_idempotently_without_retranslating_or_changing_source_data(): void
    {
        $before = DB::table('part_categories')->orderBy('id')->get()->toJson();
        $parts = DB::table('parts')->get()->toJson();
        $translation = CategoryTranslation::first();
        $updated = $translation->updated_at;
        DB::table('category_translations')->update(['slug' => null, 'slug_name_hash' => null, 'slug_history' => null]);
        $migration = require database_path('migrations/2026_10_14_000005_add_french_category_slugs.php');
        $migration->up();
        $snapshot = DB::table('category_translations')->get()->toJson();
        $migration->up();
        $migration->down();
        $this->assertSame($snapshot, DB::table('category_translations')->get()->toJson());
        $this->assertSame($before, DB::table('part_categories')->orderBy('id')->get()->toJson());
        $this->assertSame($parts, DB::table('parts')->get()->toJson());
        $this->assertTrue($translation->fresh()->updated_at->equalTo($updated));
        $this->assertSame('reviewed', $translation->fresh()->status);
        $this->assertNotNull($translation->fresh()->slug);
        Http::assertNothingSent();
    }

    public function test_fr_category_filter_supports_current_and_historical_french_slugs(): void
    {
        $translation = $this->categories[2]->translations()->first();
        $old = $translation->slug;
        $translation->update(['name' => 'Portes et fenêtres']);
        $current = $translation->fresh()->slug;
        foreach ([$old, $current, substr(parse_url($this->fr(), PHP_URL_PATH), strlen('/kategoria-produktu/'))] as $slug) {
            $this->get('https://gpswiss.fr/czesci?category='.$slug)->assertOk()->assertSee('Porte fixture');
        }
        DB::table('category_translations')->where('category_id', $this->categories[2]->id)->update(['slug' => null, 'slug_name_hash' => null]);
        $this->get('https://gpswiss.fr/czesci?category='.$current)->assertOk()->assertSee('Porte fixture');
        Http::assertNothingSent();
    }

    public function test_unknown_ancestor_records_keep_legacy_prefix_and_are_explicit_in_diagnostics(): void
    {
        $category = PartCategory::create(['name' => 'Osierocona kategoria', 'slug' => 'orphane', 'full_slug_path' => 'brakujacy-korzen/brakujacy-rodzic/orphane', 'parent_id' => null, 'is_visible' => true, 'woo_product_count' => 1]);
        $category->translations()->create(['locale' => 'fr', 'name' => 'Portes avant', 'status' => 'translated']);
        $url = $this->fr($category);
        $this->assertStringContainsString('/brakujacy-korzen/brakujacy-rodzic/portes-avant-', $url);
        $this->get('https://gpswiss.fr/kategoria-produktu/'.$category->full_slug_path)->assertStatus(301)->assertRedirect($url);
        $row = app(FrenchCategoryDiagnostics::class)->report()['rows']->first(fn ($r) => $r['category']->id === $category->id);
        $this->assertSame(2, $row['unresolved_ancestor_segments']);
        $this->assertTrue($row['fr_path_has_fallback']);
        Http::assertNothingSent();
    }

    public function test_partial_slug_schema_is_completed_without_changing_translation_content_or_timestamps(): void
    {
        Schema::table('category_translations', function (Blueprint $table): void {
            $table->dropUnique('ct_locale_slug_uq');
            $table->dropColumn(['slug', 'slug_history']);
        });
        $translation = $this->categories[2]->translations()->first();
        $translation->update(['description' => 'Description conservée']);
        $before = $translation->fresh();
        $migration = require database_path('migrations/2026_10_14_000005_add_french_category_slugs.php');
        $migration->up();
        $migration->up();
        $after = $translation->fresh();
        $this->assertSame($before->name, $after->name);
        $this->assertSame($before->description, $after->description);
        $this->assertTrue($before->updated_at->equalTo($after->updated_at));
        $this->assertTrue(Schema::hasColumns('category_translations', ['slug', 'slug_name_hash', 'slug_history']));
        $this->assertTrue(Schema::hasIndex('category_translations', ['locale', 'slug'], 'unique'));
        $this->get($this->fr())->assertOk();
        Http::assertNothingSent();
    }

    public function test_public_url_reads_and_redirects_do_not_write_source_or_translation_rows(): void
    {
        $before = [DB::table('part_categories')->get()->toJson(), DB::table('category_translations')->get()->toJson(), DB::table('parts')->get()->toJson()];
        $this->get('https://gpswiss.fr/kategoria-produktu/'.self::OLD)->assertStatus(301);
        $this->get($this->fr())->assertOk();
        $this->get('https://gpswiss.fr/sitemaps/pages.xml')->assertOk();
        $this->assertSame($before, [DB::table('part_categories')->get()->toJson(), DB::table('category_translations')->get()->toJson(), DB::table('parts')->get()->toJson()]);
        Http::assertNothingSent();
    }

    private function fr(?PartCategory $category = null): string
    {
        return app(CategoryTreeService::class)->forLocale('fr')->localizedUrl($category ?? $this->categories[2],'fr');
    }
}
