<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Part;
use App\Models\User;
use App\Services\Marketplace\AllegroGpsrAuditRunnerService;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AllegroGpsrAuditRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_text_with_description_and_responsible_entities_is_valid(): void
    {
        $result = app(AllegroGpsrAuditRunnerService::class)->classifyOfferPayload($this->offer([ $this->product('TEXT', 'Instrukcja bezpieczeństwa produktu.', true, true) ]));
        $this->assertSame('valid_text', $result['overall_classification']);
        $this->assertFalse($result['repair_required']);
        $this->assertSame('none', $result['activation_risk']);
    }

    public function test_attachments_are_valid(): void
    {
        $result = app(AllegroGpsrAuditRunnerService::class)->classifyOfferPayload($this->offer([ $this->product('ATTACHMENTS', '', true, true, [['id' => 'att-1']]) ]));
        $this->assertSame('valid_attachments', $result['overall_classification']);
    }

    public function test_no_safety_information_is_high_risk_repair_required(): void
    {
        $result = app(AllegroGpsrAuditRunnerService::class)->classifyOfferPayload($this->offer([ $this->product('NO_SAFETY_INFORMATION', '', true, true) ]));
        $this->assertSame('no_safety_information', $result['overall_classification']);
        $this->assertTrue($result['repair_required']);
        $this->assertSame('high', $result['activation_risk']);
    }

    public function test_missing_safety_information_and_invalid_text(): void
    {
        $missing = app(AllegroGpsrAuditRunnerService::class)->classifyOfferPayload($this->offer([['product' => ['id' => 'p1']]]));
        $invalid = app(AllegroGpsrAuditRunnerService::class)->classifyOfferPayload($this->offer([ $this->product('TEXT', '', true, true) ]));
        $this->assertSame('missing_safety_information', $missing['overall_classification']);
        $this->assertSame('invalid_text', $invalid['overall_classification']);
    }

    public function test_mixed_product_set_and_missing_responsible_entities(): void
    {
        $mixed = app(AllegroGpsrAuditRunnerService::class)->classifyOfferPayload($this->offer([ $this->product('TEXT', 'ok text', true, true), $this->product('NO_SAFETY_INFORMATION') ]));
        $producer = app(AllegroGpsrAuditRunnerService::class)->classifyOfferPayload($this->offer([ $this->product('TEXT', 'ok text', false, true) ]));
        $person = app(AllegroGpsrAuditRunnerService::class)->classifyOfferPayload($this->offer([ $this->product('TEXT', 'ok text', true, false) ]));
        $this->assertSame('mixed_product_set', $mixed['overall_classification']);
        $this->assertSame('missing_responsible_producer', $producer['overall_classification']);
        $this->assertSame('missing_responsible_person', $person['overall_classification']);
    }

    public function test_unknown_type_and_marketed_before_gpsr_obligation(): void
    {
        $result = app(AllegroGpsrAuditRunnerService::class)->classifyOfferPayload($this->offer([ array_merge($this->product('ALIEN', '', true, true), ['marketedBeforeGPSRObligation' => true]) ]));
        $this->assertSame('unknown_type', $result['overall_classification']);
        $this->assertTrue($result['products'][0]['marketed_before_gpsr_obligation']);
    }

    public function test_runner_start_deduplicates_candidates_and_blocks_parallel_runs(): void
    {
        $this->actingAsAdminUser(); $this->account();
        $this->listing('1234567890'); $this->listing('1234567890'); $this->listing(null);
        $this->postJson('/admin/tools/allegro/gpsr-audit-runner/start', ['confirm' => 'start-allegro-gpsr-audit'])->assertOk()->assertJsonPath('candidate_diagnostics.duplicate_offer_ids', 1)->assertJsonPath('total', 1);
        $this->postJson('/admin/tools/allegro/gpsr-audit-runner/start', ['confirm' => 'start-allegro-gpsr-audit'])->assertStatus(422)->assertJsonPath('reason', 'already_running');
    }

    public function test_run_next_batch_json_csv_stop_and_no_listing_mutation(): void
    {
        $this->actingAsAdminUser(); $this->account(); $listing = $this->listing('17659472600'); $before = $listing->fresh()->toArray();
        Http::fake(['https://api.allegro.test/sale/product-offers/17659472600' => Http::response($this->offer([ $this->product('TEXT', 'Bezpieczny opis.', true, true) ]), 200)]);
        $this->postJson('/admin/tools/allegro/gpsr-audit-runner/start', ['confirm' => 'start-allegro-gpsr-audit', 'batch_size' => 1, 'delay_seconds' => 1])->assertOk();
        $this->postJson('/admin/tools/allegro/gpsr-audit-runner/run-next-batch', ['confirm' => 'run-allegro-gpsr-audit-batch'])->assertOk()->assertJsonPath('summary.valid_text', 1);
        $this->getJson('/admin/tools/allegro/gpsr-audit-runner/export.json')->assertOk()->assertJsonPath('results.0.would_call_allegro_write_api', false);
        $this->get('/admin/tools/allegro/gpsr-audit-runner/export.csv')->assertOk()->assertSee('description_length')->assertDontSee('Bezpieczny opis.');
        $this->postJson('/admin/tools/allegro/gpsr-audit-runner/stop', ['confirm' => 'stop-allegro-gpsr-audit'])->assertOk();
        $this->assertSame($before, $listing->fresh()->toArray());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
    }

    public function test_api_errors_are_not_classified_as_missing_gpsr(): void
    {
        $this->actingAsAdminUser(); $this->account(); $this->listing('17714383698');
        Http::fake(['https://api.allegro.test/sale/product-offers/17714383698' => Http::response(['errors' => [['code' => 'Forbidden']]], 403)]);
        $this->postJson('/admin/tools/allegro/gpsr-audit-runner/start', ['confirm' => 'start-allegro-gpsr-audit'])->assertOk();
        $this->postJson('/admin/tools/allegro/gpsr-audit-runner/run-next-batch', ['confirm' => 'run-allegro-gpsr-audit-batch'])->assertOk()->assertJsonPath('summary.api_errors', 1)->assertJsonPath('summary.missing_safety_information', 0);
    }


    public function test_common_api_statuses_are_api_errors(): void
    {
        $this->actingAsAdminUser();
        foreach ([401, 403, 404, 429, 500] as $status) {
            Cache::forget(AllegroGpsrAuditRunnerService::CACHE_KEY);
            MarketplaceListing::query()->delete(); Part::query()->delete(); MarketplaceAccount::query()->delete();
            $this->account(); $this->listing((string) (9900000000 + $status));
            Http::fake(['https://api.allegro.test/sale/product-offers/*' => Http::response(['errors' => [['code' => 'E'.$status]]], $status)]);
            $this->postJson('/admin/tools/allegro/gpsr-audit-runner/start', ['confirm' => 'start-allegro-gpsr-audit'])->assertOk();
            $this->postJson('/admin/tools/allegro/gpsr-audit-runner/run-next-batch', ['confirm' => 'run-allegro-gpsr-audit-batch'])->assertOk()->assertJsonPath('summary.api_errors', 1);
        }
    }

    public function test_invalid_json_is_api_error(): void
    {
        $this->actingAsAdminUser(); $this->account(); $this->listing('18888888888');
        Http::fake(['https://api.allegro.test/sale/product-offers/18888888888' => Http::response('not-json', 200)]);
        $this->postJson('/admin/tools/allegro/gpsr-audit-runner/start', ['confirm' => 'start-allegro-gpsr-audit'])->assertOk();
        $this->postJson('/admin/tools/allegro/gpsr-audit-runner/run-next-batch', ['confirm' => 'run-allegro-gpsr-audit-batch'])->assertOk()->assertJsonPath('summary.api_errors', 1);
    }

    private function offer(array $productSet): array { return ['id' => 'offer-fixture', 'publication' => ['status' => 'ACTIVE'], 'productSet' => $productSet]; }
    private function product(?string $type, string $description = '', bool $producer = true, bool $person = true, array $attachments = []): array
    {
        return array_filter(['product' => ['id' => 'catalog-product', 'publication' => ['status' => 'LISTED']], 'quantity' => ['value' => 1], 'safetyInformation' => $type === null ? null : ['type' => $type, 'description' => $description, 'attachments' => $attachments], 'responsibleProducer' => $producer ? ['id' => 'producer-1'] : null, 'responsiblePerson' => $person ? ['id' => 'person-1'] : null], fn ($v) => $v !== null);
    }
    private function listing(?string $offerId): MarketplaceListing { $part = Part::query()->create(['name' => 'Part', 'quantity' => 1, 'status' => 'ready', 'currency' => 'PLN']); return MarketplaceListing::query()->create(['part_id' => $part->id, 'marketplace' => 'allegro', 'external_offer_id' => $offerId, 'status' => 'ended', 'sync_status' => 'mapped', 'currency' => 'PLN']); }
    private function account(): void { MarketplaceAccount::query()->create(['code' => 'allegro_main', 'marketplace' => 'allegro', 'name' => 'Allegro', 'status' => 'active', 'api_enabled' => true, 'api_base_url' => 'https://api.allegro.test', 'api_credentials' => ['access_token' => 'token']]); }
    private function actingAsAdminUser(): User { $this->seed(RoleSeeder::class); app(PermissionRegistrar::class)->forgetCachedPermissions(); $user = User::query()->create(['name' => 'Owner Admin', 'email' => uniqid('admin').'@example.test', 'password' => 'password']); $user->assignRole(UserRole::OwnerAdmin->value); $this->actingAs($user); Filament::setCurrentPanel(Filament::getPanel('admin')); return $user; }
}
