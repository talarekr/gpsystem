<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FrenchStorefrontSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['frontend-maintenance.enabled' => false]);
    }

    public function test_fr_matches_translated_names_and_categories_but_pl_keeps_source_name_search(): void
    {
        $category = PartCategory::create(['name' => 'Silniki', 'slug' => 'silniki', 'is_visible' => true]);
        $category->translations()->create(['locale' => 'fr', 'status' => 'reviewed', 'name' => 'Moteurs']);
        $part = $this->part('Silnik Audi', '123-ABC', $category->id);
        $part->translations()->create(['locale' => 'fr', 'status' => 'translated', 'name' => 'Moteur diesel Audi']);
        foreach (['MOTEUR diesel', 'Moteurs', 'Moteurs Audi'] as $phrase) {
            $this->get('https://gpswiss.fr/czesci?q='.urlencode($phrase))->assertOk()
                ->assertViewHas('parts', fn ($parts) => $parts->total() === 1 && $parts->first()->id === $part->id);
        }
        $this->get('https://gpswiss.fr/czesci?category=Moteurs')->assertOk()
            ->assertViewHas('parts', fn ($parts) => $parts->total() === 1);
        $this->get('https://gpswiss.fr/czesci?q=Silnik')->assertOk()
            ->assertViewHas('parts', fn ($parts) => $parts->total() === 0);
        $this->get('https://gpswiss.pl/czesci?q=Silnik+Audi')->assertOk()
            ->assertViewHas('parts', fn ($parts) => $parts->total() === 1);
        $this->withSession(['locale' => 'fr'])->get('https://gpswiss.pl/czesci?q=Moteur')->assertOk()
            ->assertViewHas('parts', fn ($parts) => $parts->total() === 0);
        $this->assertSame('Silnik Audi', $part->fresh()->name);
    }

    public function test_fallback_and_nonpublic_translations_do_not_enter_search_or_change_oem_search(): void
    {
        $part = $this->part('Pompa testowa', 'OEM987');
        foreach (['failed', 'needs_update', 'missing', 'queued'] as $status) {
            $part->translations()->updateOrCreate(['locale' => 'fr'], ['status' => $status, 'name' => 'Pompe secrète']);
            $this->get('https://gpswiss.fr/czesci?q=Pompe')->assertOk()
                ->assertViewHas('parts', fn ($parts) => $parts->total() === 0);
            $this->get('https://gpswiss.fr/czesci?q=Pompa')->assertOk()
                ->assertViewHas('parts', fn ($parts) => $parts->total() === 1);
        }
        $part->translations()->update(['status' => 'reviewed', 'name' => ' ']);
        $this->get('https://gpswiss.fr/czesci?q=Pompa')->assertOk()
            ->assertViewHas('parts', fn ($parts) => $parts->total() === 1);
        foreach (['gpswiss.pl', 'gpswiss.fr'] as $host) {
            $this->get('https://'.$host.'/czesci?q=OEM987')->assertOk()
                ->assertViewHas('parts', fn ($parts) => $parts->total() === 1);
            $this->get('https://'.$host.'/czesci?part_number=OEM987')->assertOk()
                ->assertViewHas('parts', fn ($parts) => $parts->total() === 1);
        }
        $this->get('https://gpswiss.fr/szukaj?q=Pompa')->assertRedirect('https://gpswiss.fr/czesci?q=Pompa');
        Http::assertNothingSent();
    }

    public function test_all_tokens_and_visibility_still_apply_to_french_results(): void
    {
        $matching = $this->part('Silnik Audi', 'MATCH');
        $matching->translations()->create(['locale' => 'fr', 'status' => 'reviewed', 'name' => 'Moteur Audi diesel']);
        $other = $this->part('Silnik BMW', 'OTHER');
        $other->translations()->create(['locale' => 'fr', 'status' => 'reviewed', 'name' => 'Moteur BMW essence']);
        $sold = $this->part('Silnik Audi sold', 'SOLD');
        $sold->update(['status' => 'sold', 'quantity' => 0]);
        $sold->translations()->create(['locale' => 'fr', 'status' => 'reviewed', 'name' => 'Moteur Audi diesel']);
        $this->get('https://gpswiss.fr/czesci?q=Moteur+Audi+diesel')->assertOk()
            ->assertViewHas('parts', fn ($parts) => $parts->total() === 1 && $parts->first()->id === $matching->id);
        $this->get('https://gpswiss.pl/czesci?q=Silnik+Audi')->assertOk()
            ->assertViewHas('parts', fn ($parts) => $parts->total() === 1 && $parts->first()->id === $matching->id);
    }

    private function part(string $name, string $sku, ?int $categoryId = null): Part
    {
        return Part::create(['name' => $name, 'sku' => $sku, 'category_id' => $categoryId,
            'status' => 'ready', 'quantity' => 1, 'price' => 100, 'currency' => 'PLN']);
    }
}
