<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Marketplace\GoogleTranslateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FrTranslationsPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_counts_fresh_stale_incomplete_and_missing_without_writes_or_api(): void
    {
        Http::preventStrayRequests();
        $this->app->bind(GoogleTranslateService::class, fn () => throw new \RuntimeException('Preview must not resolve Google Translate'));
        $parts = [];
        foreach (['absent', 'translated', 'reviewed', 'stale', 'failed', 'queued', 'needs_update', 'incomplete', 'hidden'] as $i => $state) {
            $part = Part::create(['name' => 'Pièce '.$i, 'slug' => 'piece-'.$i, 'sku' => 'FR-'.$i,
                'description' => 'Opis źródłowy', 'status' => 'ready', 'price' => $state === 'hidden' ? 0 : 100,
                'quantity' => 2, 'needs_listing' => false, 'needs_review' => false]);
            $parts[] = $part;
            if ($state !== 'absent') {
                $part->translations()->create(['locale' => 'fr', 'status' => in_array($state, ['stale', 'incomplete', 'hidden']) ? 'translated' : $state,
                    'name' => 'Nom FR', 'description' => $state === 'incomplete' ? null : 'Description FR',
                    'source_hash' => $state === 'stale' ? str_repeat('0', 64) : $part->translationSourceHash()]);
            }
        }
        $currentCategory = PartCategory::create(['name' => 'Moteurs', 'slug' => 'moteurs', 'description' => 'Opis', 'is_visible' => true]);
        $currentCategory->translations()->create(['locale' => 'fr', 'name' => 'Moteurs FR', 'description' => 'Description', 'status' => 'reviewed', 'source_hash' => $currentCategory->translationSourceHash()]);
        PartCategory::create(['name' => 'Łączniki', 'slug' => 'laczniki', 'is_visible' => true]);
        PartCategory::create(['name' => 'Hidden', 'slug' => 'hidden', 'is_visible' => false]);
        PartCategory::create(['name' => 'Bez kategorii', 'slug' => 'uncategorized', 'is_visible' => true]);
        $tables = ['parts', 'part_categories', 'part_translations', 'category_translations'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $before = $snapshot();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->assertSame(0, Artisan::call('storefront:fr-translations-preview', ['--json' => true, '--examples' => 20]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['read_only']);
        $this->assertSame([], $report['schema_missing']);
        $this->assertSame(8, $report['products']['total']);
        $this->assertSame(2, $report['products']['current']);
        $this->assertSame(6, $report['products']['needing_translation']);
        $this->assertSame(['missing' => 1, 'needs_update' => 2, 'failed' => 1, 'not_ready' => 2], $report['products']['reasons']);
        $expectedChars = 0;
        foreach ([0, 3, 4, 5, 6, 7] as $index) {
            $expectedChars += array_sum(array_map('mb_strlen', $parts[$index]->translationSource()));
        }
        $this->assertSame($expectedChars, $report['products']['estimated_characters']);
        $this->assertSame(2, $report['categories']['total']);
        $this->assertSame(1, $report['categories']['current']);
        $this->assertSame(mb_strlen('Łączniki'), $report['categories']['estimated_characters']);
        $this->assertSame($parts[0]->translationSourceHash(), $report['products']['examples'][0]['source_hash_current']);
        $this->assertNull($report['products']['examples'][0]['source_hash_existing']);
        $this->assertFalse($report['products']['examples'][0]['translation_exists']);
        $this->assertSame($before, $snapshot());
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(select|pragma)\b/i', $sql);
        }
        Http::assertNothingSent();
        $this->assertSame(0, Artisan::call('storefront:fr-translations-preview', ['--examples' => 0]));
        $this->assertStringContainsString('read-only', Artisan::output());
        $this->assertSame($before, $snapshot());
    }

    public function test_preview_reports_missing_schema_without_creating_tables(): void
    {
        Schema::drop('part_translations');
        Schema::drop('category_translations');
        $this->assertSame(0, Artisan::call('storefront:fr-translations-preview', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['part_translations', 'category_translations'], $report['schema_missing']);
        $this->assertFalse(Schema::hasTable('part_translations'));
        $this->assertFalse(Schema::hasTable('category_translations'));
        $this->assertSame(0, $report['products']['total']);
    }
}
