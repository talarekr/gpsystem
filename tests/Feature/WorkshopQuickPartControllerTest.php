<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartImage;
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
            'description' => null,
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
        $this->assertSame('rysa, cena 300 zł', $part->description);
        $this->assertSame('Hala B / Kosz 12', $part->storageLocation->name);
        $this->assertCount(2, $part->images);
        $this->assertNotContains($part->id, Part::query()->storefrontVisible()->pluck('id')->all());

        foreach ($part->images as $image) {
            $this->assertStringStartsWith('parts/photos/imported/'.$part->id.'/', $image->path);
            $this->assertStringNotStartsWith('/storage/', $image->path);
            $this->assertStringNotStartsWith('http://', $image->path);
            $this->assertStringNotStartsWith('https://', $image->path);
            $this->assertSame('workshop_quick_create', $image->source_system);
            $this->assertNotEmpty($image->external_id);
            $this->assertSame('/storage/'.$image->path, $image->relativePublicUrl());
            Storage::disk('public')->assertExists($image->path);
        }
    }

    public function test_workshop_image_diagnostics_reports_admin_urls_and_storage_state(): void
    {
        Storage::fake('public');

        $this->post('/tools/workshop/quick-part-create?token='.self::TOKEN, [
            'photos' => [UploadedFile::fake()->image('front.jpg')],
            'storage_location' => 'A1',
            'part_number' => 'DIAG123',
        ])->assertRedirect('/tools/workshop/quick-part-create?token='.self::TOKEN);

        $part = Part::query()->with('images')->where('part_number', 'DIAG123')->firstOrFail();
        $image = $part->images->first();

        $this->get('/tools/check-workshop-part-images?token='.self::TOKEN.'&part_id='.$part->id)
            ->assertOk()
            ->assertJsonPath('part_id', $part->id)
            ->assertJsonPath('images_relation_count', 1)
            ->assertJsonPath('images.0.id', $image->id)
            ->assertJsonPath('images.0.storage_public_exists', true)
            ->assertJsonPath('images.0.is_primary', true)
            ->assertJsonPath('images.0.admin_image_url_accessor', $image->absolutePublicUrl())
            ->assertJsonPath('images.0.storage_url', 'https://gpswiss.pl/storage/'.$image->path)
            ->assertJsonPath('images.0.storage_url_host', 'gpswiss.pl')
            ->assertJsonPath('images.0.gpswiss_storage_url', 'https://gpswiss.pl/storage/'.$image->path)
            ->assertJsonPath('images.0.relative_storage_url', '/storage/'.$image->path);
    }


    public function test_workshop_image_diagnostics_can_repair_one_direct_workshop_original_path(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('parts/photos/original.jpg', 'fake image');

        $part = Part::factory()->create();
        $image = PartImage::query()->create([
            'part_id' => $part->id,
            'path' => 'parts/photos/original.jpg',
            'source_system' => 'workshop_quick_create',
            'legacy_payload' => [
                'presentation' => [
                    'source_path' => 'parts/photos/original.jpg',
                ],
            ],
        ]);

        $this->get('/tools/check-workshop-part-images?token='.self::TOKEN.'&part_id='.$part->id.'&repair_direct_original=1')
            ->assertOk()
            ->assertJsonPath('repair_direct_original.0.image_id', $image->id)
            ->assertJsonPath('repair_direct_original.0.old_path', 'parts/photos/original.jpg')
            ->assertJsonPath('repair_direct_original.0.new_path', 'parts/photos/imported/'.$part->id.'/original.jpg')
            ->assertJsonPath('repair_direct_original.0.status', 'repaired')
            ->assertJsonPath('images.0.path', 'parts/photos/imported/'.$part->id.'/original.jpg');

        Storage::disk('public')->assertExists('parts/photos/imported/'.$part->id.'/original.jpg');
        $this->assertSame('parts/photos/imported/'.$part->id.'/original.jpg', $image->fresh()->path);
    }

}
