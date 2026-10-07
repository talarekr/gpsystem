<?php

namespace Tests\Feature;

use App\Jobs\TranslateCategoryToFrenchJob;
use App\Jobs\TranslatePartToFrenchJob;
use App\Models\Part;
use App\Models\PartCategory;
use App\Services\Marketplace\GoogleTranslateService;
use App\Services\Storefront\FrenchCatalogTranslationService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class FrenchCatalogTranslationTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND = 'storefront:fr-translations-translate-all';

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront-translations.fr_enabled' => true,
            'services.google_translate.enabled' => true, 'services.google_translate.mode' => 'dry_run',
            'services.google_translate.key' => 'test-key-never-sent']);
        Http::preventStrayRequests();
        Sleep::fake();
    }

    private function part(array $attributes = []): Part
    {
        static $id = 0;
        $id++;

        return Part::create($attributes + ['name' => 'Silnik', 'slug' => 'silnik-'.$id, 'sku' => 'FR-ALL-'.$id,
            'price' => 100, 'quantity' => 2, 'status' => 'ready', 'needs_listing' => false, 'needs_review' => false]);
    }

    private function category(array $attributes = []): PartCategory
    {
        static $id = 0;
        $id++;

        return PartCategory::create($attributes + ['name' => 'Silniki '.$id, 'slug' => 'silniki-'.$id, 'is_visible' => true]);
    }

    private function fakeSuccess(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['translation.googleapis.com/*' => fn ($request) => Http::response([
            'data' => ['translations' => [['translatedText' => 'FR '.$request['q']]]],
        ])]);
    }

    private function apply(array $options = []): int
    {
        return Artisan::call(self::COMMAND, $options + ['--apply' => true, '--confirm' => 'TRANSLATE-ALL-GPSWISS-FR-CATALOG']);
    }

    private function translation(Part|PartCategory $record, string $status, ?string $hash = null): void
    {
        $record->translations()->create(['locale' => 'fr', 'name' => 'Nom validé', 'status' => $status,
            'source_hash' => $hash ?? $record->translationSourceHash(), 'reviewed_at' => $status === 'reviewed' ? now() : null]);
    }

    public function test_dry_run_and_default_are_read_only_and_do_not_resolve_provider(): void
    {
        config(['storefront-translations.fr_enabled' => false]);
        $this->part(['description' => 'Opis', 'short_description' => 'Skrót', 'condition_notes' => 'Rysa']);
        $this->category(['name' => 'Silniki', 'description' => 'Kategorie']);
        $this->app->bind(GoogleTranslateService::class, fn () => throw new \RuntimeException('Dry-run resolved provider'));
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        foreach ([[], ['--dry-run' => true]] as $options) {
            $this->assertSame(0, Artisan::call(self::COMMAND, $options));
            $output = Artisan::output();
            $this->assertStringContainsString('DRY-RUN', $output);
            $this->assertStringContainsString('parts {"total":1,"eligible":1,"skipped":0,"fields":4,"estimated_characters":19}', $output);
            $this->assertStringContainsString('categories {"total":1,"eligible":1,"skipped":0,"fields":2,"estimated_characters":16}', $output);
        }
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(select|pragma)\b/i', $sql);
        }
        $this->assertDatabaseCount('part_translations', 0);
        $this->assertDatabaseCount('category_translations', 0);
        Http::assertNothingSent();
    }

    public function test_apply_requires_flag_token_and_ready_provider_before_writing(): void
    {
        $this->part();
        config(['storefront-translations.fr_enabled' => false]);
        $this->assertSame(1, $this->apply());
        $this->assertStringContainsString('GPSWISS_FR_TRANSLATIONS_ENABLED=true', Artisan::output());
        config(['storefront-translations.fr_enabled' => true]);
        foreach ([[], ['--confirm' => 'wrong']] as $options) {
            $this->assertSame(1, Artisan::call(self::COMMAND, $options + ['--apply' => true]));
            $this->assertStringContainsString('requires --confirm=', Artisan::output());
        }
        config(['services.google_translate.key' => '']);
        $this->assertSame(1, $this->apply());
        $this->assertStringContainsString('not ready', Artisan::output());
        $this->assertDatabaseCount('part_translations', 0);
        Http::assertNothingSent();
    }

    public function test_apply_translates_entire_catalog_in_chunks_and_preserves_source_and_other_tables(): void
    {
        $this->fakeSuccess();
        $parts = collect(range(1, 5))->map(fn ($i) => $this->part(['price' => $i === 5 ? 0 : 100,
            'description' => 'Opis', 'short_description' => 'Skrót', 'condition_notes' => 'Rysa']));
        $categories = collect(range(1, 3))->map(fn ($i) => $this->category(['description' => 'Opis', 'is_visible' => $i !== 3]));
        $placeholder = $this->part();
        $placeholder->translations()->create(['locale' => 'fr', 'status' => 'missing']);
        $tables = ['parts', 'part_categories', 'orders', 'order_items', 'marketplace_accounts', 'marketplace_listings', 'marketplace_sync_logs'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $before = $snapshot();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $this->assertSame(0, $this->apply(['--only-missing' => true, '--chunk' => 2]));
        $this->assertStringContainsString('"processed":9,"translated":9,"skipped":0,"failed":0', Artisan::output());
        foreach ($parts->concat([$placeholder])->concat($categories) as $record) {
            $row = $record->translations()->where('locale', 'fr')->firstOrFail();
            $this->assertSame('translated', $row->status);
            $this->assertSame($record->translationSourceHash(), $row->source_hash);
            $this->assertSame('google_translate', $row->provider);
            $this->assertSame(1, $row->attempts);
            $this->assertNotNull($row->translated_at);
            foreach ($record->translationSource() as $field => $text) {
                $this->assertSame($text === '' ? null : 'FR '.$text, $row->{$field});
            }
        }
        $this->assertSame($before, $snapshot());
        foreach ($writes as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(insert into|update|delete from)\s+["`]*(part_translations|category_translations)\b/i', $sql);
        }
        Http::assertSentCount(27);
        Http::assertSent(fn ($request) => $request['source'] === 'pl' && $request['target'] === 'fr');
        $this->assertSame(0, $this->apply());
        $this->assertStringContainsString('"processed":9,"translated":0,"skipped":9', Artisan::output());
        Http::assertSentCount(27);
    }

    public function test_part_and_category_jobs_save_translated_and_after_commit(): void
    {
        $this->fakeSuccess();
        $part = $this->part(['description' => 'Opis']);
        $category = $this->category(['description' => 'Opis kategorii']);
        $jobs = [new TranslatePartToFrenchJob($part->id), new TranslateCategoryToFrenchJob($category->id)];
        foreach ($jobs as $job) {
            $this->assertTrue($job->afterCommit);
            $this->assertSame('storefront-translations', $job->connection);
            $this->assertSame('storefront-fr-translations', $job->queue);
            $this->assertGreaterThan($job->timeout, config('queue.connections.storefront-translations.retry_after'));
            $this->assertSame([30, 120, 300], $job->backoff());
            $job->handle(app(FrenchCatalogTranslationService::class));
        }
        $this->assertDatabaseHas('part_translations', ['part_id' => $part->id, 'status' => 'translated', 'name' => 'FR Silnik']);
        $this->assertDatabaseHas('category_translations', ['category_id' => $category->id, 'status' => 'translated', 'name' => 'FR '.$category->name]);
        $this->assertSame('FR Silnik', $part->fresh()->storefrontNameForLocale('fr'));
        $this->assertSame('Silnik', $part->fresh()->storefrontNameForLocale('pl'));
        Http::assertSentCount(4);
    }

    public function test_jobs_cannot_call_provider_or_write_when_flag_is_off(): void
    {
        config(['storefront-translations.fr_enabled' => false]);
        (new TranslatePartToFrenchJob($this->part()->id))->handle(app(FrenchCatalogTranslationService::class));
        (new TranslateCategoryToFrenchJob($this->category()->id))->handle(app(FrenchCatalogTranslationService::class));
        $this->assertDatabaseCount('part_translations', 0);
        $this->assertDatabaseCount('category_translations', 0);
        Http::assertNothingSent();
    }

    public function test_jobs_are_enqueued_only_after_commit_and_discarded_on_rollback(): void
    {
        $part = $this->part();
        $category = $this->category();
        DB::transaction(function () use ($part): void {
            Bus::dispatch(new TranslatePartToFrenchJob($part->id));
            $this->assertDatabaseCount('jobs', 0);
        });
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['queue' => 'storefront-fr-translations']);
        try {
            DB::transaction(function () use ($category): void {
                Bus::dispatch(new TranslateCategoryToFrenchJob($category->id));
                $this->assertDatabaseCount('jobs', 1);
                throw new \RuntimeException('Rollback test transaction');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Rollback test transaction', $exception->getMessage());
        }
        $this->assertDatabaseCount('jobs', 1);
        Http::assertNothingSent();
    }

    public function test_current_translated_and_reviewed_including_stale_reviewed_are_protected(): void
    {
        $this->fakeSuccess();
        foreach (['translated', 'reviewed'] as $status) {
            $part = $this->part();
            $category = $this->category();
            $this->translation($part, $status);
            $this->translation($category, $status);
        }
        $stale = $this->part();
        $this->translation($stale, 'reviewed', str_repeat('0', 64));
        $rowsBefore = DB::table('part_translations')->get()->toJson();
        (new TranslatePartToFrenchJob($stale->id, ['include_needs_update' => true]))->handle(app(FrenchCatalogTranslationService::class));
        $this->assertSame(0, $this->apply(['--include-needs-update' => true, '--include-failed' => true]));
        $this->assertSame($rowsBefore, DB::table('part_translations')->get()->toJson());
        Http::assertNothingSent();
    }

    public function test_source_mismatch_marks_needs_update_then_explicit_option_refreshes_both_types(): void
    {
        $this->fakeSuccess();
        $part = $this->part();
        $category = $this->category();
        foreach ([$part, $category] as $record) {
            $this->translation($record, 'translated', str_repeat('0', 64));
        }
        $this->assertSame(0, $this->apply(['--only-missing' => true]));
        Http::assertNothingSent();
        foreach ([$part, $category] as $record) {
            $this->assertSame('needs_update', $record->translations()->first()->status);
        }
        $this->assertSame(0, $this->apply(['--only-missing' => true, '--include-needs-update' => true]));
        Http::assertSentCount(2);
        foreach ([$part, $category] as $record) {
            $row = $record->translations()->first();
            $this->assertSame('translated', $row->status);
            $this->assertSame($record->translationSourceHash(), $row->source_hash);
        }
    }

    public function test_provider_errors_are_failed_sanitized_and_explicitly_resumable(): void
    {
        Http::fake(['translation.googleapis.com/*' => Http::response(['error' => [
            'message' => 'secret-value-in-untrusted-provider-message token=supersecret Bearer unsafe',
        ]], 403)]);
        Log::spy();
        $part = $this->part();
        (new TranslatePartToFrenchJob($part->id))->handle(app(FrenchCatalogTranslationService::class));
        $row = $part->translations()->first();
        $this->assertSame('failed', $row->status);
        $this->assertSame('google_http_403', $row->error_code);
        $this->assertSame('Google Translate returned HTTP 403.', $row->error_message);
        $this->assertSame(1, $row->attempts);
        $this->assertNull($row->translated_at);
        Log::shouldHaveReceived('info')->with('storefront.fr_catalog_translation', \Mockery::on(fn ($context) => $context['part_id'] === $part->id && $context['sku'] === $part->sku && $context['error_code'] === 'google_http_403'
            && ! str_contains(json_encode($context), 'supersecret') && ! str_contains(json_encode($context), 'test-key')))->once();
        $this->assertSame(0, $this->apply());
        Http::assertSentCount(1);
        $this->fakeSuccess();
        $this->assertSame(0, $this->apply(['--only-missing' => true, '--include-failed' => true]));
        $this->assertSame('translated', $row->fresh()->status);
        $this->assertSame(2, $row->fresh()->attempts);
        $this->assertNull($row->fresh()->error_message);
    }

    public function test_command_retries_429_and_5xx_with_backoff_and_counts_attempts(): void
    {
        Http::fake(['translation.googleapis.com/*' => Http::sequence()->push([], 429)->push([], 503)
            ->push(['data' => ['translations' => [['translatedText' => 'Moteur']]]])]);
        $part = $this->part();
        $this->assertSame(0, $this->apply(['--type' => 'parts']));
        $this->assertSame(3, $part->translations()->first()->attempts);
        $this->assertSame('translated', $part->translations()->first()->status);
        Sleep::assertSequence([Sleep::for(30)->seconds(), Sleep::for(120)->seconds()]);
        Http::assertSentCount(3);
    }

    public function test_retries_are_bounded_and_command_continues_to_next_record(): void
    {
        $first = $this->part();
        $second = $this->part(['name' => 'Drzwi']);
        Http::fake(['translation.googleapis.com/*' => fn ($request) => $request['q'] === 'Silnik'
            ? Http::response([], 500) : Http::response(['data' => ['translations' => [['translatedText' => 'Porte']]]])]);
        $this->assertSame(1, $this->apply());
        $this->assertSame(4, $first->translations()->first()->attempts);
        $this->assertSame('failed', $first->translations()->first()->status);
        $this->assertSame('translated', $second->translations()->first()->status);
        $this->assertStringContainsString('"processed":2,"translated":1,"skipped":0,"failed":1', Artisan::output());
        Http::assertSentCount(5);
    }

    public function test_job_transient_exception_contains_only_controlled_error_code(): void
    {
        Http::fake(['translation.googleapis.com/*' => Http::response(['error' => ['message' => 'Bearer secret']], 429)]);
        $part = $this->part();
        try {
            (new TranslatePartToFrenchJob($part->id))->handle(app(FrenchCatalogTranslationService::class));
            $this->fail('Expected retry exception');
        } catch (\RuntimeException $exception) {
            $this->assertSame('French catalog translation transient failure: google_http_429', $exception->getMessage());
        }
        $this->assertSame('failed', $part->translations()->first()->status);
    }

    public function test_category_failure_and_queued_retry_use_the_same_guards(): void
    {
        $category = $this->category();
        Http::fake(['translation.googleapis.com/*' => Http::response([], 503)]);
        try {
            (new TranslateCategoryToFrenchJob($category->id))->handle(app(FrenchCatalogTranslationService::class));
            $this->fail('Expected retry exception');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('google_http_503', $exception->getMessage());
        }
        $this->assertSame('failed', $category->translations()->first()->status);
        $this->fakeSuccess();
        $job = new TranslateCategoryToFrenchJob($category->id);
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->once()->andReturn(2);
        $job->setJob($queueJob);
        $job->handle(app(FrenchCatalogTranslationService::class));
        $this->assertSame('translated', $category->translations()->first()->status);
        $this->assertSame(2, $category->translations()->first()->attempts);
    }

    public function test_disabling_flag_between_fields_prevents_further_calls_and_partial_save(): void
    {
        $part = $this->part(['description' => 'Opis']);
        Http::fake(['translation.googleapis.com/*' => function () {
            config(['storefront-translations.fr_enabled' => false]);

            return Http::response(['data' => ['translations' => [['translatedText' => 'Moteur']]]]);
        }]);
        $result = app(FrenchCatalogTranslationService::class)->translate('parts', $part->id);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('translations_disabled', $part->translations()->first()->error_code);
        $this->assertNull($part->translations()->first()->name);
        $this->assertNull($part->translations()->first()->translated_at);
        Http::assertSentCount(1);
    }

    public function test_source_changed_during_api_cannot_publish_stale_translation(): void
    {
        $part = $this->part();
        $oldHash = $part->translationSourceHash();
        Http::fake(['translation.googleapis.com/*' => function () use ($part) {
            $part->update(['name' => 'Nowy silnik']);

            return Http::response(['data' => ['translations' => [['translatedText' => 'Ancien moteur']]]]);
        }]);
        $result = app(FrenchCatalogTranslationService::class)->translate('parts', $part->id);
        $row = $part->translations()->first();
        $this->assertSame('failed', $result['status']);
        $this->assertSame('needs_update', $row->status);
        $this->assertSame($oldHash, $row->source_hash);
        $this->assertNull($row->name);
        $this->assertNull($row->translated_at);
        $this->fakeSuccess();
        $this->assertSame(0, $this->apply(['--include-needs-update' => true]));
        $this->assertSame('FR Nowy silnik', $row->fresh()->name);
        $this->assertSame($part->fresh()->translationSourceHash(), $row->fresh()->source_hash);
    }

    public function test_review_added_during_api_is_not_overwritten(): void
    {
        $part = $this->part();
        Http::fake(['translation.googleapis.com/*' => function () use ($part) {
            $part->translations()->first()->update(['status' => 'reviewed', 'name' => 'Texte relu', 'reviewed_at' => now()]);

            return Http::response(['data' => ['translations' => [['translatedText' => 'Automatic']]]]);
        }]);
        $result = app(FrenchCatalogTranslationService::class)->translate('parts', $part->id);
        $this->assertSame('translation_changed', $result['reason']);
        $this->assertSame('reviewed', $part->translations()->first()->status);
        $this->assertSame('Texte relu', $part->translations()->first()->name);
    }

    public function test_busy_lock_prevents_duplicate_calls_and_interrupted_queued_record_resumes(): void
    {
        $part = $this->part();
        $this->translation($part, 'queued');
        $lock = Cache::lock('storefront:fr:parts:'.$part->id, 600);
        $this->assertTrue($lock->get());
        $result = app(FrenchCatalogTranslationService::class)->translate('parts', $part->id);
        $this->assertSame('busy', $result['reason']);
        Http::assertNothingSent();
        $lock->release();
        $this->fakeSuccess();
        $this->assertSame(0, $this->apply(['--only-missing' => true]));
        $this->assertSame('translated', $part->translations()->first()->status);
        Http::assertSentCount(1);
    }

    public function test_removed_source_field_is_cleared_and_long_descriptions_are_segmented(): void
    {
        $part = $this->part(['description' => str_repeat('Opis źródła ', 800)]);
        $this->translation($part, 'needs_update');
        $part->translations()->first()->update(['short_description' => 'Ancien résumé']);
        $this->fakeSuccess();
        $result = app(FrenchCatalogTranslationService::class)->translate('parts', $part->id, ['include_needs_update' => true]);
        $this->assertSame('translated', $result['status']);
        $this->assertNull($part->translations()->first()->short_description);
        $this->assertGreaterThan(2, Http::recorded()->count());
        foreach (Http::recorded() as [$request]) {
            $this->assertLessThanOrEqual(4500, mb_strlen($request['q']));
        }
    }

    public function test_invalid_options_refuse_without_calls_or_writes(): void
    {
        foreach ([['--type' => 'bad'], ['--chunk' => 0], ['--chunk' => 'bad'], ['--chunk' => 1001], ['--dry-run' => true, '--apply' => true]] as $options) {
            $this->assertSame(1, Artisan::call(self::COMMAND, $options));
        }
        $this->assertDatabaseCount('part_translations', 0);
        Http::assertNothingSent();
    }
}
