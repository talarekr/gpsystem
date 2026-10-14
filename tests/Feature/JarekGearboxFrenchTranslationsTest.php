<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\JarekGearbox;
use App\Models\Part;
use App\Models\User;
use App\Services\Storefront\FrenchCatalogTranslationAdminRunner as Runner;
use App\Services\Storefront\FrenchCatalogTranslationPreviewService as Preview;
use App\Services\Storefront\FrenchCatalogTranslationRunStore as RunStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JarekGearboxFrenchTranslationsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/admin/tools/storefront/fr-translations';

    private const URL = self::BASE.'/jarek-gearboxes';

    private const SCOPE = 'jarek_gearboxes';

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['storefront-translations.fr_enabled' => true, 'storefront-translations.admin_runner' => 'queue',
            'services.google_translate.enabled' => true, 'services.google_translate.mode' => 'dry_run',
            'services.google_translate.key' => 'fake-jarek-key', 'queue.connections.storefront-translations.driver' => 'database']);
        Http::preventStrayRequests();
        Http::fake(['translation.googleapis.com/*' => fn ($request) => Http::response([
            'data' => ['translations' => [['translatedText' => 'FR '.$request['q']]]],
        ])]);
        Queue::fake();
        Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');
        $this->actor = User::create(['name' => 'Admin', 'email' => 'jarek-fr@example.test', 'password' => 'password']);
        $this->actor->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($this->actor);
    }

    public function test_same_page_contains_two_independent_sections_and_dry_run_only_counts_jarek_text(): void
    {
        $record = $this->gearbox();
        $before = $record->fresh()->getAttributes();
        $page = $this->get(self::BASE)->assertOk()->assertSee('Produkty i kategorie GPSwiss.fr')
            ->assertSee('Skrzynie Jarka — tłumaczenia FR')->assertSee(Runner::JAREK_CONFIRM)
            ->assertSee('id="jarek-start"', false)->assertSee('id="start"', false)->assertDontSee('fake-jarek-key');
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $ids = [];
        foreach ((new \DOMXPath($document))->query('//*[@id]') as $node) {
            $ids[] = $node->getAttribute('id');
        }
        $this->assertCount(count(array_unique($ids)), $ids);
        $report = $this->postJson(self::URL.'/dry-run')->assertOk()
            ->assertJsonPath('dry_run.scope', self::SCOPE)->assertJsonPath('dry_run.total_jarek_gearboxes', 1)
            ->assertJsonPath('dry_run.jarek_gearboxes.eligible', 1)->assertJsonPath('dry_run.jarek_gearboxes.fields', 2)
            ->assertJsonPath('dry_run.inventory.missing', 1)->json('dry_run');
        $this->assertSame(array_sum(array_map(fn ($t) => mb_strlen(trim($t)), $record->translationSource())), $report['estimated_characters']);
        $this->assertArrayNotHasKey('products', $report);
        $this->assertArrayNotHasKey('categories', $report);
        $this->assertNull(app(Preview::class)->saved($this->actor->id));
        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertDatabaseCount('parts', 0);
        $this->assertDatabaseCount('jarek_gearbox_translations', 0);
        Http::assertNothingSent();
    }

    public function test_confirmation_and_preview_are_scope_specific_and_existing_active_run_blocks_start(): void
    {
        $this->gearbox();
        $report = $this->postJson(self::URL.'/dry-run')->json('dry_run');
        $this->postJson(self::URL.'/start', ['dry_run_id' => $report['dry_run_id'], 'confirm' => Runner::CONFIRM])
            ->assertUnprocessable()->assertJsonPath('reason', 'invalid_confirmation');
        $catalog = $this->postJson(self::BASE.'/dry-run')->json('dry_run');
        $this->postJson(self::URL.'/start', ['dry_run_id' => $catalog['dry_run_id'], 'confirm' => Runner::JAREK_CONFIRM])
            ->assertUnprocessable()->assertJsonPath('reason', 'dry_run_required');
        $run = $this->postJson(self::URL.'/start', ['dry_run_id' => $report['dry_run_id'], 'confirm' => Runner::JAREK_CONFIRM])->assertOk()->json();
        $this->assertSame('browser', $run['runner_mode']);
        $this->postJson(self::URL.'/start', ['dry_run_id' => $report['dry_run_id'], 'confirm' => Runner::JAREK_CONFIRM])
            ->assertStatus(409)->assertJsonPath('reason', 'active_run');
        $this->assertDatabaseCount(RunStore::TABLE, 1);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_catalog_and_jarek_runs_do_not_share_status_locks_cursors_or_translations(): void
    {
        $part = Part::create(['name' => 'Silnik', 'sku' => 'CATALOG', 'price' => 100, 'quantity' => 3, 'status' => 'draft']);
        $record = $this->gearbox();
        $catalogPreview = $this->postJson(self::BASE.'/dry-run')->json('dry_run');
        $catalog = $this->postJson(self::BASE.'/start', ['dry_run_id' => $catalogPreview['dry_run_id'], 'confirm' => Runner::CONFIRM])->assertOk()->json();
        $jarekId = $this->start();
        $this->assertNotSame($catalog['run_id'], $jarekId);
        $this->getJson(self::BASE.'/status')->assertJsonPath('run_id', $catalog['run_id'])->assertJsonPath('total', 1);
        $this->postJson(self::URL.'/pause', ['run_id' => $catalog['run_id']])->assertStatus(409);
        $lock = Cache::lock(Runner::PROCESSING_LOCK, 600);
        $this->assertTrue($lock->get());
        try {
            $this->tick($jarekId)->assertOk()->assertJsonPath('processed', 1)->assertJsonPath('translated', 1);
        } finally {
            $lock->release();
        }
        $this->assertSame('translated', $record->translations()->first()->status);
        $this->assertNull($part->translations()->first());
        $this->getJson(self::BASE.'/status')->assertJsonPath('processed', 0);
        $this->assertDatabaseHas(RunStore::TABLE, ['run_id' => $jarekId, 'scope' => self::SCOPE]);
        $this->assertDatabaseHas(RunStore::TABLE, ['run_id' => $catalog['run_id'], 'scope' => 'catalog']);
        $this->assertSame(['jarek_gearboxes'], array_keys($this->checkpoint()['_cursor']));
    }

    public function test_one_record_per_tick_preserves_codes_and_never_writes_source_price_stock_or_parts(): void
    {
        $first = $this->gearbox(['title' => 'SKRZYNIA BIEGÓW XTRONIC GQQ DSG 02E DQ250 0AW 722.9 123456789 WDB12345678901234',
            'parameters' => [['name' => 'Kod skrzyni', 'values' => ['XTRONIC']]],
            'plain_description' => 'Pasuje do skrzyni 02E. OEM ABCD1234. Stan 1 szt.']);
        $second = $this->gearbox(['allegro_offer_id' => 'SECOND']);
        $before = JarekGearbox::query()->orderBy('id')->get()->map->getAttributes()->all();
        $runId = $this->start();
        $this->tick($runId)->assertOk()->assertJsonPath('processed', 1)->assertJsonPath('remaining', 1)->assertJsonPath('current_type', self::SCOPE);
        $translation = $first->translations()->first();
        $this->assertSame('translated', $translation->status);
        foreach (['XTRONIC', 'GQQ', 'DSG', '02E', 'DQ250', '0AW', '722.9', '123456789', 'WDB12345678901234'] as $code) {
            $this->assertStringContainsString($code, $translation->title);
        }
        Http::assertSent(fn ($request) => str_contains($request['q'], 'SKRZYNIA BIEGÓW') && ! str_contains($request['q'], 'XTRONIC') && ! str_contains($request['q'], 'DQ250') && ! str_contains($request['q'], '02E'));
        Http::assertSentCount(2);
        $this->assertNull($second->translations()->first());
        $this->assertSame($before, JarekGearbox::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertDatabaseCount('parts', 0);
        $this->assertDatabaseCount('marketplace_listings', 0);
        $this->assertDatabaseCount('stripe_fr_attempts', 0);
        Queue::assertNothingPushed();
    }

    public function test_code_and_numeric_only_fields_are_preserved_without_provider_calls(): void
    {
        $record = $this->gearbox(['title' => 'JAREK-123 722.9 GQQ', 'plain_description' => '12']);
        $id = $this->start();
        $this->tick($id)->assertJsonPath('translated', 1)->assertJsonPath('chars_translated', 0);
        $translation = $record->translations()->first();
        $this->assertSame($record->title, $translation->title);
        $this->assertSame('12', $translation->description);
        Http::assertNothingSent();
    }

    public function test_changed_code_marker_fails_without_persisting_bad_translation_or_retrying(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['translation.googleapis.com/*' => Http::response(['data' => ['translations' => [['translatedText' => 'Boîte DQ251']]]])]);
        $record = $this->gearbox(['title' => 'Skrzynia DQ250']);
        $id = $this->start();
        $this->tick($id)->assertOk()->assertJsonPath('failed', 1)->assertJsonPath('status', 'stopped_on_error')
            ->assertJsonPath('last_error.code', 'technical_code_changed');
        $translation = $record->translations()->first();
        $this->assertSame('failed', $translation->status);
        $this->assertNull($translation->title);
        $this->assertSame('technical_code_changed', $translation->error_code);
        $this->postJson(self::URL.'/resume', ['run_id' => $id])->assertOk();
        $this->tick($id)->assertJsonPath('status', 'completed');
        Http::assertSentCount(1);
    }

    public function test_pause_resume_stop_and_cache_loss_recover_same_durable_run(): void
    {
        $this->gearbox();
        $this->gearbox(['allegro_offer_id' => '2']);
        $id = $this->start();
        $this->tick($id)->assertJsonPath('processed', 1);
        $this->postJson(self::URL.'/pause', ['run_id' => $id])->assertJsonPath('status', 'paused');
        Cache::flush();
        app('auth')->forgetGuards();
        $this->actingAs($this->actor);
        $this->getJson(self::URL.'/status')->assertJsonPath('run_id', $id)->assertJsonPath('processed', 1)->assertJsonPath('checkpoint_source', 'database');
        $this->get(self::BASE)->assertOk()->assertSee('Skrzynie Jarka — tłumaczenia FR');
        $this->postJson(self::URL.'/resume', ['run_id' => $id])->assertOk()->assertJsonPath('run_id', $id);
        $this->tick($id)->assertJsonPath('processed', 2);
        $this->postJson(self::URL.'/stop', ['run_id' => $id])->assertJsonPath('status', 'stopped');
        $this->postJson(self::URL.'/resume', ['run_id' => $id])->assertStatus(409);
        Queue::assertNothingPushed();
    }

    public function test_existing_statuses_and_changed_source_are_skipped_by_current_policy(): void
    {
        foreach (['translated', 'reviewed', 'failed', 'needs_update'] as $status) {
            $record = $this->gearbox(['allegro_offer_id' => $status]);
            $record->translations()->create(['locale' => 'fr', 'status' => $status, 'title' => 'Protected',
                'source_hash' => $record->translationSourceHash(), 'attempts' => 1]);
        }
        $changed = $this->gearbox(['allegro_offer_id' => 'changed']);
        $changed->translations()->create(['locale' => 'fr', 'status' => 'translated', 'title' => 'Stary',
            'source_hash' => str_repeat('a', 64), 'attempts' => 1]);
        $report = $this->postJson(self::URL.'/dry-run')->assertOk()->assertJsonPath('dry_run.jarek_gearboxes.eligible', 0)
            ->assertJsonPath('dry_run.inventory.needs_update', 2)->json('dry_run');
        $id = $this->postJson(self::URL.'/start', ['dry_run_id' => $report['dry_run_id'], 'confirm' => Runner::JAREK_CONFIRM])->assertOk()->json('run_id');
        for ($i = 0; $i < 5; $i++) {
            $this->tick($id)->assertOk();
        }
        $this->getJson(self::URL.'/status')->assertJsonPath('processed', 5)->assertJsonPath('skipped', 5);
        $this->assertSame('needs_update', $changed->translations()->first()->status);
        $this->assertDatabaseCount('parts', 0);
        Http::assertNothingSent();
    }

    public function test_jarek_lease_and_record_lock_prevent_parallel_calls(): void
    {
        $record = $this->gearbox();
        $id = $this->start();
        foreach ([Runner::PROCESSING_LOCK.':'.self::SCOPE, 'storefront:fr:jarek_gearboxes:'.$record->id] as $key) {
            $lock = Cache::lock($key, 600);
            $this->assertTrue($lock->get());
            try {
                $this->tick($id)->assertJsonPath('processed', 0);
                Http::assertNothingSent();
            } finally {
                $lock->release();
            }
            $this->travel(31)->seconds();
        }
        $this->tick($id)->assertJsonPath('processed', 1);
    }

    public function test_interrupted_provider_attempt_is_recovered_without_charging_again(): void
    {
        $record = $this->gearbox();
        $id = $this->start();
        $run = $this->checkpoint();
        $run['_pending'] = ['type' => self::SCOPE, 'id' => $record->id, 'sku' => 'JAREK-'.$record->allegro_offer_id,
            'source_hash' => $record->translationSourceHash(), 'in_flight' => true, 'retry_attempt' => 0,
            'retry_at' => null, 'baseline_attempts' => 0, 'chars_translated' => 0, 'estimated_characters' => 20];
        app(RunStore::class)->forScope(self::SCOPE)->save($run);
        $record->translations()->create(['locale' => 'fr', 'status' => 'queued', 'attempts' => 1, 'source_hash' => $record->translationSourceHash()]);
        Cache::flush();
        $this->postJson(self::URL.'/resume', ['run_id' => $id])->assertOk();
        $this->tick($id)->assertJsonPath('processed', 1)->assertJsonPath('failed', 1)->assertJsonPath('last_error.code', 'interrupted_attempt');
        Http::assertNothingSent();
    }

    public function test_structured_description_is_text_only_and_empty_record_is_skipped(): void
    {
        $record = $this->gearbox(['plain_description' => null, 'description' => json_encode(['sections' => [['items' => [
            ['type' => 'TEXT', 'content' => '<p>Opis &amp; stan DQ250</p>'], ['type' => 'IMAGE', 'url' => 'https://example.test/image.jpg'],
        ]]]])]);
        $this->assertSame('Opis & stan DQ250', $record->translationSource()['description']);
        $this->gearbox(['allegro_offer_id' => 'empty', 'title' => '', 'description' => null, 'plain_description' => null]);
        $this->postJson(self::URL.'/dry-run')->assertJsonPath('dry_run.jarek_gearboxes.eligible', 1)
            ->assertJsonPath('dry_run.jarek_gearboxes.skipped_reasons.empty_source', 1);
        Http::assertNothingSent();
    }

    public function test_source_change_during_translation_needs_update_without_overwriting_source(): void
    {
        $record = $this->gearbox();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $changed = false;
        Http::fake(['translation.googleapis.com/*' => function ($request) use ($record, &$changed) {
            if (! $changed) {
                $record->update(['title' => 'Zmieniona skrzynia DQ250']);
                $changed = true;
            }

            return Http::response(['data' => ['translations' => [['translatedText' => 'FR '.$request['q']]]]]);
        }]);
        $id = $this->start();
        $this->tick($id)->assertJsonPath('last_error.code', 'source_changed')->assertJsonPath('failed', 1);
        $this->assertSame('needs_update', $record->translations()->first()->status);
        $this->assertNull($record->translations()->first()->title);
        $this->assertSame('Zmieniona skrzynia DQ250', $record->fresh()->title);
    }

    public function test_durable_in_flight_success_is_counted_once_after_cache_loss(): void
    {
        $record = $this->gearbox();
        $id = $this->start();
        $run = $this->checkpoint();
        $run['_pending'] = ['type' => self::SCOPE, 'id' => $record->id, 'sku' => 'JAREK-'.$record->allegro_offer_id,
            'source_hash' => $record->translationSourceHash(), 'in_flight' => true, 'retry_attempt' => 0,
            'retry_at' => null, 'baseline_attempts' => 0, 'chars_translated' => 0, 'estimated_characters' => 20];
        app(RunStore::class)->forScope(self::SCOPE)->save($run);
        $record->translations()->create(['locale' => 'fr', 'status' => 'translated', 'title' => 'Boîte DQ250',
            'attempts' => 1, 'source_hash' => $record->translationSourceHash()]);
        Cache::flush();
        $this->postJson(self::URL.'/resume', ['run_id' => $id])->assertOk();
        $this->tick($id)->assertJsonPath('processed', 1)->assertJsonPath('translated', 1);
        $this->tick($id)->assertJsonPath('status', 'completed')->assertJsonPath('processed', 1);
        Http::assertNothingSent();
    }

    public function test_jarek_routes_have_same_admin_and_french_host_restrictions(): void
    {
        $viewer = User::create(['name' => 'Viewer', 'email' => 'viewer-jarek@example.test', 'password' => 'password']);
        Role::findOrCreate(UserRole::Viewer->value, 'web');
        $viewer->assignRole(UserRole::Viewer->value);
        $this->actingAs($viewer)->postJson(self::URL.'/dry-run')->assertForbidden();
        $this->actingAs($this->actor)->getJson('https://gpswiss.fr'.self::URL.'/status')->assertForbidden();
        Http::assertNothingSent();
    }

    private function gearbox(array $values = []): JarekGearbox
    {
        return JarekGearbox::create($values + ['allegro_offer_id' => '1', 'title' => 'Skrzynia DQ250',
            'plain_description' => 'Sprawna skrzynia', 'price' => 1000, 'currency' => 'PLN', 'quantity' => 0, 'allegro_status' => 'ENDED']);
    }

    private function start(): string
    {
        $report = $this->postJson(self::URL.'/dry-run')->assertOk()->json('dry_run');

        return $this->postJson(self::URL.'/start', ['dry_run_id' => $report['dry_run_id'], 'confirm' => Runner::JAREK_CONFIRM])->assertOk()->json('run_id');
    }

    private function tick(string $id)
    {
        return $this->postJson(self::URL.'/process-next-batch', ['run_id' => $id]);
    }

    private function checkpoint(): array
    {
        return app(RunStore::class)->forScope(self::SCOPE)->latest();
    }
}
