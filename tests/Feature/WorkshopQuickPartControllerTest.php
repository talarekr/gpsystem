<?php

namespace Tests\Feature;

use App\Mail\WorkshopPartCreatedMail;
use App\Models\Part;
use App\Models\PartImage;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
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
    public function test_workshop_storage_autocomplete_returns_existing_locations_after_minimum_three_characters_without_import_description(): void
    {
        $this->actingAs(User::factory()->create());

        StorageLocation::query()->create([
            'name' => '2D3',
            'description' => StorageLocation::ALLEGRO_IMPORT_DESCRIPTION,
        ]);

        $this->getJson('/warsztat/storage-locations?q=2D')
            ->assertOk()
            ->assertJsonPath('data', []);

        $response = $this->getJson('/warsztat/storage-locations?q=2D3')
            ->assertOk()
            ->assertJsonPath('data.0.name', '2D3');

        $this->assertStringNotContainsString(StorageLocation::ALLEGRO_IMPORT_DESCRIPTION, $response->getContent());
    }

    public function test_workshop_selected_existing_storage_location_assigns_existing_id(): void
    {
        Storage::fake('public');
        $location = StorageLocation::query()->create(['name' => 'HGF7904']);

        $this->post('/tools/workshop/quick-part-create?token='.self::TOKEN, [
            'photos' => [UploadedFile::fake()->image('front.jpg')],
            'storage_location' => 'HGF7904',
            'storage_location_id' => $location->id,
            'part_number' => 'EXISTING-ID',
        ])->assertRedirect('/tools/workshop/quick-part-create?token='.self::TOKEN);

        $this->assertSame($location->id, Part::query()->where('part_number', 'EXISTING-ID')->value('storage_location_id'));
        $this->assertSame(1, StorageLocation::query()->where('name', 'HGF7904')->count());
    }

    public function test_workshop_does_not_duplicate_storage_location_matching_normalized_name(): void
    {
        Storage::fake('public');
        $location = StorageLocation::query()->create(['name' => 'KON 12']);

        $this->post('/tools/workshop/quick-part-create?token='.self::TOKEN, [
            'photos' => [UploadedFile::fake()->image('front.jpg')],
            'storage_location' => '  kon   12  ',
            'part_number' => 'NORMALIZED-ID',
        ])->assertRedirect('/tools/workshop/quick-part-create?token='.self::TOKEN);

        $this->assertSame($location->id, Part::query()->where('part_number', 'NORMALIZED-ID')->value('storage_location_id'));
        $this->assertSame(1, StorageLocation::query()->count());
    }

    public function test_workshop_creates_new_storage_location_only_when_no_match_exists_and_trims_whitespace(): void
    {
        Storage::fake('public');

        $this->post('/tools/workshop/quick-part-create?token='.self::TOKEN, [
            'photos' => [UploadedFile::fake()->image('front.jpg')],
            'storage_location' => '  Nowy   Regał  7  ',
            'part_number' => 'NEW-LOCATION-ID',
        ])->assertRedirect('/tools/workshop/quick-part-create?token='.self::TOKEN);

        $this->assertDatabaseHas('storage_locations', ['name' => 'Nowy Regał 7']);
        $this->assertSame('Nowy Regał 7', Part::query()->where('part_number', 'NEW-LOCATION-ID')->firstOrFail()->storageLocation->name);
    }

    public function test_workshop_storage_changes_do_not_enable_marketplace_or_api_writes(): void
    {
        Storage::fake('public');

        $beforeListings = Schema::hasTable('marketplace_listings') ? DB::table('marketplace_listings')->count() : null;

        $this->post('/tools/workshop/quick-part-create?token='.self::TOKEN, [
            'photos' => [UploadedFile::fake()->image('front.jpg')],
            'storage_location' => 'NOAPI',
            'part_number' => 'NOAPI-ID',
        ])->assertRedirect('/tools/workshop/quick-part-create?token='.self::TOKEN);

        $this->assertFalse(config('product-hub.feature_flags.external_api_writes_enabled'));
        $this->assertFalse(config('product-hub.feature_flags.ebay_publishing_enabled'));
        $this->assertFalse(config('product-hub.feature_flags.allegro_integration_enabled'));
        $this->assertFalse(config('product-hub.feature_flags.ovoko_integration_enabled'));

        if ($beforeListings !== null) {
            $this->assertSame($beforeListings, DB::table('marketplace_listings')->count());
        }
    }

    public function test_email_copy_checkbox_is_visible_and_checked_before_save_button(): void
    {
        $response = $this->get('/tools/workshop/quick-part-create?token='.self::TOKEN)
            ->assertOk()
            ->assertSee('Wyślij kopię wiadomości na e-mail')
            ->assertSee('name="send_email_copy" type="checkbox" value="1" checked', false);

        $html = $response->getContent();
        $this->assertLessThan(
            strpos($html, 'Zapisz część'),
            strpos($html, 'Wyślij kopię wiadomości na e-mail')
        );
    }

    public function test_checked_email_copy_sends_workshop_notification(): void
    {
        Storage::fake('public');
        Mail::fake();
        config(['services.workshop_intake.notification_email' => 'workshop@example.com']);

        $this->post('/tools/workshop/quick-part-create?token='.self::TOKEN, [
            'photos' => [UploadedFile::fake()->image('front.jpg')],
            'storage_location' => 'A1-P2',
            'part_number' => '3Q0919294F',
            'internal_note' => 'notatka warsztatowa',
            'send_email_copy' => '1',
        ])->assertRedirect('/tools/workshop/quick-part-create?token='.self::TOKEN);

        $part = Part::query()->with(['images', 'storageLocation'])->where('part_number', '3Q0919294F')->firstOrFail();

        Mail::assertSent(WorkshopPartCreatedMail::class, function (WorkshopPartCreatedMail $mail) use ($part): bool {
            return $mail->hasTo('workshop@example.com')
                && $mail->part->is($part)
                && $mail->envelope()->subject === 'Warsztat: A1-P2 - 3Q0919294F';
        });
    }

    public function test_unchecked_email_copy_does_not_send_workshop_notification(): void
    {
        Storage::fake('public');
        Mail::fake();
        config(['services.workshop_intake.notification_email' => 'workshop@example.com']);

        $this->post('/tools/workshop/quick-part-create?token='.self::TOKEN, [
            'photos' => [UploadedFile::fake()->image('front.jpg')],
            'storage_location' => 'A1-P2',
            'part_number' => 'NOEMAIL123',
            'send_email_copy' => '0',
        ])->assertRedirect('/tools/workshop/quick-part-create?token='.self::TOKEN);

        $this->assertDatabaseHas('parts', ['part_number' => 'NOEMAIL123']);
        Mail::assertNothingSent();
    }

    public function test_missing_notification_email_does_not_block_part_creation(): void
    {
        Storage::fake('public');
        Mail::fake();
        config(['services.workshop_intake.notification_email' => null]);

        $this->post('/tools/workshop/quick-part-create?token='.self::TOKEN, [
            'photos' => [UploadedFile::fake()->image('front.jpg')],
            'storage_location' => 'A1-P2',
            'part_number' => 'NOCONFIG123',
            'send_email_copy' => '1',
        ])->assertRedirect('/tools/workshop/quick-part-create?token='.self::TOKEN);

        $this->assertDatabaseHas('parts', ['part_number' => 'NOCONFIG123']);
        Mail::assertNothingSent();
    }

    public function test_workshop_notification_mail_contains_required_body_and_original_image_attachments(): void
    {
        Storage::fake('public');

        $this->post('/tools/workshop/quick-part-create?token='.self::TOKEN, [
            'photos' => [UploadedFile::fake()->image('front.jpg'), UploadedFile::fake()->image('side.jpg')],
            'storage_location' => 'A1-P2',
            'part_number' => 'BODY123',
            'internal_note' => 'opis testowy',
            'send_email_copy' => '0',
        ])->assertRedirect('/tools/workshop/quick-part-create?token='.self::TOKEN);

        $part = Part::query()->with(['images', 'storageLocation'])->where('part_number', 'BODY123')->firstOrFail();
        $mail = new WorkshopPartCreatedMail($part);

        $mail->assertSeeInHtml('Dodano nową część przez formularz warsztatowy.');
        $mail->assertSeeInHtml('BODY123');
        $mail->assertSeeInHtml('A1-P2');
        $mail->assertSeeInHtml('opis testowy');
        $mail->assertSeeInHtml((string) $part->id);
        $mail->assertDontSeeInHtml('Cena');
        $mail->assertDontSeeInHtml('Marketplace');
        $this->assertCount(2, $mail->attachments());
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
