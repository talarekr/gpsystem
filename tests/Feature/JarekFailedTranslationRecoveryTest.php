<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\JarekGearbox;
use App\Models\User;
use App\Services\Storefront\FrenchCatalogTranslationAdminRunner;
use App\Services\Storefront\FrenchCatalogTranslationRunStore as RunStore;
use App\Services\Storefront\FrenchCatalogTranslationService;
use App\Services\Storefront\JarekFailedTranslationPreview as Preview;
use App\Services\Storefront\JarekTranslationTechnicalTokens as Tokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JarekFailedTranslationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://gpswiss.pl/admin/tools/storefront/fr-translations/jarek-failed';

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront-translations.fr_enabled' => true, 'frontend-maintenance.enabled' => false,
            'services.google_translate.enabled' => true, 'services.google_translate.mode' => 'dry_run',
            'services.google_translate.key' => 'fake-failed-key']);
        Http::preventStrayRequests();
        $this->provider();
        Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');
        $actor = User::create(['name' => 'Admin', 'email' => 'failed@example.test', 'password' => 'password']);
        $actor->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($actor);
    }

    private function provider(?callable $transform = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['translation.googleapis.com/*' => function ($request) use ($transform) {
            $this->assertSame('html', $request['format']);
            if (str_contains($request['q'], 'ZXQ')) {
                $this->assertStringContainsString('class="notranslate" translate="no"', $request['q']);
            }
            $text = $transform ? $transform($request['q']) : str_replace('Skrzynia', 'Boîte', $request['q']);

            return Http::response(['data' => ['translations' => [['translatedText' => $text]]]]);
        }]);
    }

    private function record(string $status = 'failed', string $error = 'technical_code_changed'): JarekGearbox
    {
        $record = JarekGearbox::create(['title' => 'Skrzynia Renault Kangoo I20 B8Skoda 100',
            'plain_description' => 'Skrzynia kod A9063502500. Sprawna skrzynia.',
            'allegro_offer_id' => 'offer-'.JarekGearbox::count(), 'price' => 3000, 'currency' => 'PLN', 'quantity' => 0]);
        $record->translations()->create(['locale' => 'fr', 'status' => $status, 'source_hash' => $record->translationSourceHash(),
            'title' => $status === 'failed' ? null : 'Titre relu', 'attempts' => 1, 'error_code' => $status === 'failed' ? $error : null]);

        return $record;
    }

    private function start(string $filter = 'all'): array
    {
        $preview = $this->getJson(self::URL.'?action=preview&filter='.$filter)->assertOk()->json('report');

        return $this->postJson(self::URL, ['action' => 'start', 'preview_id' => $preview['preview_id'], 'confirm' => Preview::CONFIRM])
            ->assertOk()->json('run');
    }

    private function tick(string $id)
    {
        return $this->postJson(self::URL, ['action' => 'tick', 'run_id' => $id])->assertOk();
    }

    public function test_preview_reads_only_failed_and_does_not_write_or_call_google(): void
    {
        $failed = $this->record();
        $this->record('failed', 'google_translate_failed');
        $this->record('failed', 'translated_name_too_long');
        $this->record('translated');
        $this->record('reviewed');
        $this->record('missing');
        $before = DB::table('jarek_gearbox_translations')->get()->toJson();
        $cache = DB::table('cache')->get()->toJson();
        $this->getJson(self::URL.'?action=preview')->assertOk()->assertJsonPath('report.total_failed', 3)
            ->assertJsonPath('report.breakdown.technical_code_changed', 1)->assertJsonPath('report.breakdown.google_translate_failed', 1)
            ->assertJsonCount(3, 'report.examples')->assertJsonPath('report.examples.0.gearbox_id', $failed->id);
        $this->get('https://gpswiss.pl/admin/tools/storefront/fr-translations')->assertOk()
            ->assertSee('Jarek — brakujące / błędne tłumaczenia')->assertSee(Preview::CONFIRM);
        $this->assertSame($before, DB::table('jarek_gearbox_translations')->get()->toJson());
        $this->assertSame($cache, DB::table('cache')->get()->toJson());
        $this->assertDatabaseCount(RunStore::TABLE, 0);
        Http::assertNothingSent();
    }

    public function test_retry_only_failed_uses_html_restores_codes_and_preserves_original_full_run(): void
    {
        $failed = $this->record();
        $google = $this->record('failed', 'google_translate_failed');
        $translated = $this->record('translated');
        $reviewed = $this->record('reviewed');
        $store = app(RunStore::class)->forScope('jarek_gearboxes');
        $full = ['run_id' => 'full-original', 'status' => 'completed', 'runner_mode' => 'browser', 'processed' => 1518,
            '_cursor' => ['jarek_gearboxes' => 1518], 'failed' => 40];
        $store->save($full);
        $original = DB::table(RunStore::TABLE)->where('run_id', 'full-original')->first()->checkpoint;
        $sources = DB::table('jarek_gearboxes')->get()->toJson();
        $run = $this->start();
        $this->assertSame(2, $run['total']);
        $this->tick($run['run_id'])->assertJsonPath('run.technical_retry_translated', 1);
        $this->tick($run['run_id'])->assertJsonPath('run.technical_retry_translated', 2);
        $this->tick($run['run_id'])->assertJsonPath('run.status', 'completed')->assertJsonPath('report.total_failed', 0);
        foreach ([$failed, $google] as $record) {
            $translation = $record->fresh()->translationForLocale('fr');
            $this->assertSame('translated', $translation->status);
            foreach (['Renault', 'Kangoo', 'I20', 'B8Skoda', '100'] as $code) {
                $this->assertStringContainsString($code, $translation->title);
            }
            $this->assertStringContainsString('A9063502500', $translation->description);
            $this->assertStringNotContainsString('ZXQ', $translation->title);
        }
        $this->assertSame(1, $translated->fresh()->translationForLocale('fr')->attempts);
        $this->assertSame(1, $reviewed->fresh()->translationForLocale('fr')->attempts);
        $this->assertSame($original, DB::table(RunStore::TABLE)->where('run_id', 'full-original')->first()->checkpoint);
        $this->assertSame($sources, DB::table('jarek_gearboxes')->get()->toJson());
        Http::assertSentCount(4);
    }

    public function test_changed_or_missing_marker_remains_failed_and_does_not_stop_next_record(): void
    {
        $broken = $this->record();
        $next = $this->record();
        $this->provider(fn ($html) => preg_replace('/ZXQ([A-Z0-9]+)/', 'ZX$1', $html));
        $run = $this->start();
        $this->tick($run['run_id'])->assertJsonPath('run.status', 'running')->assertJsonPath('run.technical_retry_failed', 1);
        $this->assertSame('technical_code_changed', $broken->fresh()->translationForLocale('fr')->error_code);
        $this->assertNull($broken->fresh()->translationForLocale('fr')->title);
        $this->provider();
        $this->tick($run['run_id'])->assertJsonPath('run.technical_retry_translated', 1);
        $this->tick($run['run_id'])->assertJsonPath('run.status', 'completed')->assertJsonPath('report.total_failed', 1);
        $this->assertSame('translated', $next->fresh()->translationForLocale('fr')->status);
        $this->assertSame($broken->id, $this->getJson(self::URL)->json('report.examples.0.gearbox_id'));
    }

    public function test_google_failure_can_be_selected_separately_and_continues_after_failed_record(): void
    {
        $technical = $this->record();
        $google = $this->record('failed', 'google_translate_failed');
        $this->provider(fn ($html) => '');
        $run = $this->start('google_translate_failed');
        $this->assertSame(1, $run['total']);
        $this->tick($run['run_id'])->assertJsonPath('run.status', 'running')->assertJsonPath('run.technical_retry_failed', 1);
        $this->tick($run['run_id'])->assertJsonPath('run.status', 'completed');
        $this->assertSame('google_translate_failed', $google->fresh()->translationForLocale('fr')->error_code);
        $this->assertSame(1, $technical->fresh()->translationForLocale('fr')->attempts);
        $this->provider();
        $retry = $this->start('google_translate_failed');
        $this->tick($retry['run_id']);
        $this->assertSame('translated', $google->fresh()->translationForLocale('fr')->status);
        $this->assertSame('failed', $technical->fresh()->translationForLocale('fr')->status);
    }

    public function test_review_or_translation_after_start_is_never_retried_and_preview_changes_require_new_confirmation(): void
    {
        $record = $this->record();
        $preview = $this->getJson(self::URL.'?action=preview')->json('report');
        $record->translations()->update(['status' => 'reviewed']);
        $this->postJson(self::URL, ['action' => 'start', 'preview_id' => $preview['preview_id'], 'confirm' => Preview::CONFIRM])
            ->assertUnprocessable();
        $record->translations()->update(['status' => 'failed']);
        $run = $this->start();
        $record->translations()->update(['status' => 'translated']);
        $this->tick($run['run_id'])->assertJsonPath('run.technical_retry_skipped', 1);
        $this->assertSame(1, $record->fresh()->translationForLocale('fr')->attempts);
        Http::assertNothingSent();
    }

    public function test_other_failed_without_error_code_can_be_retried_and_atomic_attempt_guard_skips_changes(): void
    {
        $record = $this->record();
        $record->translations()->update(['error_code' => null]);
        $run = $this->start();
        $this->tick($run['run_id']);
        $this->assertSame('translated', $record->fresh()->translationForLocale('fr')->status);
        $this->provider();
        $record->translations()->update(['status' => 'failed', 'attempts' => 4, 'error_code' => 'google_translate_failed']);
        $result = app(FrenchCatalogTranslationService::class)->translate('jarek_gearboxes', $record->id,
            ['include_failed' => true, 'expected_failed_attempts' => 3, 'expected_failed_code' => 'google_translate_failed']);
        $this->assertSame('translation_changed', $result['reason']);
        Http::assertNothingSent();
    }

    public function test_original_run_resume_repairs_google_failure_without_resetting_its_cursor(): void
    {
        $record = $this->record();
        $record->translations()->delete();
        $base = 'https://gpswiss.pl/admin/tools/storefront/fr-translations/jarek-gearboxes';
        $preview = $this->postJson($base.'/dry-run')->assertOk()->json('dry_run');
        $run = $this->postJson($base.'/start', ['dry_run_id' => $preview['dry_run_id'],
            'confirm' => FrenchCatalogTranslationAdminRunner::JAREK_CONFIRM])->assertOk()->json();
        $this->provider(fn ($html) => '');
        $this->postJson($base.'/process-next-batch', ['run_id' => $run['run_id']])->assertOk()->assertJsonPath('status', 'running')->assertJsonPath('failed', 1);
        $this->postJson($base.'/process-next-batch', ['run_id' => $run['run_id']])->assertOk()->assertJsonPath('status', 'completed');
        $before = app(RunStore::class)->forScope('jarek_gearboxes')->latest();
        $this->provider();
        $this->postJson($base.'/resume', ['run_id' => $run['run_id']])->assertOk()->assertJsonPath('technical_retry_total', 1);
        $this->postJson($base.'/process-next-batch', ['run_id' => $run['run_id']])->assertOk()->assertJsonPath('processed', 1)
            ->assertJsonPath('failed', 0)->assertJsonPath('technical_retry_translated', 1);
        $after = app(RunStore::class)->forScope('jarek_gearboxes')->latest();
        $this->assertSame($before['run_id'], $after['run_id']);
        $this->assertSame($before['_cursor'], $after['_cursor']);
        $this->assertSame($before['total'], $after['total']);
    }

    public function test_recovery_routes_require_admin_and_pl_host_and_explicit_confirmation(): void
    {
        $this->record();
        $preview = $this->getJson(self::URL.'?action=preview')->json('report');
        $this->postJson(self::URL, ['action' => 'start', 'preview_id' => $preview['preview_id'], 'confirm' => 'wrong'])->assertUnprocessable();
        $this->getJson(str_replace('gpswiss.pl', 'gpswiss.fr', self::URL))->assertForbidden();
        $viewer = User::create(['name' => 'Viewer', 'email' => 'viewer-failed@example.test', 'password' => 'password']);
        Role::findOrCreate(UserRole::Viewer->value, 'web');
        $viewer->assignRole(UserRole::Viewer->value);
        $this->actingAs($viewer)->getJson(self::URL)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_html_placeholder_is_strict_and_long_segments_do_not_split_it(): void
    {
        $tokens = app(Tokens::class);
        $source = str_repeat('A', 4480).' RGA '.str_repeat('b', 5000);
        $masked = $tokens->mask($source);
        $this->assertNotEmpty($masked['tokens']);
        $marker = array_key_first($masked['tokens']);
        $this->assertNull($tokens->restore(str_replace($marker, substr($marker, 1), $masked['text']), $masked['tokens']));
        $this->assertNull($tokens->restore(str_replace($marker, '', $masked['text']), $masked['tokens']));
        $this->assertSame($source, $tokens->restore($tokens->htmlText($tokens->html($masked['text'], $masked['tokens'])), $masked['tokens']));
        $record = $this->record();
        $record->update(['plain_description' => str_repeat('a', 4490).':RGA:'.str_repeat('b', 5000)]);
        $record->translations()->update(['source_hash' => $record->translationSourceHash()]);
        $run = $this->start();
        $this->tick($run['run_id']);
        $this->assertSame('translated', $record->fresh()->translationForLocale('fr')->status);
    }
}
