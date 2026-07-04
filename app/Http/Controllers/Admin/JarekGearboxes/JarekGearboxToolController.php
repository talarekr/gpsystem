<?php

namespace App\Http\Controllers\Admin\JarekGearboxes;

use App\Http\Controllers\Controller;
use App\Models\JarekGearbox;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategoryMapping;
use App\Models\MarketplaceSyncLog;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartImage;
use App\Services\JarekGearboxes\AllegroJarekImportService;
use App\Services\JarekGearboxes\JarekGearboxEbayPreviewService;
use App\Services\Marketplace\EbayDescriptionTemplateRenderer;
use App\Services\Marketplace\GoogleTranslateService;
use App\Services\Marketplace\NbpExchangeRateService;
use App\Services\Marketplace\Api\EbayApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class JarekGearboxToolController extends Controller
{
    private const JAREK_PREVIEW_MAX_IMAGE_DIAGNOSTIC_RECORDS = 10;
    private const JAREK_PREVIEW_MAX_STORAGE_CANDIDATE_PATHS_PER_RECORD = 20;
    private const JAREK_PREVIEW_MAX_FILE_EXISTS_CHECKS = 100;

    private int $jarekImageFileExistsChecks = 0;
    private bool $jarekImageFileExistsBudgetExceeded = false;

    public function ping(AllegroJarekImportService $service): JsonResponse
    {
        try {
            $config = $service->configStatus();
            return response()->json([
                'ok' => true,
                'module' => 'Skrzynie Jarka',
                'table_exists' => Schema::hasTable('jarek_gearboxes'),
                'expected_columns_missing' => $this->missingExpectedColumns(),
                'migration_entry_exists' => $this->migrationEntryExists(),
                'config_present' => $config['present'],
                'missing_config_keys' => $config['missing'],
                'marketplace_write' => false,
            ]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'module' => 'Skrzynie Jarka', 'error' => $e->getMessage(), 'marketplace_write' => false], 200);
        }
    }

    public function dryRun(Request $request, AllegroJarekImportService $service): JsonResponse
    {
        return response()->json($service->dryRun($this->limit($request), $this->offset($request)));
    }

    public function partsImportDryRun(Request $request): JsonResponse
    {
        $summary = $this->buildPartsImportDryRun($this->limit($request), $this->offset($request), $request->integer('jarek_gearbox_id') ?: null);

        if (Schema::hasTable('marketplace_sync_logs')) {
            MarketplaceSyncLog::query()->create([
                'marketplace' => 'admin',
                'action' => 'jarek_gearboxes_parts_import_dry_run',
                'status' => 'success',
                'message' => 'Dry-run importu Skrzyń Jarka do parts; bez zapisu marketplace i bez tworzenia parts.',
                'payload' => $summary,
                'created_at' => now(),
            ]);
        }

        return response()->json($summary);
    }

    public function apply(Request $request, AllegroJarekImportService $service): JsonResponse
    {
        if ($request->query('confirm') !== 'jarek-gearboxes-import') {
            return response()->json(['ok' => false, 'error' => 'Missing confirm=jarek-gearboxes-import', 'marketplace_write' => false], 422);
        }

        return response()->json(['ok' => true] + $service->apply($this->limit($request), $this->offset($request)));
    }

    public function partsImportApply(Request $request): JsonResponse
    {
        if ($request->query('confirm') !== 'jarek-to-parts') {
            $response = ['ok' => false, 'error' => 'Missing confirm=jarek-to-parts', 'marketplace_write' => false];
            $this->logPartsImportApply('blocked', 'Odmowa importu Skrzyń Jarka do parts: brak wymaganego confirm.', $response);

            return response()->json($response, 422);
        }

        $limit = max(1, min(5, (int) $request->query('limit', 1)));
        $jarekGearboxId = $request->integer('jarek_gearbox_id') ?: null;
        $updateExisting = $request->boolean('update_existing');

        if ($updateExisting && ($jarekGearboxId === null || $limit !== 1)) {
            $response = ['ok' => false, 'error' => 'update_existing requires jarek_gearbox_id and limit=1', 'marketplace_write' => false];
            $this->logPartsImportApply('blocked', 'Odmowa update_existing: wymagane jarek_gearbox_id i limit=1.', $response);

            return response()->json($response, 422);
        }

        $result = $this->applyPartsImport($limit, $this->offset($request), $jarekGearboxId, $updateExisting);
        $this->logPartsImportApply('success', 'Apply importu Skrzyń Jarka do parts; tylko lokalny draft, marketplace_write=false.', $result);

        return response()->json($result);
    }

    public function status(AllegroJarekImportService $service): JsonResponse
    {
        return response()->json($service->status());
    }

    public function runner(): \Illuminate\View\View
    {
        return view('admin.jarek-gearboxes.import-runner', [
            'defaultBatchSize' => 100,
            'maxApplyBatchSize' => 200,
        ]);
    }

    public function ebayPreview(JarekGearbox $jarekGearbox, JarekGearboxEbayPreviewService $service): JsonResponse
    {
        return response()->json($service->build($jarekGearbox));
    }


    public function ebayDePreparePreview(Request $request, GoogleTranslateService $translateService, EbayDescriptionTemplateRenderer $renderer, NbpExchangeRateService $exchangeRateService): JsonResponse
    {
        $started = microtime(true);
        $sku = trim((string) $request->query('sku', ''));
        $offerId = trim((string) $request->query('allegro_offer_id', ''));
        if ($offerId === '' && preg_match('/^JAREK-(.+)$/', $sku, $matches)) {
            $offerId = $matches[1];
        }

        $gearbox = Schema::hasTable('jarek_gearboxes')
            ? JarekGearbox::query()
                ->when($offerId !== '', fn ($query) => $query->where('allegro_offer_id', $offerId))
                ->when($offerId === '' && $sku !== '', fn ($query) => $query->whereRaw("concat('JAREK-', allegro_offer_id) = ?", [$sku]))
                ->first()
            : null;

        if (! $gearbox) {
            $payload = [
                'ok' => false,
                'dry_run' => true,
                'marketplace_write' => false,
                'parts_changed' => false,
                'source_table' => 'jarek_gearboxes',
                'sku' => $sku ?: null,
                'allegro_offer_id' => $offerId ?: null,
                'market' => 'ebay_de',
                'ready' => false,
                'blockers' => ['jarek_gearbox_not_found'],
                'warnings' => [],
            ];
            $this->logJarekEbayDePreparePreview('blocked', 'Preview eBay DE dla Skrzyń Jarka zablokowany: brak rekordu źródłowego.', $payload, $started);

            return response()->json($payload, 404);
        }

        $warnings = [];
        $blockers = [];
        $sku = 'JAREK-'.($gearbox->allegro_offer_id ?: $gearbox->id);
        $sourceTitle = trim((string) $gearbox->title);
        $titleTranslation = $translateService->translate($sourceTitle, 'de', 'pl');
        $warnings = array_merge($warnings, $titleTranslation['warnings'] ?? []);
        $blockers = array_merge($blockers, $titleTranslation['blockers'] ?? []);
        $translatedTitle = trim((string) ($titleTranslation['translated_text'] ?? ''));
        $titleLength = mb_strlen($translatedTitle);
        $titleRequiresReview = $titleLength > 80;
        $suggestedShortTitle = $titleRequiresReview ? $this->suggestJarekEbayShortTitle($translatedTitle, 80) : null;
        if ($titleRequiresReview) $blockers[] = 'ebay_title_needs_review';

        $descriptionWarnings = [];
        $sourceDescription = $this->normalizeJarekDescription($gearbox->description ?: $gearbox->plain_description, $descriptionWarnings);
        $coreReturnNotice = $this->jarekCoreReturnNotice($sourceTitle);
        $sourceDescriptionForTranslation = $coreReturnNotice['required']
            ? $this->removeCoreReturnNotices($sourceDescription, $coreReturnNotice)
            : $sourceDescription;
        $warnings = array_merge($warnings, $descriptionWarnings);
        $descriptionTranslation = $translateService->translate($sourceDescriptionForTranslation, 'de', 'pl');
        $warnings = array_merge($warnings, $descriptionTranslation['warnings'] ?? []);
        $blockers = array_merge($blockers, $descriptionTranslation['blockers'] ?? []);
        $translatedDescriptionBase = trim((string) ($descriptionTranslation['translated_text'] ?? ''));
        $translatedDescriptionBase = $coreReturnNotice['required']
            ? $this->removeCoreReturnNotices($translatedDescriptionBase, $coreReturnNotice)
            : $translatedDescriptionBase;
        $translatedDescription = $translatedDescriptionBase;
        $coreReturnNoticeAddedDe = false;

        $templatePart = new Part();
        $templatePart->name = $translatedTitle;
        $templatePart->description = $translatedDescriptionBase;
        $partNumber = $this->detectJarekPartNumber((object) $gearbox->getAttributes(), $sku);
        $conditionDiagnostics = $this->jarekEbayConditionDiagnostics($gearbox);
        if (blank($conditionDiagnostics['mapped_ebay_condition'] ?? null)) {
            $blockers[] = 'missing_or_invalid_ebay_condition';
        }

        $renderedDescription = $renderer->render('ebay_de', $templatePart, [
            'title' => $translatedTitle,
            'description' => $translatedDescription,
            'part_number' => $partNumber,
            'condition' => $conditionDiagnostics['source_condition_value'] ?? null,
        ]);
        if ($coreReturnNotice['required']) {
            $renderedDescription = $this->removeCoreReturnNotices($renderedDescription, $coreReturnNotice);
            if (! $this->containsNotice($renderedDescription, $coreReturnNotice['notice_de'])) {
                $renderedDescription = $this->appendNotice($renderedDescription, $coreReturnNotice['notice_de']);
                $coreReturnNoticeAddedDe = true;
            }
        }
        $coreReturnNoticeAdded = $coreReturnNoticeAddedDe;
        if ($coreReturnNoticeAdded && $coreReturnNotice['warning'] !== null) {
            $warnings[] = $coreReturnNotice['warning'];
        }

        $brandSelection = $this->selectJarekGearboxBrand($gearbox);
        $sourceBrandCandidates = $brandSelection['source_brand_candidates'];
        $selectedBrand = $brandSelection['selected_brand'];
        $brandSelectionReason = $brandSelection['brand_selection_reason'];
        if ($selectedBrand === null) {
            $warnings[] = 'missing_brand_manufacturer';
        }

        $imageUrls = $gearbox->localizedImageUrls();
        if ($imageUrls === []) $blockers[] = 'missing_local_images';
        $mapping = $this->jarekEbayCategoryMapping($gearbox);
        if (! $mapping) $blockers[] = 'missing_ebay_category';

        $sourcePricePln = is_numeric($gearbox->price) ? (float) $gearbox->price : null;
        $nbpRateData = $exchangeRateService->eurPln();
        $nbpExchangeRate = is_numeric($nbpRateData['rate'] ?? null) && (float) $nbpRateData['rate'] > 0 ? (float) $nbpRateData['rate'] : null;
        if ($nbpExchangeRate === null) {
            $blockers[] = 'missing_nbp_exchange_rate';
            if (filled($nbpRateData['warning'] ?? null)) {
                $warnings[] = (string) $nbpRateData['warning'];
            }
        }
        $priceEur = $sourcePricePln !== null && $sourcePricePln > 0 && $nbpExchangeRate !== null ? round($sourcePricePln / $nbpExchangeRate, 2) : null;

        $blockers = array_values(array_unique(array_filter($blockers)));
        $warnings = array_values(array_unique(array_filter($warnings)));

        $payloadPreview = [
            'sku' => $sku,
            'marketplace' => 'ebay_de',
            'title' => $translatedTitle,
            'description' => $renderedDescription,
            'categoryId' => $mapping['ebay_category_id'] ?? null,
            'imageUrls' => $imageUrls,
            'source_price_pln' => $sourcePricePln,
            'nbp_exchange_rate' => $nbpExchangeRate,
            'nbp_exchange_rate_meta' => [
                'source' => $nbpRateData['source'] ?? 'NBP_TABLE_A',
                'effective_date' => $nbpRateData['effective_date'] ?? null,
                'table_no' => $nbpRateData['table_no'] ?? null,
                'cached' => $nbpRateData['cached'] ?? null,
            ],
            'target_currency' => 'EUR',
            'price_eur' => $priceEur,
            'price' => $priceEur,
            'currency' => 'EUR',
            'quantity' => (int) $gearbox->quantity,
            'condition' => $conditionDiagnostics['mapped_ebay_condition'],
            'source_condition_name' => $conditionDiagnostics['source_condition_name'],
            'source_condition_value' => $conditionDiagnostics['source_condition_value'],
            'source_condition_parameter_id' => $conditionDiagnostics['source_condition_parameter_id'],
            'condition_source' => $conditionDiagnostics['condition_source'],
            'condition_mapping_reason' => $conditionDiagnostics['condition_mapping_reason'],
            'mapped_ebay_condition' => $conditionDiagnostics['mapped_ebay_condition'],
            'core_return_required' => $coreReturnNotice['required'],
            'core_return_type' => $coreReturnNotice['type'],
            'core_return_notice_added' => $coreReturnNoticeAdded,
            'core_return_notice_pl' => $coreReturnNotice['notice_pl'],
            'core_return_notice_de' => $coreReturnNotice['notice_de'],
            'core_return_notice_added_after_translation' => $coreReturnNoticeAddedDe,
            'core_return_notice_location' => $coreReturnNotice['required'] ? 'payload_template_footer' : null,
            'source_brand_candidates' => $sourceBrandCandidates,
            'selected_brand' => $selectedBrand,
            'brand_selection_reason' => $brandSelectionReason,
            'brand_source' => $brandSelection['brand_source'],
            'fulfillment_policy_id' => $mapping['fulfillment_policy_id'] ?? null,
            'shipping_group' => $mapping['shipping_group'] ?? null,
            'item_specifics' => array_filter([
                'Brand' => $selectedBrand,
                'Hersteller' => $selectedBrand,
                'Manufacturer Part Number' => $partNumber,
            ], fn ($value): bool => filled($value)),
        ];

        $payload = [
            'ok' => true,
            'dry_run' => true,
            'marketplace_write' => false,
            'parts_changed' => false,
            'source_table' => 'jarek_gearboxes',
            'sku' => $sku,
            'allegro_offer_id' => (string) $gearbox->allegro_offer_id,
            'market' => 'ebay_de',
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'source_title_pl' => $sourceTitle,
            'translated_title_de' => $translatedTitle,
            'title_length' => $titleLength,
            'title_limit' => 80,
            'suggested_short_title' => $suggestedShortTitle,
            'title_requires_review' => $titleRequiresReview,
            'source_description_pl_cleaned' => $sourceDescriptionForTranslation,
            'translated_description_de' => $translatedDescription,
            'rendered_description_de_template' => $renderedDescription,
            'core_return_required' => $coreReturnNotice['required'],
            'core_return_type' => $coreReturnNotice['type'],
            'core_return_notice_added' => $coreReturnNoticeAdded,
            'core_return_notice_pl' => $coreReturnNotice['notice_pl'],
            'core_return_notice_de' => $coreReturnNotice['notice_de'],
            'core_return_notice_added_after_translation' => $coreReturnNoticeAddedDe,
            'core_return_notice_location' => $coreReturnNotice['required'] ? 'payload_template_footer' : null,
            'ebay_category_id' => $mapping['ebay_category_id'] ?? null,
            'image_urls' => $imageUrls,
            'source_price_pln' => $sourcePricePln,
            'nbp_exchange_rate' => $nbpExchangeRate,
            'nbp_exchange_rate_meta' => $payloadPreview['nbp_exchange_rate_meta'],
            'target_currency' => 'EUR',
            'price_eur' => $priceEur,
            'price' => $priceEur,
            'currency' => 'EUR',
            'quantity' => (int) $gearbox->quantity,
            'existing_ebay_listing_id' => $gearbox->ebay_listing_id,
            'existing_ebay_offer_id' => $gearbox->ebay_offer_id,
            'existing_ebay_inventory_sku' => $gearbox->ebay_inventory_sku,
            'source_brand_candidates' => $sourceBrandCandidates,
            'selected_brand' => $selectedBrand,
            'brand_selection_reason' => $brandSelectionReason,
            'brand_source' => $brandSelection['brand_source'],
            'item_specifics' => $payloadPreview['item_specifics'],
            'source_condition_name' => $conditionDiagnostics['source_condition_name'],
            'source_condition_value' => $conditionDiagnostics['source_condition_value'],
            'source_condition_parameter_id' => $conditionDiagnostics['source_condition_parameter_id'],
            'condition_source' => $conditionDiagnostics['condition_source'],
            'condition_mapping_reason' => $conditionDiagnostics['condition_mapping_reason'],
            'mapped_ebay_condition' => $conditionDiagnostics['mapped_ebay_condition'],
            'payload_preview' => $payloadPreview,
            'contains_allegro_image_urls' => $this->arrayContainsStringFragment($payloadPreview, 'a.allegroimg.com'),
        ];

        $this->logJarekEbayDePreparePreview($payload['ready'] ? 'success' : 'blocked', 'Preview payloadu eBay DE dla Skrzyń Jarka; bez eBay API, bez parts, marketplace_write=false.', $payload, $started);

        return response()->json($payload);
    }


    public function ebayDePublishPreview(Request $request, GoogleTranslateService $translateService, EbayDescriptionTemplateRenderer $renderer, NbpExchangeRateService $exchangeRateService): JsonResponse
    {
        $response = $this->ebayDePreparePreview($request, $translateService, $renderer, $exchangeRateService);
        $payload = $response->getData(true);
        $payload['action'] = 'jarek_gearboxes_ebay_de_publish_preview';
        $payload['dry_run'] = true;
        $payload['marketplace_write'] = false;
        $payload['parts_changed'] = false;
        $payload['publish_preview'] = true;
        $payload['apply_requires_confirm'] = 'jarek-ebay-de-publish-one';
        $payload['idempotency'] = [
            'sku' => $payload['sku'] ?? null,
            'existing_ebay_listing_id' => $payload['existing_ebay_listing_id'] ?? null,
            'existing_ebay_offer_id' => $payload['existing_ebay_offer_id'] ?? null,
            'existing_ebay_inventory_sku' => $payload['existing_ebay_inventory_sku'] ?? null,
            'safe_to_retry' => true,
            'apply_requires_confirm' => 'jarek-ebay-de-publish-one',
        ];
        $plan = $this->jarekEbayDeApiPlan($payload);
        $payload['ebay_api_plan'] = $plan['plan'];
        $payload['blockers'] = array_values(array_unique(array_merge($payload['blockers'] ?? [], $plan['blockers'])));
        $payload['ready'] = ($payload['blockers'] ?? []) === [];
        $payload['safety'] = array_values(array_unique(array_merge($payload['safety'] ?? [], [
            'dry_run_only',
            'no_ebay_api_write',
            'no_inventory_item_write',
            'no_offer_publish',
            'no_parts_write',
        ])));

        if (Schema::hasTable('marketplace_sync_logs')) {
            MarketplaceSyncLog::query()->create([
                'marketplace' => 'ebay_de',
                'action' => 'jarek_gearboxes_ebay_de_publish_preview',
                'status' => ($payload['ready'] ?? false) ? 'success' : 'blocked',
                'message' => 'Dry-run publish preview eBay DE dla Skrzyń Jarka; bez eBay API write i bez apply.',
                'external_id' => $payload['allegro_offer_id'] ?? $payload['sku'] ?? null,
                'payload' => $payload,
                'created_at' => now(),
            ]);
        }

        return response()->json($payload, $response->getStatusCode());
    }

    public function ebayDePublishApply(Request $request, GoogleTranslateService $translateService, EbayDescriptionTemplateRenderer $renderer, NbpExchangeRateService $exchangeRateService): JsonResponse
    {
        $started = microtime(true);
        $requiredConfirm = 'jarek-ebay-de-publish-one';
        $allowedSku = 'JAREK-18727785496';
        $sku = (string) $request->query('sku', '');
        $confirm = (string) $request->query('confirm', '');

        $base = [
            'ok' => false,
            'dry_run' => false,
            'marketplace_write' => false,
            'parts_changed' => false,
            'applied' => false,
            'action' => 'jarek_gearboxes_ebay_de_publish_one',
            'required_confirm' => $requiredConfirm,
            'provided_confirm' => $request->query('confirm'),
            'allowed_sku' => $allowedSku,
            'sku' => $sku,
            'blockers' => [],
            'warnings' => [],
        ];

        if ($sku !== $allowedSku) {
            return response()->json($base + ['error' => 'Publish apply is allowed only for the single guarded SKU.', 'blockers' => ['sku_not_allowed']], 403);
        }
        if ($confirm !== $requiredConfirm) {
            return response()->json($base + ['error' => 'Missing or invalid guarded apply confirmation token.', 'blockers' => ['invalid_confirm']], 403);
        }

        $previewRequest = Request::create($request->path(), 'GET', ['sku' => $sku]);
        $prepareResponse = $this->ebayDePublishPreview($previewRequest, $translateService, $renderer, $exchangeRateService);
        $prepare = $prepareResponse->getData(true);
        $plan = is_array($prepare['ebay_api_plan'] ?? null) ? $prepare['ebay_api_plan'] : [];
        $blockers = array_values((array) ($prepare['blockers'] ?? []));

        foreach (['existing_ebay_listing_id', 'existing_ebay_offer_id', 'existing_ebay_inventory_sku'] as $field) {
            if (filled($prepare[$field] ?? null)) $blockers[] = $field.'_present';
        }

        $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', 'ebay_de')->first() : null;
        $client = new EbayApiClient('ebay_de', $account);
        $idempotencyCheck = $client->readOnlyInventoryAndOfferExistence($sku);
        if (! ($idempotencyCheck['ok'] ?? false)) $blockers[] = 'idempotency_check_failed';
        if ($idempotencyCheck['inventory_item_exists'] ?? false) $blockers[] = 'inventory_item_exists';
        if ($idempotencyCheck['offer_exists'] ?? false) $blockers[] = 'offer_exists';
        if (filled($idempotencyCheck['listing_id'] ?? null)) $blockers[] = 'listing_exists';

        $payload = $base + [
            'prepare' => $prepare,
            'idempotency' => $idempotencyCheck + ['safe_to_retry' => false],
            'ebay_api_plan' => $plan,
        ];
        $payload['blockers'] = array_values(array_unique($blockers));

        if ($payload['blockers'] !== []) {
            $payload['error'] = 'Guarded publish apply blocked before any eBay write.';
            $this->logJarekEbayDePublishOne('blocked', $payload['error'], $payload, $started);
            return response()->json($payload, 409);
        }

        $result = $client->publishInventoryOffer($sku, (array) ($plan['inventory_item_request'] ?? []), (array) ($plan['offer_request'] ?? []), 'de-DE');
        $payload['marketplace_write'] = true;
        $payload['applied'] = (bool) ($result['ok'] ?? false);
        $payload['result'] = $result;
        $payload['offer_id'] = $result['offer_id'] ?? null;
        $payload['listing_id'] = $result['listing_id'] ?? null;
        $payload['published_offer_response'] = $result['json'] ?? null;
        $payload['errors'] = ($result['ok'] ?? false) ? [] : [$result['error'] ?? ($result['json'] ?? 'eBay publish failed')];
        $payload['ok'] = (bool) ($result['ok'] ?? false);

        $this->logJarekEbayDePublishOne($payload['ok'] ? 'success' : 'error', $payload['ok'] ? 'Guarded single SKU eBay DE publish completed.' : 'Guarded single SKU eBay DE publish failed.', $payload, $started);

        return response()->json($payload, $payload['ok'] ? 200 : 502);
    }



    /** @param array<string, mixed> $payload */
    private function logJarekEbayDePublishOne(string $status, string $message, array $payload, float $started): void
    {
        if (! Schema::hasTable('marketplace_sync_logs')) return;

        MarketplaceSyncLog::query()->create([
            'marketplace' => 'ebay_de',
            'action' => 'jarek_gearboxes_ebay_de_publish_one',
            'status' => $status,
            'message' => $message,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'external_id' => $payload['offer_id'] ?? $payload['sku'] ?? null,
            'payload' => [
                'sku' => $payload['sku'] ?? null,
                'ebay_inventory_sku' => $payload['sku'] ?? null,
                'offer_id' => $payload['offer_id'] ?? null,
                'listing_id' => $payload['listing_id'] ?? null,
                'published_offer_response' => $payload['published_offer_response'] ?? null,
                'errors' => $payload['errors'] ?? [],
                'result' => $payload['result'] ?? null,
                'idempotency' => $payload['idempotency'] ?? null,
                'blockers' => $payload['blockers'] ?? [],
            ],
            'created_at' => now(),
        ]);
    }

    /** @return array{plan: array<string, mixed>, blockers: array<int, string>} */
    private function jarekEbayDeApiPlan(array $payload): array
    {
        $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', 'ebay_de')->first() : null;
        $settings = is_array($account?->api_settings) ? $account->api_settings : [];
        $mapping = is_array($payload['payload_preview'] ?? null) ? ($payload['payload_preview'] ?? []) : [];
        $categoryId = filled($payload['ebay_category_id'] ?? null) ? (string) $payload['ebay_category_id'] : null;
        $sku = filled($payload['sku'] ?? null) ? (string) $payload['sku'] : null;
        $quantity = is_numeric($payload['quantity'] ?? null) ? (int) $payload['quantity'] : null;
        $price = is_numeric($payload['price'] ?? null) ? round((float) $payload['price'], 2) : null;
        $currency = filled($payload['currency'] ?? null) ? (string) $payload['currency'] : 'EUR';
        $imageUrls = array_values((array) ($payload['image_urls'] ?? []));
        $itemSpecifics = (array) ($payload['item_specifics'] ?? []);
        $listingDescription = (string) ($payload['rendered_description_de_template'] ?? $payload['translated_description_de'] ?? '');
        $marketplaceId = filled($settings['marketplace_id'] ?? null) ? (string) $settings['marketplace_id'] : null;
        $inventoryDescription = $this->jarekInventoryDescription($payload, (string) ($sku ?? ''), (string) ($marketplaceId ?? 'EBAY_DE'));
        $format = filled($settings['format'] ?? null) ? (string) $settings['format'] : 'FIXED_PRICE';
        $listingDuration = filled($settings['listing_duration'] ?? null) ? (string) $settings['listing_duration'] : 'GTC';
        $merchantLocationKey = $this->jarekEbayMerchantLocationKey($settings, is_array($account?->config) ? $account->config : []);
        $fulfillmentPolicyId = filled($payload['payload_preview']['fulfillment_policy_id'] ?? null) ? (string) $payload['payload_preview']['fulfillment_policy_id'] : null;
        $paymentPolicyId = $this->jarekEbayPolicyId($settings, 'payment');
        $returnPolicyId = $this->jarekEbayPolicyId($settings, 'return');

        $aspects = [];
        foreach ($itemSpecifics as $name => $value) {
            if (! filled($name) || ! filled($value)) continue;
            $aspects[(string) $name] = [trim((string) $value)];
        }

        $condition = filled($payload['mapped_ebay_condition'] ?? null) ? (string) $payload['mapped_ebay_condition'] : null;

        $inventoryItemRequest = [
            'product' => ['title' => (string) ($payload['translated_title_de'] ?? ''), 'description' => $inventoryDescription, 'imageUrls' => $imageUrls, 'aspects' => $aspects],
            'condition' => $condition,
            'availability' => ['shipToLocationAvailability' => ['quantity' => $quantity]],
        ];
        $offerRequest = [
            'sku' => $sku,
            'marketplaceId' => $marketplaceId,
            'format' => $format,
            'listingDuration' => $listingDuration,
            'availableQuantity' => $quantity,
            'categoryId' => $categoryId,
            'merchantLocationKey' => $merchantLocationKey,
            'pricingSummary' => ['price' => ['value' => $price !== null ? (string) $price : null, 'currency' => $currency]],
            'listingPolicies' => ['fulfillmentPolicyId' => $fulfillmentPolicyId, 'paymentPolicyId' => $paymentPolicyId, 'returnPolicyId' => $returnPolicyId],
            'listingDescription' => $listingDescription,
        ];
        $publishOfferRequest = ['method' => 'POST', 'path' => '/sell/inventory/v1/offer/{offerId}/publish', 'body' => null];

        $blockers = [];
        if (blank($merchantLocationKey)) $blockers[] = 'missing_merchant_location_key';
        if (blank($fulfillmentPolicyId)) $blockers[] = 'missing_fulfillment_policy_id';
        if (blank($paymentPolicyId)) $blockers[] = 'missing_payment_policy_id';
        if (blank($returnPolicyId)) $blockers[] = 'missing_return_policy_id';
        if (blank($marketplaceId)) $blockers[] = 'missing_marketplace_id';
        if (blank($condition)) $blockers[] = 'missing_or_invalid_ebay_condition';
        if (blank($sku) || blank($inventoryItemRequest['product']['title']) || $imageUrls === [] || ! is_int($quantity) || $quantity <= 0) $blockers[] = 'missing_inventory_item_request_fields';
        if (blank($sku) || blank($categoryId) || blank($format) || blank($listingDuration) || blank($merchantLocationKey) || blank($fulfillmentPolicyId) || blank($paymentPolicyId) || blank($returnPolicyId) || $price === null || $price <= 0 || ! is_int($quantity) || $quantity <= 0) $blockers[] = 'missing_offer_request_fields';

        return ['plan' => [
            'inventory_item_request' => $inventoryItemRequest,
            'offer_request' => $offerRequest,
            'publish_offer_request' => $publishOfferRequest,
            'merchant_location_key' => $merchantLocationKey,
            'marketplace_id' => $marketplaceId,
            'fulfillment_policy_id' => $fulfillmentPolicyId,
            'payment_policy_id' => $paymentPolicyId,
            'return_policy_id' => $returnPolicyId,
            'format' => $format,
            'listing_duration' => $listingDuration,
            'category_id' => $categoryId,
            'sku' => $sku,
            'quantity' => $quantity,
            'price' => $price,
            'currency' => $currency,
            'image_urls' => $imageUrls,
            'item_specifics' => $itemSpecifics,
            'listing_description' => $listingDescription,
            'inventory_description_source' => filled($payload['translated_description_de'] ?? null) ? 'translated_description_de' : 'translated_title_de',
            'condition_diagnostics' => [
                'source_condition_name' => $payload['source_condition_name'] ?? null,
                'source_condition_value' => $payload['source_condition_value'] ?? null,
                'source_condition_parameter_id' => $payload['source_condition_parameter_id'] ?? null,
                'condition_source' => $payload['condition_source'] ?? null,
                'condition_mapping_reason' => $payload['condition_mapping_reason'] ?? null,
                'mapped_ebay_condition' => $condition,
            ],
        ], 'blockers' => array_values(array_unique($blockers))];
    }

    /** @return array<string, mixed> */
    private function jarekEbayConditionDiagnostics(JarekGearbox $gearbox): array
    {
        $parameter = $this->jarekFindConditionParameter($gearbox);
        $value = $parameter['value'] ?? null;
        $normalized = $this->normalizeConditionValue($value);
        $map = [
            'nowy' => 'NEW',
            'nowa' => 'NEW',
            'new' => 'NEW',
            'uzywany' => 'USED_EXCELLENT',
            'używany' => 'USED_EXCELLENT',
            'uzywana' => 'USED_EXCELLENT',
            'używana' => 'USED_EXCELLENT',
            'used' => 'USED_EXCELLENT',
            'regenerowany' => 'SELLER_REFURBISHED',
            'regenerowana' => 'SELLER_REFURBISHED',
            'po regeneracji' => 'SELLER_REFURBISHED',
            'refurbished' => 'SELLER_REFURBISHED',
        ];
        $mapped = $normalized !== null ? ($map[$normalized] ?? null) : null;

        return [
            'source_condition_name' => $parameter['name'] ?? null,
            'source_condition_value' => $value,
            'source_condition_parameter_id' => $parameter['id'] ?? null,
            'condition_source' => $parameter['source'] ?? null,
            'condition_mapping_reason' => $mapped
                ? 'mapped_from_allegro_condition_parameter'
                : ($parameter ? 'allegro_condition_value_not_mapped' : 'allegro_condition_parameter_not_found'),
            'mapped_ebay_condition' => $mapped,
        ];
    }

    /** @return array<string, mixed>|null */
    private function jarekFindConditionParameter(JarekGearbox $gearbox): ?array
    {
        foreach ([
            'parameters' => $gearbox->parameters,
            'raw_payload.parameters' => data_get($gearbox->raw_payload, 'parameters'),
            'category_payload.parameters' => data_get($gearbox->category_payload, 'parameters'),
        ] as $source => $parameters) {
            foreach (is_array($parameters) ? $parameters : [] as $parameter) {
                $name = (string) data_get($parameter, 'name', '');
                if (! in_array(mb_strtolower(trim($name)), ['stan', 'kondycja', 'condition'], true)) continue;

                $values = data_get($parameter, 'valuesLabels') ?: data_get($parameter, 'values') ?: [];
                $value = is_array($values) ? ($values[0] ?? null) : $values;
                return ['source' => $source, 'id' => data_get($parameter, 'id'), 'name' => $name, 'value' => is_scalar($value) ? (string) $value : null];
            }
        }

        return null;
    }

    private function normalizeConditionValue(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') return null;
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $value) ?? (string) $value));
    }

    private function jarekEbayPolicyId(array $settings, string $type): ?string
    {
        foreach (["{$type}_policy_id", "default_{$type}_policy_id", "ebay_{$type}_policy_id"] as $key) {
            if (filled($settings[$key] ?? null)) return (string) $settings[$key];
        }
        $policies = is_array($settings['business_policies'] ?? null) ? $settings['business_policies'] : [];
        return filled($policies[$type] ?? null) ? (string) $policies[$type] : null;
    }

    private function jarekEbayMerchantLocationKey(array $settings, array $config): ?string
    {
        foreach ([$settings, $config, array_merge((array) config('product-hub.ebay.default_location', []), (array) config('product-hub.ebay.accounts.ebay_de', []))] as $source) {
            foreach (['merchant_location_key', 'merchantLocationKey', 'location_key', 'inventory_location_key'] as $key) {
                if (filled($source[$key] ?? null)) return (string) $source[$key];
            }
        }

        return null;
    }

    private function plainJarekText(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 3900);
    }

    private function jarekInventoryDescription(array $payload, string $sku, string $marketplaceId): string
    {
        $description = $this->plainJarekText((string) (($payload['translated_description_de'] ?? null) ?: ($payload['translated_title_de'] ?? null) ?: ''));
        if ($description === '') {
            $description = match ($marketplaceId) {
                'EBAY_FR' => 'Pièce automobile '.$sku,
                default => 'Autoteil '.$sku,
            };
        }

        return mb_substr($description, 0, 3900);
    }


    /** @return array{required: bool, type: ?string, notice_pl: ?string, notice_de: ?string, warning: ?string} */
    private function jarekCoreReturnNotice(string $title): array
    {
        if (preg_match('/skrzynia\s+biegów/iu', $title)) {
            return [
                'required' => true,
                'type' => 'gearbox',
                'notice_pl' => 'Stara skrzynia biegów podlega zwrotowi',
                'notice_de' => 'Das Altgetriebe muss zurückgegeben werden.',
                'warning' => 'gearbox_core_return_notice_added',
            ];
        }

        if (preg_match('/tylny\s+most/iu', $title)) {
            return [
                'required' => true,
                'type' => 'rear_axle',
                'notice_pl' => 'Stary tylny most podlega zwrotowi',
                'notice_de' => 'Die alte Hinterachse muss zurückgegeben werden.',
                'warning' => 'rear_axle_core_return_notice_added',
            ];
        }

        return [
            'required' => false,
            'type' => null,
            'notice_pl' => null,
            'notice_de' => null,
            'warning' => null,
        ];
    }

    private function containsNotice(string $text, ?string $notice): bool
    {
        return filled($notice) && mb_stripos($text, (string) $notice) !== false;
    }

    private function appendNotice(string $text, ?string $notice): string
    {
        if (! filled($notice)) return $text;

        $text = trim($text);
        return $text === '' ? (string) $notice : $text."\n\n".$notice;
    }

    /** @param array{required: bool, type: ?string, notice_pl: ?string, notice_de: ?string, warning: ?string} $coreReturnNotice */
    private function removeCoreReturnNotices(string $text, array $coreReturnNotice): string
    {
        $notices = array_filter([
            $coreReturnNotice['notice_pl'],
            $coreReturnNotice['notice_de'],
            'Stara skrzynia biegów podlega zwrotowi',
            'Stary tylny most podlega zwrotowi',
            'Das Altgetriebe muss zurückgegeben werden.',
            'Die alte Hinterachse muss zurückgegeben werden.',
            'Das alte Getriebe kann zurückgegeben werden.',
        ], fn ($notice): bool => filled($notice));

        foreach ($notices as $notice) {
            $text = str_ireplace((string) $notice, '', $text);
        }

        return trim((string) preg_replace("/[ \t]+\n/u", "\n", preg_replace("/\n{3,}/u", "\n\n", $text)));
    }

    /** @return array{source_brand_candidates: array<int, string>, selected_brand: ?string, brand_selection_reason: string, brand_source: string} */
    private function selectJarekGearboxBrand(JarekGearbox $gearbox): array
    {
        $parameterBrand = $this->detectJarekGearboxParameterBrand($gearbox->parameters ?? []);
        $titleCandidates = $this->detectJarekGearboxTitleBrands((string) $gearbox->title);

        if ($parameterBrand !== null) {
            return [
                'source_brand_candidates' => array_values(array_unique(array_merge([$parameterBrand], $titleCandidates))),
                'selected_brand' => $parameterBrand,
                'brand_selection_reason' => 'parameter_brand',
                'brand_source' => 'parameter',
            ];
        }

        if (in_array('Audi', $titleCandidates, true)) {
            return [
                'source_brand_candidates' => $titleCandidates,
                'selected_brand' => 'Audi',
                'brand_selection_reason' => 'preferred_detected_brand',
                'brand_source' => 'title',
            ];
        }

        if ($titleCandidates !== []) {
            return [
                'source_brand_candidates' => $titleCandidates,
                'selected_brand' => $titleCandidates[0],
                'brand_selection_reason' => 'first_detected_brand',
                'brand_source' => 'title',
            ];
        }

        return [
            'source_brand_candidates' => [],
            'selected_brand' => null,
            'brand_selection_reason' => 'missing_brand_manufacturer',
            'brand_source' => 'none',
        ];
    }

    private function detectJarekGearboxParameterBrand(array $parameters): ?string
    {
        foreach ($parameters as $parameter) {
            if (! is_array($parameter)) continue;
            $name = mb_strtolower(trim((string) ($parameter['name'] ?? $parameter['id'] ?? '')));
            if (! preg_match('/(producent|manufacturer|brand|marka|oe)/u', $name)) continue;

            $values = $parameter['values'] ?? $parameter['value'] ?? null;
            $values = is_array($values) ? $values : [$values];
            $normalized = array_values(array_unique(array_filter(array_map(fn ($value): ?string => $this->normalizeJarekGearboxBrand((string) $value), $values))));
            if (count($normalized) === 1 && $normalized[0] !== 'GPSwiss') {
                return $normalized[0];
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function detectJarekGearboxTitleBrands(string $title): array
    {
        $aliases = [
            'Mercedes-Benz' => 'Mercedes-Benz', 'Mercedes' => 'Mercedes-Benz', 'Citroën' => 'Citroen', 'Citroen' => 'Citroen',
            'Volkswagen' => 'Volkswagen', 'VW' => 'Volkswagen', 'VAG' => 'Volkswagen', 'Audi' => 'Audi', 'Skoda' => 'Skoda',
            'Renault' => 'Renault', 'Kia' => 'Kia', 'Hyundai' => 'Hyundai', 'Ford' => 'Ford', 'Opel' => 'Opel', 'BMW' => 'BMW',
            'Peugeot' => 'Peugeot', 'Toyota' => 'Toyota', 'Honda' => 'Honda', 'Nissan' => 'Nissan', 'Fiat' => 'Fiat', 'Volvo' => 'Volvo',
        ];
        $matches = [];
        foreach ($aliases as $alias => $brand) {
            if (preg_match('/(?<![\pL\pN])'.preg_quote($alias, '/').'(?![\pL\pN])/iu', $title, $match, PREG_OFFSET_CAPTURE)) {
                $matches[] = ['brand' => $brand, 'pos' => $match[0][1]];
            }
        }
        usort($matches, fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);

        return array_values(array_unique(array_column($matches, 'brand')));
    }

    private function normalizeJarekGearboxBrand(string $brand): ?string
    {
        $brand = trim(preg_replace('/\s+/u', ' ', strip_tags($brand)) ?: '');
        if ($brand === '' || strcasecmp($brand, 'GPSwiss') === 0) return null;
        $map = ['vw' => 'Volkswagen', 'vag' => 'Volkswagen', 'mercedes' => 'Mercedes-Benz', 'citroën' => 'Citroen', 'citroen' => 'Citroen'];
        $key = mb_strtolower($brand);
        return $map[$key] ?? $brand;
    }

    public function ebayCsvPreview(Request $request): JsonResponse
    {
        return response()->json($this->buildJarekEbayCsvPreview($this->smallCsvLimit($request)));
    }

    public function imageSourceDiagnostics(Request $request): JsonResponse
    {
        if ($request->query('confirm') !== 'jarek-image-diagnostics') {
            return response()->json(['ok' => false, 'error' => 'Missing confirm=jarek-image-diagnostics', 'marketplace_write' => false, 'parts_changed' => false], 422);
        }

        $limit = max(1, min(5, (int) $request->query('limit', 5)));
        $deep = $request->boolean('deep');
        $this->resetJarekImageDiagnosticsBudget();

        $rows = Schema::hasTable('jarek_gearboxes')
            ? JarekGearbox::query()->orderBy('id')->limit($limit)->get()
            : collect();

        return response()->json([
            'ok' => Schema::hasTable('jarek_gearboxes'),
            'dry_run' => true,
            'marketplace_write' => false,
            'parts_changed' => false,
            'source_table' => 'jarek_gearboxes',
            'limit' => $limit,
            'deep' => $deep,
            'safety' => ['no_parts_write' => true, 'no_ovoko_write' => true, 'no_allegro_write' => true, 'no_ebay_api_write' => true, 'no_image_download_or_copy' => true],
            'diagnostics' => $rows->map(fn (JarekGearbox $gearbox): array => [
                'source_jarek_gearbox_id' => $gearbox->id,
                'sku' => 'JAREK-'.($gearbox->allegro_offer_id ?: $gearbox->id),
                'images' => $this->jarekImageDiagnostics($gearbox, $deep),
            ])->all(),
            'file_exists_checks_used' => $this->jarekImageFileExistsChecks,
            'file_exists_budget_exceeded' => $this->jarekImageFileExistsBudgetExceeded,
        ]);
    }

    public function localizeImagesDryRun(Request $request): JsonResponse
    {
        return response()->json($this->buildJarekImageLocalization($this->limit($request), false));
    }

    public function localizeImagesApply(Request $request): JsonResponse
    {
        if ($request->query('confirm') !== 'jarek-localize-images') {
            $response = ['ok' => false, 'error' => 'Missing confirm=jarek-localize-images', 'marketplace_write' => false, 'parts_changed' => false];
            $this->logJarekImageLocalization('blocked', 'Odmowa lokalizacji zdjęć Jarka: brak wymaganego confirm.', $response);

            return response()->json($response, 422);
        }

        $result = $this->buildJarekImageLocalization(max(1, min(10, (int) $request->query('limit', 5))), true);
        $this->logJarekImageLocalization('success', 'Lokalizacja zdjęć Skrzyń Jarka; bez parts i bez marketplace write.', $result);

        return response()->json($result);
    }

    public function ebayCsvExport(Request $request): JsonResponse|StreamedResponse
    {
        if ($request->query('confirm') !== 'jarek-ebay-csv') {
            return response()->json(['ok' => false, 'error' => 'Missing confirm=jarek-ebay-csv', 'marketplace_write' => false, 'parts_changed' => false], 422);
        }

        $preview = $this->buildJarekEbayCsvPreview($this->smallCsvLimit($request));
        $rows = $preview['sample_rows'];
        $filename = 'jarek-gearboxes-ebay-'.now()->format('Ymd-His').'-limit-'.$preview['limit'].'.csv';
        $path = 'exports/jarek-gearboxes/'.$filename;

        Storage::disk('local')->put($path, $this->csvString($rows));

        $payload = $preview + [
            'csv_path' => $path,
            'download_url' => route('admin.tools.jarek-gearboxes.ebay-csv-download', ['filename' => $filename]),
            'exported_count' => count($rows),
        ];

        if (Schema::hasTable('marketplace_sync_logs')) {
            MarketplaceSyncLog::query()->create([
                'marketplace' => 'ebay',
                'action' => 'jarek_gearboxes_ebay_csv_export',
                'status' => 'success',
                'message' => 'CSV export Skrzyń Jarka dla eBay; bez eBay API, bez parts, marketplace_write=false.',
                'payload' => $payload,
                'created_at' => now(),
            ]);
        }

        if ($request->boolean('download')) {
            return Storage::disk('local')->download($path, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return response()->json($payload);
    }

    public function ebayCsvDownload(string $filename): StreamedResponse|JsonResponse
    {
        if (! preg_match('/^jarek-gearboxes-ebay-[0-9]{8}-[0-9]{6}-limit-[0-9]+\.csv$/', $filename)) {
            return response()->json(['ok' => false, 'error' => 'Invalid filename', 'marketplace_write' => false], 404);
        }

        $path = 'exports/jarek-gearboxes/'.$filename;
        if (! Storage::disk('local')->exists($path)) {
            return response()->json(['ok' => false, 'error' => 'File not found', 'marketplace_write' => false], 404);
        }

        return Storage::disk('local')->download($path, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }


    /** @return array<string, mixed> */
    private function buildJarekEbayCsvPreview(int $limit): array
    {
        $base = [
            'ok' => Schema::hasTable('jarek_gearboxes'),
            'dry_run' => true,
            'marketplace_write' => false,
            'parts_changed' => false,
            'source_table' => 'jarek_gearboxes',
            'limit' => $limit,
            'max_without_separate_confirmation' => 25,
            'total' => 0,
            'exportable_count' => 0,
            'blocked_count' => 0,
            'warnings_by_reason' => array_fill_keys(['missing_title','title_too_long','missing_price','missing_quantity','missing_local_images','missing_part_number','missing_ebay_category','missing_ebay_category_mapping','duplicate_sku','invalid_currency','csv_field_normalized','csv_field_omitted','description_image_blocks_removed'], 0),
            'sample_rows' => [],
            'blocked_samples' => [],
            'local_image_url_source_fields' => [JarekGearbox::LOCALIZED_IMAGES_SOURCE],
            'localized_images_source' => JarekGearbox::LOCALIZED_IMAGES_SOURCE,
            'csv_uses_only_our_server_images' => true,
            'csv_contains_allegro_image_urls' => false,
            'allowed_image_hosts' => $this->localImageHosts(),
            'category_mapping_diagnostics' => ['source' => 'marketplace_category_mappings: allegro/allegro_main external_category_id -> local_category_id -> ebay_de/ebay_fr/ebay mapping'],
            'missing_ebay_category_mapping_summary' => [],
            'safety' => ['no_parts_write' => true, 'no_ovoko_write' => true, 'no_allegro_write' => true, 'no_ebay_api_write' => true, 'no_publish_relist_end_update' => true, 'no_api_sync' => true, 'no_image_download_or_copy' => true],
        ];

        if (! Schema::hasTable('jarek_gearboxes')) return $base;

        $query = JarekGearbox::query()->orderBy('id');
        $base['total'] = (clone $query)->count();
        $skuCounts = (clone $query)->select('allegro_offer_id', DB::raw('count(*) as c'))->groupBy('allegro_offer_id')->pluck('c', 'allegro_offer_id');

        $this->resetJarekImageDiagnosticsBudget();
        $diagnosticLimit = min($limit, self::JAREK_PREVIEW_MAX_IMAGE_DIAGNOSTIC_RECORDS);

        foreach ((clone $query)->get() as $gearbox) {
            $csvRow = $this->jarekEbayCsvRow($gearbox);
            $reasons = $this->jarekEbayCsvWarnings($gearbox, (int) ($skuCounts[$gearbox->allegro_offer_id] ?? 0));
            foreach (array_unique($reasons) as $reason) $base['warnings_by_reason'][$reason]++;
            $blockers = $this->jarekEbayCsvBlockers($reasons);

            if ($blockers === []) {
                $base['exportable_count']++;
                if (count($base['sample_rows']) < $limit) {
                    $base['sample_rows'][] = $csvRow + ['warnings' => $reasons, 'diagnostics' => $this->jarekPreviewDiagnostics($gearbox, count($base['sample_rows']) < $diagnosticLimit)];
                }
            } else {
                $base['blocked_count']++;
                if (count($base['blocked_samples']) < $limit) {
                    $titleWarnings = [];
                    $base['blocked_samples'][] = ['source_jarek_gearbox_id' => $gearbox->id, 'sku' => $csvRow['SKU'], 'title' => $this->normalizeString($gearbox->title, 'title', $titleWarnings), 'blockers' => $blockers, 'warnings' => $reasons, 'diagnostics' => $this->jarekPreviewDiagnostics($gearbox, count($base['blocked_samples']) < $diagnosticLimit)];
                }
            }
        }

        $base['image_diagnostics_limits'] = [
            'max_records_with_storage_diagnostics' => $diagnosticLimit,
            'max_candidate_paths_per_record' => self::JAREK_PREVIEW_MAX_STORAGE_CANDIDATE_PATHS_PER_RECORD,
            'max_file_exists_checks_per_request' => self::JAREK_PREVIEW_MAX_FILE_EXISTS_CHECKS,
            'file_exists_checks_used' => $this->jarekImageFileExistsChecks,
            'file_exists_budget_exceeded' => $this->jarekImageFileExistsBudgetExceeded,
            'recursive_storage_scan' => false,
        ];

        $base['csv_uses_only_our_server_images'] = collect($base['sample_rows'])->every(fn (array $row): bool => $this->csvRowImagesAreLocal($row));
        $base['csv_contains_allegro_image_urls'] = collect($base['sample_rows'])->contains(fn (array $row): bool => $this->csvRowContainsAllegroImageUrl($row));
        $base['missing_ebay_category_mapping_summary'] = $this->missingJarekEbayCategoryMappingSummary();

        return $base;
    }

    /** @return array<string, string|null> */
    private function jarekEbayCsvRow(JarekGearbox $gearbox): array
    {
        $images = $gearbox->csvImageUrls();
        $categoryMapping = $this->jarekEbayCategoryMapping($gearbox);
        $sku = 'JAREK-'.($gearbox->allegro_offer_id ?: $gearbox->id);
        $partNumber = $this->detectJarekPartNumber((object) $gearbox->getAttributes(), $sku);
        $normalizationWarnings = [];

        return [
            'SKU' => $sku,
            'Title' => $this->normalizeString($gearbox->title, 'title', $normalizationWarnings),
            'Description' => $this->normalizeJarekDescription($gearbox->description ?: $gearbox->plain_description, $normalizationWarnings),
            'Price' => $this->normalizeString($gearbox->price, 'price', $normalizationWarnings),
            'Currency' => $this->normalizeString($gearbox->currency ?: 'PLN', 'currency', $normalizationWarnings),
            'Quantity' => $this->normalizeString($gearbox->quantity, 'quantity', $normalizationWarnings),
            'Condition' => 'Used',
            'Manufacturer Part Number' => $this->normalizeString($partNumber, 'part_number', $normalizationWarnings),
            'Brand' => 'GPSwiss',
            'Allegro category ID' => $this->normalizeString($gearbox->category_id, 'category_id', $normalizationWarnings),
            'Allegro category name' => $this->normalizeString($gearbox->category_name, 'category_name', $normalizationWarnings),
            'Allegro category path' => $this->normalizeCategoryPath($gearbox->category_path, $normalizationWarnings),
            'Suggested eBay category' => $this->normalizeString($categoryMapping['ebay_category_id'] ?? null, 'ebay_category_id', $normalizationWarnings),
            'Main image URL' => $this->normalizeString($images[0] ?? null, 'main_image_url', $normalizationWarnings),
            'Additional image URLs' => implode('|', $this->normalizeUrlList(array_slice($images, 1), 'additional_image_urls', $normalizationWarnings)),
            'Source JarekGearbox ID' => (string) $gearbox->id,
            'Allegro offer ID' => $this->normalizeString($gearbox->allegro_offer_id, 'allegro_offer_id', $normalizationWarnings),
            'Original Allegro URL' => $this->normalizeString($gearbox->allegro_offer_url, 'allegro_offer_url', $normalizationWarnings),
            'normalization_warnings' => array_values(array_unique($normalizationWarnings)),
        ];
    }

    /** @return array<int, string> */
    private function jarekEbayCsvWarnings(JarekGearbox $gearbox, int $skuCount): array
    {
        $sku = 'JAREK-'.($gearbox->allegro_offer_id ?: $gearbox->id);
        $warnings = [];
        $normalizationWarnings = [];
        $title = $this->normalizeString($gearbox->title, 'title', $normalizationWarnings);
        $currency = $this->normalizeString($gearbox->currency ?: 'PLN', 'currency', $normalizationWarnings);

        if (blank($title)) $warnings[] = 'missing_title';
        if (mb_strlen($title) > 80) $warnings[] = 'title_too_long';
        if (! is_numeric($gearbox->price) || (float) $gearbox->price <= 0) $warnings[] = 'missing_price';
        if (! is_numeric($gearbox->quantity) || (int) $gearbox->quantity < 1) $warnings[] = 'missing_quantity';
        if ($gearbox->csvImageUrls() === []) $warnings[] = 'missing_local_images';
        if (blank($this->detectJarekPartNumber((object) $gearbox->getAttributes(), $sku))) $warnings[] = 'missing_part_number';
        if (! $this->jarekEbayCategoryMapping($gearbox)) {
            $warnings[] = 'missing_ebay_category';
            $warnings[] = 'missing_ebay_category_mapping';
        }
        if ($skuCount > 1) $warnings[] = 'duplicate_sku';
        if (! in_array(strtoupper($currency), ['PLN','EUR','USD','GBP'], true)) $warnings[] = 'invalid_currency';
        $csvWarnings = $this->jarekEbayCsvRow($gearbox)['normalization_warnings'] ?? [];
        if (collect($csvWarnings)->contains(fn (string $warning): bool => str_ends_with($warning, '_normalized'))) $warnings[] = 'csv_field_normalized';
        if (collect($csvWarnings)->contains(fn (string $warning): bool => str_ends_with($warning, '_omitted'))) $warnings[] = 'csv_field_omitted';
        if (in_array('description_image_blocks_removed', $csvWarnings, true)) $warnings[] = 'description_image_blocks_removed';
        return array_values(array_unique($warnings));
    }

    /** @return array<int, string> */
    private function jarekEbayCsvBlockers(array $warnings): array
    {
        $nonBlocking = ['missing_part_number', 'csv_field_normalized', 'csv_field_omitted', 'description_image_blocks_removed'];
        return array_values(array_diff($warnings, $nonBlocking));
    }

    /** @return array<string, mixed>|null */
    private function jarekEbayCategoryMapping(JarekGearbox $gearbox): ?array
    {
        if (! Schema::hasTable('marketplace_category_mappings') || blank($gearbox->category_id)) return null;

        $allegro = MarketplaceCategoryMapping::query()
            ->whereIn('channel', ['allegro_main', 'allegro'])
            ->where('external_category_id', (string) $gearbox->category_id)
            ->where('is_blocked', false)
            ->orderByRaw("case when channel = 'allegro_main' then 0 else 1 end")
            ->first();

        if (! $allegro || blank($allegro->local_category_id)) return null;

        $ebay = MarketplaceCategoryMapping::query()
            ->where('local_category_id', $allegro->local_category_id)
            ->whereIn('channel', ['ebay_de', 'ebay_fr', 'ebay'])
            ->where('is_blocked', false)
            ->whereNotNull('external_category_id')
            ->orderByRaw("case when channel = 'ebay_de' then 0 when channel = 'ebay' then 1 else 2 end")
            ->first();

        if (! $ebay || blank($ebay->external_category_id)) return null;

        return [
            'source' => 'marketplace_category_mappings',
            'source_allegro_mapping_id' => $allegro->id,
            'source_allegro_channel' => $allegro->channel,
            'local_category_id' => $allegro->local_category_id,
            'ebay_mapping_id' => $ebay->id,
            'ebay_channel' => $ebay->channel,
            'ebay_category_id' => (string) $ebay->external_category_id,
            'ebay_category_name' => $ebay->external_category_name,
            'ebay_category_path' => $ebay->external_category_path,
            'shipping_group' => $ebay->shipping_group,
            'fulfillment_policy_id' => $ebay->fulfillment_policy_id,
        ];
    }

    /** @return array<string, mixed> */
    private function jarekCategoryDiagnostics(JarekGearbox $gearbox): array
    {
        $mapping = $this->jarekEbayCategoryMapping($gearbox);
        return [
            'source_allegro_category_id' => $gearbox->category_id,
            'source_allegro_category_name' => $gearbox->category_name,
            'source_allegro_category_path' => $this->categoryPathString($gearbox->category_path),
            'ebay_category_id' => $mapping['ebay_category_id'] ?? null,
            'mapping_source' => $mapping['source'] ?? null,
            'mapping' => $mapping,
            'reason' => $mapping ? null : 'missing_ebay_category_mapping',
            'message' => $mapping ? 'Mapped from Allegro category via marketplace_category_mappings.' : 'No Allegro -> local category -> eBay category mapping found in marketplace_category_mappings.',
        ];
    }

    /** @return array<string, mixed> */
    private function jarekImageDiagnostics(JarekGearbox $gearbox, bool $includeStorage = true): array
    {
        $all = $this->rawJarekImageUrlCandidates($gearbox);
        $hosts = array_map(fn (string $url): ?string => parse_url($url, PHP_URL_HOST), $all);
        return [
            'source_fields' => $this->jarekPotentialImageColumns(),
            'local_storage_diagnostics' => $includeStorage ? $this->localJarekImageStorageDiagnostics($gearbox) : ['status' => 'local_image_storage_lookup_skipped_too_expensive', 'reason' => 'Preview storage diagnostics are limited to sample records only.', 'recursive_storage_scan' => false],
            'urls_before_filtering_count' => count($all),
            'localized_images_count' => count($gearbox->localizedImageUrls()),
            'localized_images_source' => JarekGearbox::LOCALIZED_IMAGES_SOURCE,
            'display_images_source' => $gearbox->localizedImageUrls() !== [] ? 'localized' : 'allegro_fallback',
            'csv_images_source' => $gearbox->csvImageUrls() !== [] ? 'localized' : 'missing_local_images',
            'urls_after_our_host_filtering_count' => count($gearbox->csvImageUrls()),
            'allowed_hosts' => $this->localImageHosts(),
            'rejected_sample_hosts' => array_values(array_unique(array_filter($hosts, fn ($host): bool => ! is_string($host) || ! in_array(mb_strtolower($host), $this->localImageHosts(), true)))) ,
            'full_url_count' => collect($all)->filter(fn (string $url): bool => (bool) parse_url($url, PHP_URL_SCHEME))->count(),
            'relative_url_count' => collect($all)->filter(fn (string $url): bool => ! parse_url($url, PHP_URL_SCHEME))->count(),
            'host_counts' => array_count_values(array_map(fn ($host): string => is_string($host) && $host !== '' ? mb_strtolower($host) : '(relative-or-invalid)', $hosts)),
        ];
    }

    private function categoryPathString(mixed $path): ?string
    {
        $warnings = [];
        $normalized = $this->normalizeCategoryPath($path, $warnings);
        return $normalized !== '' ? $normalized : null;
    }

    /** @return array<string, mixed> */
    private function jarekPreviewDiagnostics(JarekGearbox $gearbox, bool $includeStorage): array
    {
        return ['category' => $this->jarekCategoryDiagnostics($gearbox), 'images' => $this->jarekImageDiagnostics($gearbox, $includeStorage)];
    }

    /** @return array<int, string> */
    private function localJarekImageUrls(JarekGearbox $gearbox): array
    {
        return $gearbox->localizedImageUrls();
    }

    /** @return array<string, mixed> */
    private function buildJarekImageLocalization(int $limit, bool $apply): array
    {
        $limit = max(1, min($apply ? 10 : 100, $limit));
        $base = [
            'ok' => Schema::hasTable('jarek_gearboxes'),
            'dry_run' => ! $apply,
            'applied' => $apply,
            'marketplace_write' => false,
            'parts_changed' => false,
            'source_table' => 'jarek_gearboxes',
            'target_storage_disk' => 'public',
            'target_storage_directory' => 'jarek-gearboxes',
            'limit' => $limit,
            'records_scanned' => 0,
            'records_with_only_allegro_image_urls' => 0,
            'images_to_download_or_copy' => 0,
            'images_downloaded' => 0,
            'images_already_existing' => 0,
            'records_with_local_images_before' => 0,
            'records_with_local_images_after_expected' => 0,
            'records_with_local_images_after_apply' => 0,
            'samples' => [],
            'safety' => ['no_parts_write' => true, 'no_marketplace_write' => true, 'no_ebay_api_write' => true, 'no_ovoko_write' => true, 'no_allegro_write' => true],
        ];

        if (! Schema::hasTable('jarek_gearboxes')) return $base;

        foreach (JarekGearbox::query()->orderBy('id')->limit($limit)->get() as $gearbox) {
            $base['records_scanned']++;
            $localBefore = $gearbox->localizedImageUrls();
            if ($localBefore !== []) $base['records_with_local_images_before']++;
            $sourceUrls = array_values(array_filter($this->rawJarekImageUrlCandidates($gearbox), fn (string $url): bool => $this->isAllowedJarekSourceImageUrl($url)));
            if ($sourceUrls !== [] && $localBefore === []) $base['records_with_only_allegro_image_urls']++;

            $images = [];
            foreach ($sourceUrls as $index => $url) {
                $target = $this->jarekLocalizedImageTarget($gearbox, $url, $index);
                $exists = Storage::disk('public')->exists($target['relative_path']);
                $base['images_to_download_or_copy']++;
                if ($exists) $base['images_already_existing']++;

                $downloaded = false;
                $error = null;
                if ($apply && ! $exists) {
                    try {
                        $response = Http::timeout(20)->retry(1, 500)->get($url);
                        if ($response->successful() && str_starts_with((string) $response->header('Content-Type'), 'image/')) {
                            Storage::disk('public')->put($target['relative_path'], $response->body());
                            $downloaded = true;
                            $base['images_downloaded']++;
                            $exists = true;
                        } else {
                            $error = 'download_failed_or_not_image';
                        }
                    } catch (Throwable $e) {
                        $error = $e->getMessage();
                    }
                }

                $images[] = $target + ['source_url' => $url, 'file_exists' => $exists, 'downloaded' => $downloaded, 'error' => $error];
            }

            if ($sourceUrls !== [] || $localBefore !== []) $base['records_with_local_images_after_expected']++;
            if ($apply && $gearbox->fresh()->localizedImageUrls() !== []) $base['records_with_local_images_after_apply']++;

            $base['samples'][] = [
                'source_jarek_gearbox_id' => $gearbox->id,
                'allegro_offer_id' => $gearbox->allegro_offer_id,
                'sku' => 'JAREK-'.($gearbox->allegro_offer_id ?: $gearbox->id),
                'local_images_before_count' => count($localBefore),
                'localized_images_count' => count($gearbox->localizedImageUrls()),
                'localized_images_source' => JarekGearbox::LOCALIZED_IMAGES_SOURCE,
                'source_allegro_image_urls_count' => count($sourceUrls),
                'target_images' => $images,
            ];
        }

        return $base;
    }

    /** @return array<string, string> */
    private function jarekLocalizedImageTarget(JarekGearbox $gearbox, string $sourceUrl, int $index): array
    {
        $offerId = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($gearbox->allegro_offer_id ?: $gearbox->id));
        $path = parse_url($sourceUrl, PHP_URL_PATH);
        $extension = is_string($path) ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) $extension = 'jpg';
        $relative = 'jarek-gearboxes/'.$offerId.'/'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'.'.$extension;

        return [
            'relative_path' => $relative,
            'storage_path' => storage_path('app/public/'.$relative),
            'public_url' => $this->publicStorageUrl($relative),
            'www_public_url' => 'https://www.gpswiss.pl/storage/'.$relative,
        ];
    }

    private function isAllowedJarekSourceImageUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && mb_strtolower($host) === 'a.allegroimg.com';
    }


    /** @return array<int, string> */
    private function jarekPotentialImageColumns(): array
    {
        if (! Schema::hasTable('jarek_gearboxes')) return ['main_image_url', 'images'];

        return array_values(array_unique(array_filter(
            Schema::getColumnListing('jarek_gearboxes'),
            fn (string $column): bool => (bool) preg_match('/(image|images|photo|photos|media|path|url)/i', $column)
        )));
    }

    /** @return array<string, mixed> */
    private function localJarekImageStorageDiagnostics(JarekGearbox $gearbox): array
    {
        $partImages = $this->jarekPartImageCandidates($gearbox);
        $storageCandidates = $this->jarekStoragePathCandidates($gearbox);
        $candidates = array_values(array_merge($partImages, $storageCandidates));

        return [
            'status' => $gearbox->localizedImageUrls() === [] ? 'local_images_not_found' : 'local_images_found',
            'localized_images_count' => count($gearbox->localizedImageUrls()),
            'localized_images_source' => JarekGearbox::LOCALIZED_IMAGES_SOURCE,
            'csv_images_source' => $gearbox->csvImageUrls() !== [] ? 'localized' : 'missing_local_images',
            'display_images_source' => $gearbox->localizedImageUrls() !== [] ? 'localized' : 'allegro_fallback',
            'checked_tables' => ['part_images'],
            'checked_storage_roots' => $this->jarekStorageRoots(),
            'recursive_storage_scan' => false,
            'max_candidate_paths_per_record' => self::JAREK_PREVIEW_MAX_STORAGE_CANDIDATE_PATHS_PER_RECORD,
            'max_file_exists_checks_per_request' => self::JAREK_PREVIEW_MAX_FILE_EXISTS_CHECKS,
            'identifiers' => [
                'jarek_gearbox_id' => $gearbox->id,
                'allegro_offer_id' => $gearbox->allegro_offer_id,
                'sku' => 'JAREK-'.($gearbox->allegro_offer_id ?: $gearbox->id),
            ],
            'candidate_images_count' => count($candidates),
            'candidate_images' => array_slice($candidates, 0, 10),
            'allegro_only_source_fields' => [
                'main_image_url' => $gearbox->main_image_url,
                'images' => $gearbox->images,
            ],
            'can_build_public_url_from_our_host' => collect($candidates)->contains(fn (array $candidate): bool => is_string($candidate['public_url'] ?? null) && $this->isLocalServerImageUrl($candidate['public_url'])),
            'no_download_or_copy_performed' => true,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function jarekPartImageCandidates(JarekGearbox $gearbox): array
    {
        if (! Schema::hasTable('part_images')) return [];

        $offerId = (string) $gearbox->allegro_offer_id;
        $rows = PartImage::query()
            ->where(function ($query) use ($gearbox, $offerId): void {
                $query->where('source_system', 'jarek')
                    ->where(function ($query) use ($gearbox, $offerId): void {
                        if ($offerId !== '') $query->orWhere('external_id', 'like', $offerId.':%')->orWhere('external_id', $offerId);
                        $query->orWhere('legacy_payload->jarek_gearbox_id', $gearbox->id)
                            ->orWhere('legacy_payload->source_jarek_gearbox_id', $gearbox->id);
                    });
            })
            ->orderBy('sort_order')
            ->limit(25)
            ->get();

        return $rows->map(fn (PartImage $image): array => $this->partImageCandidatePayload($image))->all();
    }

    /** @return array<string, mixed> */
    private function partImageCandidatePayload(PartImage $image): array
    {
        $url = $this->normalizeOurServerImageUrl((string) $image->listingUrl()) ?: $this->normalizeOurServerImageUrl((string) $image->publicUrl());
        return [
            'source' => 'part_images',
            'part_image_id' => $image->id,
            'part_id' => $image->part_id,
            'path' => $image->path,
            'source_system' => $image->source_system,
            'external_id' => $image->external_id,
            'public_url' => $url,
            'file_exists' => $this->publicStorageRelativePathExists($image->path),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function jarekStoragePathCandidates(JarekGearbox $gearbox): array
    {
        $relativePaths = $this->deterministicJarekImageRelativePathCandidates($gearbox);
        $matches = [];

        foreach (array_slice($relativePaths, 0, self::JAREK_PREVIEW_MAX_STORAGE_CANDIDATE_PATHS_PER_RECORD) as $relative) {
            if (! $this->publicStorageRelativePathExists($relative)) {
                continue;
            }

            $matches[] = [
                'source' => 'deterministic_storage_lookup',
                'relative_path' => $relative,
                'public_url' => $this->publicStorageUrl($relative),
                'file_exists' => true,
            ];
        }

        return $matches;
    }

    /** @return array<int, string> */
    private function deterministicJarekImageRelativePathCandidates(JarekGearbox $gearbox): array
    {
        $ids = array_values(array_unique(array_filter([
            (string) $gearbox->id,
            (string) $gearbox->allegro_offer_id,
            'JAREK-'.($gearbox->allegro_offer_id ?: $gearbox->id),
        ])));
        $directories = ['jarek-gearboxes', 'parts/photos/imported', 'parts/photos/jarek'];
        $extensions = ['jpg', 'jpeg', 'png', 'webp'];
        $suffixes = array_merge(['', '-1', '_1', '/1', '/main'], array_map(fn (int $i): string => '/'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 20)));
        $paths = [];

        foreach ($directories as $directory) {
            foreach ($ids as $id) {
                foreach ($suffixes as $suffix) {
                    foreach ($extensions as $extension) {
                        $paths[] = $directory.'/'.$id.$suffix.'.'.$extension;
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /** @return array<int, string> */
    private function jarekStorageRoots(): array
    {
        return array_values(array_unique([
            storage_path('app/public/parts/photos/imported'),
            storage_path('app/public/parts/photos/jarek'),
            storage_path('app/public/jarek-gearboxes'),
            public_path('storage/parts/photos/imported'),
            public_path('storage/parts/photos/jarek'),
            public_path('storage/jarek-gearboxes'),
            dirname(base_path()).'/public_html/storage/parts/photos/imported',
            dirname(base_path()).'/public_html/storage/parts/photos/jarek',
            dirname(base_path()).'/public_html/storage/jarek-gearboxes',
        ]));
    }

    private function publicStorageRelativePathExists(?string $path): bool
    {
        $relative = $this->publicDiskRelativePath($path);
        if ($relative === null) return false;
        if ($this->jarekImageFileExistsChecks >= self::JAREK_PREVIEW_MAX_FILE_EXISTS_CHECKS) {
            $this->jarekImageFileExistsBudgetExceeded = true;
            return false;
        }

        foreach ([
            fn (): bool => Storage::disk('public')->exists($relative),
            fn (): bool => is_file(public_path('storage/'.$relative)),
            fn (): bool => is_file(dirname(base_path()).'/public_html/storage/'.$relative),
        ] as $exists) {
            if ($this->jarekImageFileExistsChecks >= self::JAREK_PREVIEW_MAX_FILE_EXISTS_CHECKS) {
                $this->jarekImageFileExistsBudgetExceeded = true;
                return false;
            }
            $this->jarekImageFileExistsChecks++;
            if ($exists()) return true;
        }

        return false;
    }

    private function publicDiskRelativePath(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') return null;
        $path = trim($path);
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $urlPath = parse_url($path, PHP_URL_PATH);
            if (! is_string($urlPath) || ! str_starts_with($urlPath, '/storage/')) return null;
            $path = substr($urlPath, 9);
        }

        $path = ltrim($path, '/');
        if (str_starts_with($path, 'storage/')) $path = substr($path, 8);
        return $path !== '' ? $path : null;
    }

    private function resetJarekImageDiagnosticsBudget(): void
    {
        $this->jarekImageFileExistsChecks = 0;
        $this->jarekImageFileExistsBudgetExceeded = false;
    }

    private function relativePublicStoragePath(string $absolutePath): ?string
    {
        $absolutePath = str_replace('\\', '/', $absolutePath);
        $marker = '/storage/';
        $pos = strpos($absolutePath, $marker);
        return $pos === false ? null : substr($absolutePath, $pos + strlen($marker));
    }

    private function publicStorageUrl(string $relativePath): string
    {
        return rtrim((string) config('app.url', 'https://gpswiss.pl'), '/').'/storage/'.ltrim($relativePath, '/');
    }

    /** @return array<int, array<string, mixed>> */
    private function missingJarekEbayCategoryMappingSummary(): array
    {
        if (! Schema::hasTable('jarek_gearboxes')) return [];

        return JarekGearbox::query()->get()
            ->filter(fn (JarekGearbox $gearbox): bool => $this->jarekEbayCategoryMapping($gearbox) === null)
            ->groupBy(fn (JarekGearbox $gearbox): string => (string) ($gearbox->category_id ?: ''))
            ->map(fn ($rows, string $categoryId): array => [
                'source_allegro_category_id' => $categoryId ?: null,
                'category_name' => $rows->first()->category_name,
                'category_path' => $this->categoryPathString($rows->first()->category_path),
                'count' => $rows->count(),
                'existing_allegro_mappings' => $this->existingJarekAllegroMappings($categoryId),
                'candidate_local_ebay_mappings' => $this->candidateLocalEbayMappings((string) $rows->first()->category_name),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function existingJarekAllegroMappings(string $categoryId): array
    {
        if ($categoryId === '' || ! Schema::hasTable('marketplace_category_mappings')) return [];

        return MarketplaceCategoryMapping::query()
            ->whereIn('channel', ['allegro_main', 'allegro'])
            ->where('external_category_id', $categoryId)
            ->limit(10)
            ->get()
            ->map(fn (MarketplaceCategoryMapping $mapping): array => [
                'mapping_id' => $mapping->id,
                'channel' => $mapping->channel,
                'local_category_id' => $mapping->local_category_id,
                'external_category_id' => $mapping->external_category_id,
                'external_category_name' => $mapping->external_category_name,
                'is_blocked' => (bool) $mapping->is_blocked,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function candidateLocalEbayMappings(string $categoryName): array
    {
        if ($categoryName === '' || ! Schema::hasTable('part_categories') || ! Schema::hasTable('marketplace_category_mappings')) return [];

        $query = PartCategory::query()->where('name', 'like', '%'.$categoryName.'%');
        if (Schema::hasColumn('part_categories', 'category_path')) {
            $query->orWhere('category_path', 'like', '%'.$categoryName.'%');
        }

        return $query
            ->limit(10)
            ->get()
            ->map(function (PartCategory $category): array {
                $ebay = MarketplaceCategoryMapping::query()
                    ->where('local_category_id', $category->id)
                    ->whereIn('channel', ['ebay_de', 'ebay_fr', 'ebay'])
                    ->where('is_blocked', false)
                    ->whereNotNull('external_category_id')
                    ->get();

                return [
                    'local_category_id' => $category->id,
                    'local_category_name' => $category->name,
                    'local_category_path' => $category->category_path ?? null,
                    'ebay_mappings' => $ebay->map(fn (MarketplaceCategoryMapping $mapping): array => [
                        'mapping_id' => $mapping->id,
                        'channel' => $mapping->channel,
                        'external_category_id' => $mapping->external_category_id,
                        'external_category_name' => $mapping->external_category_name,
                        'external_category_path' => $mapping->external_category_path,
                    ])->all(),
                ];
            })
            ->filter(fn (array $candidate): bool => $candidate['ebay_mappings'] !== [])
            ->values()
            ->all();
    }

    private function normalizeOurServerImageUrl(string $url): ?string
    {
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = rtrim((string) config('app.url', 'https://gpswiss.pl'), '/').$url;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false && $this->isLocalServerImageUrl($url) ? $url : null;
    }

    private function isLocalServerImageUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && in_array(mb_strtolower($host), $this->localImageHosts(), true) && ! str_contains(mb_strtolower($host), 'allegro');
    }

    /** @return array<int, string> */
    private function localImageHosts(): array
    {
        $hosts = ['gpswiss.pl', 'www.gpswiss.pl', 'gpsystem.thecamels.pl'];
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') $hosts[] = mb_strtolower($appHost);
        return array_values(array_unique($hosts));
    }

    private function csvRowContainsAllegroImageUrl(array $row): bool
    {
        return collect($row)->contains(fn (mixed $value): bool => is_string($value) && str_contains($value, 'a.allegroimg.com'));
    }

    private function csvRowImagesAreLocal(array $row): bool
    {
        $urls = array_filter(array_merge([(string) ($row['Main image URL'] ?? '')], explode('|', (string) ($row['Additional image URLs'] ?? ''))));
        return $urls !== [] && collect($urls)->every(fn (string $url): bool => $this->isLocalServerImageUrl($url));
    }

    private function csvString(array $rows): string
    {
        $columns = ['SKU','Title','Description','Price','Currency','Quantity','Condition','Manufacturer Part Number','Brand','Allegro category ID','Allegro category name','Allegro category path','Suggested eBay category','Main image URL','Additional image URLs','Source JarekGearbox ID','Allegro offer ID','Original Allegro URL'];
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $columns);
        foreach ($rows as $row) {
            $warnings = [];
            fputcsv($handle, array_map(fn (string $column): string => $this->normalizeString($row[$column] ?? '', $column, $warnings), $columns));
        }
        rewind($handle);
        return stream_get_contents($handle) ?: '';
    }

    private function smallCsvLimit(Request $request): int
    {
        return max(1, min(25, (int) $request->query('limit', 10)));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPartsImportDryRun(int $limit, int $offset, ?int $jarekGearboxId = null): array
    {
        $requiredTables = ['jarek_gearboxes', 'parts'];
        $missingTables = array_values(array_filter($requiredTables, fn (string $table): bool => ! Schema::hasTable($table)));
        $partsColumns = Schema::hasTable('parts') ? Schema::getColumnListing('parts') : [];
        $jarekColumns = Schema::hasTable('jarek_gearboxes') ? Schema::getColumnListing('jarek_gearboxes') : [];
        $requiredPartColumns = ['sku', 'name', 'price', 'currency', 'quantity', 'status', 'needs_listing'];
        $missingPartColumns = array_values(array_filter($requiredPartColumns, fn (string $column): bool => ! in_array($column, $partsColumns, true)));

        $base = [
            'ok' => $missingTables === [],
            'dry_run' => true,
            'local_write' => false,
            'marketplace_write' => false,
            'ovoko_write' => false,
            'allegro_write' => false,
            'ebay_write' => false,
            'parts_changed' => false,
            'recommended_identifier_field' => 'sku',
            'recommended_identifier_format' => 'JAREK-{allegro_offer_id}',
            'recommended_identifier_reason' => 'parts.id is an autoincrement integer; parts.sku is nullable but unique and is already searchable in admin. parts.external_id can store the source key as an additional guard.',
            'target_parts_state' => ['status' => 'draft', 'needs_listing' => true, 'is_visible_storefront' => false, 'source_system' => 'jarek'],
            'safety' => ['no_ovoko_write', 'no_allegro_main_write', 'no_ebay_live_write', 'no_publish_relist_end_update'],
            'missing_tables' => $missingTables,
            'parts_columns' => $partsColumns,
            'jarek_gearboxes_columns' => $jarekColumns,
            'missing_required_part_columns' => $missingPartColumns,
            'total' => 0,
            'eligible_to_create' => 0,
            'potential_duplicates' => 0,
            'blocked_by_reason' => array_fill_keys(['missing_title', 'missing_price', 'missing_images', 'missing_quantity', 'duplicate_sku', 'invalid_status', 'missing_required_part_field'], 0),
            'sample_to_create' => [],
            'sample_blocked' => [],
        ];

        if ($missingTables !== []) {
            $base['blocked_by_reason']['missing_required_part_field'] = 1;
            return $base;
        }

        $query = DB::table('jarek_gearboxes')->orderBy('id');
        if ($jarekGearboxId !== null) {
            $query->where('id', $jarekGearboxId);
        }

        $base['total'] = (clone $query)->count();
        $rows = $query->get();
        $allowedStatuses = ['ACTIVE', 'ENDED', 'INACTIVE', 'ACTIVATING'];

        foreach ($rows as $row) {
            $sku = filled($row->allegro_offer_id ?? null) ? 'JAREK-'.$row->allegro_offer_id : null;
            $reasons = [];
            if (! filled($row->title ?? null)) $reasons[] = 'missing_title';
            if (! is_numeric($row->price ?? null) || (float) $row->price <= 0) $reasons[] = 'missing_price';
            if (! $this->jarekRowHasImages($row)) $reasons[] = 'missing_images';
            if (! is_numeric($row->quantity ?? null) || (int) $row->quantity < 1) $reasons[] = 'missing_quantity';
            if (! filled($row->allegro_status ?? null) || ! in_array(strtoupper((string) $row->allegro_status), $allowedStatuses, true)) $reasons[] = 'invalid_status';
            if ($missingPartColumns !== []) $reasons[] = 'missing_required_part_field';
            if ($sku === null || $this->partDuplicateExists($sku, (string) ($row->allegro_offer_id ?? ''))) $reasons[] = 'duplicate_sku';

            $item = [
                'jarek_gearbox_id' => $row->id,
                'allegro_offer_id' => $row->allegro_offer_id,
                'sku' => $sku,
                'title' => $row->title,
                'price' => $row->price,
                'currency' => $row->currency,
                'quantity' => $row->quantity,
                'allegro_status' => $row->allegro_status,
                'category_id' => $row->category_id,
                'category_name' => $row->category_name,
                'diagnostics' => $this->jarekPartsMappingDiagnostics($row, $sku),
            ];

            if ($reasons === []) {
                $base['eligible_to_create']++;
                if (count($base['sample_to_create']) < $limit && $base['eligible_to_create'] > $offset) $base['sample_to_create'][] = $item;
            } else {
                $uniqueReasons = array_values(array_unique($reasons));
                if (in_array('duplicate_sku', $uniqueReasons, true)) $base['potential_duplicates']++;
                foreach ($uniqueReasons as $reason) $base['blocked_by_reason'][$reason]++;
                if (count($base['sample_blocked']) < 20) $base['sample_blocked'][] = $item + ['reasons' => $uniqueReasons];
            }
        }

        return $base;
    }


    /**
     * @return array<string, mixed>
     */
    private function applyPartsImport(int $limit, int $offset, ?int $jarekGearboxId = null, bool $updateExisting = false): array
    {
        $created = [];
        $skipped = [];
        $duplicates = [];
        $seenEligible = 0;

        $query = DB::table('jarek_gearboxes')->orderBy('id');
        if ($jarekGearboxId !== null) {
            $query->where('id', $jarekGearboxId);
        }

        $rows = $query->get();

        foreach ($rows as $row) {
            $sku = filled($row->allegro_offer_id ?? null) ? 'JAREK-'.$row->allegro_offer_id : null;
            $offerId = (string) ($row->allegro_offer_id ?? '');

            if ($sku === null || $offerId === '') {
                $skipped[] = ['source_jarek_gearbox_id' => $row->id, 'reason' => 'missing_allegro_offer_id'];
                continue;
            }

            $existingPart = $this->findExistingJarekPart($sku, $offerId);
            if ($existingPart && ! $updateExisting) {
                $duplicates[] = ['source_jarek_gearbox_id' => $row->id, 'sku' => $sku, 'external_id' => $offerId, 'part_id' => $existingPart->id];
                continue;
            }

            $seenEligible++;
            if ($seenEligible <= $offset) {
                $skipped[] = ['source_jarek_gearbox_id' => $row->id, 'sku' => $sku, 'reason' => 'offset'];
                continue;
            }

            if (count($created) >= $limit) {
                break;
            }

            $created[] = DB::transaction(function () use ($row, $sku, $offerId, $existingPart): array {
                $category = $this->safeCategoryMatch($row);
                $partData = array_filter([
                    'source_system' => 'jarek',
                    'external_id' => $offerId,
                    'sku' => $sku,
                    'name' => (string) $row->title,
                    'part_number' => $this->detectJarekPartNumber($row, $sku),
                    'description' => $row->description ?: $row->plain_description,
                    'short_description' => $row->plain_description,
                    'price' => $row->price,
                    'currency' => $row->currency ?: 'PLN',
                    'quantity' => (int) $row->quantity,
                    'status' => 'draft',
                    'needs_listing' => true,
                    'is_visible_storefront' => false,
                    'category_id' => $category?->id,
                    'suggested_category_id' => $category?->id,
                    'category_confidence' => $category ? 0.80 : null,
                    'category_suggestion_reason' => $this->categorySuggestionReason($row, $category),
                    'category_needs_review' => $category === null,
                    'internal_note' => 'Rekord pochodzi ze Skrzyń Jarka. Pierwotny status Allegro Jarka: '.($row->allegro_status ?: 'brak').'. Źródłowy jarek_gearboxes.id: '.$row->id.'.',
                    'legacy_url' => $row->allegro_offer_url,
                    'legacy_payload' => $this->jarekLegacyPayload($row),
                ], fn ($value): bool => $value !== null);

                if ($existingPart) {
                    unset($partData['status'], $partData['is_visible_storefront']);
                    $existingPart->fill($partData)->save();
                    $part = $existingPart->refresh();
                    $action = 'updated';
                } else {
                    $part = Part::query()->create($partData);
                    $action = 'created';
                }

                foreach ($this->jarekImageUrls($row) as $index => $url) {
                    PartImage::query()->updateOrCreate([
                        'part_id' => $part->id,
                        'source_system' => 'jarek',
                        'external_id' => $offerId.':'.$index,
                    ], [
                        'path' => $url,
                        'alt_text' => (string) $row->title,
                        'sort_order' => $index,
                        'is_primary' => $index === 0,
                        'legacy_payload' => ['source' => 'jarek_gearboxes', 'jarek_gearbox_id' => $row->id, 'marketplace_write' => false],
                    ]);
                }

                PartImage::query()->where('part_id', $part->id)->where('source_system', 'jarek')->where('external_id', $offerId.':0')->update(['is_primary' => true, 'sort_order' => 0]);

                return ['action' => $action, 'part_id' => $part->id, 'sku' => $part->sku, 'part_number' => $part->part_number, 'source_jarek_gearbox_id' => $row->id, 'images_count' => count($this->jarekImageUrls($row)), 'category_id' => $part->category_id, 'suggested_category_id' => $part->suggested_category_id, 'category_needs_review' => $part->category_needs_review];
            });
        }

        return [
            'ok' => true,
            'changed_count' => count($created),
            'created_count' => count(array_filter($created, fn (array $row): bool => ($row['action'] ?? null) === 'created')),
            'updated_count' => count(array_filter($created, fn (array $row): bool => ($row['action'] ?? null) === 'updated')),
            'skipped_count' => count($skipped),
            'duplicate_count' => count($duplicates),
            'changed' => $created,
            'created' => array_values(array_filter($created, fn (array $row): bool => ($row['action'] ?? null) === 'created')),
            'duplicates' => $duplicates,
            'skipped' => $skipped,
            'marketplace_write' => false,
        ];
    }

    private function safeCategoryMatch(object $row): ?PartCategory
    {
        if (! Schema::hasTable('part_categories')) return null;
        $name = trim((string) ($row->category_name ?? ''));
        if ($name === '') return null;

        $matches = PartCategory::query()->where('name', $name)->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @return array<int, string> */
    private function jarekImageUrls(object $row): array
    {
        $warnings = [];
        return $this->normalizeUrlList([$row->main_image_url ?? null, ...$this->decodedJsonArray($row->images ?? null)], 'images', $warnings);
    }

    /** @return array<int, string> */
    private function rawJarekImageUrlCandidates(JarekGearbox $gearbox): array
    {
        $values = [$gearbox->main_image_url, ...$this->decodedJsonArray($gearbox->images)];
        $urls = [];
        foreach ($values as $item) {
            $item = $this->decodedJsonValue($item);
            if (is_object($item)) $item = (array) $item;
            if (is_array($item)) {
                $candidate = $item['url'] ?? $item['src'] ?? $item['href'] ?? data_get($item, 'image.url') ?? null;
                if (filled($candidate) && is_scalar($candidate)) $urls[] = trim((string) $candidate);
                continue;
            }
            if (filled($item) && is_scalar($item)) $urls[] = trim((string) $item);
        }
        return array_values(array_unique(array_filter($urls)));
    }

    /** @param array<int, string> $warnings */
    private function normalizeJarekDescription(mixed $value, array &$warnings): string
    {
        $description = $this->normalizeString($value, 'description', $warnings);
        if ($description === '') return '';

        $cleaned = $description;
        $cleaned = preg_replace('/(?:^|[\s|]+)IMAGE\s*\|\s*https?:\/\/[^\s|]+(?:\s*\|\s*)?/iu', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\bTEXT\s*\|\s*/iu', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/https?:\/\/a\.allegroimg\.com\/[^\s<|"\']+/iu', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/https?:\/\/[^\s<|"\']+\/(?:storage\/)?[^\s<|"\']+\.(?:jpe?g|png|webp|gif)(?:\?[^\s<|"\']*)?/iu', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s*\|\s*(?=<)/u', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s{2,}/u', ' ', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned, " \t\n\r\0\x0B|");

        if ($cleaned !== $description) $warnings[] = 'description_image_blocks_removed';

        return $cleaned;
    }

    /** @param array<int, string> $warnings */
    private function normalizeString(mixed $value, string $field, array &$warnings): string
    {
        $value = $this->decodedJsonValue($value);

        if ($value === null || $value === '') {
            return '';
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return trim((string) $value);
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            $flattened = $this->flattenTextValues($value);
            if ($flattened !== []) {
                $warnings[] = $field.'_normalized';
                return implode(' | ', $flattened);
            }

            $warnings[] = $field.'_omitted';
            return '';
        }

        $warnings[] = $field.'_omitted';
        return '';
    }

    /** @param array<int, string> $warnings */
    private function normalizeCategoryPath(mixed $value, array &$warnings): string
    {
        $decoded = $this->decodedJsonValue($value);
        if (! is_array($decoded) && ! is_object($decoded)) {
            return $this->normalizeString($decoded, 'category_path', $warnings);
        }

        $items = is_object($decoded) ? (array) $decoded : $decoded;
        $names = [];
        foreach ($items as $item) {
            if (is_object($item)) {
                $item = (array) $item;
            }

            if (is_array($item)) {
                $name = $item['name'] ?? $item['label'] ?? $item['title'] ?? null;
                $normalizedName = $this->normalizeString($name, 'category_path_item', $warnings);
                if ($normalizedName !== '') {
                    $names[] = $normalizedName;
                    continue;
                }
            } else {
                $normalizedItem = $this->normalizeString($item, 'category_path_item', $warnings);
                if ($normalizedItem !== '') $names[] = $normalizedItem;
            }
        }

        $names = array_values(array_unique(array_filter($names)));
        if ($names !== []) {
            $warnings[] = 'category_path_normalized';
            return implode(' > ', $names);
        }

        return $this->normalizeString($decoded, 'category_path', $warnings);
    }

    /**
     * @param array<int, string> $warnings
     * @return array<int, string>
     */
    private function normalizeUrlList(mixed $value, string $field, array &$warnings): array
    {
        $decoded = $this->decodedJsonValue($value);
        $items = is_array($decoded) ? $decoded : [$decoded];
        $urls = [];

        foreach ($items as $item) {
            $item = $this->decodedJsonValue($item);
            if (is_object($item)) {
                $item = (array) $item;
            }

            if (is_array($item)) {
                $candidate = $item['url'] ?? $item['src'] ?? $item['href'] ?? data_get($item, 'image.url') ?? null;
                if (filled($candidate) && is_scalar($candidate)) {
                    $urls[] = trim((string) $candidate);
                } else {
                    $warnings[] = $field.'_item_omitted';
                }
                continue;
            }

            if (filled($item) && is_scalar($item)) {
                $urls[] = trim((string) $item);
            }
        }

        return array_values(array_unique(array_filter($urls, fn (string $url): bool => filter_var($url, FILTER_VALIDATE_URL) !== false)));
    }

    /** @return array<int, string> */
    private function flattenTextValues(array $value): array
    {
        $texts = [];
        foreach ($value as $item) {
            $item = $this->decodedJsonValue($item);
            if (is_object($item)) {
                $item = (array) $item;
            }

            if (is_scalar($item) || $item instanceof \Stringable) {
                if (filled($item)) $texts[] = trim((string) $item);
                continue;
            }

            if (is_array($item)) {
                foreach ($this->flattenTextValues($item) as $text) {
                    $texts[] = $text;
                }
            }
        }

        return array_values(array_unique(array_filter($texts)));
    }

    /** @return array<string, mixed> */
    private function jarekLegacyPayload(object $row): array
    {
        return [
            'source' => 'jarek_gearboxes',
            'source_note' => 'Rekord pochodzi ze Skrzyń Jarka.',
            'jarek_gearbox_id' => $row->id,
            'allegro_offer_id' => $row->allegro_offer_id,
            'allegro_status_original' => $row->allegro_status,
            'allegro_offer_url' => $row->allegro_offer_url,
            'category_id' => $row->category_id,
            'category_name' => $row->category_name,
            'category_path' => $row->category_path,
            'category_payload' => $this->decodedJsonValue($row->category_payload ?? null),
            'marketplace_write' => false,
        ];
    }


    private function suggestJarekEbayShortTitle(string $title, int $limit): string
    {
        $short = mb_substr($title, 0, $limit);
        $lastSpace = mb_strrpos($short, ' ');
        if ($lastSpace !== false && $lastSpace >= 50) {
            $short = mb_substr($short, 0, $lastSpace);
        }

        return rtrim($short, " \t\n\r\0\x0B,.;:-");
    }

    private function arrayContainsStringFragment(mixed $value, string $fragment): bool
    {
        if (is_string($value)) return str_contains($value, $fragment);
        if (! is_array($value)) return false;

        foreach ($value as $item) {
            if ($this->arrayContainsStringFragment($item, $fragment)) return true;
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function logJarekEbayDePreparePreview(string $status, string $message, array $payload, float $started): void
    {
        if (! Schema::hasTable('marketplace_sync_logs')) return;

        MarketplaceSyncLog::query()->create([
            'marketplace' => 'ebay_de',
            'action' => 'jarek_gearboxes_ebay_de_prepare_preview',
            'status' => $status,
            'message' => $message,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'external_id' => $payload['allegro_offer_id'] ?? $payload['sku'] ?? null,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }

    private function logPartsImportApply(string $status, string $message, array $payload): void
    {
        if (! Schema::hasTable('marketplace_sync_logs')) return;

        MarketplaceSyncLog::query()->create([
            'marketplace' => 'admin',
            'action' => 'jarek_gearboxes_parts_import_apply',
            'status' => $status,
            'message' => $message,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }

    private function logJarekImageLocalization(string $status, string $message, array $payload): void
    {
        if (! Schema::hasTable('marketplace_sync_logs')) return;

        MarketplaceSyncLog::query()->create([
            'marketplace' => 'admin',
            'action' => 'jarek_gearboxes_localize_images',
            'status' => $status,
            'message' => $message,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }

    private function jarekRowHasImages(object $row): bool
    {
        if (filled($row->main_image_url ?? null)) return true;
        $images = json_decode((string) ($row->images ?? ''), true);
        return is_array($images) && count(array_filter($images)) > 0;
    }

    private function partDuplicateExists(?string $sku, string $offerId): bool
    {
        if (! $sku) {
            return true;
        }

        return Part::query()
            ->where('sku', $sku)
            ->when(
                $offerId !== '' && Schema::hasColumn('parts', 'source_system') && Schema::hasColumn('parts', 'external_id'),
                fn ($query) => $query->orWhere(fn ($query) => $query->where('source_system', 'jarek')->where('external_id', $offerId)),
            )
            ->exists();
    }

    private function findExistingJarekPart(string $sku, string $offerId): ?Part
    {
        return Part::query()
            ->where('sku', $sku)
            ->when(
                $offerId !== '' && Schema::hasColumn('parts', 'source_system') && Schema::hasColumn('parts', 'external_id'),
                fn ($query) => $query->orWhere(fn ($query) => $query->where('source_system', 'jarek')->where('external_id', $offerId)),
            )
            ->first();
    }

    private function detectJarekPartNumber(object $row, string $sku): string
    {
        foreach ($this->decodedJsonArray($row->parameters ?? null) as $parameter) {
            $name = mb_strtolower((string) data_get($parameter, 'name', data_get($parameter, 'id', '')));
            if (! str_contains($name, 'numer') && ! str_contains($name, 'part') && ! str_contains($name, 'oe')) {
                continue;
            }

            $values = data_get($parameter, 'values', data_get($parameter, 'valuesIds', []));
            foreach ((array) $values as $value) {
                $valueWarnings = [];
                $detected = $this->firstPartNumberCandidate($this->normalizeString($value, 'part_number_parameter', $valueWarnings));
                if ($detected) return $detected;
            }
        }

        return $this->firstPartNumberCandidate((string) ($row->title ?? '')) ?: $sku;
    }

    private function firstPartNumberCandidate(string $text): ?string
    {
        preg_match_all('/\b(?=[A-Z0-9]*\d)(?=[A-Z0-9]*[A-Z])[A-Z0-9]{7,16}\b/u', mb_strtoupper($text), $matches);

        foreach ($matches[0] ?? [] as $candidate) {
            if (! str_starts_with($candidate, 'JAREK')) return $candidate;
        }

        return null;
    }

    private function categorySuggestionReason(object $row, ?PartCategory $category): string
    {
        $prefix = $category
            ? 'Bezpieczne dopasowanie lokalnej kategorii na podstawie kategorii Allegro/Skrzyń Jarka.'
            : 'Brak jednoznacznego lokalnego dopasowania; wymagana weryfikacja kategorii Allegro/Skrzyń Jarka.';

        return $prefix.' Allegro category_id: '.($row->category_id ?: '—')
            .'; category_name: '.($row->category_name ?: '—')
            .'; category_path: '.$this->jsonishToText($row->category_path ?? null).'.';
    }

    /** @return array<string, mixed> */
    private function jarekPartsMappingDiagnostics(object $row, ?string $sku): array
    {
        $category = $this->safeCategoryMatch($row);
        $images = $this->jarekImageUrls($row);

        return [
            'main_image_url_available' => filled($row->main_image_url ?? null),
            'detected_images_count' => count($images),
            'part_images_to_write' => array_map(fn (string $url, int $index): array => [
                'path' => $url,
                'sort_order' => $index,
                'is_primary' => $index === 0,
                'source_system' => 'jarek',
                'external_id' => ((string) ($row->allegro_offer_id ?? '')).':'.$index,
            ], $images, array_keys($images)),
            'part_number_to_set' => $sku ? $this->detectJarekPartNumber($row, $sku) : null,
            'allegro_category' => [
                'category_id' => $row->category_id ?? null,
                'category_name' => $row->category_name ?? null,
                'category_path' => $this->decodedJsonValue($row->category_path ?? null),
                'category_payload' => $this->decodedJsonValue($row->category_payload ?? null),
            ],
            'local_category_match' => $category ? ['id' => $category->id, 'name' => $category->name] : null,
            'parts_fields_to_set' => [
                'sku' => $sku,
                'source_system' => 'jarek',
                'external_id' => $row->allegro_offer_id ?? null,
                'part_number' => $sku ? $this->detectJarekPartNumber($row, $sku) : null,
                'category_id' => $category?->id,
                'suggested_category_id' => $category?->id,
                'category_needs_review' => $category === null,
                'category_suggestion_reason' => $this->categorySuggestionReason($row, $category),
                'status' => 'draft',
                'needs_listing' => true,
                'is_visible_storefront' => false,
                'legacy_payload.category_payload' => $this->decodedJsonValue($row->category_payload ?? null),
            ],
        ];
    }

    /** @return array<int, mixed> */
    private function decodedJsonArray(mixed $value): array
    {
        $decoded = $this->decodedJsonValue($value);
        return is_array($decoded) ? $decoded : [];
    }

    private function decodedJsonValue(mixed $value): mixed
    {
        if (! is_string($value)) return $value;
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function jsonishToText(mixed $value): string
    {
        $decoded = $this->decodedJsonValue($value);
        if (is_array($decoded)) return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—';
        return filled($decoded) ? (string) $decoded : '—';
    }

    private function missingExpectedColumns(): array
    {
        $table = 'jarek_gearboxes';

        if (! Schema::hasTable($table)) {
            return $this->expectedColumns();
        }

        return array_values(array_filter(
            $this->expectedColumns(),
            fn (string $column): bool => ! Schema::hasColumn($table, $column),
        ));
    }

    /**
     * @return array<int, string>
     */
    private function expectedColumns(): array
    {
        return [
            'id',
            'source_account',
            'allegro_account',
            'allegro_offer_id',
            'allegro_offer_url',
            'title',
            'description',
            'plain_description',
            'price',
            'currency',
            'quantity',
            'allegro_status',
            'main_image_url',
            'images',
            'category_id',
            'category_name',
            'category_path',
            'category_payload',
            'parameters',
            'raw_payload',
            'import_status',
            'imported_at',
            'updated_from_allegro_at',
            'ebay_status',
            'ebay_listing_id',
            'ebay_offer_id',
            'ebay_inventory_sku',
            'ebay_payload_snapshot',
            'ebay_published_at',
            'created_at',
            'updated_at',
        ];
    }

    private function migrationEntryExists(): bool
    {
        if (! Schema::hasTable('migrations')) {
            return false;
        }

        return DB::table('migrations')
            ->where('migration', '2026_07_02_100000_create_jarek_gearboxes_table')
            ->exists();
    }

    private function limit(Request $request): int
    {
        return max(1, (int) $request->query('limit', 20));
    }

    private function offset(Request $request): int
    {
        return max(0, (int) $request->query('offset', 0));
    }
}
