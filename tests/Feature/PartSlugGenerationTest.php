<?php

namespace Tests\Feature;

use App\Filament\Resources\PartResource;
use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartSlugGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_part_without_slug_gets_slug_from_final_name(): void
    {
        $part = Part::query()->create([
            'name' => 'MERCEDES BENZ W213 AMG E63 S 4MATIC+ SILNIK KOMPLETNY 177980 571KM',
            'slug' => null,
        ]);

        $this->assertSame('mercedes-benz-w213-amg-e63-s-4matic-silnik-kompletny-177980-571km', $part->slug);
        $this->assertNotSame((string) $part->getKey(), $part->slug);
        $this->assertStringNotContainsString('+', $part->slug);
    }

    public function test_explicit_slug_is_preserved(): void
    {
        $part = Part::query()->create(['name' => 'Dowolna nazwa', 'slug' => 'wlasny-slug']);

        $this->assertSame('wlasny-slug', $part->slug);
    }

    public function test_duplicate_titles_get_incrementing_slug_suffixes(): void
    {
        $first = Part::query()->create(['name' => 'Duplicate title']);
        $second = Part::query()->create(['name' => 'Duplicate title']);
        $third = Part::query()->create(['name' => 'Duplicate title']);

        $this->assertSame('duplicate-title', $first->slug);
        $this->assertSame('duplicate-title-2', $second->slug);
        $this->assertSame('duplicate-title-3', $third->slug);
    }

    public function test_slug_does_not_change_when_name_is_edited(): void
    {
        $part = Part::query()->create(['name' => 'Original name']);

        $part->update(['name' => 'Changed name']);

        $this->assertSame('original-name', $part->fresh()->slug);
    }

    public function test_blank_name_and_blank_slug_does_not_generate_slug(): void
    {
        $part = Part::query()->create(['name' => '', 'slug' => null]);

        $this->assertNull($part->slug);
    }

    public function test_polish_characters_are_transliterated_like_str_slug(): void
    {
        $name = 'Silnik kompletny Łódź Żółć';
        $part = Part::query()->create(['name' => $name]);

        $this->assertSame(Str::slug($name), $part->slug);
    }

    public function test_woo_import_payload_without_slug_gets_generated_slug_through_eloquent_create(): void
    {
        $part = Part::query()->create([
            'source_system' => 'woo',
            'external_id' => 'woo-123',
            'name' => 'Woo product without slug',
            'slug' => null,
        ]);

        $this->assertSame('woo-product-without-slug', $part->slug);
    }

    public function test_quick_eloquent_create_without_slug_gets_generated_slug(): void
    {
        $part = new Part(['name' => 'Quick form part']);
        $part->save();

        $this->assertSame('quick-form-part', $part->slug);
    }

    public function test_public_url_uses_slug_when_present_and_id_when_slug_is_blank(): void
    {
        $withSlug = Part::query()->create([
            'name' => 'Visible slug part',
            'slug' => 'visible-slug-part',
            'status' => 'ready',
            'quantity' => 1,
            'is_visible_storefront' => true,
        ]);
        $withoutSlug = Part::withoutEvents(fn () => Part::query()->create([
            'name' => 'Legacy blank slug part',
            'slug' => null,
            'status' => 'ready',
            'quantity' => 1,
            'is_visible_storefront' => true,
        ]));

        $this->assertSame(route('storefront.product', 'visible-slug-part'), PartResource::publicProductUrl($withSlug));
        $this->assertSame(route('storefront.product', $withoutSlug->getKey()), PartResource::publicProductUrl($withoutSlug));
    }

    public function test_titles_that_slugify_to_same_value_get_unique_suffixes(): void
    {
        $first = Part::query()->create(['name' => 'Silnik kompletny Łódź Żółć']);
        $second = Part::query()->create(['name' => 'Silnik kompletny Lodz Zolc']);

        $this->assertSame('silnik-kompletny-lodz-zolc', $first->slug);
        $this->assertSame('silnik-kompletny-lodz-zolc-2', $second->slug);
    }
}
