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
use App\Services\Marketplace\Ebay\EbayConditionMapper;
use App\Services\Marketplace\GoogleTranslateService;
use App\Services\Marketplace\NbpExchangeRateService;
use App\Services\Marketplace\Api\EbayApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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


    public function storageLinkDiagnostics(): JsonResponse
    {
        $result = $this->buildJarekStorageLinkDiagnostics(false);
        $this->logJarekStorageLink('dry_run', $result['can_safely_create_symlink'] ? 'Storage link diagnostics ready; apply is possible.' : 'Storage link diagnostics completed; apply is blocked.', $result);

        return response()->json($result);
    }

    public function storageLinkApply(Request $request): JsonResponse
    {
        if ($request->query('confirm') !== 'jarek-storage-link') {
            $result = $this->buildJarekStorageLinkDiagnostics(false);
            $result['ok'] = false;
            $result['error'] = 'Missing confirm=jarek-storage-link';
            $this->logJarekStorageLink('blocked', 'Storage link apply blocked: missing required confirm.', $result);

            return response()->json($result, 422);
        }

        $result = $this->buildJarekStorageLinkDiagnostics(true);
        $this->logJarekStorageLink($result['ok'] ? 'success' : 'blocked', $result['message'], $result);

        return response()->json($result, $result['ok'] ? 200 : 409);
    }


    public function publicRootDiagnostics(Request $request): JsonResponse
    {
        $relative = ltrim((string) $request->query('path', 'jarek-gearboxes/18727785496/01.jpg'), '/');
        $payload = $this->buildJarekPublicRootDiagnostics($relative);
        $this->logJarekStorageLink('dry_run', 'Diagnostyka public root / host mapping dla zdjęć Jarka; bez zapisu.', $payload);

        return response()->json($payload);
    }

    public function publicImagesDryRun(Request $request): JsonResponse
    {
        $payload = $this->buildJarekPublicImagesOne((string) $request->query('sku', 'JAREK-18727785496'), false);
        $this->logJarekImageLocalization(($payload['ok'] ?? false) ? 'success' : 'blocked', 'Dry-run kopiowania lokalnych zdjęć Jarka do działającego public_html storage dla jednego SKU.', $payload);

        return response()->json($payload, ($payload['ok'] ?? false) ? 200 : 422);
    }

    public function publicImagesApply(Request $request): JsonResponse
    {
        if ($request->query('confirm') !== 'jarek-public-images-one') {
            $payload = ['ok' => false, 'error' => 'Missing confirm=jarek-public-images-one', 'marketplace_write' => false, 'parts_changed' => false];
            $this->logJarekImageLocalization('blocked', 'Apply publicznych zdjęć Jarka zablokowany: brak confirm.', $payload);

            return response()->json($payload, 422);
        }

        $payload = $this->buildJarekPublicImagesOne((string) $request->query('sku', 'JAREK-18727785496'), true);
        $this->logJarekImageLocalization(($payload['ok'] ?? false) ? 'success' : 'blocked', 'Apply kopiowania lokalnych zdjęć Jarka do public_html storage dla jednego SKU; bez pobierania z Allegro.', $payload);

        return response()->json($payload, ($payload['ok'] ?? false) ? 200 : 422);
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
        $coreReturnNotice = $this->jarekCoreReturnNotice($sourceTitle.' '.$sourceDescription);
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

        $conditionDiagnostics = $this->jarekEbayConditionDiagnostics($gearbox);
        $templateConditionLabel = $this->jarekEbayTemplateConditionLabel($conditionDiagnostics['mapped_ebay_condition'] ?? null);

        $templatePart = new Part();
        $templatePart->name = $translatedTitle;
        $templatePart->description = $translatedDescriptionBase;
        $partNumber = $this->detectJarekPartNumber((object) $gearbox->getAttributes(), $sku);
        $renderedDescription = $renderer->render('ebay_de', $templatePart, [
            'title' => $translatedTitle,
            'description' => $translatedDescription,
            'part_number' => $partNumber,
            'condition' => $templateConditionLabel,
        ]);
        if ($coreReturnNotice['required']) {
            $renderedDescription = $this->removeCoreReturnNotices($renderedDescription, $coreReturnNotice);
            $renderedDescription = $this->insertCoreReturnNoticeInDescriptionSection($renderedDescription, $coreReturnNotice['notice_de']);
            $coreReturnNoticeAddedDe = true;
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
        $jarekEbayPrice = $this->jarekEbayDePriceDiagnostics($sourcePricePln, $nbpExchangeRate);
        $priceEur = $jarekEbayPrice['price_eur'];

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
            'ebay_markup_percent' => $jarekEbayPrice['ebay_markup_percent'],
            'ebay_price_pln' => $jarekEbayPrice['ebay_price_pln'],
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
            'template_condition_label_de' => $templateConditionLabel,
            'core_return_required' => $coreReturnNotice['required'],
            'core_return_type' => $coreReturnNotice['type'],
            'core_return_notice_added' => $coreReturnNoticeAdded,
            'core_return_notice_pl' => $coreReturnNotice['notice_pl'],
            'core_return_notice_de' => $coreReturnNotice['notice_de'],
            'core_return_notice_added_after_translation' => $coreReturnNoticeAddedDe,
            'core_return_notice_location' => $coreReturnNotice['required'] ? 'description_section_last_paragraph' : null,
            'source_brand_candidates' => $sourceBrandCandidates,
            'selected_brand' => $selectedBrand,
            'brand_selection_reason' => $brandSelectionReason,
            'brand_source' => $brandSelection['brand_source'],
            'fulfillment_policy_id' => $mapping['fulfillment_policy_id'] ?? null,
            'shipping_group' => $mapping['shipping_group'] ?? null,
            'source_condition_name' => $conditionDiagnostics['source_condition_name'],
            'source_condition_value' => $conditionDiagnostics['source_condition_value'],
            'source_condition_parameter_id' => $conditionDiagnostics['source_condition_parameter_id'],
            'condition_source' => $conditionDiagnostics['condition_source'],
            'condition_mapping_reason' => $conditionDiagnostics['condition_mapping_reason'],
            'mapped_ebay_condition' => $conditionDiagnostics['mapped_ebay_condition'],
            'template_condition_label_de' => $templateConditionLabel,
            'condition_diagnostics' => $conditionDiagnostics,
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
            'core_return_notice_location' => $coreReturnNotice['required'] ? 'description_section_last_paragraph' : null,
            'ebay_category_id' => $mapping['ebay_category_id'] ?? null,
            'image_urls' => $imageUrls,
            'source_price_pln' => $sourcePricePln,
            'ebay_markup_percent' => $jarekEbayPrice['ebay_markup_percent'],
            'ebay_price_pln' => $jarekEbayPrice['ebay_price_pln'],
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
            'template_condition_label_de' => $templateConditionLabel,
            'condition_diagnostics' => $conditionDiagnostics,
            'payload_preview' => $payloadPreview,
            'contains_allegro_image_urls' => $this->arrayContainsStringFragment($payloadPreview, 'a.allegroimg.com'),
        ];

        $this->logJarekEbayDePreparePreview($payload['ready'] ? 'success' : 'blocked', 'Preview payloadu eBay DE dla Skrzyń Jarka; bez eBay API, bez parts, marketplace_write=false.', $payload, $started);

        return response()->json($payload);
    }



    public function ebayDeBulkPreparePreview(Request $request, GoogleTranslateService $translateService, EbayDescriptionTemplateRenderer $renderer, NbpExchangeRateService $exchangeRateService): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query('limit', 20)));
        $offset = max(0, (int) $request->query('offset', 0));
        $skuFilter = trim((string) $request->query('sku', ''));
        $onlyReady = $request->boolean('only_ready');
        $onlyBlocked = $request->boolean('only_blocked');

        $payload = [
            'ok' => true,
            'dry_run' => true,
            'read_only' => true,
            'marketplace_write' => false,
            'parts_changed' => false,
            'action' => 'jarek_gearboxes_ebay_de_bulk_prepare_preview',
            'source_table' => 'jarek_gearboxes',
            'limit' => $limit,
            'offset' => $offset,
            'filters' => [
                'sku' => $skuFilter ?: null,
                'only_ready' => $onlyReady,
                'only_blocked' => $onlyBlocked,
            ],
            'summary' => [
                'total' => 0,
                'ok_count' => 0,
                'blocked_count' => 0,
                'warning_count' => 0,
                'missing_images_count' => 0,
                'missing_offer_id_count' => 0,
                'ready_to_apply_count' => 0,
            ],
            'offers' => [],
            'safety' => [
                'dry_run_only',
                'no_ebay_api_write',
                'no_publish',
                'no_apply',
                'no_revise',
                'marketplace_write_false',
            ],
        ];

        if (! Schema::hasTable('jarek_gearboxes')) {
            $payload['ok'] = false;
            $payload['blockers'] = ['jarek_gearboxes_table_missing'];
            return response()->json($payload, 200);
        }

        $query = JarekGearbox::query()->orderBy('id');
        if ($skuFilter !== '') {
            $offerId = preg_match('/^JAREK-(.+)$/', $skuFilter, $m) ? $m[1] : $skuFilter;
            $query->where(function ($query) use ($skuFilter, $offerId): void {
                $query->where('allegro_offer_id', $offerId)
                    ->orWhere('ebay_inventory_sku', $skuFilter);
            });
        }

        $gearboxes = $query->offset($offset)->limit($limit)->get();

        foreach ($gearboxes as $gearbox) {
            $sku = 'JAREK-'.($gearbox->allegro_offer_id ?: $gearbox->id);
            try {
                $previewRequest = Request::create($request->path(), 'GET', ['sku' => $sku]);
                $preview = $this->ebayDeRevisePreview($previewRequest, $translateService, $renderer, $exchangeRateService)->getData(true);
                $item = $this->jarekBulkPreparePreviewItem($sku, $preview);
            } catch (Throwable $e) {
                $item = $this->jarekBulkPreparePreviewExceptionItem($sku, $gearbox, $e);
            }

            if ($onlyReady && ! ($item['ok'] ?? false)) continue;
            if ($onlyBlocked && (($item['blockers'] ?? []) === [])) continue;

            $payload['offers'][] = $item;
            $payload['summary']['total']++;
            if ($item['ok'] ?? false) $payload['summary']['ok_count']++;
            if (($item['blockers'] ?? []) !== []) $payload['summary']['blocked_count']++;
            if (($item['warnings'] ?? []) !== []) $payload['summary']['warning_count']++;
            if (($item['public_image_urls'] ?? []) === []) $payload['summary']['missing_images_count']++;
            if (blank($item['offer_id'] ?? null)) $payload['summary']['missing_offer_id_count']++;
            if (($item['ok'] ?? false) && filled($item['offer_id'] ?? null)) $payload['summary']['ready_to_apply_count']++;
        }

        return response()->json($payload, 200);
    }

    public function ebayDeBulkPreparePublishPreview(Request $request, GoogleTranslateService $translateService, EbayDescriptionTemplateRenderer $renderer, NbpExchangeRateService $exchangeRateService): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query('limit', 20)));
        $offset = max(0, (int) $request->query('offset', 0));
        $skuFilter = trim((string) $request->query('sku', ''));
        $onlyReady = $request->boolean('only_ready');
        $onlyBlocked = $request->boolean('only_blocked');
        $missingOfferIdOnly = $request->boolean('missing_offer_id_only');

        $payload = [
            'ok' => true,
            'dry_run' => true,
            'read_only' => true,
            'marketplace_write' => false,
            'marketplace_write_enabled' => false,
            'parts_changed' => false,
            'action' => 'jarek_gearboxes_ebay_de_bulk_prepare_publish_preview',
            'source_table' => 'jarek_gearboxes',
            'offset' => $offset,
            'limit' => $limit,
            'filters' => [
                'sku' => $skuFilter ?: null,
                'only_ready' => $onlyReady,
                'only_blocked' => $onlyBlocked,
                'missing_offer_id_only' => $missingOfferIdOnly,
            ],
            'summary' => [
                'total' => 0,
                'ok_count' => 0,
                'blocked_count' => 0,
                'warning_count' => 0,
                'ready_to_publish_count' => 0,
                'missing_images_count' => 0,
                'missing_required_fields_count' => 0,
                'translation_warning_count' => 0,
                'estimated_publish_count' => 0,
                'duplicate_existing_count' => 0,
                'skipped_existing_count' => 0,
                'offset' => $offset,
                'limit' => $limit,
                'next_offset' => null,
                'has_more' => false,
            ],
            'offers' => [],
            'safety' => [
                'dry_run_only',
                'read_only',
                'marketplace_write_false',
                'no_createInventoryItem',
                'no_createOffer',
                'no_publishOffer',
                'no_ebay_api_write',
            ],
        ];

        try {
            if (! Schema::hasTable('jarek_gearboxes')) {
                $payload['ok'] = false;
                $payload['blockers'] = ['jarek_gearboxes_table_missing'];
                return response()->json($payload, 200);
            }

            $query = JarekGearbox::query()->orderBy('id');
            if ($skuFilter !== '') {
                $offerId = preg_match('/^JAREK-(.+)$/', $skuFilter, $m) ? $m[1] : $skuFilter;
                $query->where(function ($query) use ($skuFilter, $offerId): void {
                    $query->where('allegro_offer_id', $offerId)->orWhere('ebay_inventory_sku', $skuFilter);
                });
            }
            if ($missingOfferIdOnly) {
                $query->where(function ($query): void {
                    $query->whereNull('ebay_offer_id')->orWhere('ebay_offer_id', '');
                });
            }

            $totalMatching = (clone $query)->count();
            $gearboxes = $query->offset($offset)->limit($limit)->get();
            $payload['summary']['next_offset'] = $offset + $gearboxes->count();
            $payload['summary']['has_more'] = ($offset + $gearboxes->count()) < $totalMatching;

            foreach ($gearboxes as $gearbox) {
                $sku = 'JAREK-'.($gearbox->allegro_offer_id ?: $gearbox->id);
                try {
                    $previewRequest = Request::create($request->path(), 'GET', ['sku' => $sku]);
                    $prepare = $this->ebayDePreparePreview($previewRequest, $translateService, $renderer, $exchangeRateService)->getData(true);
                    $plan = $this->jarekEbayDeApiPlan($prepare);
                    $duplicateGuard = $this->jarekEbayDeBulkDuplicateGuard($sku);
                    $item = $this->jarekBulkPreparePublishPreviewItem($gearbox, $sku, $prepare, $plan, $duplicateGuard);
                } catch (Throwable $e) {
                    $item = $this->jarekBulkPreparePublishPreviewExceptionItem($gearbox, $sku, $e);
                }

                if ($onlyReady && ! ($item['ready_to_publish'] ?? false)) continue;
                if ($onlyBlocked && (($item['blockers'] ?? []) === [])) continue;

                $payload['offers'][] = $item;
                $payload['summary']['total']++;
                if ($item['ok'] ?? false) $payload['summary']['ok_count']++;
                if (($item['blockers'] ?? []) !== []) $payload['summary']['blocked_count']++;
                if (($item['warnings'] ?? []) !== []) $payload['summary']['warning_count']++;
                if (($item['public_image_urls'] ?? []) === []) $payload['summary']['missing_images_count']++;
                if (in_array('missing_required_fields', (array) ($item['blockers'] ?? []), true)) $payload['summary']['missing_required_fields_count']++;
                if (in_array('translation_warning', (array) ($item['warnings'] ?? []), true)) $payload['summary']['translation_warning_count']++;
                if (in_array('existing_ebay_offer_or_listing_found', (array) ($item['blockers'] ?? []), true)) $payload['summary']['duplicate_existing_count']++;
                if (filled($item['existing_offer_id'] ?? null) || filled($item['existing_listing_id'] ?? null)) $payload['summary']['skipped_existing_count']++;
                if ($item['ready_to_publish'] ?? false) {
                    $payload['summary']['ready_to_publish_count']++;
                    $payload['summary']['estimated_publish_count']++;
                }
            }
        } catch (Throwable $e) {
            $payload['ok'] = false;
            $payload['blockers'] = ['bulk_prepare_publish_preview_exception'];
            $payload['admin_diagnostics'] = ['error_class' => $e::class, 'error_message' => $e->getMessage()];
        }

        return response()->json($payload, 200);
    }



    public function ebayDePublishRunnerPlan(Request $request, GoogleTranslateService $translateService, EbayDescriptionTemplateRenderer $renderer, NbpExchangeRateService $exchangeRateService): JsonResponse
    {
        $runId = 'jarek-ebay-de-plan-'.now()->format('YmdHis').'-'.Str::lower(Str::random(8));
        $batchSize = max(1, min(20, (int) $request->query('batch_size', 20)));
        $limit = max(1, min(2000, (int) $request->query('limit', 2000)));
        $offset = max(0, (int) $request->query('offset', 0));
        $processed = 0;
        $items = [];
        $summary = $this->emptyJarekEbayDeRunnerSummary($runId, $batchSize, $offset);
        $summary['state'] = 'running';
        $summary['started_at'] = now()->toISOString();

        while ($processed < $limit) {
            $previewRequest = Request::create($request->path(), 'GET', [
                'limit' => min($batchSize, $limit - $processed),
                'offset' => $offset + $processed,
                'missing_offer_id_only' => $request->boolean('missing_offer_id_only', true) ? '1' : '0',
            ]);
            $batch = $this->ebayDeBulkPreparePublishPreview($previewRequest, $translateService, $renderer, $exchangeRateService)->getData(true);
            foreach ((array) ($batch['offers'] ?? []) as $offer) {
                $items[] = $this->jarekEbayDeRunnerPlanItem((array) $offer);
            }
            $count = count((array) ($batch['offers'] ?? []));
            $processed += $count;
            $summary['current_offset'] = $offset + $processed;
            if (! ($batch['summary']['has_more'] ?? false) || $count === 0) break;
        }

        $summary['state'] = 'completed';
        $summary['finished_at'] = now()->toISOString();
        $summary['total'] = count($items);
        foreach ($items as $item) {
            $summary['processed']++;
            if ($item['ready'] ?? false) $summary['succeeded']++;
            if (($item['status'] ?? null) === 'blocked') $summary['blocked']++;
            if (($item['status'] ?? null) === 'failed') $summary['failed']++;
            if (($item['status'] ?? null) === 'skipped') $summary['skipped']++;
            if (in_array('missing_public_images', $item['blockers'] ?? [], true)) $summary['missing_images_count']++;
            if (in_array('translation_failed', $item['blockers'] ?? [], true)) $summary['translation_failed_count']++;
            if (in_array('existing_ebay_offer_or_listing_found', $item['blockers'] ?? [], true)) $summary['duplicate_existing_count']++;
        }
        $summary['published_count'] = 0;
        $summary['blocked_count'] = $summary['blocked'];
        $summary['failed_count'] = $summary['failed'];
        $summary['skipped_existing_count'] = $summary['duplicate_existing_count'];

        $payload = [
            'ok' => true,
            'dry_run' => true,
            'read_only' => true,
            'marketplace_write' => false,
            'marketplace_write_enabled' => false,
            'action' => 'jarek_gearboxes_ebay_de_publish_runner_plan',
            'run_id' => $runId,
            'state' => 'completed',
            'batch_size' => $batchSize,
            'max_publish_batch_size' => 20,
            'confirm_required_for_real_publish' => 'future_guarded_confirm_token_not_implemented_in_plan_only',
            'resume' => ['current_offset' => $summary['current_offset'], 'can_resume_from_offset' => true],
            'controls' => ['pending', 'running', 'paused', 'completed', 'failed'],
            'summary' => $summary,
            'errors_or_blockers' => array_values(array_filter($items, fn ($item) => ($item['blockers'] ?? []) !== [] || filled($item['error'] ?? null))),
            'published_skus' => [],
            'items' => $items,
            'safety' => ['plan_only', 'dry_run_true', 'read_only_true', 'marketplace_write_false', 'no_createInventoryItem', 'no_createOffer', 'no_publishOffer', 'duplicate_guard_by_sku_before_publish'],
        ];
        $this->logJarekEbayDeRunnerPlan($payload);

        return response()->json($payload);
    }

    public function ebayDePublishRunnerPause(Request $request): JsonResponse
    {
        $payload = ['ok' => true, 'dry_run' => true, 'read_only' => true, 'marketplace_write' => false, 'run_id' => $request->query('run_id'), 'state' => 'paused', 'message' => 'Plan-only runner pause marker recorded; no marketplace write.'];
        $this->logJarekEbayDeRunnerPlan($payload + ['action' => 'jarek_gearboxes_ebay_de_publish_runner_pause']);
        return response()->json($payload);
    }

    private function jarekEbayDeBulkDuplicateGuard(string $sku): array
    {
        try {
            return $this->jarekEbayDePreviewIdempotency($sku) + ['guard' => 'existing_ebay_offer_or_listing_by_sku'];
        } catch (Throwable $e) {
            return ['ok' => false, 'read_only' => true, 'read_only_api_check' => 'performed', 'sku' => $sku, 'inventory_item_exists' => false, 'offer_exists' => false, 'offer_id' => null, 'listing_id' => null, 'blockers' => ['ebay_duplicate_guard_lookup_failed'], 'guard' => 'existing_ebay_offer_or_listing_by_sku', 'error_class' => $e::class, 'error_message' => $e->getMessage()];
        }
    }

    private function jarekEbayDeRunnerPlanItem(array $offer): array
    {
        $blockers = array_values((array) ($offer['blockers'] ?? []));
        $status = ($offer['ready_to_publish'] ?? false) ? 'ready' : ($blockers === [] ? 'skipped' : 'blocked');
        return ['sku' => $offer['sku'] ?? null, 'status' => $status, 'ready' => (bool) ($offer['ready_to_publish'] ?? false), 'published' => false, 'blocked' => $blockers !== [], 'error' => null, 'offer_id' => $offer['existing_offer_id'] ?? null, 'listing_id' => $offer['existing_listing_id'] ?? null, 'warnings' => array_values((array) ($offer['warnings'] ?? [])), 'blockers' => $blockers];
    }

    private function emptyJarekEbayDeRunnerSummary(string $runId, int $batchSize, int $offset): array
    {
        return ['run_id' => $runId, 'state' => 'pending', 'batch_size' => $batchSize, 'total' => 0, 'processed' => 0, 'succeeded' => 0, 'blocked' => 0, 'failed' => 0, 'skipped' => 0, 'current_offset' => $offset, 'started_at' => null, 'finished_at' => null, 'published_count' => 0, 'blocked_count' => 0, 'failed_count' => 0, 'skipped_existing_count' => 0, 'missing_images_count' => 0, 'translation_failed_count' => 0, 'duplicate_existing_count' => 0];
    }

    private function logJarekEbayDeRunnerPlan(array $payload): void
    {
        if (! Schema::hasTable('marketplace_sync_logs')) return;
        MarketplaceSyncLog::query()->create(['marketplace' => 'ebay_de', 'action' => $payload['action'] ?? 'jarek_gearboxes_ebay_de_publish_runner_plan', 'status' => $payload['state'] ?? 'success', 'message' => 'Plan-only dry-run runner snapshot for Jarek eBay DE guarded bulk publish; no marketplace write.', 'external_id' => $payload['run_id'] ?? null, 'payload' => $payload, 'created_at' => now()]);
    }

    /** @param array<string, mixed> $prepare @param array{plan: array<string, mixed>, blockers: array<int, string>} $plan */
    private function jarekBulkPreparePublishPreviewItem(JarekGearbox $gearbox, string $sku, array $prepare, array $plan, ?array $duplicateGuard = null): array
    {
        $blockers = array_values(array_unique(array_filter(array_merge((array) ($prepare['blockers'] ?? []), (array) ($plan['blockers'] ?? [])))));
        $warnings = array_values(array_unique(array_filter((array) ($prepare['warnings'] ?? []))));
        $publicImageUrls = array_values((array) ($prepare['image_urls'] ?? []));
        $inventoryItemRequest = (array) ($plan['plan']['inventory_item_request'] ?? []);
        $offerRequest = (array) ($plan['plan']['offer_request'] ?? []);

        data_set($inventoryItemRequest, 'condition', 'SELLER_REFURBISHED');
        data_set($offerRequest, 'marketplaceId', 'EBAY_DE');
        data_set($offerRequest, 'format', 'FIXED_PRICE');

        $required = [
            'title' => data_get($inventoryItemRequest, 'product.title'),
            'description' => data_get($inventoryItemRequest, 'product.description'),
            'price' => data_get($offerRequest, 'pricingSummary.price.value'),
            'categoryId' => data_get($offerRequest, 'categoryId'),
            'merchantLocationKey' => data_get($offerRequest, 'merchantLocationKey'),
            'fulfillmentPolicyId' => data_get($offerRequest, 'listingPolicies.fulfillmentPolicyId'),
            'paymentPolicyId' => data_get($offerRequest, 'listingPolicies.paymentPolicyId'),
            'returnPolicyId' => data_get($offerRequest, 'listingPolicies.returnPolicyId'),
        ];
        foreach ($required as $name => $value) {
            if (blank($value)) $blockers[] = 'missing_'.str($name)->snake()->toString();
        }
        if ($publicImageUrls === []) $blockers[] = 'missing_public_images';
        foreach ($publicImageUrls as $url) {
            if (! is_string($url) || ! preg_match('#^https://(?:www\.)?gpswiss\.pl/storage/jarek-gearboxes/#i', $url)) {
                $blockers[] = 'image_not_public_or_not_ebay_safe';
                break;
            }
        }
        if (blank($gearbox->title)) $blockers[] = 'missing_title';
        if (blank($gearbox->description) && blank($gearbox->plain_description)) $blockers[] = 'missing_description';
        if (! is_numeric($gearbox->price)) $blockers[] = 'missing_price';
        if (blank($prepare['translated_title_de'] ?? null) || blank($prepare['translated_description_de'] ?? null)) $blockers[] = 'translation_failed';
        if (blank(data_get($inventoryItemRequest, 'condition'))) $blockers[] = 'condition_mapping_failed';
        if (! is_numeric($prepare['price_eur'] ?? null)) $blockers[] = 'price_conversion_failed';
        if (array_filter($required, fn ($value): bool => blank($value)) !== []) $blockers[] = 'missing_required_fields';

        $duplicateGuard ??= ['ok' => true, 'read_only_api_check' => 'skipped'];
        $duplicateOfferId = $duplicateGuard['offer_id'] ?? null;
        $duplicateListingId = $duplicateGuard['listing_id'] ?? null;
        if (($duplicateGuard['inventory_item_exists'] ?? false) || ($duplicateGuard['offer_exists'] ?? false) || filled($duplicateListingId)) {
            $blockers[] = 'existing_ebay_offer_or_listing_found';
        }
        if (($duplicateGuard['ok'] ?? true) === false && (($duplicateGuard['read_only_api_check'] ?? null) === 'performed')) {
            $blockers[] = 'ebay_duplicate_guard_lookup_failed';
        }

        $blockers = array_values(array_unique(array_filter($blockers)));
        $ready = $blockers === [] && blank($gearbox->ebay_offer_id) && blank($gearbox->ebay_listing_id) && blank($duplicateOfferId) && blank($duplicateListingId);

        return [
            'sku' => $sku,
            'source_id' => $gearbox->id,
            'offer_source_id' => $gearbox->allegro_offer_id,
            'existing_offer_id' => $gearbox->ebay_offer_id ?: $duplicateOfferId,
            'existing_listing_id' => $gearbox->ebay_listing_id ?: $duplicateListingId,
            'ok' => $blockers === [],
            'ready_to_publish' => $ready,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'admin_diagnostics' => ['dry_run' => true, 'read_only' => true, 'marketplace_write' => false, 'ebay_duplicate_guard' => $duplicateGuard],
            'public_image_urls' => $publicImageUrls,
            'image_diagnostics' => ['public_image_urls_count' => count($publicImageUrls), 'blockers' => array_values(array_intersect($blockers, ['missing_public_images', 'image_not_public_or_not_ebay_safe']))],
            'price_diagnostics' => ['source_price_pln' => $prepare['source_price_pln'] ?? null, 'nbp_exchange_rate' => $prepare['nbp_exchange_rate'] ?? null, 'price_eur' => $prepare['price_eur'] ?? null, 'currency' => 'EUR'],
            'condition_template_diagnostics' => ['source_condition_name' => $prepare['source_condition_name'] ?? null, 'source_condition_value' => $prepare['source_condition_value'] ?? null, 'mapped_ebay_condition' => 'SELLER_REFURBISHED', 'template_condition_label_de' => 'Generalüberholt'],
            'core_return_diagnostics' => ['required' => $prepare['core_return_required'] ?? null, 'notice_de' => $prepare['core_return_notice_de'] ?? null, 'location' => $prepare['core_return_notice_location'] ?? null],
            'translated_title_de' => $prepare['translated_title_de'] ?? null,
            'translated_description_de' => $prepare['rendered_description_de_template'] ?? $prepare['translated_description_de'] ?? null,
            'inventory_item_request' => $inventoryItemRequest,
            'offer_request' => $offerRequest,
            'publish_plan' => ['steps' => ['PUT inventory_item/{sku}', 'POST offer', 'POST publish_offer/{offerId}'], 'execute' => false, 'route' => in_array('existing_ebay_offer_or_listing_found', $blockers, true) ? 'revise' : 'publish'],
        ];
    }

    private function jarekBulkPreparePublishPreviewExceptionItem(JarekGearbox $gearbox, string $sku, Throwable $e): array
    {
        return [
            'sku' => $sku,
            'source_id' => $gearbox->id,
            'offer_source_id' => $gearbox->allegro_offer_id,
            'existing_offer_id' => $gearbox->ebay_offer_id,
            'existing_listing_id' => $gearbox->ebay_listing_id,
            'ok' => false,
            'ready_to_publish' => false,
            'blockers' => ['record_prepare_exception'],
            'warnings' => [],
            'admin_diagnostics' => ['error_class' => $e::class, 'error_message' => $e->getMessage()],
            'public_image_urls' => [],
            'image_diagnostics' => ['public_image_urls_count' => 0, 'blockers' => ['record_prepare_exception']],
            'price_diagnostics' => null,
            'condition_template_diagnostics' => null,
            'core_return_diagnostics' => null,
            'translated_title_de' => null,
            'translated_description_de' => null,
            'inventory_item_request' => null,
            'offer_request' => null,
            'publish_plan' => ['steps' => ['PUT inventory_item/{sku}', 'POST offer', 'POST publish_offer/{offerId}'], 'execute' => false, 'route' => 'publish'],
        ];
    }

    /** @param array<string, mixed> $preview */
    private function jarekBulkPreparePreviewItem(string $sku, array $preview): array
    {
        $imageDiagnostics = is_array($preview['image_diagnostics'] ?? null) ? $preview['image_diagnostics'] : [];

        return [
            'sku' => $sku,
            'offer_id' => $preview['offer_id'] ?? null,
            'listing_id' => $preview['listing_id'] ?? null,
            'ok' => (bool) ($preview['ok'] ?? false),
            'blockers' => array_values((array) ($preview['blockers'] ?? [])),
            'warnings' => array_values((array) ($preview['warnings'] ?? [])),
            'public_image_urls' => array_values((array) ($preview['public_image_urls'] ?? [])),
            'price_diagnostics' => $preview['price_diagnostics'] ?? null,
            'image_diagnostics' => [
                'recommended_image_urls_count' => count((array) ($imageDiagnostics['recommended_image_urls'] ?? [])),
                'public_image_urls_count' => count((array) ($preview['public_image_urls'] ?? [])),
                'blockers' => array_values((array) ($imageDiagnostics['blockers'] ?? [])),
                'warnings' => array_values((array) ($imageDiagnostics['warnings'] ?? [])),
                'source' => $imageDiagnostics['source'] ?? null,
            ],
            'condition_template_diagnostics' => $preview['condition_template_diagnostics'] ?? null,
            'core_return_diagnostics' => $preview['core_return_diagnostics'] ?? null,
            'revised_inventory_item_request' => $preview['revised_inventory_item_request'] ?? null,
            'revised_offer_request' => $preview['revised_offer_request'] ?? null,
            'admin_diagnostics' => $preview['admin_diagnostics'] ?? [],
        ];
    }

    private function jarekBulkPreparePreviewExceptionItem(string $sku, JarekGearbox $gearbox, Throwable $e): array
    {
        return [
            'sku' => $sku,
            'offer_id' => $gearbox->ebay_offer_id,
            'listing_id' => $gearbox->ebay_listing_id,
            'ok' => false,
            'blockers' => ['bulk_prepare_preview_exception'],
            'warnings' => [],
            'public_image_urls' => [],
            'price_diagnostics' => null,
            'image_diagnostics' => ['recommended_image_urls_count' => 0, 'public_image_urls_count' => 0, 'blockers' => [], 'warnings' => [], 'source' => null],
            'condition_template_diagnostics' => null,
            'core_return_diagnostics' => null,
            'revised_inventory_item_request' => null,
            'revised_offer_request' => null,
            'admin_diagnostics' => ['error_class' => $e::class, 'error_message' => $e->getMessage()],
        ];
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
        $payload['idempotency'] = $this->jarekEbayDePreviewIdempotency((string) ($payload['sku'] ?? '')) + [
            'sku' => $payload['sku'] ?? null,
            'existing_ebay_listing_id' => $payload['existing_ebay_listing_id'] ?? null,
            'existing_ebay_offer_id' => $payload['existing_ebay_offer_id'] ?? null,
            'existing_ebay_inventory_sku' => $payload['existing_ebay_inventory_sku'] ?? null,
            'safe_to_retry' => true,
            'apply_requires_confirm' => 'jarek-ebay-de-publish-one',
        ];
        $plan = $this->jarekEbayDeApiPlan($payload);
        $payload['ebay_api_plan'] = $plan['plan'];
        $idempotencyBlockers = [];
        if (($payload['idempotency']['read_only_api_check'] ?? null) === 'performed') {
            if ($payload['idempotency']['inventory_item_exists'] ?? false) $idempotencyBlockers[] = 'inventory_item_exists';
            if ($payload['idempotency']['offer_exists'] ?? false) $idempotencyBlockers[] = 'offer_exists';
            if (filled($payload['idempotency']['listing_id'] ?? null)) $idempotencyBlockers[] = 'listing_exists';
            if (! ($payload['idempotency']['ok'] ?? false)) $idempotencyBlockers[] = 'idempotency_check_failed';
        }
        $payload['blockers'] = array_values(array_unique(array_merge($payload['blockers'] ?? [], $plan['blockers'], $idempotencyBlockers)));
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

    public function ebayDeListingDiagnostics(Request $request): JsonResponse
    {
        $payload = $this->buildJarekEbayDeListingDiagnostics((string) $request->query('sku', ''));
        $this->logJarekEbayDeDiagnostic(($payload['ok'] ?? false) ? 'success' : 'blocked', 'Read-only diagnostics for one Jarek eBay DE listing; no marketplace write.', $payload);

        return response()->json($payload, ($payload['ok'] ?? false) ? 200 : 422);
    }

    public function ebayDeRevisePreview(Request $request, GoogleTranslateService $translateService, EbayDescriptionTemplateRenderer $renderer, NbpExchangeRateService $exchangeRateService): JsonResponse
    {
        $sku = (string) $request->query('sku', '');
        $adminDiagnostics = [];
        $prepare = [
            'ok' => false,
            'dry_run' => true,
            'marketplace_write' => false,
            'sku' => $sku,
            'blockers' => [],
            'warnings' => [],
        ];
        $diagnostics = [
            'ok' => false,
            'dry_run' => true,
            'read_only' => true,
            'marketplace_write' => false,
            'sku' => $sku,
            'blockers' => [],
            'warnings' => [],
        ];
        $plan = ['plan' => [], 'blockers' => []];

        try {
            $prepareResponse = $this->ebayDePreparePreview(Request::create($request->path(), 'GET', ['sku' => $sku]), $translateService, $renderer, $exchangeRateService);
            $prepare = $prepareResponse->getData(true);
        } catch (Throwable $e) {
            $prepare['blockers'][] = 'prepare_preview_exception';
            $adminDiagnostics['prepare_preview_exception'] = [
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ];
        }

        try {
            $diagnostics = $this->buildJarekEbayDeListingDiagnostics($sku);
        } catch (Throwable $e) {
            $diagnostics['blockers'][] = 'listing_diagnostics_exception';
            $adminDiagnostics['listing_diagnostics_exception'] = [
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ];
        }

        try {
            $plan = $this->jarekEbayDeApiPlan($prepare);
        } catch (Throwable $e) {
            $plan['blockers'][] = 'api_plan_exception';
            $adminDiagnostics['api_plan_exception'] = [
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ];
        }

        try {
            $listingId = $diagnostics['listing_id'] ?? $prepare['existing_ebay_listing_id'] ?? null;
            $offerId = $diagnostics['offer_id'] ?? $prepare['existing_ebay_offer_id'] ?? null;
            $recommendedImages = $diagnostics['image_diagnostics']['recommended_image_urls'] ?? ($prepare['image_urls'] ?? []);
            $description = (string) ($prepare['rendered_description_de_template'] ?? '');
            $blockers = array_values(array_unique(array_filter(array_merge($prepare['blockers'] ?? [], $diagnostics['blockers'] ?? [], $plan['blockers'] ?? []))));
            $warnings = array_values(array_unique(array_filter(array_merge($prepare['warnings'] ?? [], $diagnostics['warnings'] ?? []))));
            $adminDiagnostics = $this->normalizeJarekRevisePreviewAdminDiagnostics($adminDiagnostics);
            $revisedInventoryItemRequest = $plan['plan']['inventory_item_request'] ?? [];
            if (! is_array($revisedInventoryItemRequest)) {
                $revisedInventoryItemRequest = [];
            }
            data_set($revisedInventoryItemRequest, 'product.imageUrls', $recommendedImages);

            $payload = [
                'ok' => (bool) (($prepare['ok'] ?? false) && $blockers === [] && $adminDiagnostics === []),
                'dry_run' => true,
                'marketplace_write' => false,
                'parts_changed' => false,
                'action' => 'jarek_gearboxes_ebay_de_revise_preview',
                'source_table' => 'jarek_gearboxes',
                'sku' => $sku,
                'inventory_sku' => $sku,
                'listing_id' => $listingId,
                'offer_id' => $offerId,
                'public_image_urls' => $recommendedImages,
                'revised_inventory_item_request' => $revisedInventoryItemRequest,
                'revised_offer_request' => [
                    'offerId' => $offerId,
                    'listingDescription' => $description,
                    'pricingSummary' => data_get($plan['plan'] ?? [], 'offer_request.pricingSummary'),
                ],
                'price_diagnostics' => [
                    'source_price_pln' => $prepare['source_price_pln'] ?? null,
                    'ebay_markup_percent' => $prepare['ebay_markup_percent'] ?? null,
                    'ebay_price_pln' => $prepare['ebay_price_pln'] ?? null,
                    'nbp_exchange_rate' => $prepare['nbp_exchange_rate'] ?? null,
                    'target_currency' => $prepare['target_currency'] ?? null,
                    'price_eur' => $prepare['price_eur'] ?? null,
                    'final_price' => data_get($plan['plan'] ?? [], 'offer_request.pricingSummary.price.value'),
                    'final_currency' => data_get($plan['plan'] ?? [], 'offer_request.pricingSummary.price.currency'),
                ],
                'image_diagnostics' => $diagnostics['image_diagnostics'] ?? null,
                'live_listing_diagnostics' => $diagnostics['live_listing_diagnostics'] ?? null,
                'public_url_diagnostics' => $diagnostics['public_url_diagnostics'] ?? null,
                'core_return_diagnostics' => [
                    'required' => $prepare['core_return_required'] ?? null,
                    'type' => $prepare['core_return_type'] ?? null,
                    'notice_de' => $prepare['core_return_notice_de'] ?? null,
                    'occurrences_in_listingDescription' => substr_count($description, (string) ($prepare['core_return_notice_de'] ?? "\0")),
                    'location' => $prepare['core_return_notice_location'] ?? null,
                ],
                'condition_template_diagnostics' => [
                    'source_condition_name' => $prepare['source_condition_name'] ?? null,
                    'source_condition_value' => $prepare['source_condition_value'] ?? null,
                    'mapped_ebay_condition' => $prepare['mapped_ebay_condition'] ?? null,
                    'template_condition_label_de' => $prepare['template_condition_label_de'] ?? null,
                ],
                'blockers' => $blockers,
                'warnings' => $warnings,
                'admin_diagnostics' => $adminDiagnostics,
            ];

            return response()->json($payload);
        } catch (Throwable $e) {
            return response()->json($this->jarekRevisePreviewExceptionPayload($sku, $e, 'revise_preview_response_exception'), 200);
        }
    }

    public function ebayDeReviseApply(Request $request, GoogleTranslateService $translateService, EbayDescriptionTemplateRenderer $renderer, NbpExchangeRateService $exchangeRateService): JsonResponse
    {
        $started = microtime(true);
        $requiredConfirm = 'jarek-ebay-de-revise-one';
        $allowedSku = 'JAREK-18727785496';
        $sku = (string) $request->query('sku', '');
        $confirm = (string) $request->query('confirm', '');

        $base = [
            'ok' => false,
            'dry_run' => false,
            'marketplace_write' => false,
            'parts_changed' => false,
            'applied' => false,
            'action' => 'jarek_gearboxes_ebay_de_revise_apply',
            'required_confirm' => $requiredConfirm,
            'provided_confirm' => $request->query('confirm'),
            'allowed_sku' => $allowedSku,
            'sku' => $sku,
            'blockers' => [],
            'warnings' => [],
        ];

        if ($sku !== $allowedSku) {
            return response()->json(array_merge($base, ['error' => 'Revise apply is allowed only for the single guarded SKU.', 'blockers' => ['sku_not_allowed']]), 403);
        }
        if ($confirm !== $requiredConfirm) {
            return response()->json(array_merge($base, ['error' => 'Missing or invalid guarded revise confirmation token.', 'blockers' => ['missing_or_invalid_confirm_token']]), 403);
        }

        $previewRequest = Request::create($request->path(), 'GET', ['sku' => $sku]);
        $previewResponse = $this->ebayDeRevisePreview($previewRequest, $translateService, $renderer, $exchangeRateService);
        $preview = $previewResponse->getData(true);
        $blockers = array_values((array) ($preview['blockers'] ?? []));
        $warnings = array_values((array) ($preview['warnings'] ?? []));
        $offerId = (string) ($preview['offer_id'] ?? data_get($preview, 'revised_offer_request.offerId', ''));
        $listingId = $preview['listing_id'] ?? null;
        $inventoryPayload = (array) ($preview['revised_inventory_item_request'] ?? []);
        $offerPayload = (array) ($preview['revised_offer_request'] ?? []);

        foreach ([
            'offer_id' => $offerId,
            'inventory_payload' => $inventoryPayload,
            'offer_payload' => $offerPayload,
            'public_image_urls' => $preview['public_image_urls'] ?? [],
            'listingDescription' => data_get($offerPayload, 'listingDescription'),
            'price' => data_get($offerPayload, 'pricingSummary.price.value'),
            'condition' => data_get($inventoryPayload, 'condition'),
        ] as $field => $value) {
            if (blank($value) || (is_array($value) && $value === [])) $blockers[] = 'missing_'.$field;
        }

        $payload = $base + [
            'preview' => $preview,
            'offer_id' => $offerId ?: null,
            'listing_id' => $listingId,
            'public_image_urls' => $preview['public_image_urls'] ?? [],
            'revised_inventory_item_request' => $inventoryPayload,
            'revised_offer_request' => $offerPayload,
            'diagnostics' => [
                'price_diagnostics' => $preview['price_diagnostics'] ?? null,
                'image_diagnostics' => $preview['image_diagnostics'] ?? null,
                'live_listing_diagnostics' => $preview['live_listing_diagnostics'] ?? null,
                'public_url_diagnostics' => $preview['public_url_diagnostics'] ?? null,
                'core_return_diagnostics' => $preview['core_return_diagnostics'] ?? null,
                'condition_template_diagnostics' => $preview['condition_template_diagnostics'] ?? null,
                'admin_diagnostics' => $preview['admin_diagnostics'] ?? null,
            ],
        ];
        $payload['blockers'] = array_values(array_unique(array_filter($blockers)));
        $payload['warnings'] = $warnings;

        if ($payload['blockers'] !== []) {
            $payload['error'] = 'Guarded revise apply blocked before any eBay write.';
            $this->logJarekEbayDeReviseOne('blocked', $payload['error'], $payload, $started);
            return response()->json($payload, 409);
        }

        $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', 'ebay_de')->first() : null;
        $client = new EbayApiClient('ebay_de', $account);
        $result = $client->reviseInventoryOffer($sku, $offerId, $inventoryPayload, $offerPayload, 'de-DE');
        $payload['marketplace_write'] = true;
        $payload['applied'] = (bool) ($result['ok'] ?? false);
        $payload['result'] = $result;
        $payload['offer_id'] = $result['offer_id'] ?? $offerId;
        $payload['listing_id'] = $result['listing_id'] ?? $listingId;
        $payload['errors'] = ($result['ok'] ?? false) ? [] : [$result['error'] ?? ($result['json'] ?? 'eBay revise failed')];
        $payload['ok'] = (bool) ($result['ok'] ?? false);

        $this->logJarekEbayDeReviseOne($payload['ok'] ? 'success' : 'error', $payload['ok'] ? 'Guarded single SKU eBay DE revise completed.' : 'Guarded single SKU eBay DE revise failed.', $payload, $started);

        return response()->json($payload, $payload['ok'] ? 200 : 502);
    }

    /** @param array<string, mixed> $payload */
    private function logJarekEbayDeReviseOne(string $status, string $message, array $payload, float $started): void
    {
        if (! Schema::hasTable('marketplace_sync_logs')) return;

        MarketplaceSyncLog::query()->create([
            'marketplace' => 'ebay_de',
            'action' => 'jarek_gearboxes_ebay_de_revise_one',
            'status' => $status,
            'message' => $message,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'external_id' => $payload['offer_id'] ?? $payload['sku'] ?? null,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $adminDiagnostics */
    private function normalizeJarekRevisePreviewAdminDiagnostics(array $adminDiagnostics): array
    {
        if ($adminDiagnostics === [] || isset($adminDiagnostics['error_class'], $adminDiagnostics['error_message'])) {
            return $adminDiagnostics;
        }

        foreach ($adminDiagnostics as $diagnostic) {
            if (is_array($diagnostic) && isset($diagnostic['error_class'], $diagnostic['error_message'])) {
                return [
                    'error_class' => $diagnostic['error_class'],
                    'error_message' => $diagnostic['error_message'],
                ] + $adminDiagnostics;
            }
        }

        return $adminDiagnostics;
    }

    private function jarekRevisePreviewExceptionPayload(string $sku, Throwable $e, string $blocker): array
    {
        return [
            'ok' => false,
            'dry_run' => true,
            'marketplace_write' => false,
            'parts_changed' => false,
            'action' => 'jarek_gearboxes_ebay_de_revise_preview',
            'source_table' => 'jarek_gearboxes',
            'sku' => $sku,
            'inventory_sku' => $sku,
            'blockers' => [$blocker],
            'warnings' => [],
            'admin_diagnostics' => [
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ],
        ];
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


    /** @return array<string, mixed> */
    private function jarekEbayDePreviewIdempotency(string $sku): array
    {
        $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', 'ebay_de')->first() : null;
        $credentials = is_array($account?->api_credentials) ? $account->api_credentials : [];
        $canCheck = $account !== null && $account->api_enabled && filled($account->api_base_url) && filled($credentials['access_token'] ?? null) && filled($sku);

        if (! $canCheck) {
            return [
                'read_only_api_check' => 'skipped',
                'read_only_api_check_reason' => 'ebay_de_account_or_access_token_not_configured_for_preview',
                'inventory_item_exists' => false,
                'offer_exists' => false,
                'offer_id' => null,
                'listing_id' => null,
                'offer_count' => 0,
            ];
        }

        $check = (new EbayApiClient('ebay_de', $account))->readOnlyInventoryAndOfferExistence($sku);

        return $check + [
            'read_only_api_check' => 'performed',
            'safe_to_retry' => ! ($check['inventory_item_exists'] ?? false) && ! ($check['offer_exists'] ?? false) && blank($check['listing_id'] ?? null),
        ];
    }

    private function buildJarekEbayDeListingDiagnostics(string $sku): array
    {
        $sku = trim($sku);
        $offerIdPart = preg_match('/^JAREK-(.+)$/', $sku, $m) ? $m[1] : '';
        $gearbox = Schema::hasTable('jarek_gearboxes') ? JarekGearbox::query()->where('allegro_offer_id', $offerIdPart)->first() : null;
        $listingId = $gearbox?->ebay_listing_id ?: null;
        $offerId = $gearbox?->ebay_offer_id ?: null;
        $inventorySku = $gearbox?->ebay_inventory_sku ?: $sku;
        $account = Schema::hasTable('marketplace_accounts') ? MarketplaceAccount::query()->where('code', 'ebay_de')->first() : null;
        $live = ['ok' => false, 'read_only_api_check' => 'skipped', 'reason' => 'ebay_de_account_not_configured'];
        if ($account && $account->api_enabled) {
            try {
                $client = new EbayApiClient('ebay_de', $account);
                $live = $client->readOnlyInventoryOfferListingDiagnostics($inventorySku, $offerId, $listingId);
                if (! is_array($live)) {
                    $live = ['ok' => false, 'read_only_api_check' => 'performed', 'reason' => 'unexpected_non_array_response'];
                }
                $listingId = $listingId ?: ($live['listing_id'] ?? null);
                $offerId = $offerId ?: ($live['offer_id'] ?? null);
            } catch (Throwable $e) {
                $live = [
                    'ok' => false,
                    'read_only_api_check' => 'failed',
                    'reason' => 'read_only_api_exception',
                    'admin_diagnostics' => [
                        'error_class' => $e::class,
                        'error_message' => $e->getMessage(),
                    ],
                ];
            }
        }
        $imageUrls = $gearbox ? array_values(array_unique(array_merge($gearbox->localizedImageUrls(), $this->jarekPublicHtmlImageUrls($gearbox)))) : [];
        $imageDiagnostics = $this->jarekEbayImageDiagnostics($gearbox, $imageUrls);
        $publicUrl = $live['public_item_url'] ?? null;

        return [
            'ok' => $gearbox !== null,
            'dry_run' => true,
            'read_only' => true,
            'marketplace_write' => false,
            'parts_changed' => false,
            'source_table' => 'jarek_gearboxes',
            'sku' => $sku,
            'inventory_sku' => $inventorySku,
            'offer_id' => $offerId,
            'listing_id' => $listingId,
            'inventory_item_status' => $live['inventory_item_status'] ?? null,
            'offer_status' => $live['offer_status'] ?? null,
            'listing_status' => $live['listing_status'] ?? null,
            'public_item_url' => $publicUrl,
            'is_publicly_visible' => (bool) ($live['is_publicly_visible'] ?? false),
            'live_listing_diagnostics' => $live,
            'public_url_diagnostics' => ['public_item_url' => $publicUrl, 'url_source' => $live['public_item_url_source'] ?? null, 'not_guessed' => true],
            'image_diagnostics' => $imageDiagnostics,
            'blockers' => array_values(array_filter([$gearbox ? null : 'jarek_gearbox_not_found'])),
            'warnings' => array_values(array_filter($imageDiagnostics['warnings'] ?? [])),
        ];
    }

    /** @return array<int, string> */
    private function jarekPublicHtmlImageUrls(JarekGearbox $gearbox): array
    {
        $offerId = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($gearbox->allegro_offer_id ?: ''));
        if (! is_string($offerId) || $offerId === '') return [];

        $directory = dirname(base_path()).'/public_html/storage/jarek-gearboxes/'.$offerId;
        if (! is_dir($directory)) return [];

        $files = array_values(array_filter(scandir($directory) ?: [], fn (string $file): bool => is_file($directory.'/'.$file) && preg_match('/\.(jpe?g|png)$/i', $file)));
        sort($files, SORT_NATURAL);

        return array_map(fn (string $file): string => 'https://gpswiss.pl/storage/jarek-gearboxes/'.$offerId.'/'.$file, $files);
    }

    private function jarekEbayImageDiagnostics(?JarekGearbox $gearbox, array $imageUrls): array
    {
        $checks = [];
        $recommended = [];
        foreach ($imageUrls as $url) {
            $candidates = array_values(array_unique([$url, preg_replace('#^https://gpswiss\\.pl/#', 'https://www.gpswiss.pl/', $url) ?: $url]));
            foreach ($candidates as $candidate) {
                $checks[] = $check = $this->checkJarekPublicImageUrl($gearbox, $candidate);
                if (($check['is_ebay_safe_image_url'] ?? false) && ! in_array($candidate, $recommended, true)) {
                    $recommended[] = $candidate;
                    break;
                }
            }
        }

        return [
            'image_urls_sent_to_ebay' => $imageUrls,
            'image_url_checks' => $checks,
            'recommended_image_urls' => $recommended !== [] ? $recommended : $imageUrls,
            'warnings' => $recommended === [] && $imageUrls !== [] ? ['no_public_ebay_safe_image_url_confirmed'] : [],
        ];
    }

    private function checkJarekPublicImageUrl(?JarekGearbox $gearbox, string $url): array
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $relative = preg_replace('#^/storage/#', '', $path) ?: ltrim($path, '/');
        $storagePath = storage_path('app/public/'.$relative);
        $publicPath = public_path('storage/'.$relative);
        $symlink = public_path('storage');
        try {
            $response = Http::withHeaders(['User-Agent' => 'eBay image diagnostics (+https://gpswiss.pl)'])->withOptions(['allow_redirects' => true])->timeout(20)->get($url);
            $effectiveUrl = method_exists($response, 'handlerStats') ? (string) (($response->handlerStats()['url'] ?? null) ?: $url) : $url;
            $body = $response->body();
            $bodyStart = substr($body, 0, 16);
            $isImageBody = str_starts_with($bodyStart, "\xFF\xD8\xFF") || str_starts_with($bodyStart, "\x89PNG");
            $contentType = (string) ($response->header('content-type') ?: $response->header('Content-Type'));
            $normalizedContentType = strtolower(trim(explode(';', $contentType)[0] ?? ''));
            $hasSafeContentType = in_array($normalizedContentType, ['image/jpeg', 'image/png'], true);
            $isPublicImage = $response->successful() && $hasSafeContentType && $isImageBody;

            return [
                'url' => $url,
                'file_exists' => is_file($storagePath),
                'storage_path' => $storagePath,
                'public_path' => $publicPath,
                'public_storage_symlink' => $symlink,
                'public_storage_is_link' => is_link($symlink),
                'public_storage_realpath' => realpath($symlink) ?: null,
                'http_status' => $response->status(),
                'final_url' => $effectiveUrl,
                'content_type' => $contentType ?: null,
                'content_length' => $response->header('content-length') !== null ? (int) $response->header('content-length') : strlen($body),
                'body_starts_as_jpeg_or_png' => $isImageBody,
                'is_public_image' => $isPublicImage,
                'is_ebay_safe_image_url' => $isPublicImage && str_starts_with($url, 'https://'),
            ];
        } catch (Throwable $e) {
            return [
                'url' => $url,
                'file_exists' => is_file($storagePath),
                'storage_path' => $storagePath,
                'public_path' => $publicPath,
                'public_storage_symlink' => $symlink,
                'public_storage_is_link' => is_link($symlink),
                'public_storage_realpath' => realpath($symlink) ?: null,
                'http_status' => null,
                'final_url' => null,
                'content_type' => null,
                'content_length' => null,
                'body_starts_as_jpeg_or_png' => false,
                'is_public_image' => false,
                'is_ebay_safe_image_url' => false,
                'error' => $e->getMessage(),
            ];
        }
    }


    /** @return array{source_price_pln: ?float, ebay_markup_percent: int, ebay_price_pln: ?float, nbp_exchange_rate: ?float, target_currency: string, price_eur: ?float} */
    private function jarekEbayDePriceDiagnostics(?float $sourcePricePln, ?float $nbpExchangeRate): array
    {
        $ebayPricePln = $sourcePricePln !== null && $sourcePricePln > 0 ? round($sourcePricePln * 1.25, 2) : null;

        return [
            'source_price_pln' => $sourcePricePln,
            'ebay_markup_percent' => 25,
            'ebay_price_pln' => $ebayPricePln,
            'nbp_exchange_rate' => $nbpExchangeRate,
            'target_currency' => 'EUR',
            'price_eur' => $ebayPricePln !== null && $nbpExchangeRate !== null && $nbpExchangeRate > 0 ? round($ebayPricePln / $nbpExchangeRate, 2) : null,
        ];
    }

    private function buildJarekPublicRootDiagnostics(string $relative): array
    {
        $relative = ltrim($relative, '/');
        $urls = [
            'https://gpswiss.pl/storage/'.$relative,
            'https://www.gpswiss.pl/storage/'.$relative,
            'https://gpsystem.thecamels.pl/storage/'.$relative,
            'https://gpsystem.thecamels.pl/app/public/storage/'.$relative,
        ];
        $publicHtml = dirname(base_path()).'/public_html';
        $partSamples = Schema::hasTable('part_images') ? PartImage::query()->where('path', 'like', 'parts/photos/imported/%')->limit(5)->pluck('path')->all() : [];

        return [
            'ok' => true,
            'dry_run' => true,
            'marketplace_write' => false,
            'parts_changed' => false,
            'laravel_paths' => [
                'public_path' => public_path(),
                'base_path' => base_path(),
                'storage_path' => storage_path(),
                'app_url_config' => config('app.url'),
                'app_url_env' => env('APP_URL'),
                'request_host' => request()->getHost(),
                'tested_public_path' => public_path('storage/'.$relative),
            ],
            'candidate_document_roots' => [
                'laravel_public' => public_path(),
                'sibling_public_html' => $publicHtml,
                'sibling_public_html_exists' => is_dir($publicHtml),
                'sibling_public_html_storage_exists' => is_dir($publicHtml.'/storage'),
                'sibling_public_html_storage_realpath' => realpath($publicHtml.'/storage') ?: null,
            ],
            'normal_parts_images' => [
                'sample_paths' => $partSamples,
                'sample_public_urls' => array_map(fn ($path) => $this->publicStorageUrl((string) $path), $partSamples),
                'sample_public_html_paths' => array_map(fn ($path) => $publicHtml.'/storage/'.ltrim((string) $path, '/'), $partSamples),
            ],
            'url_checks' => array_map(fn ($url) => $this->checkJarekPublicImageUrl(null, $url), $urls),
        ];
    }

    private function buildJarekPublicImagesOne(string $sku, bool $apply): array
    {
        $offerId = preg_match('/^JAREK-(.+)$/', trim($sku), $m) ? $m[1] : trim($sku);
        $gearbox = Schema::hasTable('jarek_gearboxes') ? JarekGearbox::query()->where('allegro_offer_id', $offerId)->first() : null;
        $sourceDir = storage_path('app/public/jarek-gearboxes/'.$offerId);
        $targetDir = dirname(base_path()).'/public_html/storage/jarek-gearboxes/'.$offerId;
        $files = is_dir($sourceDir) ? array_values(array_filter(scandir($sourceDir) ?: [], fn ($f) => is_file($sourceDir.'/'.$f) && preg_match('/\.(jpe?g|png)$/i', $f))) : [];
        sort($files);
        $items = [];
        foreach ($files as $file) {
            $source = $sourceDir.'/'.$file;
            $target = $targetDir.'/'.$file;
            $copied = false;
            $error = null;
            if ($apply && ! is_file($target)) {
                if (! is_dir($targetDir)) @mkdir($targetDir, 0755, true);
                $copied = @copy($source, $target);
                if (! $copied) $error = 'copy_failed';
            }
            $url = 'https://gpswiss.pl/storage/jarek-gearboxes/'.$offerId.'/'.$file;
            $items[] = [
                'source_local_path' => $source,
                'target_public_path' => $target,
                'target_public_url' => $url,
                'source_file_exists' => is_file($source),
                'target_file_exists_before_or_after' => is_file($target),
                'target_already_exists' => is_file($target) && ! $copied,
                'copied' => $copied,
                'idempotent' => true,
                'http_check' => $this->checkJarekPublicImageUrl($gearbox, $url),
                'error' => $error,
            ];
        }

        return [
            'ok' => $gearbox !== null && is_dir($sourceDir),
            'dry_run' => ! $apply,
            'applied' => $apply,
            'marketplace_write' => false,
            'parts_changed' => false,
            'sku' => 'JAREK-'.$offerId,
            'allegro_offer_id' => $offerId,
            'source_local_path' => $sourceDir,
            'target_public_path' => $targetDir,
            'target_public_url_base' => 'https://gpswiss.pl/storage/jarek-gearboxes/'.$offerId,
            'source_directory_exists' => is_dir($sourceDir),
            'target_directory_exists' => is_dir($targetDir),
            'files' => $items,
            'safe_to_batch_later' => false,
            'safety' => ['single_sku_only', 'no_delete', 'no_allegro_download', 'no_parts_write', 'no_ebay_api_write'],
        ];
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
        $conditionDiagnostics = is_array($payload['condition_diagnostics'] ?? null) ? $payload['condition_diagnostics'] : [];
        $condition = $conditionDiagnostics['mapped_ebay_condition'] ?? null;

        $aspects = [];
        foreach ($itemSpecifics as $name => $value) {
            if (! filled($name) || ! filled($value)) continue;
            $aspects[(string) $name] = [trim((string) $value)];
        }

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
        if (blank($sku) || blank($inventoryItemRequest['product']['title']) || $imageUrls === [] || ! is_int($quantity) || $quantity <= 0) $blockers[] = 'missing_inventory_item_request_fields';
        if (blank($condition) || ! ($conditionDiagnostics['condition_mapping_valid'] ?? false)) $blockers[] = 'missing_or_invalid_ebay_condition';
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
            'source_condition_name' => $conditionDiagnostics['source_condition_name'] ?? null,
            'source_condition_value' => $conditionDiagnostics['source_condition_value'] ?? null,
            'source_condition_parameter_id' => $conditionDiagnostics['source_condition_parameter_id'] ?? null,
            'condition_source' => $conditionDiagnostics['condition_source'] ?? null,
            'condition_mapping_reason' => $conditionDiagnostics['condition_mapping_reason'] ?? null,
            'mapped_ebay_condition' => $conditionDiagnostics['mapped_ebay_condition'] ?? null,
            'condition_mapped_value' => $conditionDiagnostics['mapped_ebay_condition'] ?? null,
            'condition_diagnostics' => $conditionDiagnostics,
        ], 'blockers' => array_values(array_unique($blockers))];
    }


    /** @return array<string, mixed> */
    private function jarekEbayConditionDiagnostics(JarekGearbox $gearbox): array
    {
        $candidate = $this->findJarekConditionParameter($gearbox);
        $value = is_string($candidate['value'] ?? null) ? (string) $candidate['value'] : '';
        $mapped = filled($value) ? (new EbayConditionMapper())->jarekCondition($value, (string) ($candidate['source'] ?? 'jarek_gearboxes.parameters')) : null;
        $valid = (bool) ($mapped['condition_mapping_valid'] ?? false);

        return [
            'source_condition_name' => $candidate['name'] ?? null,
            'source_condition_value' => $candidate['value'] ?? null,
            'source_condition_parameter_id' => $candidate['parameter_id'] ?? null,
            'condition_source' => $candidate['source'] ?? null,
            'condition_mapping_reason' => $valid ? (string) ($mapped['condition_mapping_used'] ?? 'localized_condition_map') : (filled($value) ? 'source_condition_value_not_mapped' : 'source_condition_parameter_not_found'),
            'mapped_ebay_condition' => $valid ? ($mapped['condition'] ?? null) : null,
            'condition_mapping_valid' => $valid,
            'condition_inventory_api_format' => $mapped['condition_inventory_api_format'] ?? 'string_enum',
            'condition_allowed_values' => $mapped['condition_allowed_values'] ?? [],
        ];
    }

    private function jarekEbayTemplateConditionLabel(?string $condition): string
    {
        return match ($condition) {
            'SELLER_REFURBISHED' => 'Generalüberholt',
            'USED' => 'Gebraucht',
            'NEW' => 'Neu',
            default => 'Gebraucht',
        };
    }

    /** @return array<string, mixed> */
    private function findJarekConditionParameter(JarekGearbox $gearbox): array
    {
        foreach ([
            'parameters' => $gearbox->parameters,
            'raw_payload.parameters' => data_get($gearbox->raw_payload, 'parameters'),
            'raw_payload.publication.marketplaces' => data_get($gearbox->raw_payload, 'publication.marketplaces'),
            'category_payload.parameters' => data_get($gearbox->category_payload, 'parameters'),
            'category_payload' => $gearbox->category_payload,
            'raw_payload' => $gearbox->raw_payload,
        ] as $source => $data) {
            $found = $this->findConditionInValue($data, $source);
            if ($found !== null) return $found;
        }

        return ['name' => null, 'value' => null, 'parameter_id' => null, 'source' => null];
    }

    /** @return array<string, mixed>|null */
    private function findConditionInValue(mixed $value, string $source): ?array
    {
        if (! is_array($value)) return null;

        $name = $value['name'] ?? $value['label'] ?? null;
        $id = $value['id'] ?? $value['parameterId'] ?? $value['parameter_id'] ?? null;
        if (is_string($name) && preg_match('/^(stan|kondycja|condition)$/iu', trim($name))) {
            $conditionValue = $this->extractJarekParameterValue($value);
            if (filled($conditionValue)) return ['name' => $name, 'value' => $conditionValue, 'parameter_id' => $id, 'source' => $source];
        }

        foreach ($value as $child) {
            $found = $this->findConditionInValue($child, $source);
            if ($found !== null) return $found;
        }

        return null;
    }

    private function extractJarekParameterValue(array $parameter): ?string
    {
        foreach (['valuesLabels', 'values', 'valueLabels', 'value', 'valuesIds'] as $key) {
            $value = $parameter[$key] ?? null;
            if (is_array($value)) $value = reset($value);
            if (is_scalar($value) && filled((string) $value)) return trim((string) $value);
        }

        return null;
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
        if (preg_match('/(?:reduktor\s+skrzyni(?:\s+biegów)?|skrzyni(?:a|ę)?\s+biegów|skrzyn[iy])/iu', $title)) {
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

    private function insertCoreReturnNoticeInDescriptionSection(string $html, ?string $notice): string
    {
        if (! filled($notice) || $this->containsNotice($html, $notice)) return $html;

        $paragraph = '<p style="margin:14px 0 0;color:#111827;font-size:16px;line-height:1.7;text-align:center;font-weight:700;">'.e((string) $notice).'</p>';
        $descriptionSectionPattern = '/(<div style="border:1px solid #dbe3ef;border-radius:8px;overflow:hidden;background:#ffffff;margin:0 0 22px;">\s*<div style="background:#06275d;color:#ffffff;padding:15px 17px;font-size:18px;font-weight:900;letter-spacing:\.2px;text-align:center;">Beschreibung<\/div>\s*<div style="padding:20px 22px;text-align:center;">.*?)(<\/div>\s*<\/div>)/su';

        $updated = preg_replace($descriptionSectionPattern, '$1'.$paragraph.'$2', $html, 1, $count);

        return $count === 1 && is_string($updated) ? $updated : $html;
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

    private function logJarekEbayDeDiagnostic(string $status, string $message, array $payload): void
    {
        if (! Schema::hasTable('marketplace_sync_logs')) return;

        MarketplaceSyncLog::query()->create([
            'marketplace' => 'ebay_de',
            'action' => $payload['action'] ?? 'jarek_gearboxes_ebay_de_listing_diagnostics',
            'status' => $status,
            'message' => $message,
            'external_id' => $payload['offer_id'] ?? $payload['sku'] ?? null,
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


    private function buildJarekStorageLinkDiagnostics(bool $apply): array
    {
        $linkPath = public_path('storage');
        $absoluteTarget = storage_path('app/public');
        $relativeTarget = '../storage/app/public';
        $testUrl = 'https://gpswiss.pl/storage/jarek-gearboxes/18727785496/01.jpg';

        clearstatcache(true, $linkPath);
        clearstatcache(true, $absoluteTarget);

        $exists = file_exists($linkPath) || is_link($linkPath);
        $isLink = is_link($linkPath);
        $linkTarget = $isLink ? readlink($linkPath) : null;
        $type = $isLink ? 'symlink' : (is_dir($linkPath) ? 'directory' : (is_file($linkPath) ? 'file' : ($exists ? 'other' : 'missing')));
        $targetExists = is_dir($absoluteTarget);
        $parentWritable = is_writable(dirname($linkPath));
        $alreadyCorrect = $isLink && realpath($linkPath) !== false && realpath($linkPath) === realpath($absoluteTarget);
        $canCreate = ! $exists && $targetExists && $parentWritable;
        $blockedReason = null;

        if (! $alreadyCorrect && ! $canCreate) {
            $blockedReason = $exists
                ? 'public/storage already exists and is not the expected storage symlink; it was not removed automatically'
                : (! $targetExists ? 'storage/app/public target does not exist' : 'public directory is not writable');
        }

        $created = false;
        $error = null;
        if ($apply && $canCreate) {
            try {
                $created = symlink($relativeTarget, $linkPath);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
            clearstatcache(true, $linkPath);
            $isLink = is_link($linkPath);
            $linkTarget = $isLink ? readlink($linkPath) : null;
            $type = $isLink ? 'symlink' : (is_dir($linkPath) ? 'directory' : (is_file($linkPath) ? 'file' : ((file_exists($linkPath) || is_link($linkPath)) ? 'other' : 'missing')));
            $alreadyCorrect = $isLink && realpath($linkPath) !== false && realpath($linkPath) === realpath($absoluteTarget);
        }

        $httpCheck = $this->checkJarekPublicImageUrl(null, $testUrl);
        $ok = $alreadyCorrect && $httpCheck['is_ebay_safe_image_url'] === true;

        return [
            'ok' => $ok,
            'mode' => $apply ? 'apply' : 'dry_run',
            'marketplace_write' => false,
            'parts_changed' => false,
            'ebay_write' => false,
            'message' => $ok ? 'Publiczny Laravel storage link działa i testowy URL zwraca publiczny obraz.' : ($blockedReason ?: 'Storage link nie został jeszcze potwierdzony jako publicznie dostępny obraz.'),
            'public_storage' => [
                'path' => $linkPath,
                'exists' => file_exists($linkPath) || is_link($linkPath),
                'type' => $type,
                'is_link' => $isLink,
                'link_target' => $linkTarget,
                'realpath' => realpath($linkPath) ?: null,
            ],
            'planned_symlink' => [
                'link' => $linkPath,
                'target' => $relativeTarget,
                'absolute_target' => $absoluteTarget,
                'target_exists' => $targetExists,
                'parent_writable' => $parentWritable,
                'already_correct' => $alreadyCorrect,
                'can_safely_create_symlink' => $canCreate,
                'blocked_reason' => $blockedReason,
                'created' => $created,
                'error' => $error,
            ],
            'can_safely_create_symlink' => $canCreate,
            'test_url' => $testUrl,
            'test_url_check' => $httpCheck,
        ];
    }

    private function logJarekStorageLink(string $status, string $message, array $payload): void
    {
        if (! Schema::hasTable('marketplace_sync_logs')) {
            return;
        }

        MarketplaceSyncLog::query()->create([
            'marketplace' => 'local',
            'action' => 'jarek_gearboxes_storage_link',
            'status' => $status,
            'message' => $message,
            'payload' => $payload,
            'created_at' => now(),
        ]);
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
