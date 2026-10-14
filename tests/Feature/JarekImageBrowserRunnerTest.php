<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\JarekFrProduct;
use App\Models\JarekGearbox;
use App\Models\User;
use App\Services\Images\JarekImagePresentation;
use App\Services\Images\JarekImageRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JarekImageBrowserRunnerTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private const URL = 'https://gpswiss.pl/admin/tools/storefront/fr-translations/jarek-local-images';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('public');
        config(['frontend-maintenance.enabled' => false]);
        $this->actor = User::create(['name' => 'Runner owner', 'email' => 'runner@example.test', 'password' => 'password']);
        Role::findOrCreate(UserRole::OwnerAdmin->value, 'web');
        $this->actor->assignRole(UserRole::OwnerAdmin->value);
    }

    public function test_preview_covers_every_active_source_in_small_batches_without_generating_images(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->source();
        }
        $this->source(false);
        $bad = $this->source();
        Storage::disk('public')->put($this->path($bad), 'broken jpeg');
        $before = Storage::disk('public')->allFiles();
        $runner = app(JarekImageRunner::class);
        $run = $runner->preview($this->actor->id);
        $first = $runner->tick($run['run_id'], $this->actor->id);
        $this->assertSame('previewing', $first['status']);
        $this->assertSame(10, $first['previewed_exposures']);
        $run = $runner->tick($run['run_id'], $this->actor->id);
        $this->assertSame('ready', $run['status']);
        $this->assertSame(14, $run['total_active_exposures']);
        $this->assertSame(12, $run['preview']['missing_count']);
        $this->assertSame(1, $run['preview']['failed_count']);
        $this->assertSame(1, $run['preview']['skipped_count']);
        $this->assertSame($before, Storage::disk('public')->allFiles());
        $this->assertSame('unreadable_or_too_large', $run['failed_samples'][0]->result_code);
        $this->assertArrayNotHasKey('candidate_ids', $run);
        Http::assertNothingSent();
    }

    public function test_small_preparation_batches_resume_after_reload_and_cache_loss_without_source_writes(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->source();
        }
        $before = DB::table('jarek_gearboxes')->orderBy('id')->get()->toJson();
        $runner = app(JarekImageRunner::class);
        $run = $this->preview($runner);
        $run = $runner->start($run['run_id'], $this->actor->id, JarekImageRunner::CONFIRM);
        $run = $runner->tick($run['run_id'], $this->actor->id);
        $this->assertSame(2, $run['progress']['generated']);
        $runner->control($run['run_id'], $this->actor->id, 'pause');
        $this->assertSame(2, $runner->tick($run['run_id'], $this->actor->id)['progress']['generated']);
        Cache::flush();
        $reloaded = new JarekImageRunner(app(JarekImagePresentation::class));
        $this->assertSame('paused', $reloaded->latest($this->actor->id)['status']);
        $reloaded->control($run['run_id'], $this->actor->id, 'resume');
        do {
            $run = $reloaded->tick($run['run_id'], $this->actor->id);
        } while ($run['status'] === 'running');
        $this->assertSame('completed', $run['status']);
        $this->assertSame(5, $run['progress']['generated']);
        $this->assertSame(0, $run['progress']['remaining']);
        $again = $reloaded->start($run['run_id'], $this->actor->id, JarekImageRunner::CONFIRM);
        $this->assertSame($again['progress'], $reloaded->tick($run['run_id'], $this->actor->id)['progress']);
        $this->assertSame($before, DB::table('jarek_gearboxes')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('parts', 0);
        $this->assertDatabaseCount('part_images', 0);
        Http::assertNothingSent();
    }

    public function test_one_processing_exception_is_recorded_and_other_images_continue(): void
    {
        $bad = $this->source();
        $good = $this->source();
        $real = app(JarekImagePresentation::class);
        $images = \Mockery::mock(JarekImagePresentation::class);
        $images->shouldReceive('inspect')->andReturnUsing(fn ($url) => $real->inspect($url));
        $images->shouldReceive('prepareUrl')->andReturnUsing(function ($url, $fp) use ($bad, $real) {
            if (str_contains($url, '/'.$bad->allegro_offer_id.'/')) {
                throw new \RuntimeException('fixture processing error');
            }

            return $real->prepareUrl($url, $fp);
        });
        $runner = new JarekImageRunner($images);
        $run = $this->preview($runner);
        $runner->start($run['run_id'], $this->actor->id, JarekImageRunner::CONFIRM);
        $run = $runner->tick($run['run_id'], $this->actor->id);
        $this->assertSame('completed', $run['status']);
        $this->assertSame(1, $run['progress']['generated']);
        $this->assertSame(1, $run['progress']['failed']);
        $this->assertSame('processing_exception', $run['last_error']['code']);
        $this->assertTrue($real->variants($this->url($good))['ready']);
        Http::assertNothingSent();
    }

    public function test_fatal_interruption_recovers_manifest_once_or_skips_failed_attempt_and_continues(): void
    {
        $first = $this->source();
        $second = $this->source();
        $this->source();
        $runner = app(JarekImageRunner::class);
        $images = app(JarekImagePresentation::class);
        $run = $this->preview($runner);
        $runner->start($run['run_id'], $this->actor->id, JarekImageRunner::CONFIRM);
        $images->prepareUrl($this->url($first));
        DB::table(JarekImageRunner::ITEMS)->where('source_id', $first->id)->update(['status' => 'processing']);
        $run = $runner->tick($run['run_id'], $this->actor->id);
        $this->assertSame(2, $run['progress']['generated']);
        $thirdItem = DB::table(JarekImageRunner::ITEMS)->where('status', 'pending')->first();
        DB::table(JarekImageRunner::ITEMS)->where('id', $thirdItem->id)->update(['status' => 'processing']);
        $run = $runner->tick($run['run_id'], $this->actor->id);
        $this->assertSame('completed', $run['status']);
        $this->assertSame(2, $run['progress']['generated']);
        $this->assertSame(1, $run['progress']['failed']);
        $this->assertSame('interrupted_image_attempt', $run['last_error']['code']);
        $this->assertTrue($images->variants($this->url($second))['ready']);
        Http::assertNothingSent();
    }

    public function test_stop_is_persistent_and_new_preview_reuses_ready_images(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->source();
        }
        $runner = app(JarekImageRunner::class);
        $run = $this->preview($runner);
        $runner->start($run['run_id'], $this->actor->id, JarekImageRunner::CONFIRM);
        $run = $runner->tick($run['run_id'], $this->actor->id);
        $runner->control($run['run_id'], $this->actor->id, 'stop');
        $files = Storage::disk('public')->allFiles();
        $this->assertSame('stopped', $runner->control($run['run_id'], $this->actor->id, 'resume')['status']);
        $runner->tick($run['run_id'], $this->actor->id);
        $this->assertSame($files, Storage::disk('public')->allFiles());
        $new = $this->preview($runner);
        $this->assertSame(2, $new['preview']['ready_count']);
        $this->assertSame(1, $new['preview']['missing_count']);
        Http::assertNothingSent();
    }

    public function test_preview_freezes_fingerprints_and_rechecks_active_sources_before_generating(): void
    {
        $changed = $this->source();
        $inactive = $this->source();
        $runner = app(JarekImageRunner::class);
        $run = $this->preview($runner);
        Storage::disk('public')->put($this->path($changed), 'changed source');
        JarekFrProduct::where('jarek_gearbox_id', $inactive->id)->update(['is_active' => false]);
        $runner->start($run['run_id'], $this->actor->id, JarekImageRunner::CONFIRM);
        $run = $runner->tick($run['run_id'], $this->actor->id);
        $this->assertSame('completed', $run['status']);
        $this->assertSame(1, $run['progress']['failed']);
        $this->assertSame(1, $run['progress']['skipped']);
        $this->assertSame('source_changed', $run['last_error']['code']);
        Http::assertNothingSent();
    }

    public function test_browser_flow_requires_confirmation_and_ownership_and_get_never_starts_processing(): void
    {
        $this->source();
        $this->actingAs($this->actor);
        $this->get(self::URL)->assertOk()->assertSee('Przygotuj wszystkie brakujące warianty')->assertDontSee('name="after_id"', false);
        $id = $this->postJson(self::URL, ['action' => 'preview'])->assertOk()->json('run.run_id');
        $this->postJson(self::URL, ['action' => 'tick', 'run_id' => $id])->assertOk()->assertJsonPath('run.status', 'ready');
        $before = Storage::disk('public')->allFiles();
        $this->getJson(self::URL)->assertOk()->assertJsonPath('run.run_id', $id);
        $this->assertSame($before, Storage::disk('public')->allFiles());
        $this->postJson(self::URL, ['action' => 'start', 'run_id' => $id])->assertUnprocessable();
        $this->postJson(self::URL, ['action' => 'start', 'run_id' => $id, 'confirm' => JarekImageRunner::CONFIRM])->assertOk();
        $other = User::create(['name' => 'Other', 'email' => 'other-runner@example.test', 'password' => 'password']);
        $other->assignRole(UserRole::OwnerAdmin->value);
        $this->actingAs($other)->postJson(self::URL, ['action' => 'tick', 'run_id' => $id])->assertNotFound();
        $this->get(str_replace('gpswiss.pl', 'gpswiss.fr', self::URL))->assertForbidden();
        Http::assertNothingSent();
    }

    private function preview(JarekImageRunner $runner): array
    {
        $run = $runner->preview($this->actor->id);
        while ($run['status'] === 'previewing') {
            $run = $runner->tick($run['run_id'], $this->actor->id);
        }

        return $run;
    }

    private function source(bool $local = true): JarekGearbox
    {
        $source = JarekGearbox::create(['allegro_offer_id' => 'browser-'.JarekGearbox::count(), 'title' => 'Boîte fixture', 'price' => 1000, 'currency' => 'PLN', 'quantity' => 0, 'main_image_url' => 'https://remote.test/source.jpg']);
        JarekFrProduct::create(['jarek_gearbox_id' => $source->id, 'is_active' => true]);
        if ($local) {
            $im = imagecreatetruecolor(400, 300);
            imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
            imagefilledrectangle($im, 80, 70, 320, 230, imagecolorallocate($im, 40, 60, 80));
            ob_start();
            imagejpeg($im, null, 85);
            $bytes = ob_get_clean();
            imagedestroy($im);
            Storage::disk('public')->put($this->path($source), $bytes);
        }

        return $source;
    }

    private function path(JarekGearbox $source): string
    {
        return 'jarek-gearboxes/'.$source->allegro_offer_id.'/01.jpg';
    }

    private function url(JarekGearbox $source): string
    {
        return 'https://gpswiss.pl/storage/'.$this->path($source);
    }
}
