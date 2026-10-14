<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\FrenchProductTranslationRequest;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartImage;
use App\Models\User;
use App\Services\Storefront\FrenchCatalogTranslationRunStore;
use App\Services\Storefront\FrenchCatalogTranslationService;
use App\Services\Storefront\FrenchNewProductsTranslationRunner;
use App\Services\Storefront\FrenchProductTranslationRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\Concerns\ResolvesFrenchCategoryUrls;
use Tests\TestCase;

class FrenchNewProductsLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use ResolvesFrenchCategoryUrls;

    private const URL = 'https://gpswiss.pl/admin/tools/storefront/fr-translations/new-products';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['frontend-maintenance.enabled' => false, 'storefront-translations.fr_enabled' => true,
            'services.google_translate.enabled' => true, 'services.google_translate.mode' => 'dry_run', 'services.google_translate.key' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake(['translation.googleapis.com/*' => fn ($r) => Http::response(['data' => ['translations' => [['translatedText' => 'FR '.$r['q']]]]])]);
        Queue::fake();
        Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');
        $actor = User::create(['name' => 'Operator', 'email' => 'new-products@example.test', 'password' => 'password']);
        $actor->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($actor);
    }

    public function test_product_save_registers_pending_without_google_or_queue_and_both_hosts_share_visibility(): void
    {
        $part = $this->part(['is_visible_storefront' => false, 'currency' => 'PLN', 'price' => 400]);
        Cache::put('nbp_table_a_rate:EUR:latest', ['ok' => true, 'currency' => 'EUR', 'source' => 'NBP_TABLE_A', 'rate' => 5, 'effective_date' => today()->toDateString()], 3600);
        $this->assertSame($part->translationSourceHash(), FrenchProductTranslationRequest::sole()->source_hash);
        $this->assertSame('pending', FrenchProductTranslationRequest::sole()->status);
        $this->assertDatabaseCount('part_translations', 0);
        foreach (['pl', 'fr'] as $host) {
            $this->get('https://gpswiss.'.$host.'/czesci')->assertOk()->assertViewHas('parts', fn ($parts) => $parts->total() === 1);
        }
        PartImage::withoutEvents(fn () => PartImage::create(['part_id' => $part->id, 'path' => 'parts/new-photo.jpg', 'is_primary' => true]));
        $this->get('https://gpswiss.fr/produkt/'.$part->slug)->assertOk()->assertSee('parts/new-photo.jpg')->assertSee($part->name)->assertSee('100,00 EUR')->assertDontSee('PLN');
        $this->get('https://gpswiss.fr/sitemaps/products-1.xml')->assertOk()->assertSee('/produkt/'.$part->slug);
        $this->get('https://gpswiss.pl/admin/tools/storefront/fr-translations')->assertOk()->assertSee('Nowe/zmienione produkty')->assertSee(FrenchNewProductsTranslationRunner::CONFIRM);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_draft_with_checkbox_is_not_visible_and_becomes_eligible_on_shared_rules(): void
    {
        $part = $this->part(['status' => 'draft', 'is_visible_storefront' => true]);
        foreach (['pl', 'fr'] as $host) {
            $this->get('https://gpswiss.'.$host.'/czesci')->assertViewHas('parts', fn ($parts) => $parts->total() === 0);
        }
        $this->assertSame(0, $this->preview()['summary']['selected_count']);
        $part->update(['status' => 'ready']);
        $this->assertSame(1, $this->preview()['summary']['selected_count']);
        foreach (['needs_listing' => true, 'needs_review' => true, 'quantity' => 0, 'price' => 0] as $field => $value) {
            $part->update([$field => $value]);
            foreach (['pl', 'fr'] as $host) {
                $this->get('https://gpswiss.'.$host.'/czesci')->assertViewHas('parts', fn ($parts) => $parts->total() === 0);
            }
            $part->update([$field => in_array($field, ['needs_listing', 'needs_review']) ? false : 100]);
        }
        Http::assertNothingSent();
    }

    public function test_translated_changes_are_marked_needs_update_but_price_and_stock_changes_do_not_requeue(): void
    {
        $part = $this->part();
        $this->translation($part, 'translated');
        $initial = FrenchProductTranslationRequest::sole()->getAttributes();
        $part->update(['price' => 200, 'quantity' => 2]);
        $this->assertSame($initial, FrenchProductTranslationRequest::sole()->getAttributes());
        $part->update(['name' => 'Nowy silnik Audi']);
        $this->assertSame('needs_update', $part->translations()->sole()->status);
        $this->assertSame($part->translationSourceHash(), FrenchProductTranslationRequest::sole()->source_hash);
        $this->assertSame(1, $this->preview()['summary']['selected_count']);
        Http::assertNothingSent();
    }

    public function test_reviewed_changes_require_review_and_are_never_selected_or_overwritten(): void
    {
        $part = $this->part();
        $translation = $this->translation($part, 'reviewed');
        $before = $translation->fresh()->getAttributes();
        $part->update(['description' => 'Zmieniony opis']);
        $this->assertSame('needs_review', FrenchProductTranslationRequest::sole()->status);
        $this->assertSame($before, $translation->fresh()->getAttributes());
        $report = $this->preview(true);
        $this->assertSame(1, $report['summary']['reviewed_protected_count']);
        $this->assertSame(0, $report['summary']['selected_count']);
        Http::assertNothingSent();
    }

    public function test_confirmed_browser_batch_translates_once_without_worker_or_source_writes(): void
    {
        $part = $this->part();
        $before = $part->fresh()->getAttributes();
        $report = $this->preview();
        $this->postJson(self::URL.'/start', ['dry_run_id' => $report['dry_run_id'], 'confirm' => 'wrong'])->assertUnprocessable();
        $run = $this->start($report);
        $this->assertSame($run['run_id'], $this->start($report)['run_id']);
        Http::assertNothingSent();
        $this->tick($run)->assertOk()->assertJsonPath('status.translated', 1)->assertJsonPath('status.status', 'completed');
        $this->tick($run)->assertOk()->assertJsonPath('status.translated', 1);
        $this->assertSame('translated', $part->translations()->sole()->status);
        $this->assertSame('done', FrenchProductTranslationRequest::sole()->status);
        $this->assertSame($before, $part->fresh()->getAttributes());
        $this->assertSame('FR Silnik Audi', $part->fresh()->storefrontNameForLocale('fr'));
        $this->assertNull(app(FrenchCatalogTranslationRunStore::class)->latest());
        Http::assertSentCount(2);
        Queue::assertNothingPushed();
    }

    public function test_source_changes_invalidate_preview_and_then_skip_old_manifest_without_google(): void
    {
        $part = $this->part();
        $old = $this->preview();
        $part->update(['name' => 'Zmieniony silnik']);
        $this->postJson(self::URL.'/start', ['dry_run_id' => $old['dry_run_id'], 'confirm' => FrenchNewProductsTranslationRunner::CONFIRM])->assertUnprocessable();
        $run = $this->start($this->preview());
        $part->update(['description' => 'Jeszcze nowszy opis']);
        $this->tick($run)->assertJsonPath('status.skipped', 1);
        $this->assertSame('pending', FrenchProductTranslationRequest::sole()->status);
        $this->assertSame($part->translationSourceHash(), FrenchProductTranslationRequest::sole()->source_hash);
        Http::assertNothingSent();
    }

    public function test_shared_record_lock_and_incremental_lease_prevent_parallel_provider_calls(): void
    {
        $part = $this->part();
        $run = $this->start($this->preview());
        foreach ([FrenchNewProductsTranslationRunner::PROCESSING_LOCK, 'storefront:fr:parts:'.$part->id] as $key) {
            $lock = Cache::lock($key, 600);
            $this->assertTrue($lock->get());
            try {
                $this->tick($run)->assertJsonPath('status.processed', 0)->assertJsonPath('status.busy', true);
                Http::assertNothingSent();
            } finally {
                $lock->release();
            }
        }
        $this->tick($run)->assertJsonPath('status.translated', 1);
        Http::assertSentCount(2);
    }

    public function test_uncertain_inflight_attempt_after_cache_loss_is_not_repeated(): void
    {
        $this->part();
        $run = $this->start($this->preview());
        $run['in_flight'] = $run['manifest'][0];
        app(FrenchCatalogTranslationRunStore::class)->forScope('new_products')->save($run);
        Cache::flush();
        $this->tick($run)->assertJsonPath('status.failed', 1)->assertJsonPath('status.status', 'stopped_on_error')
            ->assertJsonPath('status.last_error.code', 'interrupted_attempt');
        $this->assertSame('failed', FrenchProductTranslationRequest::sole()->status);
        $this->assertSame(0, $this->preview()['summary']['selected_count']);
        Http::assertNothingSent();
    }

    public function test_durable_success_after_interrupted_checkpoint_is_counted_once(): void
    {
        $part = $this->part();
        $run = $this->start($this->preview());
        $run['in_flight'] = $run['manifest'][0];
        app(FrenchCatalogTranslationRunStore::class)->forScope('new_products')->save($run);
        $this->translation($part, 'translated');
        Cache::flush();
        $this->tick($run)->assertJsonPath('status.translated', 1)->assertJsonPath('status.status', 'completed');
        $this->tick($run)->assertJsonPath('status.translated', 1);
        Http::assertNothingSent();
    }

    public function test_bulk_import_is_local_only_and_preview_caps_records_and_characters(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->part(['sku' => 'BULK-'.$i, 'slug' => 'bulk-'.$i]);
        }
        config(['storefront-translations.new_products_max_records' => 3, 'storefront-translations.new_products_max_characters' => 100]);
        $report = $this->preview();
        $this->assertLessThanOrEqual(3, $report['summary']['selected_count']);
        $this->assertLessThanOrEqual(100, $report['summary']['selected_characters']);
        $this->assertGreaterThan(0, $report['summary']['deferred_count']);
        $this->assertDatabaseCount('storefront_fr_product_translation_requests', 25);
        $this->assertDatabaseCount('part_translations', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_provider_failure_stops_without_retry_and_failed_requires_new_explicit_approval(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['translation.googleapis.com/*' => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403)]);
        $part = $this->part();
        $run = $this->start($this->preview());
        $this->tick($run)->assertJsonPath('status.failed', 1)->assertJsonPath('status.status', 'stopped_on_error');
        $this->tick($run)->assertJsonPath('status.failed', 1);
        Http::assertSentCount(1);
        $this->assertSame('failed', $part->translations()->sole()->status);
        $this->assertSame(0, $this->preview()['summary']['selected_count']);
        $this->postJson(self::URL.'/control', ['run_id' => $run['run_id'], 'action' => 'stop'])->assertOk();
        $retry = $this->preview(true);
        $this->assertSame(1, $retry['summary']['selected_count']);
    }

    public function test_disabled_google_does_not_block_save_or_fallback_and_rollback_leaves_no_request(): void
    {
        config(['services.google_translate.enabled' => false]);
        $part = $this->part();
        $this->get('https://gpswiss.fr/produkt/'.$part->slug)->assertOk()->assertSee($part->name);
        $report = $this->preview();
        $this->postJson(self::URL.'/start', ['dry_run_id' => $report['dry_run_id'], 'confirm' => FrenchNewProductsTranslationRunner::CONFIRM])->assertUnprocessable();
        DB::beginTransaction();
        $this->part(['sku' => 'ROLLBACK', 'slug' => 'rollback']);
        DB::rollBack();
        $this->assertDatabaseCount('parts', 1);
        $this->assertDatabaseCount('storefront_fr_product_translation_requests', 1);
        Http::assertNothingSent();
    }

    public function test_expected_hash_is_checked_inside_translator_before_claim_and_google(): void
    {
        $part = $this->part();
        $result = app(FrenchCatalogTranslationService::class)->translate('parts', $part->id, ['expected_source_hash' => str_repeat('a', 64)]);
        $this->assertSame('source_changed', $result['reason']);
        $this->assertDatabaseCount('part_translations', 0);
        Http::assertNothingSent();
    }

    public function test_stale_nbp_refreshes_for_new_pln_product_without_changing_visibility_or_source(): void
    {
        $part = $this->part(['currency' => 'PLN', 'price' => 500]);
        Cache::put('nbp_table_a_rate:EUR:latest', ['ok' => true, 'source' => 'NBP_TABLE_A', 'currency' => 'EUR', 'rate' => 4, 'effective_date' => today()->subDays(8)->toDateString()], 3600);
        Http::fake(['api.nbp.pl/*' => Http::response(['rates' => [['mid' => 5, 'effectiveDate' => today()->toDateString(), 'no' => 'fixture']]])]);
        $this->get('https://gpswiss.fr/produkt/'.$part->slug)->assertOk()->assertSee('125,00 EUR')->assertDontSee('PLN');
        $this->get('https://gpswiss.pl/produkt/'.$part->slug)->assertOk()->assertSee('500,00 PLN');
        $this->assertSame('500.00', $part->fresh()->price);
        $this->assertSame(1, $part->fresh()->quantity);
        Http::assertSentCount(1);
    }

    public function test_pause_resume_and_stop_never_call_google_by_themselves(): void
    {
        $this->part();
        $run = $this->start($this->preview());
        $this->postJson(self::URL.'/control', ['run_id' => $run['run_id'], 'action' => 'pause'])->assertJsonPath('status.status', 'paused');
        $this->tick($run)->assertJsonPath('status.processed', 0);
        $this->postJson(self::URL.'/control', ['run_id' => $run['run_id'], 'action' => 'resume'])->assertJsonPath('status.status', 'running');
        $this->postJson(self::URL.'/control', ['run_id' => $run['run_id'], 'action' => 'stop'])->assertJsonPath('status.status', 'stopped');
        $this->tick($run)->assertJsonPath('status.processed', 0);
        Http::assertNothingSent();
    }

    public function test_source_change_during_google_keeps_new_request_pending_without_overwriting_source(): void
    {
        $part = $this->part();
        $run = $this->start($this->preview());
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $changed = false;
        Http::fake(['translation.googleapis.com/*' => function ($r) use ($part, &$changed) {
            if (! $changed) {
                $changed = true;
                $part->update(['description' => 'Opis zmieniony podczas próby']);
            }

            return Http::response(['data' => ['translations' => [['translatedText' => 'FR '.$r['q']]]]]);
        }]);
        $this->tick($run)->assertJsonPath('status.failed', 1)->assertJsonPath('status.last_error.code', 'source_changed');
        $this->assertSame('needs_update', $part->translations()->sole()->status);
        $this->assertSame('Opis zmieniony podczas próby', $part->fresh()->description);
        $this->assertSame($part->fresh()->translationSourceHash(), FrenchProductTranslationRequest::sole()->source_hash);
        $this->assertSame('pending', FrenchProductTranslationRequest::sole()->status);
    }

    public function test_missing_nbp_blocks_only_fr_purchase_price_and_preserves_catalog_visibility(): void
    {
        $part = $this->part(['currency' => 'PLN', 'price' => 500]);
        Http::fake(['api.nbp.pl/*' => Http::response([], 503)]);
        $this->get('https://gpswiss.fr/produkt/'.$part->slug)->assertOk()->assertSee(__('storefront.fr_price_unavailable', [], 'fr'))->assertDontSee('PLN');
        foreach (['pl', 'fr'] as $host) {
            $this->get('https://gpswiss.'.$host.'/czesci')->assertViewHas('parts', fn ($parts) => $parts->total() === 1);
        }
        $this->get('https://gpswiss.pl/produkt/'.$part->slug)->assertOk()->assertSee('500,00 PLN');
        $this->assertSame('500.00', $part->fresh()->price);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'translation.googleapis.com'));
    }

    public function test_tracking_failure_cannot_fail_product_save(): void
    {
        $this->mock(FrenchProductTranslationRequests::class)->shouldReceive('register')->once()->andThrow(new \RuntimeException('optional tracking failure'));
        Log::spy();
        $part = $this->part();
        $this->assertTrue($part->exists);
        $this->assertDatabaseCount('parts', 1);
        Log::shouldHaveReceived('warning')->with('fr_product_translation_registration_failed', ['part_id' => $part->id, 'exception_type' => \RuntimeException::class]);
        Http::assertNothingSent();
    }

    public function test_new_endpoints_require_admin_on_pl_and_owned_preview(): void
    {
        $this->part();
        $report = $this->preview();
        $this->getJson('https://gpswiss.fr/admin/tools/storefront/fr-translations/new-products/status')->assertForbidden();
        $other = User::create(['name' => 'Other', 'email' => 'other-new@example.test', 'password' => 'password']);
        $this->actingAs($other)->getJson(self::URL.'/status')->assertForbidden();
        $other->assignRole(UserRole::OwnerAdmin->value);
        $this->postJson(self::URL.'/start', ['dry_run_id' => $report['dry_run_id'], 'confirm' => FrenchNewProductsTranslationRunner::CONFIRM])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_first_visible_product_refreshes_previously_empty_category_cache_on_both_hosts(): void
    {
        $category = PartCategory::create(['name' => 'Nowa kategoria', 'slug' => 'new-category', 'source_system' => 'woo', 'is_visible' => true, 'woo_product_count' => 0]);
        $category->translations()->create(['locale' => 'fr', 'status' => 'reviewed', 'name' => 'Nouvelle catégorie']);
        $this->get('https://gpswiss.pl/czesci')->assertOk()->assertDontSee('Nowa kategoria');
        $this->part(['category_id' => $category->id]);
        $this->get($this->frenchCategoryUrl($category))->assertOk()->assertSee('Nouvelle catégorie')->assertViewHas('parts', fn ($parts) => $parts->total() === 1);
        $this->get('https://gpswiss.pl/kategoria-produktu/new-category')->assertOk()->assertSee('Nowa kategoria')->assertViewHas('parts', fn ($parts) => $parts->total() === 1);
        Http::assertNothingSent();
    }

    public function test_bulk_sql_without_events_can_be_registered_from_browser_without_google_or_product_writes(): void
    {
        $part = Part::withoutEvents(fn () => $this->part());
        $before = $part->fresh()->getAttributes();
        $this->assertDatabaseCount('storefront_fr_product_translation_requests', 0);
        $this->postJson(self::URL.'/register', ['part_id' => $part->id])->assertOk();
        $this->postJson(self::URL.'/register', ['part_id' => $part->id])->assertOk();
        $this->assertDatabaseCount('storefront_fr_product_translation_requests', 1);
        $this->assertSame(1, $this->preview()['summary']['selected_count']);
        $this->assertSame($before, $part->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    private function part(array $values = []): Part
    {
        return Part::create($values + ['name' => 'Silnik Audi', 'description' => 'Sprawny silnik', 'sku' => 'NEW-FR', 'slug' => 'new-fr', 'price' => 100, 'currency' => 'EUR', 'quantity' => 1, 'status' => 'ready']);
    }

    private function translation(Part $part, string $status)
    {
        return $part->translations()->create(['locale' => 'fr', 'status' => $status, 'name' => 'Moteur Audi', 'description' => 'Description FR', 'source_hash' => $part->translationSourceHash()]);
    }

    private function preview(bool $failed = false): array
    {
        return $this->postJson(self::URL.'/dry-run', ['include_failed' => $failed])->assertOk()->json('report');
    }

    private function start(array $report): array
    {
        return $this->postJson(self::URL.'/start', ['dry_run_id' => $report['dry_run_id'], 'confirm' => FrenchNewProductsTranslationRunner::CONFIRM])->assertOk()->json('status');
    }

    private function tick(array $run)
    {
        return $this->postJson(self::URL.'/process-next-batch', ['run_id' => $run['run_id']]);
    }
}
