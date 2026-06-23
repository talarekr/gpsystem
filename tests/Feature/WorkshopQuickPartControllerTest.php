<?php

namespace Tests\Feature;

use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkshopQuickPartControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'gps_images_import_2026';

    public function test_workshop_quick_part_page_requires_token(): void
    {
        $this->get('/tools/workshop/quick-part-create')->assertForbidden();
        $this->get('/tools/workshop/quick-part-create?token='.self::TOKEN)
            ->assertOk()
            ->assertSee('Zrób zdjęcie')
            ->assertSee('Wybierz z telefonu')
            ->assertSee('Magazyn / miejsce składowania')
            ->assertSee('Główny kod części')
            ->assertSee('Notatka wewnętrzna')
            ->assertSee('Zapisz część')
            ->assertSee('capture="environment"', false)
            ->assertDontSee('Krok 1/4');
    }

    public function test_required_fields_are_validated_and_internal_note_is_optional(): void
    {
        $this->post('/tools/workshop/quick-part-create?token='.self::TOKEN, [])
            ->assertSessionHasErrors(['photos', 'storage_location', 'part_number']);

        Storage::fake('public');

        $this->post('/tools/workshop/quick-part-create?token='.self::TOKEN, [
            'photos' => [UploadedFile::fake()->image('part.jpg')],
            'storage_location' => 'A1-P2',
            'part_number' => '8K0953568D',
        ])->assertRedirect('/tools/workshop/quick-part-create?token='.self::TOKEN);

        $this->assertDatabaseHas('parts', [
            'part_number' => '8K0953568D',
            'needs_listing' => true,
            'quantity' => 1,
            'status' => 'draft',
            'is_visible_storefront' => false,
            'needs_review' => false,
            'internal_note' => null,
        ]);
    }

    public function test_store_creates_local_needs_listing_part_with_note_photo_and_hidden_storefront_scope(): void
    {
        Storage::fake('public');

        $this->post('/tools/workshop/quick-part-create?token='.self::TOKEN, [
            'photos' => [UploadedFile::fake()->image('front.jpg'), UploadedFile::fake()->image('side.jpg')],
            'storage_location' => 'Hala B / Kosz 12',
            'part_number' => 'ABC123',
            'internal_note' => 'rysa, cena 300 zł',
        ])->assertRedirect('/tools/workshop/quick-part-create?token='.self::TOKEN);

        $part = Part::query()->with(['images', 'storageLocation'])->where('part_number', 'ABC123')->firstOrFail();

        $this->assertTrue($part->needs_listing);
        $this->assertSame(1, $part->quantity);
        $this->assertSame('draft', $part->status);
        $this->assertFalse($part->is_visible_storefront);
        $this->assertFalse($part->needs_review);
        $this->assertSame('rysa, cena 300 zł', $part->internal_note);
        $this->assertSame('Hala B / Kosz 12', $part->storageLocation->name);
        $this->assertCount(2, $part->images);
        $this->assertNotContains($part->id, Part::query()->storefrontVisible()->pluck('id')->all());

        foreach ($part->images as $image) {
            $this->assertStringStartsWith('parts/photos/workshop/'.$part->id.'/', $image->path);
            $this->assertStringNotStartsWith('/storage/', $image->path);
            $this->assertStringNotStartsWith('http://', $image->path);
            $this->assertStringNotStartsWith('https://', $image->path);
            $this->assertSame('workshop_quick_create', $image->source_system);
            $this->assertNotEmpty($image->external_id);
            $this->assertSame('/storage/'.$image->path, $image->relativePublicUrl());
            Storage::disk('public')->assertExists($image->path);
        }
    }
}
