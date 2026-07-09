<?php

use App\Http\Controllers\Admin\ImportMigration\PartImagePresentationController;
use App\Http\Controllers\Admin\LocalSaleController;
use App\Http\Controllers\Admin\LocalSaleEndMarketplacesController;
use App\Http\Controllers\Admin\MarketplacePartDebugController;
use App\Http\Controllers\Admin\MarketplaceRelistPartController;
use App\Http\Controllers\Admin\AllegroDuplicateCheckController;
use App\Http\Controllers\Admin\EbayDePreviewController;
use App\Http\Controllers\Admin\MarketplaceCategoryMapperController;
use App\Http\Controllers\Admin\Allegro\AllegroOAuthController;
use App\Http\Controllers\Admin\Ebay\EbayOAuthController;
use App\Http\Controllers\Admin\JarekGearboxes\JarekAllegroOAuthController;
use App\Http\Controllers\Admin\JarekGearboxes\JarekGearboxToolController;
use App\Http\Controllers\Admin\MarketplaceOAuthTokenHealthController;
use App\Http\Controllers\Admin\PartSearchController;
use App\Http\Controllers\Admin\PartLocalAvailabilityController;
use App\Http\Controllers\Admin\ImportMigration\WooCategoryTreeController;
use App\Http\Controllers\Admin\ImportMigration\WooProductImportRunController;
use App\Http\Controllers\Admin\ImportMigration\WooStoragePublicController;
use App\Http\Controllers\OvokoPublicPhotoController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Controllers\Storefront\CategoryController;
use App\Http\Controllers\Storefront\ContactController;
use App\Http\Controllers\Storefront\Auth\CustomerAuthController;
use App\Http\Controllers\Storefront\Auth\GoogleAuthController;
use App\Http\Controllers\Storefront\Auth\PasswordResetController;
use App\Http\Controllers\Storefront\CustomerAccountController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\PartController;
use App\Http\Controllers\Storefront\PayuReturnController;
use App\Http\Controllers\Payments\PayuNotifyController;
use App\Http\Controllers\Storefront\PrivacyPolicyController;
use App\Http\Controllers\Storefront\SearchController;
use App\Http\Controllers\Storefront\TermsController;
use App\Http\Controllers\Tools\CheckOrdersFlowController;
use App\Http\Controllers\Tools\PayuDiagnosticsController;
use App\Http\Controllers\Tools\DebugOrderItemThumbnailController;
use App\Http\Controllers\Tools\DhlConfigDiagnoseController;
use App\Http\Controllers\Tools\WorkshopQuickPartController;
use App\Http\Controllers\Tools\BackfillPartDefaultConditionAndSteeringController;
use App\Http\Controllers\Tools\BackfillPartMarketplacePricesController;
use App\Http\Controllers\Tools\WorkshopImageDiagnosticsController;
use App\Http\Controllers\Tools\CheckOvokoApiSettingsController;
use App\Http\Controllers\Tools\MarketplaceApiSettingsDiagnosticsController;
use App\Http\Controllers\Tools\MarketplaceApiFoundationController;
use App\Http\Controllers\Tools\MarketplaceListingDryRunController;
use App\Http\Controllers\Tools\AllegroCompatibilityDryRunController;
use App\Http\Controllers\Tools\AllegroMarketplaceDiagnoseController;
use App\Http\Controllers\Tools\MarketplaceListingImageRefreshController;
use App\Http\Controllers\Tools\OvokoCarMappingController;
use App\Http\Controllers\Tools\OvokoCrossChannelDiagnoseController;
use App\Http\Controllers\Tools\OvokoOrderItemPartBackfillController;
use App\Http\Controllers\Tools\MarketplaceListingUrlBackfillController;
use App\Http\Controllers\Tools\MarketplaceLinkRepairController;
use App\Http\Controllers\Tools\EbayListingAuditController;
use App\Http\Controllers\Tools\EbayDescriptionAuditController;
use App\Http\Controllers\Tools\EbayListingAuditRunnerController;
use App\Http\Controllers\Tools\EbayMarketplaceDiagnoseController;
use App\Http\Controllers\Tools\MarketplaceCategoryTreeImportController;
use App\Http\Controllers\Tools\EbayListingDryRunController;
use App\Http\Controllers\Tools\CheckOvokoMappingController;
use App\Http\Controllers\Tools\DebugOvokoPartMatchController;
use App\Http\Controllers\Tools\DebugPartCategorySuggestionController;
use App\Http\Controllers\Tools\AuditPartCategorySuggestionsController;
use App\Http\Controllers\Tools\DeleteAllegroGearboxesDryRunController;
use App\Http\Controllers\Tools\DeleteAllegroGearboxesLiveController;
use App\Http\Controllers\Tools\PurgeAllegroGearboxesDryRunController;
use App\Http\Controllers\Tools\PurgeAllegroGearboxesLiveController;
use App\Http\Controllers\Tools\ExportOvokoUnmappedController;
use App\Http\Controllers\Tools\ExportOvokoOrdersUnmatchedController;
use App\Http\Controllers\Tools\CheckPartImagePresentationController;
use App\Http\Controllers\Tools\CheckProductImageController;
use App\Http\Controllers\Tools\CheckStorefrontVisibilityController;
use App\Http\Controllers\Tools\TestOvokoApiConnectionController;
use App\Http\Controllers\Tools\FetchOvokoPartStatusDictionaryController;
use App\Http\Controllers\Tools\OvokoOrdersDryRunController;
use App\Http\Controllers\Tools\OvokoPriceImportController;
use App\Http\Controllers\Tools\OvokoProductSyncController;
use App\Http\Controllers\Tools\OvokoStockReconciliationController;
use App\Http\Controllers\Tools\OvokoStockSyncController;
use App\Http\Controllers\Tools\OvokoStockSyncRunnerController;
use App\Http\Controllers\Tools\OvokoListingUrlBackfillController;
use App\Http\Controllers\Tools\OvokoListingUrlDiagnosticsController;
use App\Http\Controllers\Tools\OvokoMarketplaceDiagnoseController;
use App\Http\Controllers\Tools\OvokoListingDiagnoseController;
use App\Http\Controllers\Tools\OvokoUnlinkStaleListingController;
use App\Http\Controllers\Tools\OvokoLinkedProductsCheckController;
use App\Http\Controllers\Tools\OvokoSoldMappingCheckController;
use App\Http\Controllers\Tools\OvokoImportProductDataController;
use App\Http\Controllers\Tools\MarketplaceMappingGapsExportController;
use App\Http\Controllers\Tools\MarketplaceOrdersResetController;
use App\Http\Controllers\Tools\MarketplaceOrdersSyncController;
use App\Http\Controllers\Tools\MarketplaceOrdersPurgeToolController;
use App\Http\Controllers\Tools\MarketplaceFulfillmentSyncToolController;
use App\Http\Controllers\Tools\MarketplaceOrderFulfillmentSyncController;
use App\Http\Controllers\Tools\MarketplaceOrdersTimezoneFixController;
use App\Http\Controllers\Tools\ManualLinkMappingDiagnosticsController;
use App\Http\Controllers\Tools\ManualLinkMappingReplaceController;
use App\Http\Controllers\Tools\PartMarketplaceReadinessController;
use App\Http\Controllers\Tools\PartsToListStorageLocationBackfillController;
use App\Http\Controllers\Tools\MarketplacePublishPartController;
use App\Http\Controllers\Tools\ImportOvokoOrdersDryRunController;
use App\Http\Controllers\Tools\CheckPartNumberPerformanceController;
use App\Http\Controllers\Tools\CheckPartsToListController;
use App\Http\Controllers\Tools\CheckAllegroChannelsController;
use App\Http\Controllers\Tools\CheckAllegroOAuthReadinessController;
use App\Http\Controllers\Tools\ExportAllegroOfferIdCoverageController;
use App\Http\Controllers\Tools\CheckAllegroOfferIdCoverageController;
use App\Http\Controllers\Tools\CheckAllegroLocalIdSourcesController;
use App\Http\Controllers\Tools\SuggestAllegroCategoryMappingsFromLegacyCsvController;
use App\Http\Controllers\Tools\SuggestOvokoCategoryMappingsFromLocalTreeController;
use App\Http\Controllers\Tools\WarehouseImportFromAllegroCsvController;
use App\Http\Controllers\Tools\SetOvokoCategoryMappingsBatchController;
use App\Http\Controllers\Tools\CheckAllegroProductMappingCandidatesController;
use App\Http\Controllers\Tools\ExportAllegroProductMappingCandidatesController;
use App\Http\Controllers\Tools\CheckAdminPartsTableUiController;
use App\Http\Controllers\Tools\CheckCatalogSearchController;
use App\Http\Controllers\Tools\CheckCatalogRenderController;
use App\Http\Controllers\Tools\CheckCatalogViewController;
use App\Http\Controllers\Tools\CheckDomainHardcodedLinksController;
use App\Http\Controllers\Tools\DebugCategoryMarketplaceMappingsController;
use App\Http\Controllers\Tools\CheckEbayLegacyCategoryMappingsController;
use App\Http\Controllers\Tools\CheckEbayLegacyShippingMappingsController;
use App\Http\Controllers\Tools\EbayLegacyCategoryMappingImportController;
use App\Http\Controllers\Tools\EbayCategoryShippingPolicyCsvImportController;
use App\Http\Controllers\Tools\CheckFrontendMaintenanceController;
use App\Http\Controllers\Tools\PostDomainSwitchCheckController;
use App\Http\Controllers\Tools\CheckCatalogViewStageController;
use App\Http\Controllers\Tools\CheckCatalogErrorController;
use App\Http\Controllers\Tools\LastLaravelErrorController;
use App\Http\Controllers\Tools\MarkGpsGmailToListDryRunController;
use App\Http\Controllers\Tools\MarkGpsGmailToListLiveController;
use App\Http\Controllers\Tools\FinalizeDomainSwitchController;
use App\Http\Controllers\Tools\FixImportedImagesPublicFilesController;
use App\Http\Controllers\Tools\GpswissPublicHtmlController;
use App\Http\Controllers\Tools\GoogleTranslateDiagnosticsController;
use App\Http\Controllers\Tools\EbayDescriptionTemplateController;
use App\Http\Controllers\Tools\ImportedImagesStorageReportController;
use App\Http\Controllers\Tools\InspectLegacyPayloadKeysController;
use App\Http\Controllers\Tools\PhotoStorageReportController;
use App\Http\Controllers\Tools\PreDomainSwitchCheckController;
use App\Http\Controllers\Tools\ProductImagesDryRunController;
use App\Http\Controllers\Tools\ProductImagesImportController;
use App\Http\Controllers\Tools\ProductImagesImportRunnerController;
use App\Http\Controllers\Tools\ProcessPartImagePresentationController;
use App\Http\Controllers\Tools\ProcessPartImagePresentationRunnerController;
use App\Http\Controllers\Tools\RunOvokoMappingDryRunController;
use App\Http\Controllers\Tools\RunOvokoMappingLiveController;
use App\Http\Controllers\Tools\RunAllegroMappingDryRunController;
use App\Http\Controllers\Tools\RunAllegroMappingLiveController;
use App\Http\Controllers\Tools\RunEbayMappingDryRunController;
use App\Http\Controllers\Tools\RunEbayMappingLiveController;
use App\Http\Controllers\Tools\RefreshAllegroActiveOfferLinksController;
use App\Http\Controllers\Tools\RefreshAllegroActiveOfferLinksDryRunController;
use App\Http\Controllers\Tools\CheckAdminMarketplaceStatusUiController;
use App\Http\Controllers\Tools\AllegroListingReadOnlyStatusController;
use App\Http\Controllers\Tools\SetFrontendMaintenanceController;
use App\Http\Controllers\Tools\DeleteTestMarketplaceOrdersController;
use App\Http\Controllers\Tools\ImportMarketplaceOrdersController;
use App\Http\Controllers\Tools\ShipmentToolsController;
use App\Services\ImportMigration\WooProductImport;
use App\Support\ImportMigration\ManualImportFileResolver;
use App\Support\ImportMigration\WooProductImportRunRepository;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('storefront.home');
Route::get('/api-info', fn () => response()->view('storefront.api-info'))->name('storefront.api-info');
Route::get('/admin/brand/logo.png', function () {
    $candidates = [
        storage_path('app/imports/logo.png'),
        base_path('app/storage/app/imports/logo.png'),
        dirname(base_path()).'/public_html/storage/brand/logo.png',
        public_path('storage/brand/logo.png'),
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            return response()->file($path, [
                'Cache-Control' => 'public, max-age=3600',
                'Content-Type' => 'image/png',
            ]);
        }
    }

    abort(404);
})->name('admin.brand.logo');

Route::get('/marketplace/ovoko/photos/{partImage}/{signature}/{filename}', OvokoPublicPhotoController::class)
    ->where(['signature' => '[A-Fa-f0-9]{24}', 'filename' => '[A-Za-z0-9._-]+'])
    ->name('marketplace.ovoko.photos.show');

$serveEbayTemplateAsset = function (string $filename) {
    $allowed = ['icon-shipping.png', 'icon-returns.png', 'icon-packaging.png', 'icon-original.png', 'europe-map.png', 'dhl-logo.png', 'dpd-logo.png'];

    if (! in_array($filename, $allowed, true)) {
        abort(404);
    }

    $candidates = [
        storage_path('app/imports/ebay-template/'.$filename),
        storage_path('app/imports/'.$filename),
    ];

    $path = collect($candidates)->first(fn (string $candidate): bool => is_file($candidate));

    if (! $path) {
        abort(404);
    }

    return response()->file($path, [
        'Cache-Control' => 'public, max-age=86400',
        'Content-Type' => 'image/png',
    ]);
};

Route::get('/ebay-template/assets/{filename}', $serveEbayTemplateAsset)
    ->where('filename', '[A-Za-z0-9._-]+\.png')
    ->name('ebay-template.asset');

Route::get('/wp-content/uploads/ebay-template/{filename}', $serveEbayTemplateAsset)
    ->where('filename', '[A-Za-z0-9._-]+\.png')
    ->name('ebay-template.asset.legacy');
Route::get('/sklep', fn (Request $request) => redirect()->route('storefront.catalog', $request->query(), 301))->name('storefront.shop.legacy');
Route::get('/czesci', [CatalogController::class, 'index'])->name('storefront.catalog');
Route::get('/szukaj', [SearchController::class, 'index'])->name('storefront.search');
Route::get('/koszyk', [CartController::class, 'index'])->name('storefront.cart.index');

Route::get('/login', fn () => redirect()->route('storefront.login'))->name('login');
Route::get('/logowanie', [CustomerAuthController::class, 'loginForm'])->name('storefront.login');
Route::get('/admin/csrf-token', fn (): JsonResponse => response()->json(['token' => csrf_token()]))
    ->name('admin.csrf-token');

Route::post('/logowanie', [CustomerAuthController::class, 'login'])->name('storefront.login.store');
Route::get('/rejestracja', [CustomerAuthController::class, 'registerForm'])->name('storefront.register');
Route::post('/rejestracja', [CustomerAuthController::class, 'register'])->name('storefront.register.store');
Route::post('/wyloguj', [CustomerAuthController::class, 'logout'])->name('storefront.logout');
Route::get('/przypomnij-haslo', [PasswordResetController::class, 'requestForm'])->name('password.request');
Route::post('/przypomnij-haslo', [PasswordResetController::class, 'sendLink'])->name('password.email');
Route::get('/reset-hasla/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
Route::post('/reset-hasla', [PasswordResetController::class, 'reset'])->name('password.update');
Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('storefront.google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('storefront.google.callback');
Route::middleware('auth')->group(function (): void {
    Route::get('/moje-konto', CustomerAccountController::class)->name('storefront.account');
    Route::patch('/moje-konto/dane', [CustomerAccountController::class, 'update'])->name('storefront.account.update');
    Route::post('/moje-konto/zwroty', [CustomerAccountController::class, 'storeReturn'])->name('storefront.account.returns.store');
});

Route::get('/kontakt', [ContactController::class, 'show'])->name('storefront.contact');
Route::post('/kontakt', [ContactController::class, 'send'])->name('storefront.contact.send');
Route::get('/regulamin', TermsController::class)->name('storefront.terms');
Route::get('/polityka-prywatnosci', PrivacyPolicyController::class)->name('storefront.privacy-policy');
Route::post('/koszyk/dodaj/{part}', [CartController::class, 'add'])->name('storefront.cart.add');
Route::post('/koszyk/aktualizuj', [CartController::class, 'update'])->name('storefront.cart.update');
Route::post('/koszyk/usun/{part}', [CartController::class, 'remove'])->name('storefront.cart.remove');
Route::post('/koszyk/wyczysc', [CartController::class, 'clear'])->name('storefront.cart.clear');
Route::get('/zamowienie', [CheckoutController::class, 'show'])->name('storefront.checkout.show');
Route::post('/zamowienie', [CheckoutController::class, 'store'])->name('storefront.checkout.store');
Route::get('/zamowienie/dziekujemy/{order}', [CheckoutController::class, 'thankYou'])->name('storefront.checkout.thank-you');
Route::get('/zamowienie/payu/powrot', PayuReturnController::class)->name('storefront.checkout.payu-return');
Route::post('/payu/notify', PayuNotifyController::class)->name('payu.notify');
Route::get('/produkt/{slug}', [PartController::class, 'show'])->name('storefront.product');
Route::get('/kategoria-produktu/{path}', [CategoryController::class, 'show'])->where('path', '.*')->name('storefront.category');

Route::middleware([Authenticate::class])->group(function (): void {
    Route::get('/warsztat', [WorkshopQuickPartController::class, 'createAuthenticated'])->name('workshop.quick-part-create');
    Route::get('/warsztat/storage-locations', [WorkshopQuickPartController::class, 'storageLocationAutocomplete'])->name('workshop.storage-locations.autocomplete');
    Route::post('/warsztat', [WorkshopQuickPartController::class, 'storeAuthenticated'])->name('workshop.quick-part-create.store');

    Route::get('/admin/allegro/oauth/redirect', [AllegroOAuthController::class, 'redirect'])->name('admin.allegro.oauth.redirect');
    Route::get('/admin/allegro/oauth/callback', [AllegroOAuthController::class, 'callback'])->name('admin.allegro.oauth.callback');
    Route::get('/admin/ebay/oauth/redirect', [EbayOAuthController::class, 'redirect'])->name('admin.ebay.oauth.redirect');
    Route::get('/admin/ebay/oauth/callback', [EbayOAuthController::class, 'callback'])->name('admin.ebay.oauth.callback');
    Route::get('/admin/tools/parts-to-list/storage-location-backfill-dry-run', [PartsToListStorageLocationBackfillController::class, 'dryRun'])->name('admin.tools.parts-to-list.storage-location-backfill-dry-run');
    Route::match(['get', 'post'], '/admin/tools/parts-to-list/storage-location-backfill-apply', [PartsToListStorageLocationBackfillController::class, 'apply'])->name('admin.tools.parts-to-list.storage-location-backfill-apply');
    Route::get('/admin/tools/parts-to-list/storage-location-backfill-results', [PartsToListStorageLocationBackfillController::class, 'results'])->name('admin.tools.parts-to-list.storage-location-backfill-results');
    Route::get('/admin/tools/payu-diagnostics', PayuDiagnosticsController::class)->name('admin.tools.payu-diagnostics');
    Route::get('/admin/tools/dhl/config-diagnose', DhlConfigDiagnoseController::class)->name('admin.tools.dhl.config-diagnose');
    Route::get('/admin/tools/jarek-gearboxes/ping', [JarekGearboxToolController::class, 'ping'])->name('admin.tools.jarek-gearboxes.ping');
    Route::get('/admin/tools/jarek-gearboxes/allegro-import-runner', [JarekGearboxToolController::class, 'runner'])->name('admin.tools.jarek-gearboxes.allegro-import-runner');
    Route::get('/admin/tools/jarek-gearboxes/runner', [JarekGearboxToolController::class, 'jarekRunner'])->name('admin.tools.jarek-gearboxes.runner');
    Route::get('/admin/tools/jarek-gearboxes/image-import-runner-batch', [JarekGearboxToolController::class, 'imageImportRunnerBatch'])->name('admin.tools.jarek-gearboxes.image-import-runner-batch');
    Route::get('/admin/tools/jarek-gearboxes/ebay-prepare-runner-batch', [JarekGearboxToolController::class, 'ebayPrepareRunnerBatch'])->name('admin.tools.jarek-gearboxes.ebay-prepare-runner-batch');
    Route::get('/admin/tools/jarek-gearboxes/publish-runner', [JarekGearboxToolController::class, 'publishRunner'])->name('admin.tools.jarek-gearboxes.publish-runner');
    Route::match(['get', 'post'], '/admin/tools/jarek-gearboxes/publish-runner-batch', [JarekGearboxToolController::class, 'publishRunnerBatch'])->name('admin.tools.jarek-gearboxes.publish-runner-batch');
    Route::get('/admin/tools/jarek-gearboxes/publish-runner-scan-batch', [JarekGearboxToolController::class, 'publishRunnerScanBatch'])->name('admin.tools.jarek-gearboxes.publish-runner-scan-batch');
    Route::get('/admin/tools/jarek-gearboxes/allegro-import-dry-run', [JarekGearboxToolController::class, 'dryRun'])->name('admin.tools.jarek-gearboxes.allegro-import-dry-run');
    Route::get('/admin/tools/jarek-gearboxes/parts-import-dry-run', [JarekGearboxToolController::class, 'partsImportDryRun'])->name('admin.tools.jarek-gearboxes.parts-import-dry-run');
    Route::get('/admin/tools/jarek-gearboxes/parts-import-apply', [JarekGearboxToolController::class, 'partsImportApply'])->name('admin.tools.jarek-gearboxes.parts-import-apply');
    Route::get('/admin/tools/jarek-gearboxes/allegro-import-apply', [JarekGearboxToolController::class, 'apply'])->name('admin.tools.jarek-gearboxes.allegro-import-apply');
    Route::get('/admin/tools/jarek-gearboxes/import-status', [JarekGearboxToolController::class, 'status'])->name('admin.tools.jarek-gearboxes.import-status');
    Route::get('/admin/tools/jarek-gearboxes/allegro-oauth/start', [JarekAllegroOAuthController::class, 'start'])->name('admin.tools.jarek-gearboxes.allegro-oauth.start');
    Route::get('/admin/tools/jarek-gearboxes/allegro-oauth/callback', [JarekAllegroOAuthController::class, 'callback'])->name('admin.tools.jarek-gearboxes.allegro-oauth.callback');
    Route::get('/admin/tools/jarek-gearboxes/ebay-csv-preview', [JarekGearboxToolController::class, 'ebayCsvPreview'])->name('admin.tools.jarek-gearboxes.ebay-csv-preview');
    Route::get('/admin/tools/jarek-gearboxes/ebay-de-prepare-preview', [JarekGearboxToolController::class, 'ebayDePreparePreview'])->name('admin.tools.jarek-gearboxes.ebay-de-prepare-preview');
    Route::get('/admin/tools/jarek-gearboxes/ebay-de-bulk-prepare-preview', [JarekGearboxToolController::class, 'ebayDeBulkPreparePreview'])->name('admin.tools.jarek-gearboxes.ebay-de-bulk-prepare-preview');
    Route::get('/admin/tools/jarek-gearboxes/ebay-de-bulk-prepare-publish-preview', [JarekGearboxToolController::class, 'ebayDeBulkPreparePublishPreview'])->name('admin.tools.jarek-gearboxes.ebay-de-bulk-prepare-publish-preview');
    Route::get('/admin/tools/jarek-gearboxes/ebay-de-publish-runner-plan', [JarekGearboxToolController::class, 'ebayDePublishRunnerPlan'])->name('admin.tools.jarek-gearboxes.ebay-de-publish-runner-plan');
    Route::match(['get', 'post'], '/admin/tools/jarek-gearboxes/ebay-de-publish-runner-pause', [JarekGearboxToolController::class, 'ebayDePublishRunnerPause'])->name('admin.tools.jarek-gearboxes.ebay-de-publish-runner-pause');
    Route::get('/admin/tools/jarek-gearboxes/ebay-de-publish-preview', [JarekGearboxToolController::class, 'ebayDePublishPreview'])->name('admin.tools.jarek-gearboxes.ebay-de-publish-preview');
    Route::match(['get', 'post'], '/admin/tools/jarek-gearboxes/ebay-de-publish-apply', [JarekGearboxToolController::class, 'ebayDePublishApply'])->name('admin.tools.jarek-gearboxes.ebay-de-publish-apply');
    Route::get('/admin/tools/jarek-gearboxes/ebay-de-listing-diagnostics', [JarekGearboxToolController::class, 'ebayDeListingDiagnostics'])->name('admin.tools.jarek-gearboxes.ebay-de-listing-diagnostics');
    Route::get('/admin/tools/jarek-gearboxes/ebay-de-revise-preview', [JarekGearboxToolController::class, 'ebayDeRevisePreview'])->name('admin.tools.jarek-gearboxes.ebay-de-revise-preview');
    Route::match(['get', 'post'], '/admin/tools/jarek-gearboxes/ebay-de-revise-apply', [JarekGearboxToolController::class, 'ebayDeReviseApply'])->name('admin.tools.jarek-gearboxes.ebay-de-revise-apply');
    Route::get('/admin/tools/jarek-gearboxes/storage-link-diagnostics', [JarekGearboxToolController::class, 'storageLinkDiagnostics'])->name('admin.tools.jarek-gearboxes.storage-link-diagnostics');
    Route::match(['get', 'post'], '/admin/tools/jarek-gearboxes/storage-link-apply', [JarekGearboxToolController::class, 'storageLinkApply'])->name('admin.tools.jarek-gearboxes.storage-link-apply');
    Route::get('/admin/tools/jarek-gearboxes/public-root-diagnostics', [JarekGearboxToolController::class, 'publicRootDiagnostics'])->name('admin.tools.jarek-gearboxes.public-root-diagnostics');
    Route::get('/admin/tools/jarek-gearboxes/public-images-one-dry-run', [JarekGearboxToolController::class, 'publicImagesDryRun'])->name('admin.tools.jarek-gearboxes.public-images-one-dry-run');
    Route::match(['get', 'post'], '/admin/tools/jarek-gearboxes/public-images-one-apply', [JarekGearboxToolController::class, 'publicImagesApply'])->name('admin.tools.jarek-gearboxes.public-images-one-apply');
    Route::get('/admin/tools/jarek-gearboxes/localize-images-dry-run', [JarekGearboxToolController::class, 'localizeImagesDryRun'])->name('admin.tools.jarek-gearboxes.localize-images-dry-run');
    Route::get('/admin/tools/jarek-gearboxes/localize-images-apply', [JarekGearboxToolController::class, 'localizeImagesApply'])->name('admin.tools.jarek-gearboxes.localize-images-apply');
    Route::get('/admin/tools/jarek-gearboxes/ebay-csv-export', [JarekGearboxToolController::class, 'ebayCsvExport'])->name('admin.tools.jarek-gearboxes.ebay-csv-export');
    Route::get('/admin/tools/jarek-gearboxes/image-import-preview', [JarekGearboxToolController::class, 'imageImportPreview'])->name('admin.tools.jarek-gearboxes.image-import-preview');
    Route::match(['get', 'post'], '/admin/tools/jarek-gearboxes/image-import-apply', [JarekGearboxToolController::class, 'imageImportApply'])->name('admin.tools.jarek-gearboxes.image-import-apply');
    Route::get('/admin/tools/jarek-gearboxes/image-source-diagnostics', [JarekGearboxToolController::class, 'imageSourceDiagnostics'])->name('admin.tools.jarek-gearboxes.image-source-diagnostics');
    Route::get('/admin/tools/jarek-gearboxes/ebay-csv-download/{filename}', [JarekGearboxToolController::class, 'ebayCsvDownload'])->name('admin.tools.jarek-gearboxes.ebay-csv-download');
    Route::get('/admin/tools/jarek-gearboxes/{jarekGearbox}/ebay-preview', [JarekGearboxToolController::class, 'ebayPreview'])->name('admin.tools.jarek-gearboxes.ebay-preview');
    Route::get('/admin/tools/ebay/prepare-debug/{partId}', [PartMarketplaceReadinessController::class, 'ebayPrepareDebug'])->name('admin.tools.ebay.prepare-debug');
    Route::match(['get', 'post'], '/admin/tools/ebay/marketplace-diagnose', EbayMarketplaceDiagnoseController::class)->name('admin.tools.ebay.marketplace-diagnose');
    Route::match(['get', 'post'], '/admin/tools/ebay/listing-audit-runner', EbayListingAuditRunnerController::class)->name('admin.tools.ebay.listing-audit-runner');
    Route::get('/admin/tools/ebay/listing-audit-runner/status', [EbayListingAuditRunnerController::class, 'status'])->name('admin.tools.ebay.listing-audit-runner.status');
    Route::get('/admin/tools/ebay-listing-audit-runner', fn (Request $request) => redirect()->to('/admin/tools/ebay/listing-audit-runner'.($request->getQueryString() ? '?'.$request->getQueryString() : '')))->name('admin.tools.ebay-listing-audit-runner.redirect');
    Route::post('/admin/tools/ebay-listing-audit-runner', EbayListingAuditRunnerController::class)->name('admin.tools.ebay-listing-audit-runner.alias');
    Route::get('/admin/tools/ebay-listing-audit-runner/status', fn (Request $request) => redirect()->to('/admin/tools/ebay/listing-audit-runner/status'.($request->getQueryString() ? '?'.$request->getQueryString() : '')))->name('admin.tools.ebay-listing-audit-runner.status.redirect');
    Route::get('/admin/tools/marketplace/oauth-token-health', MarketplaceOAuthTokenHealthController::class)->name('admin.tools.marketplace.oauth-token-health');
    Route::get('/admin/tools/marketplace/ovoko-url-backfill', OvokoListingUrlBackfillController::class)->name('admin.tools.marketplace.ovoko-url-backfill');
    Route::match(['get', 'post'], '/admin/tools/marketplace/listing-image-refresh', MarketplaceListingImageRefreshController::class)->name('admin.tools.marketplace.listing-image-refresh');
    Route::get('/admin/tools/marketplace/mapping-gaps-export', MarketplaceMappingGapsExportController::class)->name('admin.tools.marketplace.mapping-gaps-export');
    Route::get('/admin/tools/ovoko/listing-url-backfill', OvokoListingUrlBackfillController::class)->name('admin.tools.ovoko.listing-url-backfill');
    Route::get('/admin/tools/ovoko/backfill-links', OvokoListingUrlBackfillController::class)->name('admin.tools.ovoko.backfill-links');
    Route::get('/admin/tools/ovoko/listing-url-diagnostics', OvokoListingUrlDiagnosticsController::class)->name('admin.tools.ovoko.listing-url-diagnostics');
    Route::get('/admin/tools/ovoko/listing-diagnose', OvokoListingDiagnoseController::class)->name('admin.tools.ovoko.listing-diagnose');
    Route::match(['get', 'post'], '/admin/tools/ovoko/unlink-stale-listing', OvokoUnlinkStaleListingController::class)->name('admin.tools.ovoko.unlink-stale-listing');
    Route::get('/admin/tools/ovoko/linked-products-check', OvokoLinkedProductsCheckController::class)->name('admin.tools.ovoko.linked-products-check');
    Route::get('/admin/tools/ovoko/sold-mapping-check', OvokoSoldMappingCheckController::class)->name('admin.tools.ovoko.sold-mapping-check');
    Route::get('/admin/tools/orders/ovoko-cross-channel-diagnose', OvokoCrossChannelDiagnoseController::class)->name('admin.tools.orders.ovoko-cross-channel-diagnose');
    Route::match(['get', 'post'], '/admin/tools/orders/ovoko-order-item-part-backfill', [OvokoOrderItemPartBackfillController::class, 'admin'])->name('admin.tools.orders.ovoko-order-item-part-backfill');
    Route::get('/admin/tools/ovoko/sold-mapping-check-ping', function () {
        return response()->json(['ok' => true, 'tool' => 'sold-mapping-check-ping']);
    })->name('admin.tools.ovoko.sold-mapping-check-ping');
    Route::get('/admin/tools/ovoko/import-product-data', OvokoImportProductDataController::class)->name('admin.tools.ovoko.import-product-data');
    Route::get('/admin/tools/ovoko-stock-sync-dry-run', [OvokoStockSyncController::class, 'dryRun'])->name('admin.tools.ovoko-stock-sync-dry-run');
    Route::match(['get', 'post'], '/admin/tools/ovoko-stock-sync-apply', [OvokoStockSyncController::class, 'apply'])->name('admin.tools.ovoko-stock-sync-apply');
    Route::get('/admin/tools/ovoko-stock-sync-runner', [OvokoStockSyncRunnerController::class, 'index'])->name('admin.tools.ovoko-stock-sync-runner.index');
    Route::get('/admin/tools/ovoko-stock-sync-runner/ping', [OvokoStockSyncRunnerController::class, 'ping'])->name('admin.tools.ovoko-stock-sync-runner.ping');
    Route::get('/admin/tools/ovoko-stock-sync-runner/debug-minimal', [OvokoStockSyncRunnerController::class, 'debugMinimal'])->name('admin.tools.ovoko-stock-sync-runner.debug-minimal');
    Route::match(['get', 'post'], '/admin/tools/ovoko-stock-sync-runner/start', [OvokoStockSyncRunnerController::class, 'start'])->name('admin.tools.ovoko-stock-sync-runner.start');
    Route::match(['get', 'post'], '/admin/tools/ovoko-stock-sync-runner/start-browser', [OvokoStockSyncRunnerController::class, 'startBrowser'])->name('admin.tools.ovoko-stock-sync-runner.start-browser');
    Route::get('/admin/tools/ovoko-stock-sync-runner/diagnostics', [OvokoStockSyncRunnerController::class, 'diagnosticsEndpoint'])->name('admin.tools.ovoko-stock-sync-runner.diagnostics');
    Route::get('/admin/tools/ovoko-stock-sync-runner/tick/{run}', [OvokoStockSyncRunnerController::class, 'tick'])->name('admin.tools.ovoko-stock-sync-runner.tick');
    Route::get('/admin/tools/ovoko-stock-sync-runner/status/{run}', [OvokoStockSyncRunnerController::class, 'status'])->name('admin.tools.ovoko-stock-sync-runner.status');
    Route::get('/admin/tools/ovoko-stock-sync-runner/results/{run}', [OvokoStockSyncRunnerController::class, 'results'])->name('admin.tools.ovoko-stock-sync-runner.results');
    Route::match(['get', 'post'], '/admin/tools/ovoko-stock-sync-runner/cancel/{run}', [OvokoStockSyncRunnerController::class, 'cancel'])->name('admin.tools.ovoko-stock-sync-runner.cancel');
    Route::match(['get', 'post'], '/admin/tools/marketplace/link-repair', MarketplaceLinkRepairController::class)->name('admin.tools.marketplace.link-repair');
    Route::get('/admin/tools/marketplace/listing-url-backfill', MarketplaceListingUrlBackfillController::class)->name('admin.tools.marketplace.listing-url-backfill');
    Route::get('/admin/tools/marketplace/manual-link-mapping/diagnostics', ManualLinkMappingDiagnosticsController::class)->name('admin.tools.marketplace.manual-link-mapping.diagnostics');
    Route::get('/admin/tools/marketplace/ovoko-diagnose', OvokoMarketplaceDiagnoseController::class)->name('admin.tools.marketplace.ovoko-diagnose');
    Route::get('/admin/tools/marketplace/allegro-diagnose', AllegroMarketplaceDiagnoseController::class)->name('admin.tools.marketplace.allegro-diagnose');
    Route::post('/admin/tools/marketplace/allegro-diagnose/refresh-status', [AllegroMarketplaceDiagnoseController::class, 'refresh'])->name('admin.tools.marketplace.allegro-diagnose.refresh-status');
    Route::match(['get', 'post'], '/admin/tools/marketplace/allegro-diagnose/refresh-pending', [AllegroMarketplaceDiagnoseController::class, 'refreshPending'])->name('admin.tools.marketplace.allegro-diagnose.refresh-pending');
    Route::get('/admin/tools/marketplace/manual-link-mapping/replace-dry-run', [ManualLinkMappingReplaceController::class, 'dryRun'])->name('admin.tools.marketplace.manual-link-mapping.replace-dry-run');
    Route::get('/admin/tools/marketplace/manual-link-mapping/replace-apply', [ManualLinkMappingReplaceController::class, 'apply'])->name('admin.tools.marketplace.manual-link-mapping.replace-apply');
    Route::get('/admin/tools/marketplace/ebay-listing-audit', EbayListingAuditController::class)->name('admin.tools.marketplace.ebay-listing-audit');
    Route::get('/admin/tools/marketplace/ebay-description-audit', EbayDescriptionAuditController::class)->name('admin.tools.marketplace.ebay-description-audit');
    Route::get('/admin/tools/marketplace/ebay-listing-audit-runner', fn (Request $request) => redirect()->to('/admin/tools/ebay/listing-audit-runner'.($request->getQueryString() ? '?'.$request->getQueryString() : '')))->name('admin.tools.marketplace.ebay-listing-audit-runner.redirect');
    Route::post('/admin/tools/marketplace/ebay-listing-audit-runner', EbayListingAuditRunnerController::class)->name('admin.tools.marketplace.ebay-listing-audit-runner.alias');
    Route::get('/admin/tools/marketplace/ebay-listing-audit-runner/status', fn (Request $request) => redirect()->to('/admin/tools/ebay/listing-audit-runner/status'.($request->getQueryString() ? '?'.$request->getQueryString() : '')))->name('admin.tools.marketplace.ebay-listing-audit-runner.status.redirect');
    Route::match(['get', 'post'], '/admin/tools/marketplace/orders-reset', MarketplaceOrdersResetController::class)->name('admin.tools.marketplace.orders-reset');
    Route::match(['get', 'post'], '/admin/tools/marketplace/orders-sync', MarketplaceOrdersSyncController::class)->name('admin.tools.marketplace.orders-sync');
    Route::match(['get', 'post'], '/admin/tools/marketplace/orders-purge', MarketplaceOrdersPurgeToolController::class)->name('admin.tools.marketplace.orders-purge');
    Route::match(['get', 'post'], '/admin/tools/marketplace/fulfillment-status-sync', MarketplaceFulfillmentSyncToolController::class)->name('admin.tools.marketplace.fulfillment-status-sync');
    Route::match(['get', 'post'], '/admin/tools/marketplace/orders/{order}/fulfillment-sync', MarketplaceOrderFulfillmentSyncController::class)->name('admin.tools.marketplace.orders.fulfillment-sync');
    Route::get('/admin/tools/marketplace/orders-timezone-fix', MarketplaceOrdersTimezoneFixController::class)->name('admin.tools.marketplace.orders-timezone-fix');
    Route::get('/admin/tools/marketplace/allegro/responsible-producers', [MarketplaceListingDryRunController::class, 'allegroResponsibleProducers'])->name('admin.tools.marketplace.allegro.responsible-producers');
    Route::get('/admin/tools/marketplace/allegro/compatible-products', [MarketplaceListingDryRunController::class, 'allegroCompatibleProducts'])->name('admin.tools.marketplace.allegro.compatible-products');
    Route::get('/admin/tools/allegro/parts/{part}/compatibility-dry-run', AllegroCompatibilityDryRunController::class)->name('admin.tools.allegro.parts.compatibility-dry-run');
    Route::get('/admin/tools/ovoko/cars/{car}/mapping-dry-run', [OvokoCarMappingController::class, 'dryRun'])->name('admin.tools.ovoko.cars.mapping-dry-run');
    Route::match(['get', 'post'], '/admin/tools/ovoko/cars/{car}/mapping-apply', [OvokoCarMappingController::class, 'apply'])->name('admin.tools.ovoko.cars.mapping-apply');
    Route::get('/admin/tools/allegro/offers/description-update-dry-run', [MarketplaceListingDryRunController::class, 'allegroDescriptionUpdateDryRun'])->name('admin.tools.allegro.offers.description-update-dry-run');
    Route::get('/admin/tools/allegro/offers/description-update-apply', [MarketplaceListingDryRunController::class, 'allegroDescriptionUpdateApply'])->name('admin.tools.allegro.offers.description-update-apply');
    Route::get('/admin/tools/marketplace/allegro/offers/{offerId}/description-update', [MarketplaceListingDryRunController::class, 'allegroDescriptionUpdate'])->name('admin.tools.marketplace.allegro.offers.description-update');
});

Route::get('/product-images-dry-run', ProductImagesDryRunController::class)->name('tools.product-images-dry-run');
Route::get('/product-images-import', ProductImagesImportController::class)->name('tools.product-images-import');
Route::get('/product-images-import-runner', ProductImagesImportRunnerController::class)->name('tools.product-images-import-runner');
Route::get('/tools/check-product-image', CheckProductImageController::class)->name('tools.check-product-image');
Route::get('/tools/check-frontend-maintenance', CheckFrontendMaintenanceController::class)->name('tools.check-frontend-maintenance');
Route::get('/tools/set-frontend-maintenance', SetFrontendMaintenanceController::class)->name('tools.set-frontend-maintenance');
Route::get('/tools/check-gpswiss-public-html', [GpswissPublicHtmlController::class, 'check'])->name('tools.check-gpswiss-public-html');
Route::get('/tools/link-gpswiss-storage', [GpswissPublicHtmlController::class, 'linkStorage'])->name('tools.link-gpswiss-storage');
Route::get('/tools/sync-gpswiss-public-html', [GpswissPublicHtmlController::class, 'sync'])->name('tools.sync-gpswiss-public-html');
Route::get('/tools/check-workshop-part-images', WorkshopImageDiagnosticsController::class)->name('tools.check-workshop-part-images');
Route::get('/tools/backfill-part-default-condition-and-steering', BackfillPartDefaultConditionAndSteeringController::class)->name('tools.backfill-part-default-condition-and-steering');
Route::get('/tools/backfill-part-marketplace-prices', BackfillPartMarketplacePricesController::class)->name('tools.backfill-part-marketplace-prices');
Route::get('/tools/workshop/quick-part-create', [WorkshopQuickPartController::class, 'create'])->name('tools.workshop.quick-part-create');
Route::post('/tools/workshop/quick-part-create', [WorkshopQuickPartController::class, 'store'])->name('tools.workshop.quick-part-create.store');
Route::get('/tools/check-orders-flow', CheckOrdersFlowController::class)->name('tools.check-orders-flow');
Route::get('/tools/check-ovoko-mapping', CheckOvokoMappingController::class)->name('tools.check-ovoko-mapping');
Route::get('/tools/debug-ovoko-part-match', DebugOvokoPartMatchController::class)->name('tools.debug-ovoko-part-match');
Route::get('/tools/debug-part-category-suggestion', DebugPartCategorySuggestionController::class)->name('tools.debug-part-category-suggestion');
Route::get('/tools/audit-part-category-suggestions', AuditPartCategorySuggestionsController::class)->name('tools.audit-part-category-suggestions');
Route::get('/tools/debug-category-marketplace-mappings', DebugCategoryMarketplaceMappingsController::class)->name('tools.debug-category-marketplace-mappings');
Route::get('/tools/check-ovoko-api-settings', CheckOvokoApiSettingsController::class)->name('tools.check-ovoko-api-settings');
Route::get('/tools/check-marketplace-api-readiness', [MarketplaceApiFoundationController::class, 'readiness'])->name('tools.check-marketplace-api-readiness');
Route::get('/tools/test-marketplace-api-connection', [MarketplaceApiFoundationController::class, 'testConnection'])->name('tools.test-marketplace-api-connection');
Route::get('/tools/fetch-marketplace-offers-sample', [MarketplaceApiFoundationController::class, 'fetchOffersSample'])->name('tools.fetch-marketplace-offers-sample');
Route::get('/tools/check-marketplace-price-strategy', [MarketplaceApiFoundationController::class, 'priceStrategy'])->name('tools.check-marketplace-price-strategy');
Route::get('/tools/check-marketplace-price-fields', [MarketplaceApiFoundationController::class, 'priceFields'])->name('tools.check-marketplace-price-fields');
Route::get('/tools/check-marketplace-price-coverage', [MarketplaceApiFoundationController::class, 'priceCoverage'])->name('tools.check-marketplace-price-coverage');
Route::get('/tools/check-marketplace-stock-readiness', [MarketplaceApiFoundationController::class, 'stockReadiness'])->name('tools.check-marketplace-stock-readiness');
Route::get('/tools/check-marketplace-linking-health', [MarketplaceApiFoundationController::class, 'linkingHealth'])->name('tools.check-marketplace-linking-health');
Route::get('/tools/test-ovoko-api-connection', TestOvokoApiConnectionController::class)->name('tools.test-ovoko-api-connection');
Route::get('/tools/fetch-ovoko-part-status-dictionary', FetchOvokoPartStatusDictionaryController::class)->name('tools.fetch-ovoko-part-status-dictionary');
Route::get('/tools/check-ovoko-price-import', [OvokoPriceImportController::class, 'check'])->name('tools.check-ovoko-price-import');
Route::get('/tools/dry-run-delete-local-categories', [OvokoProductSyncController::class, 'dryRunDeleteLocalCategories'])->name('tools.dry-run-delete-local-categories');
Route::get('/tools/debug-products-by-category', [OvokoProductSyncController::class, 'debugProductsByCategory'])->name('tools.debug-products-by-category');
Route::get('/tools/delete-local-categories', [OvokoProductSyncController::class, 'deleteLocalCategories'])->name('tools.delete-local-categories');
Route::get('/tools/dry-run-ovoko-product-sync', [OvokoProductSyncController::class, 'dryRun'])->name('tools.dry-run-ovoko-product-sync');
Route::get('/tools/check-ovoko-product-sync-readiness', [OvokoProductSyncController::class, 'readiness'])->name('tools.check-ovoko-product-sync-readiness');
Route::get('/tools/check-ovoko-category-data-sources', [OvokoProductSyncController::class, 'categoryDataSources'])->name('tools.check-ovoko-category-data-sources');
Route::get('/tools/inspect-ovoko-category-legacy-payloads', [OvokoProductSyncController::class, 'inspectOvokoCategoryLegacyPayloads'])->name('tools.inspect-ovoko-category-legacy-payloads');
Route::get('/tools/fetch-ovoko-category-tree-preview', [OvokoProductSyncController::class, 'fetchOvokoCategoryTreePreview'])->name('tools.fetch-ovoko-category-tree-preview');
Route::get('/tools/dry-run-ovoko-category-mapping-from-linked-products', [OvokoProductSyncController::class, 'dryRunOvokoCategoryMappingFromLinkedProducts'])->name('tools.dry-run-ovoko-category-mapping-from-linked-products');
Route::get('/tools/dry-run-ovoko-category-path-mapping', [OvokoProductSyncController::class, 'dryRunOvokoCategoryPathMapping'])->name('tools.dry-run-ovoko-category-path-mapping');
Route::get('/tools/dry-run-sync-ovoko-tree-to-shop-categories', [OvokoProductSyncController::class, 'dryRunSyncOvokoTreeToShopCategories'])->name('tools.dry-run-sync-ovoko-tree-to-shop-categories');
Route::get('/tools/dry-run-detect-bad-slash-split-categories', [OvokoProductSyncController::class, 'dryRunDetectBadSlashSplitCategories'])->name('tools.dry-run-detect-bad-slash-split-categories');
Route::get('/tools/dry-run-audit-shop-category-tree-display', [OvokoProductSyncController::class, 'dryRunAuditShopCategoryTreeDisplay'])->name('tools.dry-run-audit-shop-category-tree-display');
Route::get('/tools/dry-run-compare-woo-category-tree-with-laravel', [OvokoProductSyncController::class, 'dryRunCompareWooCategoryTreeWithLaravel'])->name('tools.dry-run-compare-woo-category-tree-with-laravel');
Route::get('/tools/dry-run-audit-category-display-against-woo-and-ovoko', [OvokoProductSyncController::class, 'dryRunAuditCategoryDisplayAgainstWooAndOvoko'])->name('tools.dry-run-audit-category-display-against-woo-and-ovoko');
Route::get('/tools/dry-run-plan-fix-category-display-splits', [OvokoProductSyncController::class, 'dryRunPlanFixCategoryDisplaySplits'])->name('tools.dry-run-plan-fix-category-display-splits');
Route::get('/tools/dry-run-hide-empty-category-display-splits', [OvokoProductSyncController::class, 'dryRunHideEmptyCategoryDisplaySplits'])->name('tools.dry-run-hide-empty-category-display-splits');
Route::get('/tools/hide-empty-category-display-splits', [OvokoProductSyncController::class, 'hideEmptyCategoryDisplaySplits'])->name('tools.hide-empty-category-display-splits');
Route::get('/tools/hide-empty-public-categories', [OvokoProductSyncController::class, 'hideEmptyPublicCategories'])->name('tools.hide-empty-public-categories');
Route::get('/tools/debug-category-display-names', [OvokoProductSyncController::class, 'debugCategoryDisplayNames'])->name('tools.debug-category-display-names');
Route::get('/tools/debug-public-category-labels', [OvokoProductSyncController::class, 'debugPublicCategoryLabels'])->name('tools.debug-public-category-labels');
Route::get('/tools/debug-category-visibility-usage', [OvokoProductSyncController::class, 'debugCategoryVisibilityUsage'])->name('tools.debug-category-visibility-usage');
Route::get('/tools/clear-category-tree-cache', [OvokoProductSyncController::class, 'clearCategoryTreeCache'])->name('tools.clear-category-tree-cache');
Route::get('/tools/dry-run-clean-category-db-names', [OvokoProductSyncController::class, 'dryRunCleanCategoryDbNames'])->name('tools.dry-run-clean-category-db-names');
Route::get('/tools/clean-category-db-names', [OvokoProductSyncController::class, 'cleanCategoryDbNames'])->name('tools.clean-category-db-names');
Route::get('/tools/dry-run-clean-category-display-names', [OvokoProductSyncController::class, 'dryRunCleanCategoryDisplayNames'])->name('tools.dry-run-clean-category-display-names');
Route::get('/tools/clean-category-display-names', [OvokoProductSyncController::class, 'cleanCategoryDisplayNames'])->name('tools.clean-category-display-names');
Route::get('/tools/dry-run-verify-woo-ebay-mapping-for-category-splits', [OvokoProductSyncController::class, 'dryRunVerifyWooEbayMappingForCategorySplits'])->name('tools.dry-run-verify-woo-ebay-mapping-for-category-splits');
Route::get('/tools/category-display-splits-fix-autorun', [OvokoProductSyncController::class, 'categoryDisplaySplitsFixAutorun'])->name('tools.category-display-splits-fix-autorun');
Route::get('/tools/debug-category-display-splits-fix-autorun', [OvokoProductSyncController::class, 'debugCategoryDisplaySplitsFixAutorun'])->name('tools.debug-category-display-splits-fix-autorun');
Route::get('/tools/start-category-display-splits-fix-autorun', [OvokoProductSyncController::class, 'startCategoryDisplaySplitsFixAutorun'])->name('tools.start-category-display-splits-fix-autorun');
Route::get('/tools/run-category-display-splits-fix-autorun', [OvokoProductSyncController::class, 'runCategoryDisplaySplitsFixAutorun'])->name('tools.run-category-display-splits-fix-autorun');
Route::get('/tools/status-category-display-splits-fix-autorun', [OvokoProductSyncController::class, 'statusCategoryDisplaySplitsFixAutorun'])->name('tools.status-category-display-splits-fix-autorun');
Route::get('/tools/results-category-display-splits-fix-autorun', [OvokoProductSyncController::class, 'resultsCategoryDisplaySplitsFixAutorun'])->name('tools.results-category-display-splits-fix-autorun');
Route::get('/tools/reset-category-display-splits-fix-autorun', [OvokoProductSyncController::class, 'resetCategoryDisplaySplitsFixAutorun'])->name('tools.reset-category-display-splits-fix-autorun');
Route::get('/tools/ovoko-category-mapping-autorun', [OvokoProductSyncController::class, 'ovokoCategoryMappingAutorun'])->name('tools.ovoko-category-mapping-autorun');
Route::get('/tools/ovoko-category-mapping-autorun-smoke', [OvokoProductSyncController::class, 'ovokoCategoryMappingAutorunSmoke'])->name('tools.ovoko-category-mapping-autorun-smoke');
Route::get('/tools/debug-ovoko-category-mapping-autorun-start-minimal', [OvokoProductSyncController::class, 'debugOvokoCategoryMappingAutorunStartMinimal'])->name('tools.debug-ovoko-category-mapping-autorun-start-minimal');
Route::get('/tools/debug-ovoko-category-mapping-start-steps', [OvokoProductSyncController::class, 'debugOvokoCategoryMappingStartSteps'])->name('tools.debug-ovoko-category-mapping-start-steps');
Route::get('/tools/app-deploy-debug', [OvokoProductSyncController::class, 'appDeployDebug'])->name('tools.app-deploy-debug');
Route::get('/tools/clear-laravel-cache', [OvokoProductSyncController::class, 'clearLaravelCache'])->name('tools.clear-laravel-cache');
Route::get('/tools/start-ovoko-category-mapping-autorun', [OvokoProductSyncController::class, 'startOvokoCategoryMappingAutorun'])->name('tools.start-ovoko-category-mapping-autorun');
Route::get('/tools/run-ovoko-category-mapping-autorun', [OvokoProductSyncController::class, 'runOvokoCategoryMappingAutorun'])->name('tools.run-ovoko-category-mapping-autorun');
Route::get('/tools/status-ovoko-category-mapping-autorun', [OvokoProductSyncController::class, 'statusOvokoCategoryMappingAutorun'])->name('tools.status-ovoko-category-mapping-autorun');
Route::get('/tools/reset-ovoko-category-mapping-autorun', [OvokoProductSyncController::class, 'resetOvokoCategoryMappingAutorun'])->name('tools.reset-ovoko-category-mapping-autorun');
Route::get('/tools/results-ovoko-category-mapping-autorun', [OvokoProductSyncController::class, 'resultsOvokoCategoryMappingAutorun'])->name('tools.results-ovoko-category-mapping-autorun');
Route::get('/tools/preview-ovoko-category-from-linked-products', [OvokoProductSyncController::class, 'previewOvokoCategoryFromLinkedProducts'])->name('tools.preview-ovoko-category-from-linked-products');
Route::get('/tools/debug-ovoko-linked-product-raw-fields', [OvokoProductSyncController::class, 'debugOvokoLinkedProductRawFields'])->name('tools.debug-ovoko-linked-product-raw-fields');
Route::get('/tools/debug-ovoko-part-detail-endpoints', [OvokoProductSyncController::class, 'debugOvokoPartDetailEndpoints'])->name('tools.debug-ovoko-part-detail-endpoints');
Route::get('/tools/debug-ovoko-find-linked-part-in-snapshot', [OvokoProductSyncController::class, 'debugOvokoFindLinkedPartInSnapshot'])->name('tools.debug-ovoko-find-linked-part-in-snapshot');
Route::get('/tools/ovoko-stock-reconciliation-runner', [OvokoStockReconciliationController::class, 'runner'])->name('tools.ovoko-stock-reconciliation-runner');
Route::get('/tools/dry-run-ovoko-stock-reconciliation', [OvokoStockReconciliationController::class, 'dryRun'])->name('tools.dry-run-ovoko-stock-reconciliation');
Route::get('/tools/dry-run-ovoko-stock-reconciliation-all', [OvokoStockReconciliationController::class, 'dryRunAll'])->name('tools.dry-run-ovoko-stock-reconciliation-all');
Route::get('/tools/prepare-ovoko-stock-reconciliation-snapshot', [OvokoStockReconciliationController::class, 'prepareSnapshot'])->name('tools.prepare-ovoko-stock-reconciliation-snapshot');
Route::get('/tools/run-ovoko-stock-snapshot-step', [OvokoStockReconciliationController::class, 'snapshotStep'])->name('tools.run-ovoko-stock-snapshot-step');
Route::get('/tools/run-ovoko-stock-reconciliation-step', [OvokoStockReconciliationController::class, 'reconciliationStep'])->name('tools.run-ovoko-stock-reconciliation-step');
Route::get('/tools/check-ovoko-stock-reconciliation-run', [OvokoStockReconciliationController::class, 'runStatus'])->name('tools.check-ovoko-stock-reconciliation-run');
Route::get('/tools/dry-run-ovoko-stock-reconciliation-batch', [OvokoStockReconciliationController::class, 'dryRunBatch'])->name('tools.dry-run-ovoko-stock-reconciliation-batch');
Route::get('/tools/dry-run-ovoko-stock-reconciliation-range', [OvokoStockReconciliationController::class, 'dryRunRange'])->name('tools.dry-run-ovoko-stock-reconciliation-range');
Route::get('/tools/run-ovoko-stock-reconciliation', [OvokoStockReconciliationController::class, 'run'])->name('tools.run-ovoko-stock-reconciliation');
Route::get('/tools/check-parts-needs-review', [OvokoStockReconciliationController::class, 'check'])->name('tools.check-parts-needs-review');
Route::get('/tools/debug-ovoko-price-fields', [OvokoPriceImportController::class, 'debugPriceFields'])->name('tools.debug-ovoko-price-fields');
Route::get('/tools/export-ovoko-price-import', [OvokoPriceImportController::class, 'export'])->name('tools.export-ovoko-price-import');
Route::get('/tools/import-ovoko-prices', [OvokoPriceImportController::class, 'import'])->name('tools.import-ovoko-prices');
Route::get('/tools/start-ovoko-price-import-run', [OvokoPriceImportController::class, 'startRun'])->name('tools.start-ovoko-price-import-run');
Route::get('/tools/ovoko-price-import-runner', [OvokoPriceImportController::class, 'runner'])->name('tools.ovoko-price-import-runner');
Route::get('/tools/run-ovoko-price-import-batch', [OvokoPriceImportController::class, 'runBatch'])->name('tools.run-ovoko-price-import-batch');
Route::get('/tools/check-ovoko-price-import-run', [OvokoPriceImportController::class, 'checkRun'])->name('tools.check-ovoko-price-import-run');
Route::get('/tools/resume-ovoko-price-import-run', [OvokoPriceImportController::class, 'resumeRun'])->name('tools.resume-ovoko-price-import-run');
Route::get('/tools/check-allegro-api-settings', [MarketplaceApiSettingsDiagnosticsController::class, 'allegro'])->name('tools.check-allegro-api-settings');
Route::get('/tools/check-allegro-oauth-readiness', CheckAllegroOAuthReadinessController::class)->name('tools.check-allegro-oauth-readiness');
Route::get('/tools/check-allegro-product-mapping-candidates', CheckAllegroProductMappingCandidatesController::class)->name('tools.check-allegro-product-mapping-candidates');
Route::get('/tools/export-allegro-product-mapping-candidates', ExportAllegroProductMappingCandidatesController::class)->name('tools.export-allegro-product-mapping-candidates');
Route::get('/tools/suggest-allegro-category-mappings-from-legacy-csv', SuggestAllegroCategoryMappingsFromLegacyCsvController::class)->name('tools.suggest-allegro-category-mappings-from-legacy-csv');
Route::get('/tools/suggest-ovoko-category-mappings-from-local-tree', SuggestOvokoCategoryMappingsFromLocalTreeController::class)->name('tools.suggest-ovoko-category-mappings-from-local-tree');
Route::get('/tools/suggest-warehouse-import-from-allegro-csv', [WarehouseImportFromAllegroCsvController::class, 'suggest'])->name('tools.suggest-warehouse-import-from-allegro-csv');
Route::get('/tools/apply-warehouse-import-from-allegro-csv', [WarehouseImportFromAllegroCsvController::class, 'apply'])->name('tools.apply-warehouse-import-from-allegro-csv');
Route::get('/tools/set-ovoko-category-mappings-batch', SetOvokoCategoryMappingsBatchController::class)->name('tools.set-ovoko-category-mappings-batch');
Route::get('/tools/apply-ovoko-category-mappings-from-local-tree', [SuggestOvokoCategoryMappingsFromLocalTreeController::class, 'apply'])->name('tools.apply-ovoko-category-mappings-from-local-tree');
Route::get('/tools/apply-allegro-category-mappings-from-legacy-csv', [SuggestAllegroCategoryMappingsFromLegacyCsvController::class, 'apply'])->name('tools.apply-allegro-category-mappings-from-legacy-csv');
Route::get('/tools/allegro-legacy-category-mapping-runner', [SuggestAllegroCategoryMappingsFromLegacyCsvController::class, 'runner'])->name('tools.allegro-legacy-category-mapping-runner');
Route::get('/tools/run-allegro-legacy-category-mapping-batch', [SuggestAllegroCategoryMappingsFromLegacyCsvController::class, 'batch'])->name('tools.run-allegro-legacy-category-mapping-batch');
Route::get('/tools/check-allegro-local-id-sources', CheckAllegroLocalIdSourcesController::class)->name('tools.check-allegro-local-id-sources');
Route::get('/tools/check-allegro-offer-id-coverage', CheckAllegroOfferIdCoverageController::class)->name('tools.check-allegro-offer-id-coverage');
Route::get('/tools/refresh-allegro-active-offer-links-dry-run', RefreshAllegroActiveOfferLinksDryRunController::class)->name('tools.refresh-allegro-active-offer-links-dry-run');
Route::get('/tools/refresh-allegro-active-offer-links', RefreshAllegroActiveOfferLinksController::class)->name('tools.refresh-allegro-active-offer-links');
Route::get('/tools/check-admin-marketplace-status-ui', CheckAdminMarketplaceStatusUiController::class)->name('tools.check-admin-marketplace-status-ui');
Route::get('/tools/export-allegro-offer-id-coverage', ExportAllegroOfferIdCoverageController::class)->name('tools.export-allegro-offer-id-coverage');
Route::get('/tools/check-ebay-api-settings', [MarketplaceApiSettingsDiagnosticsController::class, 'ebay'])->name('tools.check-ebay-api-settings');
Route::get('/tools/check-ebay-api-readiness', [MarketplaceApiSettingsDiagnosticsController::class, 'ebayReadiness'])->name('tools.check-ebay-api-readiness');
Route::get('/tools/check-ebay-oauth-routes', [MarketplaceApiSettingsDiagnosticsController::class, 'ebayOAuthRoutes'])->name('tools.check-ebay-oauth-routes');
Route::get('/tools/check-ebay-legacy-category-mappings', CheckEbayLegacyCategoryMappingsController::class)->name('tools.check-ebay-legacy-category-mappings');
Route::get('/tools/check-ebay-legacy-shipping-mappings', CheckEbayLegacyShippingMappingsController::class)->name('tools.check-ebay-legacy-shipping-mappings');
Route::get('/tools/import-ebay-legacy-category-mappings-dry-run', [EbayLegacyCategoryMappingImportController::class, 'dryRun'])->name('tools.import-ebay-legacy-category-mappings-dry-run');
Route::get('/tools/import-ebay-legacy-category-mappings', [EbayLegacyCategoryMappingImportController::class, 'live'])->name('tools.import-ebay-legacy-category-mappings');
Route::get('/tools/check-marketplace-category-mappings', [EbayLegacyCategoryMappingImportController::class, 'check'])->name('tools.check-marketplace-category-mappings');
Route::get('/tools/import-ebay-category-shipping-policies-from-csv-dry-run', [EbayCategoryShippingPolicyCsvImportController::class, 'dryRun'])->name('tools.import-ebay-category-shipping-policies-from-csv-dry-run');
Route::get('/tools/import-ebay-category-shipping-policies-from-csv', [EbayCategoryShippingPolicyCsvImportController::class, 'live'])->name('tools.import-ebay-category-shipping-policies-from-csv');
Route::get('/tools/check-ebay-category-shipping-coverage', [EbayCategoryShippingPolicyCsvImportController::class, 'coverage'])->name('tools.check-ebay-category-shipping-coverage');
Route::get('/tools/test-allegro-api-connection', [MarketplaceApiSettingsDiagnosticsController::class, 'testAllegro'])->name('tools.test-allegro-api-connection');
Route::get('/tools/test-ebay-api-connection', [MarketplaceApiSettingsDiagnosticsController::class, 'testEbay'])->name('tools.test-ebay-api-connection');
Route::get('/tools/check-ebay-business-policies', [MarketplaceApiSettingsDiagnosticsController::class, 'checkEbayBusinessPolicies'])->name('tools.check-ebay-business-policies');
Route::get('/tools/check-ebay-account-policy-settings', [EbayListingDryRunController::class, 'checkAccountPolicySettings'])->name('tools.check-ebay-account-policy-settings');
Route::get('/tools/set-ebay-account-policy-settings', [EbayListingDryRunController::class, 'setAccountPolicySettings'])->name('tools.set-ebay-account-policy-settings');
Route::get('/tools/check-ebay-listing-readiness', [EbayListingDryRunController::class, 'readiness'])->name('tools.check-ebay-listing-readiness');
Route::get('/tools/dry-run-ebay-listing-payload', [EbayListingDryRunController::class, 'dryRunPayload'])->name('tools.dry-run-ebay-listing-payload');
Route::get('/tools/check-ebay-part-compatibility', [EbayListingDryRunController::class, 'compatibility'])->name('tools.check-ebay-part-compatibility');
Route::get('/tools/check-ebay-compatibility-policies', [EbayListingDryRunController::class, 'compatibilityPolicies'])->name('tools.check-ebay-compatibility-policies');
Route::get('/tools/check-ebay-compatibility-properties', [EbayListingDryRunController::class, 'compatibilityProperties'])->name('tools.check-ebay-compatibility-properties');
Route::get('/tools/check-ebay-compatibility-property-values', [EbayListingDryRunController::class, 'compatibilityPropertyValues'])->name('tools.check-ebay-compatibility-property-values');
Route::get('/tools/dry-run-ebay-fitment-match', [EbayListingDryRunController::class, 'dryRunFitmentMatch'])->name('tools.dry-run-ebay-fitment-match');
Route::get('/tools/dry-run-ebay-fitment-coverage', [EbayListingDryRunController::class, 'dryRunFitmentCoverage'])->name('tools.dry-run-ebay-fitment-coverage');
Route::get('/tools/dry-run-ebay-part-compatibility-payload', [EbayListingDryRunController::class, 'dryRunCompatibilityPayload'])->name('tools.dry-run-ebay-part-compatibility-payload');
Route::get('/tools/check-ebay-listing-readiness-all', [EbayListingDryRunController::class, 'readinessAll'])->name('tools.check-ebay-listing-readiness-all');
Route::get('/tools/check-ebay-template-assets', [EbayDescriptionTemplateController::class, 'checkAssets'])->name('tools.check-ebay-template-assets');
Route::get('/tools/sync-ebay-template-assets-dry-run', [EbayDescriptionTemplateController::class, 'syncAssetsDryRun'])->name('tools.sync-ebay-template-assets-dry-run');
Route::get('/tools/sync-ebay-template-assets', [EbayDescriptionTemplateController::class, 'syncAssets'])->name('tools.sync-ebay-template-assets');
Route::get('/tools/preview-ebay-description-template', [EbayDescriptionTemplateController::class, 'preview'])->name('tools.preview-ebay-description-template');
Route::get('/tools/preview-ebay-description-template-html', [EbayDescriptionTemplateController::class, 'previewHtml'])->name('tools.preview-ebay-description-template-html');
Route::get('/tools/check-ebay-description-template', [EbayDescriptionTemplateController::class, 'checkTemplate'])->name('tools.check-ebay-description-template');
Route::get('/tools/check-google-translate-readiness', [GoogleTranslateDiagnosticsController::class, 'readiness'])->name('tools.check-google-translate-readiness');
Route::get('/tools/test-google-translate', [GoogleTranslateDiagnosticsController::class, 'test'])->name('tools.test-google-translate');
Route::get('/tools/dry-run-product-translation', [GoogleTranslateDiagnosticsController::class, 'dryRunProduct'])->name('tools.dry-run-product-translation');
Route::get('/tools/import-marketplace-orders', ImportMarketplaceOrdersController::class)->name('tools.import-marketplace-orders');
Route::get('/tools/debug-order-item-thumbnail', DebugOrderItemThumbnailController::class)->name('tools.debug-order-item-thumbnail');
Route::get('/tools/delete-test-marketplace-orders', DeleteTestMarketplaceOrdersController::class)->name('tools.delete-test-marketplace-orders');
Route::get('/tools/ovoko-orders-dry-run', OvokoOrdersDryRunController::class)->name('tools.ovoko-orders-dry-run');
Route::get('/tools/import-ovoko-orders-dry-run', ImportOvokoOrdersDryRunController::class)->name('tools.import-ovoko-orders-dry-run');
Route::get('/tools/ovoko-order-item-part-backfill/preview', [OvokoOrderItemPartBackfillController::class, 'preview'])->name('tools.ovoko-order-item-part-backfill.preview');
Route::post('/tools/ovoko-order-item-part-backfill', [OvokoOrderItemPartBackfillController::class, 'apply'])->name('tools.ovoko-order-item-part-backfill.apply');
Route::get('/tools/inspect-ovoko-orders-structure', [OvokoOrdersDryRunController::class, 'inspect'])->name('tools.inspect-ovoko-orders-structure');
Route::get('/tools/check-marketplace-mappings', CheckOvokoMappingController::class)->name('tools.check-marketplace-mappings');
Route::get('/tools/check-allegro-channels', CheckAllegroChannelsController::class)->name('tools.check-allegro-channels');
Route::get('/tools/run-ovoko-mapping-dry-run', RunOvokoMappingDryRunController::class)->name('tools.run-ovoko-mapping-dry-run');
Route::get('/tools/run-ovoko-mapping-live', RunOvokoMappingLiveController::class)->name('tools.run-ovoko-mapping-live');
Route::get('/tools/run-allegro-mapping-dry-run', RunAllegroMappingDryRunController::class)->name('tools.run-allegro-mapping-dry-run');
Route::get('/tools/run-allegro-mapping-live', RunAllegroMappingLiveController::class)->name('tools.run-allegro-mapping-live');
Route::get('/tools/run-ebay-mapping-dry-run', RunEbayMappingDryRunController::class)->name('tools.run-ebay-mapping-dry-run');
Route::get('/tools/run-ebay-mapping-live', RunEbayMappingLiveController::class)->name('tools.run-ebay-mapping-live');
Route::get('/tools/delete-allegro-gearboxes-dry-run', DeleteAllegroGearboxesDryRunController::class)->name('tools.delete-allegro-gearboxes-dry-run');
Route::get('/tools/delete-allegro-gearboxes-live', DeleteAllegroGearboxesLiveController::class)->name('tools.delete-allegro-gearboxes-live');
Route::get('/tools/purge-allegro-gearboxes-dry-run', PurgeAllegroGearboxesDryRunController::class)->name('tools.purge-allegro-gearboxes-dry-run');
Route::get('/tools/purge-allegro-gearboxes-live', PurgeAllegroGearboxesLiveController::class)->name('tools.purge-allegro-gearboxes-live');
Route::get('/tools/inspect-legacy-payload-keys', InspectLegacyPayloadKeysController::class)->name('tools.inspect-legacy-payload-keys');
Route::get('/tools/export-ovoko-unmapped', ExportOvokoUnmappedController::class)->name('tools.export-ovoko-unmapped');
Route::get('/tools/export-ovoko-orders-unmatched', ExportOvokoOrdersUnmatchedController::class)->name('tools.export-ovoko-orders-unmatched');
Route::get('/tools/check-part-number-performance', CheckPartNumberPerformanceController::class)->name('tools.check-part-number-performance');
Route::get('/tools/check-parts-to-list', CheckPartsToListController::class)->name('tools.check-parts-to-list');
Route::get('/tools/check-part-marketplace-readiness', [PartMarketplaceReadinessController::class, 'check'])->name('tools.check-part-marketplace-readiness');
Route::get('/tools/check-marketplace-listing-readiness', [MarketplaceListingDryRunController::class, 'readiness'])->name('tools.check-marketplace-listing-readiness');
Route::get('/tools/dry-run-marketplace-listing-payload', [MarketplaceListingDryRunController::class, 'payload'])->name('tools.dry-run-marketplace-listing-payload');
Route::get('/tools/dry-run-marketplace-listing-coverage', [MarketplaceListingDryRunController::class, 'coverage'])->name('tools.dry-run-marketplace-listing-coverage');
Route::get('/tools/dry-run-marketplace-listing-coverage-all', [MarketplaceListingDryRunController::class, 'coverageAll'])->name('tools.dry-run-marketplace-listing-coverage-all');
Route::get('/tools/export-marketplace-listing-coverage', [MarketplaceListingDryRunController::class, 'export'])->name('tools.export-marketplace-listing-coverage');
Route::get('/tools/check-part-marketplace-preparation-payload', [PartMarketplaceReadinessController::class, 'payload'])->name('tools.check-part-marketplace-preparation-payload');
Route::get('/tools/check-allegro-listing-status-read-only', AllegroListingReadOnlyStatusController::class)->name('tools.check-allegro-listing-status-read-only');
Route::get('/tools/allegro-listing-preview', [MarketplaceListingDryRunController::class, 'allegroPreview'])->name('tools.allegro-listing-preview');
Route::get('/tools/ebay-listing-preview', [PartMarketplaceReadinessController::class, 'ebayPreview'])->name('tools.ebay-listing-preview');
Route::get('/tools/ebay-listing-preview-html', [PartMarketplaceReadinessController::class, 'ebayPreviewHtml'])->name('tools.ebay-listing-preview-html');
Route::get('/tools/prepare-ebay-listing-translations', [PartMarketplaceReadinessController::class, 'prepareEbay'])->name('tools.prepare-ebay-listing-translations');
Route::get('/tools/prepare-ebay-listing-translations-all', [PartMarketplaceReadinessController::class, 'prepareEbayAll'])->name('tools.prepare-ebay-listing-translations-all');
Route::get('/tools/prepare-part-marketplace-card', [PartMarketplaceReadinessController::class, 'prepareCard'])->name('tools.prepare-part-marketplace-card');
Route::get('/tools/marketplace-category-children', [PartMarketplaceReadinessController::class, 'categoryChildren'])->name('tools.marketplace-category-children');
Route::get('/tools/part-category-children', [PartMarketplaceReadinessController::class, 'partCategoryChildren'])->name('tools.part-category-children');
Route::post('/tools/part-marketplace-category-mapping', [PartMarketplaceReadinessController::class, 'storeCategoryMapping'])->name('tools.part-marketplace-category-mapping.store');
Route::get('/tools/marketplace-publish-part-preview', [MarketplacePublishPartController::class, 'preview'])->name('tools.marketplace-publish-part-preview');
Route::get('/tools/marketplace-publish-part-confirm', [MarketplacePublishPartController::class, 'confirm'])->name('tools.marketplace-publish-part-confirm');
Route::get('/tools/check-admin-parts-table-ui', CheckAdminPartsTableUiController::class)->name('tools.check-admin-parts-table-ui');
Route::get('/tools/check-storefront-visibility', CheckStorefrontVisibilityController::class)->name('tools.check-storefront-visibility');
Route::get('/tools/mark-gps-gmail-to-list-dry-run', MarkGpsGmailToListDryRunController::class)->name('tools.mark-gps-gmail-to-list-dry-run');
Route::get('/tools/mark-gps-gmail-to-list-live', MarkGpsGmailToListLiveController::class)->name('tools.mark-gps-gmail-to-list-live');
Route::get('/tools/check-catalog-search', CheckCatalogSearchController::class)->name('tools.check-catalog-search');
Route::get('/tools/check-catalog-render', CheckCatalogRenderController::class)->name('tools.check-catalog-render');
Route::get('/tools/check-catalog-error', CheckCatalogErrorController::class)->name('tools.check-catalog-error');
Route::get('/tools/last-laravel-error', LastLaravelErrorController::class)->name('tools.last-laravel-error');
Route::get('/tools/check-czesci-render-ping', function () {
    if (! hash_equals('gps_images_import_2026', (string) request()->query('token', ''))) {
        return response()->json([
            'ok' => false,
            'error_message' => 'Invalid diagnostics token.',
        ], 403);
    }

    return response()->json([
        'ok' => true,
        'stage' => 'check-czesci-render-ping',
    ]);
})->name('tools.check-czesci-render-ping');
Route::get('/tools/check-czesci-final', function (Request $request) {
    if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
        return response()->json([
            'ok' => false,
            'error_message' => 'Invalid diagnostics token.',
        ], 403);
    }

    try {
        /** @var CatalogController $controller */
        $controller = app(CatalogController::class);
        $data = $controller->viewData($request);
        $html = view('storefront.catalog.index', $data)->render();
        $parts = $data['parts'] ?? null;

        return response()->json([
            'ok' => true,
            'parts_count' => method_exists($parts, 'count') ? $parts->count() : null,
            'total' => method_exists($parts, 'total') ? $parts->total() : null,
            'per_page' => method_exists($parts, 'perPage') ? $parts->perPage() : null,
            'rendered_length' => strlen($html),
            'data_keys' => array_keys($data),
        ]);
    } catch (\Throwable $exception) {
        return response()->json([
            'ok' => false,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace_first_20' => collect($exception->getTrace())->take(20)->map(fn ($frame) => [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
            ])->values()->all(),
        ], 200);
    }
})->name('tools.check-czesci-final');
Route::get('/tools/check-czesci-render-now', function () {
    if (! hash_equals('gps_images_import_2026', (string) request()->query('token', ''))) {
        return response()->json([
            'ok' => false,
            'error_message' => 'Invalid diagnostics token.',
        ], 403);
    }

    try {
        $step = (string) request()->query('step', 'index');
        $trace = function ($exception) {
            return collect($exception->getTrace())->take(20)->map(fn ($frame) => [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
            ])->values()->all();
        };
        $failure = function ($exception, $failedStep, $limit = null) use ($trace) {
            return response()->json([
                'ok' => false,
                'stage_entered' => true,
                'step' => $failedStep,
                'limit' => $limit,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace_first_20' => $trace($exception),
            ], 200);
        };

        $partSummary = function ($part) {
            if (is_int($part)) {
                return [
                    'id' => $part,
                    'scalar' => true,
                ];
            }

            if (is_array($part)) {
                return [
                    'id' => $part['id'] ?? null,
                    'name' => $part['name'] ?? null,
                    'title' => $part['title'] ?? null,
                    'slug' => $part['slug'] ?? null,
                    'price' => $part['price'] ?? null,
                    'main_image' => $part['main_image'] ?? $part['listing_image_url'] ?? null,
                    'array' => true,
                ];
            }

            if (! is_object($part)) {
                return [
                    'id' => null,
                    'scalar' => true,
                    'type' => gettype($part),
                ];
            }

            $mainImage = null;

            try {
                if (method_exists($part, 'listingImageUrl')) {
                    $mainImage = $part->listingImageUrl();
                }
            } catch (\Throwable $exception) {
                $mainImage = null;
            }

            return [
                'id' => $part->id ?? null,
                'name' => $part->name ?? null,
                'title' => $part->title ?? null,
                'slug' => $part->slug ?? null,
                'price' => $part->price ?? null,
                'main_image' => $mainImage,
            ];
        };

        $partsCollection = function ($parts) {
            if ($parts instanceof \Illuminate\Pagination\AbstractPaginator) {
                return $parts->getCollection();
            }

            return collect($parts);
        };
        $partIds = fn ($parts) => $partsCollection($parts)->pluck('id')->values()->all();

        $paginatorSummary = function ($parts) use ($partIds) {
            $summary = [
                'class' => is_object($parts) ? $parts::class : gettype($parts),
                'count' => is_object($parts) && method_exists($parts, 'count') ? $parts->count() : null,
                'total' => is_object($parts) && method_exists($parts, 'total') ? $parts->total() : null,
                'per_page' => is_object($parts) && method_exists($parts, 'perPage') ? $parts->perPage() : null,
                'current_page' => is_object($parts) && method_exists($parts, 'currentPage') ? $parts->currentPage() : null,
                'part_ids' => $parts instanceof \Illuminate\Pagination\AbstractPaginator ? $partIds($parts) : [],
            ];

            return $summary;
        };

        if ($step === '' || $step === 'index') {
            try {
                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'started',
                    'selected_step' => 'index',
                    'available_steps' => [
                        'routes',
                        'model',
                        'query',
                        'view-exists',
                        'render-minimal',
                        'render-index-empty',
                        'render-index-one',
                        'render-index-page',
                        'render-index-page-6',
                        'render-index-page-12',
                        'render-index-page-24',
                        'render-index-page-48',
                        'render-index-page-paginate',
                        'render-index-page-paginate-controller-resolve',
                        'render-index-page-paginate-viewdata',
                        'viewdata-step-start',
                        'viewdata-step-request',
                        'viewdata-step-base-query',
                        'viewdata-step-search',
                        'viewdata-step-sort',
                        'viewdata-step-paginate',
                        'viewdata-step-return-array',
                        'render-index-page-paginate-parts-only',
                        'render-index-page-paginate-render-content',
                        'render-index-page-paginate-render-index',
                        'render-product-cards-scan',
                        'render-index-page-small',
                        'render-index-page-small-collect',
                        'render-index-page-small-paginator',
                        'render-index-page-small-content-only',
                        'render-index-page-small-index',
                    ],
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'index');
            }
        }

        if ($step === 'routes') {
            try {
                $routeNameExists = \Illuminate\Support\Facades\Route::has('storefront.catalog');
                $routePathExists = collect(\Illuminate\Support\Facades\Route::getRoutes())->contains(
                    fn ($route) => in_array('GET', $route->methods(), true) && $route->uri() === 'czesci'
                );

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'routes',
                    'route_name_exists' => $routeNameExists,
                    'route_path_exists' => $routePathExists,
                    'catalog_url' => $routeNameExists ? route('storefront.catalog') : url('/czesci'),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'routes');
            }
        }

        if ($step === 'model') {
            try {
                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'model',
                    'part_model_exists' => class_exists('App\\Models\\Part'),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'model');
            }
        }

        if ($step === 'query') {
            try {
                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'query',
                    'count_limit_1' => \App\Models\Part::query()->limit(1)->count(),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'query');
            }
        }

        if ($step === 'view-exists') {
            try {
                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'view-exists',
                    'view_exists' => view()->exists('storefront.catalog.index'),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'view-exists');
            }
        }

        if ($step === 'render-minimal') {
            try {
                $html = \Illuminate\Support\Facades\Blade::render('<div>OK</div>');

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'render-minimal',
                    'rendered_length' => strlen($html),
                    'html' => $html,
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'render-minimal');
            }
        }

        if ($step === 'render-index-empty') {
            try {
                $html = view('storefront.catalog.index', [
                    'parts' => collect(),
                ])->render();

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'render-index-empty',
                    'rendered_length' => strlen($html),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'render-index-empty');
            }
        }

        if ($step === 'render-index-one') {
            try {
                $parts = \App\Models\Part::query()->limit(1)->get();
                $html = view('storefront.catalog.index', [
                    'parts' => $parts,
                ])->render();

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'render-index-one',
                    'parts_count' => $parts->count(),
                    'rendered_length' => strlen($html),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'render-index-one');
            }
        }

        if ($step === 'render-index-page') {
            try {
                $limits = [6, 12, 24, 48];
                $baseUrl = url('/tools/check-czesci-render-now').'?token=gps_images_import_2026&step=';

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'render-index-page',
                    'message' => 'This step is an index only. Use a limit step to render storefront.catalog.index.',
                    'limit_steps' => array_map(fn ($limit) => 'render-index-page-'.$limit, $limits),
                    'paginate_step' => 'render-index-page-paginate',
                    'paginate_diagnostic_steps' => [
                        'render-index-page-paginate-controller-resolve',
                        'render-index-page-paginate-viewdata',
                        'viewdata-step-start',
                        'viewdata-step-request',
                        'viewdata-step-base-query',
                        'viewdata-step-search',
                        'viewdata-step-sort',
                        'viewdata-step-paginate',
                        'viewdata-step-return-array',
                        'render-index-page-paginate-parts-only',
                        'render-index-page-paginate-render-content',
                        'render-index-page-paginate-render-index',
                    ],
                    'urls' => [
                        'limit_6' => $baseUrl.'render-index-page-6',
                        'limit_12' => $baseUrl.'render-index-page-12',
                        'limit_24' => $baseUrl.'render-index-page-24',
                        'limit_48' => $baseUrl.'render-index-page-48',
                        'paginate' => $baseUrl.'render-index-page-paginate',
                        'paginate_controller_resolve' => $baseUrl.'render-index-page-paginate-controller-resolve',
                        'paginate_viewdata' => $baseUrl.'render-index-page-paginate-viewdata',
                        'viewdata_step_start' => $baseUrl.'viewdata-step-start',
                        'viewdata_step_request' => $baseUrl.'viewdata-step-request',
                        'viewdata_step_base_query' => $baseUrl.'viewdata-step-base-query',
                        'viewdata_step_search' => $baseUrl.'viewdata-step-search',
                        'viewdata_step_sort' => $baseUrl.'viewdata-step-sort',
                        'viewdata_step_paginate' => $baseUrl.'viewdata-step-paginate',
                        'viewdata_step_return_array' => $baseUrl.'viewdata-step-return-array',
                        'paginate_parts_only' => $baseUrl.'render-index-page-paginate-parts-only',
                        'paginate_render_content' => $baseUrl.'render-index-page-paginate-render-content',
                        'paginate_render_index' => $baseUrl.'render-index-page-paginate-render-index',
                    ],
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'render-index-page', null);
            }
        }

        if ($step === 'render-product-cards-scan') {
            try {
                $parts = \App\Models\Part::query()
                    ->with(['images', 'category', 'car'])
                    ->storefrontVisible()
                    ->latest('updated_at')
                    ->limit(60)
                    ->get();
                $failures = [];
                $passedIds = [];

                foreach ($parts as $index => $part) {
                    try {
                        view('storefront.partials.product-card', ['part' => $part])->render();
                        $passedIds[] = $part->id;
                    } catch (\Throwable $exception) {
                        $failures[] = [
                            'index' => $index,
                            'part_id' => $part->id ?? null,
                            'part_name' => $part->name ?? null,
                            'part_title' => $part->title ?? null,
                            'exception_class' => $exception::class,
                            'exception_message' => $exception->getMessage(),
                            'file' => $exception->getFile(),
                            'line' => $exception->getLine(),
                        ];
                    }
                }

                return response()->json([
                    'ok' => count($failures) === 0,
                    'stage_entered' => true,
                    'step' => 'render-product-cards-scan',
                    'tested_count' => $parts->count(),
                    'failed_count' => count($failures),
                    'failures' => $failures,
                    'passed_ids' => $passedIds,
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'render-product-cards-scan');
            }
        }

        if ($step === 'render-index-page-small') {
            try {
                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'render-index-page-small',
                    'message' => 'This step is an index only. Use one of the render-index-page-small-* substeps.',
                    'substeps' => [
                        'render-index-page-small-collect',
                        'render-index-page-small-paginator',
                        'render-index-page-small-content-only',
                        'render-index-page-small-index',
                    ],
                    'urls' => [
                        'collect' => url('/tools/check-czesci-render-now').'?token=gps_images_import_2026&step=render-index-page-small-collect',
                        'paginator' => url('/tools/check-czesci-render-now').'?token=gps_images_import_2026&step=render-index-page-small-paginator',
                        'content_only' => url('/tools/check-czesci-render-now').'?token=gps_images_import_2026&step=render-index-page-small-content-only',
                        'index' => url('/tools/check-czesci-render-now').'?token=gps_images_import_2026&step=render-index-page-small-index',
                    ],
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'render-index-page-small');
            }
        }

        if ($step === 'render-index-page-small-collect') {
            try {
                $parts = \App\Models\Part::query()
                    ->with(['images', 'category', 'car'])
                    ->storefrontVisible()
                    ->latest('updated_at')
                    ->take(3)
                    ->get();

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'render-index-page-small-collect',
                    'count' => $parts->count(),
                    'ids' => $partIds($parts),
                    'parts' => $parts->map($partSummary)->values()->all(),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'render-index-page-small-collect');
            }
        }

        if ($step === 'render-index-page-small-paginator') {
            try {
                $collection = \App\Models\Part::query()
                    ->with(['images', 'category', 'car'])
                    ->storefrontVisible()
                    ->latest('updated_at')
                    ->take(3)
                    ->get();
                $parts = new \Illuminate\Pagination\LengthAwarePaginator(
                    $collection,
                    $collection->count(),
                    3,
                    1,
                    [
                        'path' => url('/czesci'),
                        'pageName' => 'page',
                    ]
                );

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'render-index-page-small-paginator',
                    'class' => $parts::class,
                    'count' => $parts->count(),
                    'total' => $parts->total(),
                    'per_page' => $parts->perPage(),
                    'current_page' => $parts->currentPage(),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'render-index-page-small-paginator');
            }
        }

        if ($step === 'render-index-page-small-content-only') {
            try {
                $collection = \App\Models\Part::query()
                    ->with(['images', 'category', 'car'])
                    ->storefrontVisible()
                    ->latest('updated_at')
                    ->take(3)
                    ->get();
                $parts = new \Illuminate\Pagination\LengthAwarePaginator(
                    $collection,
                    $collection->count(),
                    3,
                    1,
                    [
                        'path' => url('/czesci'),
                        'pageName' => 'page',
                    ]
                );
                $html = view('storefront.catalog._content', ['parts' => $parts])->render();

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'render-index-page-small-content-only',
                    'parts_count' => $parts->count(),
                    'rendered_length' => strlen($html),
                    'part_ids' => $partIds($parts),
                    'parts' => $partsCollection($parts)->map($partSummary)->values()->all(),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'render-index-page-small-content-only');
            }
        }

        if ($step === 'render-index-page-small-index') {
            try {
                $collection = \App\Models\Part::query()
                    ->with(['images', 'category', 'car'])
                    ->storefrontVisible()
                    ->latest('updated_at')
                    ->take(3)
                    ->get();
                $parts = new \Illuminate\Pagination\LengthAwarePaginator(
                    $collection,
                    $collection->count(),
                    3,
                    1,
                    [
                        'path' => url('/czesci'),
                        'pageName' => 'page',
                    ]
                );
                $html = view('storefront.catalog.index', ['parts' => $parts])->render();

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'render-index-page-small-index',
                    'parts_count' => $parts->count(),
                    'rendered_length' => strlen($html),
                    'part_ids' => $partIds($parts),
                    'parts' => $partsCollection($parts)->map($partSummary)->values()->all(),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'render-index-page-small-index');
            }
        }

        $limitSteps = [
            'render-index-page-6' => 6,
            'render-index-page-12' => 12,
            'render-index-page-24' => 24,
            'render-index-page-48' => 48,
        ];

        if (array_key_exists($step, $limitSteps)) {
            $limit = $limitSteps[$step];

            try {
                $collection = \App\Models\Part::query()
                    ->with(['images', 'category', 'car'])
                    ->storefrontVisible()
                    ->latest('updated_at')
                    ->take($limit)
                    ->get();
                $parts = new \Illuminate\Pagination\LengthAwarePaginator(
                    $collection,
                    $collection->count(),
                    $limit,
                    1,
                    [
                        'path' => url('/czesci'),
                        'pageName' => 'page',
                    ]
                );
                $html = view('storefront.catalog.index', ['parts' => $parts])->render();

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => $step,
                    'limit' => $limit,
                    'parts_count' => $parts->count(),
                    'rendered_length' => strlen($html),
                    'part_ids' => $partIds($parts),
                    'parts' => $partsCollection($parts)->map($partSummary)->values()->all(),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, $step, $limit);
            }
        }

        if ($step === 'render-index-page-paginate') {
            try {
                $baseUrl = url('/tools/check-czesci-render-now').'?token=gps_images_import_2026&step=';

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'render-index-page-paginate',
                    'message' => 'This step is an index only. Use the paginate diagnostic substeps to isolate controller resolution, viewData, query pagination, and rendering.',
                    'substeps' => [
                        'render-index-page-paginate-controller-resolve',
                        'render-index-page-paginate-viewdata',
                        'viewdata-step-start',
                        'viewdata-step-request',
                        'viewdata-step-base-query',
                        'viewdata-step-search',
                        'viewdata-step-sort',
                        'viewdata-step-paginate',
                        'viewdata-step-return-array',
                        'render-index-page-paginate-parts-only',
                        'render-index-page-paginate-render-content',
                        'render-index-page-paginate-render-index',
                    ],
                    'urls' => [
                        'controller_resolve' => $baseUrl.'render-index-page-paginate-controller-resolve',
                        'viewdata' => $baseUrl.'render-index-page-paginate-viewdata',
                        'viewdata_step_start' => $baseUrl.'viewdata-step-start',
                        'viewdata_step_request' => $baseUrl.'viewdata-step-request',
                        'viewdata_step_base_query' => $baseUrl.'viewdata-step-base-query',
                        'viewdata_step_search' => $baseUrl.'viewdata-step-search',
                        'viewdata_step_sort' => $baseUrl.'viewdata-step-sort',
                        'viewdata_step_paginate' => $baseUrl.'viewdata-step-paginate',
                        'viewdata_step_return_array' => $baseUrl.'viewdata-step-return-array',
                        'parts_only' => $baseUrl.'render-index-page-paginate-parts-only',
                        'render_content' => $baseUrl.'render-index-page-paginate-render-content',
                        'render_index' => $baseUrl.'render-index-page-paginate-render-index',
                    ],
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'render-index-page-paginate');
            }
        }

        if ($step === 'render-index-page-paginate-controller-resolve') {
            try {
                $controller = app(\App\Http\Controllers\Storefront\CatalogController::class);

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'render-index-page-paginate-controller-resolve',
                    'controller_class' => $controller::class,
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'render-index-page-paginate-controller-resolve');
            }
        }

        if ($step === 'render-index-page-paginate-viewdata') {
            try {
                $controller = app(\App\Http\Controllers\Storefront\CatalogController::class);
                $categoryTree = app(\App\Services\Storefront\CategoryTreeService::class);
                $data = $controller->viewData(request(), $categoryTree);
                $parts = $data['parts'] ?? null;

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'render-index-page-paginate-viewdata',
                    'data_keys' => array_keys($data),
                    'parts' => $paginatorSummary($parts),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'render-index-page-paginate-viewdata');
            }
        }

        if ($step === 'viewdata-step-start') {
            try {
                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'viewdata-step-start',
                    'controller_class' => \App\Http\Controllers\Storefront\CatalogController::class,
                    'category_tree_class' => \App\Services\Storefront\CategoryTreeService::class,
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'viewdata-step-start');
            }
        }

        if ($step === 'viewdata-step-request') {
            try {
                $diagnosticRequest = request();

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'viewdata-step-request',
                    'request_class' => $diagnosticRequest::class,
                    'path' => $diagnosticRequest->path(),
                    'query' => $diagnosticRequest->query(),
                    'inputs_used_by_viewdata' => [
                        'q' => $diagnosticRequest->string('q')->toString(),
                        'part_number' => $diagnosticRequest->string('part_number')->toString(),
                        'price_from' => $diagnosticRequest->input('price_from', $diagnosticRequest->input('price_min')),
                        'price_to' => $diagnosticRequest->input('price_to', $diagnosticRequest->input('price_max')),
                        'producer' => $diagnosticRequest->string('producer')->toString(),
                        'model' => $diagnosticRequest->string('model')->toString(),
                        'vehicle_model' => $diagnosticRequest->string('vehicle_model')->toString(),
                        'category' => $diagnosticRequest->string('category')->toString(),
                        'sort' => $diagnosticRequest->string('sort')->toString(),
                    ],
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'viewdata-step-request');
            }
        }

        if ($step === 'viewdata-step-base-query') {
            try {
                $query = \App\Models\Part::query()
                    ->with(['images', 'category', 'car'])
                    ->storefrontVisible();

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'viewdata-step-base-query',
                    'count_limit_1' => (clone $query)->limit(1)->count(),
                    'sql' => $query->toSql(),
                    'bindings' => $query->getBindings(),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'viewdata-step-base-query');
            }
        }

        if ($step === 'viewdata-step-search') {
            try {
                $diagnosticRequest = request();
                $query = \App\Models\Part::query()
                    ->with(['images', 'category', 'car'])
                    ->storefrontVisible()
                    ->searchStorefront($diagnosticRequest->string('q')->toString())
                    ->partNumberSearch($diagnosticRequest->string('part_number')->toString())
                    ->priceBetween(
                        $diagnosticRequest->input('price_from', $diagnosticRequest->input('price_min')),
                        $diagnosticRequest->input('price_to', $diagnosticRequest->input('price_max')),
                    );

                $producer = trim($diagnosticRequest->string('producer')->toString());
                if ($producer !== '') {
                    $query->whereStorefrontDetail('make', $producer);
                }

                $model = trim($diagnosticRequest->string('model')->toString());
                if ($model !== '') {
                    $query->whereStorefrontDetail('model', $model);
                }

                $vehicleModel = trim($diagnosticRequest->string('vehicle_model')->toString());
                if ($vehicleModel !== '') {
                    foreach (preg_split('/\s+/', $vehicleModel) ?: [] as $token) {
                        $query->where(function (\Illuminate\Database\Eloquent\Builder $inner) use ($token): void {
                            $like = '%'.$token.'%';
                            $inner->where('name', 'like', $like)->orWhereHas('car', function (\Illuminate\Database\Eloquent\Builder $carQuery) use ($like): void {
                                $carQuery->where('make', 'like', $like)
                                    ->orWhere('model', 'like', $like)
                                    ->orWhere('model_variant', 'like', $like)
                                    ->orWhere('engine_code', 'like', $like);
                            });
                        });
                    }
                }

                $category = trim($diagnosticRequest->string('category')->toString());
                if ($category !== '') {
                    $query->whereHas('category', fn (\Illuminate\Database\Eloquent\Builder $categoryQuery) => $categoryQuery->where('slug', $category)->orWhere('name', 'like', '%'.$category.'%'));
                }

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'viewdata-step-search',
                    'count_limit_1' => (clone $query)->limit(1)->count(),
                    'sql' => $query->toSql(),
                    'bindings' => $query->getBindings(),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'viewdata-step-search');
            }
        }

        if ($step === 'viewdata-step-sort') {
            try {
                $diagnosticRequest = request();
                $query = \App\Models\Part::query()
                    ->with(['images', 'category', 'car'])
                    ->storefrontVisible()
                    ->searchStorefront($diagnosticRequest->string('q')->toString())
                    ->partNumberSearch($diagnosticRequest->string('part_number')->toString())
                    ->priceBetween(
                        $diagnosticRequest->input('price_from', $diagnosticRequest->input('price_min')),
                        $diagnosticRequest->input('price_to', $diagnosticRequest->input('price_max')),
                    );

                $producer = trim($diagnosticRequest->string('producer')->toString());
                if ($producer !== '') {
                    $query->whereStorefrontDetail('make', $producer);
                }

                $model = trim($diagnosticRequest->string('model')->toString());
                if ($model !== '') {
                    $query->whereStorefrontDetail('model', $model);
                }

                $vehicleModel = trim($diagnosticRequest->string('vehicle_model')->toString());
                if ($vehicleModel !== '') {
                    foreach (preg_split('/\s+/', $vehicleModel) ?: [] as $token) {
                        $query->where(function (\Illuminate\Database\Eloquent\Builder $inner) use ($token): void {
                            $like = '%'.$token.'%';
                            $inner->where('name', 'like', $like)->orWhereHas('car', function (\Illuminate\Database\Eloquent\Builder $carQuery) use ($like): void {
                                $carQuery->where('make', 'like', $like)
                                    ->orWhere('model', 'like', $like)
                                    ->orWhere('model_variant', 'like', $like)
                                    ->orWhere('engine_code', 'like', $like);
                            });
                        });
                    }
                }

                $category = trim($diagnosticRequest->string('category')->toString());
                if ($category !== '') {
                    $query->whereHas('category', fn (\Illuminate\Database\Eloquent\Builder $categoryQuery) => $categoryQuery->where('slug', $category)->orWhere('name', 'like', '%'.$category.'%'));
                }

                match ($diagnosticRequest->string('sort')->toString()) {
                    'price_asc' => $query->orderByRaw('price is null')->orderBy('price'),
                    'price_desc' => $query->orderByDesc('price'),
                    'name' => $query->orderBy('name'),
                    default => $query->latest('updated_at'),
                };

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'viewdata-step-sort',
                    'sort' => $diagnosticRequest->string('sort')->toString(),
                    'count_limit_1' => (clone $query)->limit(1)->count(),
                    'sql' => $query->toSql(),
                    'bindings' => $query->getBindings(),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'viewdata-step-sort');
            }
        }

        if ($step === 'viewdata-step-paginate') {
            try {
                $controller = app(\App\Http\Controllers\Storefront\CatalogController::class);
                $reflection = new \ReflectionMethod($controller, 'storefrontQuery');
                $reflection->setAccessible(true);
                $partsQuery = $reflection->invoke($controller, request());
                $parts = $partsQuery->paginate(60)->withQueryString();

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'viewdata-step-paginate',
                    'parts' => $paginatorSummary($parts),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'viewdata-step-paginate');
            }
        }

        if ($step === 'viewdata-step-return-array') {
            try {
                $controller = app(\App\Http\Controllers\Storefront\CatalogController::class);
                $categoryTree = app(\App\Services\Storefront\CategoryTreeService::class);
                $filterReflection = new \ReflectionMethod($controller, 'storefrontFilterOptions');
                $filterReflection->setAccessible(true);
                $filterOptions = $filterReflection->invoke($controller, \App\Models\Part::query()->storefrontVisible());
                $categoryRoots = $categoryTree->roots();
                $data = [
                    'categoryRoots' => $categoryRoots,
                    'categoryTreeService' => $categoryTree,
                    'producers' => $filterOptions['producers'] ?? [],
                    'models' => $filterOptions['models'] ?? [],
                    'metaTitle' => 'Katalog części GPSwiss - używane części samochodowe',
                    'metaDescription' => 'Katalog oryginalnych używanych części samochodowych GPSwiss.',
                    'breadcrumbs' => [['label' => 'Strona główna', 'url' => route('storefront.home')], ['label' => 'Katalog części']],
                ];

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'viewdata-step-return-array',
                    'data_keys' => array_keys($data),
                    'category_roots_count' => method_exists($categoryRoots, 'count') ? $categoryRoots->count() : null,
                    'producers_count' => count($data['producers']),
                    'models_count' => count($data['models']),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'viewdata-step-return-array');
            }
        }

        if ($step === 'render-index-page-paginate-parts-only') {
            try {
                $parts = \App\Models\Part::query()
                    ->with(['images', 'category', 'car'])
                    ->storefrontVisible()
                    ->searchStorefront(request()->string('q')->toString())
                    ->partNumberSearch(request()->string('part_number')->toString())
                    ->priceBetween(
                        request()->input('price_from', request()->input('price_min')),
                        request()->input('price_to', request()->input('price_max')),
                    );

                $producer = trim(request()->string('producer')->toString());
                if ($producer !== '') {
                    $parts->whereStorefrontDetail('make', $producer);
                }

                $model = trim(request()->string('model')->toString());
                if ($model !== '') {
                    $parts->whereStorefrontDetail('model', $model);
                }

                $vehicleModel = trim(request()->string('vehicle_model')->toString());
                if ($vehicleModel !== '') {
                    foreach (preg_split('/\s+/', $vehicleModel) ?: [] as $token) {
                        $parts->where(function ($inner) use ($token) {
                            $like = '%'.$token.'%';
                            $inner->where('name', 'like', $like)->orWhereHas('car', function ($carQuery) use ($like) {
                                $carQuery->where('make', 'like', $like)
                                    ->orWhere('model', 'like', $like)
                                    ->orWhere('model_variant', 'like', $like)
                                    ->orWhere('engine_code', 'like', $like);
                            });
                        });
                    }
                }

                $category = trim(request()->string('category')->toString());
                if ($category !== '') {
                    $parts->whereHas('category', function ($categoryQuery) use ($category) {
                        $categoryQuery->where('slug', $category)->orWhere('name', 'like', '%'.$category.'%');
                    });
                }

                match (request()->string('sort')->toString()) {
                    'price_asc' => $parts->orderByRaw('price is null')->orderBy('price'),
                    'price_desc' => $parts->orderByDesc('price'),
                    'name' => $parts->orderBy('name'),
                    default => $parts->latest('updated_at'),
                };

                $parts = $parts->paginate(60)->withQueryString();

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'render-index-page-paginate-parts-only',
                    'parts' => $paginatorSummary($parts),
                    'sample_parts' => $partsCollection($parts)->map($partSummary)->values()->all(),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'render-index-page-paginate-parts-only');
            }
        }

        if ($step === 'render-index-page-paginate-render-content') {
            try {
                $controller = app(\App\Http\Controllers\Storefront\CatalogController::class);
                $categoryTree = app(\App\Services\Storefront\CategoryTreeService::class);
                $data = $controller->viewData(request(), $categoryTree);
                $parts = $data['parts'] ?? null;
                $html = view('storefront.catalog._content', $data)->render();

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'render-index-page-paginate-render-content',
                    'parts' => $paginatorSummary($parts),
                    'rendered_length' => strlen($html),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'render-index-page-paginate-render-content');
            }
        }

        if ($step === 'render-index-page-paginate-render-index') {
            try {
                $controller = app(\App\Http\Controllers\Storefront\CatalogController::class);
                $categoryTree = app(\App\Services\Storefront\CategoryTreeService::class);
                $data = $controller->viewData(request(), $categoryTree);
                $parts = $data['parts'] ?? null;
                $html = view('storefront.catalog.index', $data)->render();

                return response()->json([
                    'ok' => true,
                    'stage_entered' => true,
                    'step' => 'render-index-page-paginate-render-index',
                    'parts' => $paginatorSummary($parts),
                    'rendered_length' => strlen($html),
                ], 200);
            } catch (\Throwable $exception) {
                return $failure($exception, 'render-index-page-paginate-render-index');
            }
        }

        return response()->json([
            'ok' => false,
            'stage_entered' => true,
            'step' => $step,
            'error_message' => 'Unknown diagnostics step.',
        ], 200);
    } catch (\Throwable $exception) {
        return response()->json([
            'ok' => false,
            'stage_entered' => false,
            'step' => 'outer',
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace_first_20' => collect($exception->getTrace())->take(20)->map(fn ($frame) => [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
            ])->values()->all(),
        ], 200);
    }
})->name('tools.check-czesci-render-now');
Route::get('/tools/mark-log', function (Request $request) {
    if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
        return response()->json([
            'ok' => false,
            'error_message' => 'Invalid diagnostics token.',
        ], 403);
    }

    $label = trim((string) $request->query('label', 'manual'));
    $label = preg_replace('/[^A-Za-z0-9_.:-]+/', '-', $label) ?: 'manual';
    $timestamp = now()->toIso8601String();
    $logFile = storage_path('logs/laravel.log');

    \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($logFile));
    \Illuminate\Support\Facades\File::append($logFile, "[CATALOG_MARKER] {$label} {$timestamp}\n");

    return response()->json([
        'ok' => true,
        'log_file' => $logFile,
        'label' => $label,
        'marker' => "[CATALOG_MARKER] {$label} {$timestamp}",
        'timestamp' => $timestamp,
    ]);
})->name('tools.mark-log');
Route::get('/tools/check-catalog-direct', function () {
    try {
        $trace = function ($exception) {
            return collect($exception->getTrace())->take(5)->map(fn ($frame) => [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
            ])->values()->all();
        };

        $fail = function (\Throwable $exception, string $stage) use ($trace) {
            return response()->json([
                'ok' => false,
                'failed_stage' => $stage,
                'error_class' => $exception::class,
                'error_message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $trace($exception),
            ], 200);
        };

        $result = [
            'ok' => true,
            'route_entered' => true,
        ];

        try {
            if (! hash_equals('gps_images_import_2026', (string) request()->query('token', ''))) {
                return response()->json([
                    'ok' => false,
                    'failed_stage' => 'stage_token',
                    'error_class' => 'AuthorizationException',
                    'error_message' => 'Invalid diagnostics token.',
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'trace' => [],
                ], 403);
            }

            $result['stage_token'] = true;
        } catch (\Throwable $exception) {
            return $fail($exception, 'stage_token');
        }

        try {
            $result['stage_part_model_exists'] = class_exists(\App\Models\Part::class);
        } catch (\Throwable $exception) {
            return $fail($exception, 'stage_part_model_exists');
        }

        try {
            $result['stage_part_count'] = \App\Models\Part::query()->count();
        } catch (\Throwable $exception) {
            return $fail($exception, 'stage_part_count');
        }

        try {
            $result['stage_catalog_controller_exists'] = class_exists(\App\Http\Controllers\Storefront\CatalogController::class);
        } catch (\Throwable $exception) {
            return $fail($exception, 'stage_catalog_controller_exists');
        }

        try {
            $result['stage_catalog_route_exists'] = collect(\Illuminate\Support\Facades\Route::getRoutes())->contains(
                fn ($route) => in_array('GET', $route->methods(), true) && $route->uri() === 'czesci'
            );
        } catch (\Throwable $exception) {
            return $fail($exception, 'stage_catalog_route_exists');
        }

        try {
            $result['stage_catalog_view_exists'] = view()->exists('storefront.catalog.index');
        } catch (\Throwable $exception) {
            return $fail($exception, 'stage_catalog_view_exists');
        }

        return response()->json($result);
    } catch (\Throwable $exception) {
        return response()->json([
            'ok' => false,
            'failed_stage' => 'route_outer',
            'requested_stage' => 'route_outer',
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => collect($exception->getTrace())->take(5)->map(fn ($frame) => [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
            ])->values()->all(),
        ], 200);
    }
})->name('tools.check-catalog-direct');
Route::get('/tools/check-catalog-minimal-view', function () {
    try {
        if (! hash_equals('gps_images_import_2026', (string) request()->query('token', ''))) {
            return response()->json([
                'ok' => false,
                'requested_stage' => 'token',
                'exception_class' => 'AuthorizationException',
                'exception_message' => 'Invalid diagnostics token.',
                'file' => __FILE__,
                'line' => __LINE__,
                'trace' => [],
            ], 403);
        }

        $parts = \App\Models\Part::query()->limit(5)->get(['id', 'name', 'part_number']);
        $items = $parts->map(fn ($part): array => [
            'id' => $part->id,
            'name' => $part->name,
            'part_number' => $part->part_number,
        ])->values();

        if ((string) request()->query('render', '') === '1') {
            $html = '<!doctype html><html><head><meta charset="utf-8"><title>Catalog minimal diagnostic</title></head><body><h1>Catalog minimal diagnostic</h1><ul>';

            foreach ($items as $part) {
                $html .= '<li>#'.e((string) $part['id']).' '.e((string) $part['name']).' — '.e((string) ($part['part_number'] ?? '')).'</li>';
            }

            return response($html.'</ul></body></html>');
        }

        return response()->json([
            'ok' => true,
            'parts' => $items,
        ]);
    } catch (\Throwable $exception) {
        return response()->json([
            'ok' => false,
            'requested_stage' => 'minimal_view',
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => collect($exception->getTrace())->take(5)->map(fn ($frame) => [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
            ])->values()->all(),
        ], 200);
    }
})->name('tools.check-catalog-minimal-view');
Route::get('/tools/check-catalog-view-ping', function (Request $request) {
    if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
        return response()->json([
            'ok' => false,
            'error_class' => 'AuthorizationException',
            'error_message' => 'Invalid diagnostics token.',
            'file' => __FILE__,
            'line' => __LINE__,
            'trace' => [],
        ], 403);
    }

    return response()->json(['ok' => true, 'route' => 'check-catalog-view-ping']);
})->name('tools.check-catalog-view-ping');
Route::get('/tools/check-catalog-view', CheckCatalogViewController::class)->name('tools.check-catalog-view');
Route::get('/tools/check-catalog-view-stage', CheckCatalogViewStageController::class)->name('tools.check-catalog-view-stage');
Route::get('/tools/check-catalog-blade-stages', function () {
    $makeTrace = function (\Throwable $exception): array {
        return collect($exception->getTrace())->take(10)->map(fn ($frame) => [
            'file' => $frame['file'] ?? null,
            'line' => $frame['line'] ?? null,
            'function' => $frame['function'] ?? null,
            'class' => $frame['class'] ?? null,
        ])->values()->all();
    };

    $fail = function (\Throwable $exception, string $stage) use ($makeTrace) {
        return response()->json([
            'ok' => false,
            'failed_stage' => $stage,
            'requested_stage' => $stage,
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $makeTrace($exception),
        ], 200);
    };

    try {
        if (! hash_equals('gps_images_import_2026', (string) ($_GET['token'] ?? ''))) {
            return response()->json([
                'ok' => false,
                'failed_stage' => 'token',
                'requested_stage' => 'token',
                'exception_class' => 'AuthorizationException',
                'exception_message' => 'Invalid diagnostics token.',
                'file' => __FILE__,
                'line' => __LINE__,
                'trace' => [],
            ], 403);
        }
    } catch (\Throwable $exception) {
        return $fail($exception, 'token');
    }

    try {
        $requestedStageRaw = (string) ($_GET['stage'] ?? '');
        $requestedStage = strtolower($requestedStageRaw) === 'ping' ? 'ping' : strtoupper($requestedStageRaw);

        if ($requestedStage === 'ping') {
            return response()->json(['ok' => true, 'stage' => 'ping']);
        }

        $allowedStages = ['A', 'B', 'C', 'D', 'E', 'F', 'F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F6A', 'F6B', 'F6B1', 'F6B2', 'F6B3', 'F6B4', 'F6B5', 'F6B_PING', 'F6C', 'F6C1', 'F6C2', 'F6C3', 'F6C4', 'F6C5', 'F6D', 'F6E', 'F6F', 'G'];

        if ($requestedStage !== '' && ! in_array($requestedStage, $allowedStages, true)) {
            return response()->json([
                'ok' => false,
                'failed_stage' => 'stage_parameter',
                'requested_stage' => $requestedStage,
                'exception_class' => 'InvalidArgumentException',
                'exception_message' => 'Invalid stage parameter. Allowed values: ping, A, B, C, D, E, F, F1, F2, F3, F4, F5, F6, F6A, F6B, F6B1, F6B2, F6B3, F6B4, F6B5, F6B_ping, F6C, F6C1, F6C2, F6C3, F6C4, F6C5, F6D, F6E, F6F, G.',
                'file' => __FILE__,
                'line' => __LINE__,
                'trace' => [],
            ], 422);
        }

        if ($requestedStage === 'F') {
            return response()->json([
                'ok' => true,
                'route_entered' => true,
                'requested_stage' => 'F',
                'message' => 'Stage F is a diagnostic index only. Run F1-F6 separately; F never renders storefront.catalog._content automatically.',
                'available_substages' => [
                    'F1' => 'Check whether storefront.catalog._content exists without rendering it.',
                    'F2' => 'Read the first lines of _content.blade.php without rendering Blade.',
                    'F3' => 'Count selected Blade directives in _content.blade.php without rendering Blade.',
                    'F4' => 'Render a small product-card Blade diagnostic snippet.',
                    'F5' => 'Render a small catalog filter Blade diagnostic snippet.',
                    'F6' => 'Diagnostic index for F6A-F6F; never renders storefront.catalog._content.',
                    'F6A' => 'Prepare catalog data and inspect the parts paginator/collection without rendering Blade.',
                    'F6B_ping' => 'Minimal F6B reachability ping; does not touch models, views, or Blade.',
                    'F6B' => 'Diagnostic index for F6B1-F6B5; never touches models, request(), route(), url(), views, or Blade.',
                    'F6C' => 'Diagnostic index for F6C1-F6C5; never renders views, partials, or Blade automatically.',
                    'F6D' => 'Render only the product grid with @forelse and product-card, without pagination.',
                    'F6E' => 'Render only pagination using unescaped Blade output.',
                    'F6F' => 'Render the full storefront.catalog._content view with catalog data.',
                ],
            ]);
        }

        if ($requestedStage === 'F6') {
            return response()->json([
                'ok' => true,
                'route_entered' => true,
                'requested_stage' => 'F6',
                'message' => 'Stage F6 is a diagnostic index only. Run F6A-F6F separately; F6 never renders storefront.catalog._content automatically.',
                'available_substages' => [
                    'F6A' => 'Prepare catalog data and inspect the parts paginator/collection without rendering Blade.',
                    'F6B_ping' => 'Minimal F6B reachability ping; does not touch models, views, or Blade.',
                    'F6B' => 'Diagnostic index for F6B1-F6B5; never touches models, request(), route(), url(), views, or Blade.',
                    'F6C' => 'Diagnostic index for F6C1-F6C5; never renders views, partials, or Blade automatically.',
                    'F6D' => 'Render only the product grid with @forelse and product-card, without pagination.',
                    'F6E' => 'Render only pagination using unescaped Blade output.',
                    'F6F' => 'Render the full storefront.catalog._content view with catalog data.',
                ],
            ]);
        }

        if ($requestedStage === 'F6B_PING') {
            return response()->json(['ok' => true, 'stage' => 'F6B_ping']);
        }

        if ($requestedStage === 'F6B') {
            try {
                return response()->json([
                    'ok' => true,
                    'stage' => 'F6B',
                    'message' => 'F6B is a diagnostic index only. Run F6B1-F6B5 separately to isolate the failing PHP line.',
                    'available_substages' => ['F6B1', 'F6B2', 'F6B3', 'F6B4', 'F6B5'],
                ], 200);
            } catch (\Throwable $exception) {
                return response()->json([
                    'ok' => false,
                    'failed_stage' => 'F6B',
                    'requested_stage' => 'F6B',
                    'exception_class' => get_class($exception),
                    'exception_message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ], 200);
            }
        }

        if ($requestedStage === 'F6B1') {
            try {
                return response()->json(['ok' => true, 'stage' => 'F6B1'], 200);
            } catch (\Throwable $exception) {
                return response()->json(['ok' => false, 'failed_stage' => 'F6B1', 'requested_stage' => 'F6B1', 'exception_class' => get_class($exception), 'exception_message' => $exception->getMessage(), 'file' => $exception->getFile(), 'line' => $exception->getLine()], 200);
            }
        }

        if ($requestedStage === 'F6B2') {
            try {
                return response()->json(['ok' => true, 'stage' => 'F6B2', 'query_values' => ['q' => (string) ($_GET['q'] ?? ''), 'part_number' => (string) ($_GET['part_number'] ?? ''), 'sort' => (string) ($_GET['sort'] ?? '')]], 200);
            } catch (\Throwable $exception) {
                return response()->json(['ok' => false, 'failed_stage' => 'F6B2', 'requested_stage' => 'F6B2', 'exception_class' => get_class($exception), 'exception_message' => $exception->getMessage(), 'file' => $exception->getFile(), 'line' => $exception->getLine()], 200);
            }
        }

        if ($requestedStage === 'F6B3') {
            try {
                return response()->json(['ok' => true, 'stage' => 'F6B3', 'catalog_url' => '/czesci'], 200);
            } catch (\Throwable $exception) {
                return response()->json(['ok' => false, 'failed_stage' => 'F6B3', 'requested_stage' => 'F6B3', 'exception_class' => get_class($exception), 'exception_message' => $exception->getMessage(), 'file' => $exception->getFile(), 'line' => $exception->getLine()], 200);
            }
        }

        if ($requestedStage === 'F6B4') {
            try {
                return response()->json(['ok' => true, 'stage' => 'F6B4', 'part_model_exists' => class_exists('App\\Models\\Part')], 200);
            } catch (\Throwable $exception) {
                return response()->json(['ok' => false, 'failed_stage' => 'F6B4', 'requested_stage' => 'F6B4', 'exception_class' => get_class($exception), 'exception_message' => $exception->getMessage(), 'file' => $exception->getFile(), 'line' => $exception->getLine()], 200);
            }
        }

        if ($requestedStage === 'F6B5') {
            try {
                $partCount = \App\Models\Part::query()->limit(1)->count();

                return response()->json(['ok' => true, 'stage' => 'F6B5', 'part_count_limited_to_one' => $partCount], 200);
            } catch (\Throwable $exception) {
                return response()->json(['ok' => false, 'failed_stage' => 'F6B5', 'requested_stage' => 'F6B5', 'exception_class' => get_class($exception), 'exception_message' => $exception->getMessage(), 'file' => $exception->getFile(), 'line' => $exception->getLine()], 200);
            }
        }

        if ($requestedStage === 'F6C') {
            try {
                return response()->json([
                    'ok' => true,
                    'stage' => 'F6C',
                    'message' => 'F6C is a diagnostic index only. Run F6C1-F6C5 separately to isolate toolbar/sort Blade behavior.',
                    'available_substages' => ['F6C1', 'F6C2', 'F6C3', 'F6C4', 'F6C5'],
                ], 200);
            } catch (\Throwable $exception) {
                return response()->json(['ok' => false, 'failed_stage' => 'F6C', 'requested_stage' => 'F6C', 'exception_class' => get_class($exception), 'exception_message' => $exception->getMessage(), 'file' => $exception->getFile(), 'line' => $exception->getLine()], 200);
            }
        }

        if ($requestedStage === 'F6C1') {
            try {
                return response()->json(['ok' => true, 'stage' => 'F6C1'], 200);
            } catch (\Throwable $exception) {
                return response()->json(['ok' => false, 'failed_stage' => 'F6C1', 'requested_stage' => 'F6C1', 'exception_class' => get_class($exception), 'exception_message' => $exception->getMessage(), 'file' => $exception->getFile(), 'line' => $exception->getLine()], 200);
            }
        }

        if ($requestedStage === 'F6C2') {
            try {
                $sortableQuery = $_GET;
                unset($sortableQuery['sort'], $sortableQuery['page']);

                return response()->json(['ok' => true, 'stage' => 'F6C2', 'sortable_query' => $sortableQuery], 200);
            } catch (\Throwable $exception) {
                return response()->json(['ok' => false, 'failed_stage' => 'F6C2', 'requested_stage' => 'F6C2', 'exception_class' => get_class($exception), 'exception_message' => $exception->getMessage(), 'file' => $exception->getFile(), 'line' => $exception->getLine()], 200);
            }
        }

        if ($requestedStage === 'F6C3') {
            try {
                $sort = (string) ($_GET['sort'] ?? '');
                $priceAscSelected = $sort === 'price_asc' ? ' selected' : '';
                $priceDescSelected = $sort === 'price_desc' ? ' selected' : '';
                $nameSelected = $sort === 'name' ? ' selected' : '';
                $htmlPreview = '<section><div class="sf-toolbar"><span>1 wyników</span><form method="get" action="/czesci"><select name="sort"><option value="">Sortuj domyślnie</option><option value="price_asc"'.$priceAscSelected.'>Cena rosnąco</option><option value="price_desc"'.$priceDescSelected.'>Cena malejąco</option><option value="name"'.$nameSelected.'>Nazwa</option></select></form></div></section>';

                return response()->json(['ok' => true, 'stage' => 'F6C3', 'html_preview' => $htmlPreview], 200);
            } catch (\Throwable $exception) {
                return response()->json(['ok' => false, 'failed_stage' => 'F6C3', 'requested_stage' => 'F6C3', 'exception_class' => get_class($exception), 'exception_message' => $exception->getMessage(), 'file' => $exception->getFile(), 'line' => $exception->getLine()], 200);
            }
        }

        if ($requestedStage === 'F6C4') {
            try {
                $resultCount = 1;
                $html = \Illuminate\Support\Facades\Blade::render('<span>{{ $resultCount }} wyników</span>', ['resultCount' => $resultCount], deleteCachedView: true);

                return response()->json(['ok' => true, 'stage' => 'F6C4', 'rendered_length' => strlen($html), 'html_preview' => $html], 200);
            } catch (\Throwable $exception) {
                return response()->json(['ok' => false, 'failed_stage' => 'F6C4', 'requested_stage' => 'F6C4', 'exception_class' => get_class($exception), 'exception_message' => $exception->getMessage(), 'file' => $exception->getFile(), 'line' => $exception->getLine()], 200);
            }
        }

        if ($requestedStage === 'F6C5') {
            try {
                $sort = (string) ($_GET['sort'] ?? '');
                $selected = [
                    'price_asc' => $sort === 'price_asc' ? 'selected' : '',
                    'price_desc' => $sort === 'price_desc' ? 'selected' : '',
                    'name' => $sort === 'name' ? 'selected' : '',
                ];
                $html = \Illuminate\Support\Facades\Blade::render('<select name="sort"><option value="">Sortuj domyślnie</option><option value="price_asc" {{ $selected["price_asc"] }}>Cena rosnąco</option><option value="price_desc" {{ $selected["price_desc"] }}>Cena malejąco</option><option value="name" {{ $selected["name"] }}>Nazwa</option></select>', ['selected' => $selected], deleteCachedView: true);

                return response()->json(['ok' => true, 'stage' => 'F6C5', 'rendered_length' => strlen($html), 'html_preview' => $html], 200);
            } catch (\Throwable $exception) {
                return response()->json(['ok' => false, 'failed_stage' => 'F6C5', 'requested_stage' => 'F6C5', 'exception_class' => get_class($exception), 'exception_message' => $exception->getMessage(), 'file' => $exception->getFile(), 'line' => $exception->getLine()], 200);
            }
        }

        $stagesToRun = $requestedStage === '' ? ['A', 'B', 'C', 'D', 'E', 'G'] : [$requestedStage];
        $result = [
            'ok' => true,
            'route_entered' => true,
            'requested_stage' => $requestedStage === '' ? null : $requestedStage,
            'stages' => [],
        ];
    } catch (\Throwable $exception) {
        return $fail($exception, 'stage_parameter');
    }

    $contentView = 'storefront.catalog._content';
    $contentPath = resource_path('views/storefront/catalog/_content.blade.php');

    $runStage = function (string $stage) use ($contentView, $contentPath): array {
        if ($stage === 'A') {
            $html = '<!doctype html><html><body><h1>Catalog Blade stages diagnostic</h1><p>Inline HTML OK</p></body></html>';
            return ['ok' => true, 'rendered_length' => strlen($html)];
        }

        if ($stage === 'B') {
            $html = \Illuminate\Support\Facades\Blade::render('<div data-stage="B">Catalog Blade diagnostic: {{ $label }}</div>', ['label' => 'simple Blade OK'], deleteCachedView: true);
            return ['ok' => true, 'rendered_length' => strlen($html)];
        }

        if ($stage === 'C') {
            $html = view('storefront.partials.search-bar')->render();
            return ['ok' => true, 'rendered_length' => strlen($html)];
        }

        if ($stage === 'D') {
            $part = \App\Models\Part::query()->storefrontVisible()->first();
            $html = view('storefront.partials.product-card', ['part' => $part])->render();
            return ['ok' => true, 'rendered_length' => strlen($html)];
        }

        if ($stage === 'E') {
            $html = \App\Models\Part::query()->storefrontVisible()->paginate(5)->withQueryString()->links()->toHtml();
            return ['ok' => true, 'rendered_length' => strlen($html)];
        }

        if ($stage === 'F1') {
            return [
                'ok' => true,
                'view_exists' => view()->exists($contentView),
                'file_exists' => file_exists($contentPath),
                'path' => $contentPath,
            ];
        }

        if ($stage === 'F2') {
            $lines = file_exists($contentPath) ? array_slice(file($contentPath, FILE_IGNORE_NEW_LINES) ?: [], 0, 160) : [];

            return [
                'ok' => true,
                'line_count_returned' => count($lines),
                'source' => collect($lines)->mapWithKeys(
                    fn (string $line, int $index): array => [(string) ($index + 1) => $line]
                )->all(),
            ];
        }

        if ($stage === 'F3') {
            $source = file_exists($contentPath) ? (string) file_get_contents($contentPath) : '';
            $directives = ['@extends', '@section', '@endsection', '@push', '@once', '@php', '@foreach', '@if'];

            return [
                'ok' => true,
                'directives' => collect($directives)->mapWithKeys(
                    fn (string $directive): array => [$directive => substr_count($source, $directive)]
                )->all(),
            ];
        }

        if ($stage === 'F4') {
            $parts = \App\Models\Part::query()->storefrontVisible()->limit(3)->get();
            $html = \Illuminate\Support\Facades\Blade::render(
                '<section><h1>Katalog części</h1><div class="sf-grid sf-grid--3">@foreach($parts as $part) @include("storefront.partials.product-card", ["part" => $part]) @endforeach</div></section>',
                ['parts' => $parts],
                deleteCachedView: true
            );

            return ['ok' => true, 'rendered_length' => strlen($html)];
        }

        if ($stage === 'F5') {
            $catalogUrl = \Illuminate\Support\Facades\Route::has('storefront.catalog') ? route('storefront.catalog') : url('/czesci');
            $html = \Illuminate\Support\Facades\Blade::render(
                '<form class="sf-filters" method="get" action="{{ $catalogUrl }}"><h3>Wyszukaj w katalogu</h3><label>Fraza <input type="search" name="q" value="{{ request("q") }}"></label><label>Numer części <input name="part_number" value="{{ request("part_number") }}"></label><label>Sortowanie <select name="sort"><option value="">Sortuj domyślnie</option><option value="price_asc" @selected(request("sort") === "price_asc")>Cena rosnąco</option><option value="price_desc" @selected(request("sort") === "price_desc")>Cena malejąco</option><option value="name" @selected(request("sort") === "name")>Nazwa</option></select></label><button class="sf-btn" type="submit">Szukaj</button><a class="sf-clear" href="{{ $catalogUrl }}">Wyczyść</a></form>',
                ['catalogUrl' => $catalogUrl],
                deleteCachedView: true
            );

            return ['ok' => true, 'rendered_length' => strlen($html)];
        }

        $prepareCatalogData = function (): array {
            return app(\App\Http\Controllers\Storefront\CatalogController::class)->viewData(request(), app(\App\Services\Storefront\CategoryTreeService::class));
        };

        if ($stage === 'F6A') {
            $catalogData = $prepareCatalogData();
            $parts = $catalogData['parts'] ?? collect();

            return [
                'ok' => true,
                'count' => method_exists($parts, 'count') ? $parts->count() : null,
                'total' => method_exists($parts, 'total') ? $parts->total() : null,
                'parts_class' => is_object($parts) ? get_class($parts) : gettype($parts),
            ];
        }

        if ($stage === 'F6D') {
            $catalogData = $prepareCatalogData();
            $html = \Illuminate\Support\Facades\Blade::render(
                '<div class="sf-grid sf-grid--3">@forelse($parts as $part) @include("storefront.partials.product-card", ["part" => $part]) @empty <p class="sf-empty">Brak produktów dla wybranych kryteriów.</p> @endforelse</div>',
                $catalogData,
                deleteCachedView: true
            );

            return ['ok' => true, 'rendered_length' => strlen($html)];
        }

        if ($stage === 'F6E') {
            $catalogData = $prepareCatalogData();
            $html = \Illuminate\Support\Facades\Blade::render(
                '@if(method_exists($parts, "links")) {!! method_exists($parts, "withQueryString") ? $parts->withQueryString()->links() : $parts->links() !!} @endif',
                $catalogData,
                deleteCachedView: true
            );

            return ['ok' => true, 'rendered_length' => strlen($html)];
        }

        if ($stage === 'F6F') {
            $catalogData = $prepareCatalogData();
            $html = view($contentView, $catalogData)->render();

            return ['ok' => true, 'rendered_length' => strlen($html)];
        }

        $catalogData = app(\App\Http\Controllers\Storefront\CatalogController::class)->viewData(request(), app(\App\Services\Storefront\CategoryTreeService::class));
        $html = view('storefront.catalog.index', $catalogData)->render();

        return ['ok' => true, 'rendered_length' => strlen($html)];
    };

    foreach ($stagesToRun as $stage) {
        try {
            $result['stages'][$stage] = $runStage($stage);
        } catch (\Throwable $exception) {
            return $fail($exception, $stage);
        }
    }

    return response()->json($result);
})->name('tools.check-catalog-blade-stages');
Route::get('/tools/clear-view-cache', function (Request $request) {
    if (
        ! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))
        || ! hash_equals('clear', (string) $request->query('confirm', ''))
    ) {
        return response()->json([
            'ok' => false,
            'error_message' => 'Invalid cache clear token or confirmation.',
        ], 403);
    }

    $commands = [
        'view:clear',
        'optimize:clear',
        'route:clear',
        'config:clear',
        'cache:clear',
    ];

    $results = [];
    foreach ($commands as $command) {
        $exitCode = \Illuminate\Support\Facades\Artisan::call($command);
        $results[$command] = [
            'exit_code' => $exitCode,
            'output' => \Illuminate\Support\Facades\Artisan::output(),
        ];
    }

    return response()->json([
        'ok' => true,
        'commands' => $results,
    ]);
})->name('tools.clear-view-cache');
Route::get('/tools/check-header-source', function (Request $request) {
    if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
        return response()->json([
            'ok' => false,
            'error_message' => 'Invalid diagnostics token.',
        ], 403);
    }

    $headerPath = resource_path('views/storefront/partials/header.blade.php');
    $headerExists = is_file($headerPath);
    $headerSource = $headerExists ? (string) file_get_contents($headerPath) : '';
    $compiledViews = collect(glob(storage_path('framework/views/*.php')) ?: [])
        ->sortByDesc(fn (string $path): int => filemtime($path) ?: 0)
        ->take(10)
        ->map(fn (string $path): array => [
            'file' => basename($path),
            'modified_at' => date('c', filemtime($path) ?: 0),
            'size' => filesize($path) ?: 0,
        ])
        ->values();

    return response()->json([
        'ok' => true,
        'header_exists' => $headerExists,
        'header_first_120_lines' => implode("\n", array_slice(preg_split('/\R/', $headerSource) ?: [], 0, 120)),
        'contains_at_media' => str_contains($headerSource, '@media'),
        'contains_escaped_at_media' => str_contains($headerSource, '@@media'),
        'header_modified_at' => $headerExists ? date('c', filemtime($headerPath) ?: 0) : null,
        'compiled_views' => $compiledViews,
    ]);
})->name('tools.check-header-source');
Route::get('/tools/check-compiled-header', function (Request $request) {
    if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
        return response()->json([
            'ok' => false,
            'error_message' => 'Invalid diagnostics token.',
        ], 403);
    }

    $headerPath = resource_path('views/storefront/partials/header.blade.php');
    $compiledPath = null;
    $compiledSource = null;

    foreach (glob(storage_path('framework/views/*.php')) ?: [] as $path) {
        $source = (string) file_get_contents($path);

        if (str_contains($source, $headerPath) || str_contains($source, 'storefront/partials/header.blade.php')) {
            $compiledPath = $path;
            $compiledSource = $source;
            break;
        }
    }

    $startLine = 50;
    $endLine = 75;
    $fragment = [];
    $fragmentText = '';

    if ($compiledSource !== null) {
        $lines = preg_split('/\R/', $compiledSource) ?: [];

        foreach (range($startLine, $endLine) as $lineNumber) {
            if (array_key_exists($lineNumber - 1, $lines)) {
                $fragment[$lineNumber] = $lines[$lineNumber - 1];
            }
        }

        $fragmentText = implode("\n", $fragment);
    }

    return response()->json([
        'ok' => true,
        'header_view' => $headerPath,
        'compiled_exists' => $compiledPath !== null,
        'compiled_file' => $compiledPath ? basename($compiledPath) : null,
        'compiled_path' => $compiledPath,
        'compiled_modified_at' => $compiledPath ? date('c', filemtime($compiledPath) ?: 0) : null,
        'fragment_range' => [$startLine, $endLine],
        'fragment' => $fragment,
        'fragment_contains_at' => str_contains($fragmentText, '@'),
    ]);
})->name('tools.check-compiled-header');
Route::get('/tools/dry-run-import-marketplace-category-trees', [MarketplaceCategoryTreeImportController::class, 'dryRunImport'])->name('tools.dry-run-import-marketplace-category-trees');
Route::get('/tools/debug-marketplace-category-tree-fetch', [MarketplaceCategoryTreeImportController::class, 'debugFetch'])->name('tools.debug-marketplace-category-tree-fetch');
Route::get('/tools/import-marketplace-category-trees', [MarketplaceCategoryTreeImportController::class, 'import'])->name('tools.import-marketplace-category-trees');
Route::get('/tools/marketplace-category-tree-import-autorun', [MarketplaceCategoryTreeImportController::class, 'autorun'])->name('tools.marketplace-category-tree-import-autorun');
Route::get('/tools/start-marketplace-category-tree-import-autorun', [MarketplaceCategoryTreeImportController::class, 'startAutorun'])->name('tools.start-marketplace-category-tree-import-autorun');
Route::get('/tools/run-marketplace-category-tree-import-autorun', [MarketplaceCategoryTreeImportController::class, 'runAutorun'])->name('tools.run-marketplace-category-tree-import-autorun');
Route::get('/tools/status-marketplace-category-tree-import-autorun', [MarketplaceCategoryTreeImportController::class, 'statusAutorun'])->name('tools.status-marketplace-category-tree-import-autorun');
Route::get('/tools/reset-marketplace-category-tree-import-autorun', [MarketplaceCategoryTreeImportController::class, 'resetAutorun'])->name('tools.reset-marketplace-category-tree-import-autorun');
Route::get('/tools/results-marketplace-category-tree-import-autorun', [MarketplaceCategoryTreeImportController::class, 'resultsAutorun'])->name('tools.results-marketplace-category-tree-import-autorun');
Route::get('/tools/debug-marketplace-category-tree-import-autorun', [MarketplaceCategoryTreeImportController::class, 'debugAutorun'])->name('tools.debug-marketplace-category-tree-import-autorun');
Route::get('/tools/dry-run-backfill-ebay-de-category-mapping-names', [MarketplaceCategoryTreeImportController::class, 'dryRunBackfill'])->name('tools.dry-run-backfill-ebay-de-category-mapping-names');
Route::get('/tools/backfill-ebay-de-category-mapping-names', [MarketplaceCategoryTreeImportController::class, 'backfill'])->name('tools.backfill-ebay-de-category-mapping-names');
Route::get('/tools/check-part-image-presentation', CheckPartImagePresentationController::class)->name('tools.check-part-image-presentation');
Route::get('/tools/process-part-image-presentation', ProcessPartImagePresentationController::class)->name('tools.process-part-image-presentation');
Route::get('/tools/process-part-image-presentation-runner', ProcessPartImagePresentationRunnerController::class)->name('tools.process-part-image-presentation-runner');
Route::get('/tools/fix-imported-images-public-files', FixImportedImagesPublicFilesController::class)->name('tools.fix-imported-images-public-files');
Route::get('/tools/imported-images-storage-report', ImportedImagesStorageReportController::class)->name('tools.imported-images-storage-report');
Route::get('/tools/photo-storage-report', PhotoStorageReportController::class)->name('tools.photo-storage-report');
Route::get('/tools/pre-domain-switch-check', PreDomainSwitchCheckController::class)->name('tools.pre-domain-switch-check');
Route::get('/tools/check-domain-hardcoded-links', CheckDomainHardcodedLinksController::class)->name('tools.check-domain-hardcoded-links');
Route::get('/tools/finalize-domain-switch', FinalizeDomainSwitchController::class)->name('tools.finalize-domain-switch');
Route::get('/tools/post-domain-switch-check', PostDomainSwitchCheckController::class)->name('tools.post-domain-switch-check');


Route::middleware(Authenticate::class)->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/search/parts', PartSearchController::class)->name('search.parts');
    Route::patch('/parts/{part}/local-availability', [PartLocalAvailabilityController::class, 'update'])->name('parts.local-availability.update');
    Route::get('/tools/marketplace/ebay-de-preview/{part}', EbayDePreviewController::class)->name('tools.marketplace.ebay-de-preview');
    Route::get('/tools/marketplace/allegro-duplicate-check', AllegroDuplicateCheckController::class)->name('tools.marketplace.allegro-duplicate-check');
    Route::post('/local-sales', [LocalSaleController::class, 'store'])->name('local-sales.store');
    Route::get('/tools/parts/{part}/local-sale-end-marketplaces-dry-run', [LocalSaleEndMarketplacesController::class, 'dryRun'])->name('tools.parts.local-sale-end-marketplaces-dry-run');
    Route::get('/tools/parts/{part}/local-sale-end-marketplaces-apply', [LocalSaleEndMarketplacesController::class, 'apply'])->name('tools.parts.local-sale-end-marketplaces-apply');
    Route::get('/tools/marketplace/debug-part', MarketplacePartDebugController::class)->name('tools.marketplace.debug-part');
    Route::get('/tools/marketplace/relist-part', MarketplaceRelistPartController::class)->name('tools.marketplace.relist-part');
    Route::get('/marketplace-category-mapper', [MarketplaceCategoryMapperController::class, 'index'])->name('marketplace-category-mapper.index');
    Route::get('/marketplace-category-mapper/tree/local', [MarketplaceCategoryMapperController::class, 'localTree'])->name('marketplace-category-mapper.tree.local');
    Route::get('/marketplace-category-mapper/tree/{channel}', [MarketplaceCategoryMapperController::class, 'channelTree'])->name('marketplace-category-mapper.tree.channel');
    Route::get('/marketplace-category-mapper/mapping/{local_category_id}', [MarketplaceCategoryMapperController::class, 'showMapping'])->name('marketplace-category-mapper.mapping.show');
    Route::post('/marketplace-category-mapper/mapping/{local_category_id}', [MarketplaceCategoryMapperController::class, 'saveMapping'])->name('marketplace-category-mapper.mapping.save');
    Route::get('/marketplace-category-mapper/local-category/{local_category_id}/delete-safety', [MarketplaceCategoryMapperController::class, 'inspectLocalCategoryDeleteSafety'])->name('marketplace-category-mapper.delete.inspect');
    Route::delete('/marketplace-category-mapper/local-category/{local_category_id}', [MarketplaceCategoryMapperController::class, 'deleteLocalCategory'])->name('marketplace-category-mapper.delete');
});

Route::middleware(Authenticate::class)->prefix('admin/import-migracyjny/produkty-woo')->name('admin.import-migration.woo-products.')->group(function (): void {
    Route::get('/category-tree/audit', [WooCategoryTreeController::class, 'audit'])->name('category-tree.audit');
    Route::post('/category-tree/import', [WooCategoryTreeController::class, 'import'])->name('category-tree.import');
    Route::get('/storage-public/diagnostyka', [WooStoragePublicController::class, 'diagnostics'])->name('storage-public.diagnostics');
    Route::post('/storage-public/ensure', [WooStoragePublicController::class, 'ensure'])->name('storage-public.ensure');
    Route::post('/storage-public/force-copy', [WooStoragePublicController::class, 'forceCopy'])->name('storage-public.force-copy');
    Route::post('/part-images/{part}/process', [PartImagePresentationController::class, 'process'])->name('part-images.process');

    Route::post('/start', function (Request $request) {
        $lastDiagnosticStep = 'step_00_route_entered';

        $diagnosticStep = static function (string $step) use ($request, &$lastDiagnosticStep): void {
            $lastDiagnosticStep = $step;
            woo_import_append_start_ping_step($step);
        };

        register_shutdown_function(static function () use ($request, &$lastDiagnosticStep): void {
            woo_import_write_fatal_error_diagnostic($request, $lastDiagnosticStep);
        });

        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', '300');

        woo_import_write_start_ping($request, 'step_01_route_reached');
        woo_import_append_start_ping_context([
            'step' => 'step_01_ini_values_after_set_attempt',
            'php_memory_limit' => ini_get('memory_limit'),
            'php_max_execution_time' => ini_get('max_execution_time'),
        ]);

        try {
            $diagnosticStep('step_02_before_controller');
            $controller = app(WooProductImportRunController::class);
            $diagnosticStep('step_03_after_controller_resolved');
            $diagnosticStep('step_04_before_start_call');
            $response = $controller->start($request);
            $diagnosticStep('step_05_after_start_call');

            return $response;
        } catch (Throwable $exception) {
            woo_import_write_route_emergency_diagnostic($exception, $request, 'start', $lastDiagnosticStep);

            $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $path = htmlspecialchars(storage_path('app/imports/manual/woo/last_error.log'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return response(<<<HTML
<!doctype html>
<html lang="pl">
<head><meta charset="utf-8"><title>Import Woo nie wystartował</title></head>
<body>
    <h1>Import Woo nie wystartował</h1>
    <p>Podczas uruchamiania importu wystąpił wyjątek.</p>
    <p><strong>Komunikat:</strong> {$message}</p>
    <p>Szczegóły zapisano w pliku: <code>{$path}</code></p>
</body>
</html>
HTML, 500)->header('Content-Type', 'text/html; charset=UTF-8');
        }
    })->name('start');

    Route::get('/start-ping', function (Request $request) {
        woo_import_write_minimal_ping($request, 'get_ping.log', 'GET reached start ping route');

        return response('OK', 200)->header('Content-Type', 'text/plain');
    })->name('start-ping');

    Route::post('/post-ping', function (Request $request) {
        woo_import_write_minimal_ping($request, 'post_ping.log', 'POST reached post ping route');

        return response('OK', 200)->header('Content-Type', 'text/plain');
    })->name('post-ping');

    Route::get('/diagnostyka', function () {
        $directory = storage_path('app/imports/manual/woo');
        $productsPath = $directory.DIRECTORY_SEPARATOR.'products.csv';

        return response()->json([
            'route_exists' => true,
            'controller_class_exists' => class_exists(WooProductImportRunController::class),
            'import_service_class_exists' => class_exists(WooProductImport::class),
            'manual_file_resolver_class_exists' => class_exists(ManualImportFileResolver::class),
            'run_repository_class_exists' => class_exists(WooProductImportRunRepository::class),
            'manual_folder_path' => $directory,
            'manual_folder_exists' => is_dir($directory),
            'manual_folder_writable' => is_dir($directory) && is_writable($directory),
            'products_csv_path' => $productsPath,
            'products_csv_exists' => is_file($productsPath),
            'products_csv_readable' => is_file($productsPath) && is_readable($productsPath),
            'last_error_log_path' => $directory.DIRECTORY_SEPARATOR.'last_error.log',
            'fatal_error_log_path' => $directory.DIRECTORY_SEPARATOR.'fatal_error.log',
            'start_ping_log_path' => $directory.DIRECTORY_SEPARATOR.'start_ping.log',
            'get_ping_log_path' => $directory.DIRECTORY_SEPARATOR.'get_ping.log',
            'post_ping_log_path' => $directory.DIRECTORY_SEPARATOR.'post_ping.log',
        ]);
    })->name('diagnostics');

    Route::get('/runs/{runId}/autorun', [WooProductImportRunController::class, 'autorun'])->name('autorun');
    Route::get('/runs/{runId}/status', [WooProductImportRunController::class, 'status'])->name('status');
    Route::post('/runs/{runId}/next', [WooProductImportRunController::class, 'next'])->name('next');
    Route::post('/runs/{runId}/next-many', [WooProductImportRunController::class, 'nextMany'])->name('next-many');
    Route::post('/runs/{runId}/autorun-log', [WooProductImportRunController::class, 'autorunnerLog'])->name('autorun-log');
});


if (! function_exists('woo_import_write_start_ping')) {
    function woo_import_write_start_ping(Request $request, string $step): void
    {
        try {
            $directory = storage_path('app/imports/manual/woo');

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $path = $directory.DIRECTORY_SEPARATOR.'start_ping.log';
            $keys = [];

            try {
                $keys = array_keys($request->all());
            } catch (Throwable $exception) {
                $keys = ['__unavailable__' => $exception->getMessage()];
            }

            $content = implode(PHP_EOL, [
                'timestamp: '.date(DATE_ATOM),
                'message: POST reached start route',
                'step: '.$step,
                'method: '.$request->getMethod(),
                'request_uri: '.($_SERVER['REQUEST_URI'] ?? ''),
                'content_length: '.($_SERVER['CONTENT_LENGTH'] ?? '0'),
                'submitted_keys: '.json_encode($keys, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'php_memory_limit: '.ini_get('memory_limit'),
                'php_max_execution_time: '.ini_get('max_execution_time'),
                'memory_usage: '.memory_get_usage(true),
                'memory_peak_usage: '.memory_get_peak_usage(true),
                'cwd: '.getcwd(),
                '---',
            ]).PHP_EOL;

            file_put_contents($path, $content, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // This ping must never replace the original route behavior.
        }
    }
}

if (! function_exists('woo_import_append_start_ping_step')) {
    function woo_import_append_start_ping_step(string $step): void
    {
        try {
            $directory = storage_path('app/imports/manual/woo');

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents(
                $directory.DIRECTORY_SEPARATOR.'start_ping.log',
                'timestamp: '.date(DATE_ATOM).PHP_EOL.'step: '.$step.PHP_EOL.'---'.PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );
        } catch (Throwable) {
            // Step logging is diagnostic-only.
        }
    }
}

if (! function_exists('woo_import_write_minimal_ping')) {
    function woo_import_write_minimal_ping(Request $request, string $filename, string $message): void
    {
        try {
            $directory = storage_path('app/imports/manual/woo');

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $content = implode(PHP_EOL, [
                'timestamp: '.date(DATE_ATOM),
                'message: '.$message,
                'method: '.$request->getMethod(),
                'request_uri: '.($_SERVER['REQUEST_URI'] ?? ''),
                'content_length: '.($_SERVER['CONTENT_LENGTH'] ?? '0'),
                'submitted_keys: '.json_encode(array_keys($request->all()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'php_memory_limit: '.ini_get('memory_limit'),
                'php_max_execution_time: '.ini_get('max_execution_time'),
                'memory_usage: '.memory_get_usage(true),
                'memory_peak_usage: '.memory_get_peak_usage(true),
                'cwd: '.getcwd(),
                '---',
            ]).PHP_EOL;

            file_put_contents($directory.DIRECTORY_SEPARATOR.$filename, $content, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Ping routes are diagnostic-only.
        }
    }
}

if (! function_exists('woo_import_request_fields')) {
    function woo_import_request_fields(): array
    {
        return [
            'products_filename',
            'categories_filename',
            'meta_filename',
            'attributes_filename',
            'summary_filename',
            'images_filename',
            'mode',
        ];
    }
}

if (! function_exists('woo_import_write_route_emergency_diagnostic')) {
    function woo_import_write_route_emergency_diagnostic(Throwable $exception, Request $request, string $action, string $lastDiagnosticStep = ''): array
    {
        $directory = storage_path('app/imports/manual/woo');
        $diagnosticPath = $directory.DIRECTORY_SEPARATOR.'last_error.log';

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $diagnostic = [
            'timestamp' => date(DATE_ATOM),
            'action' => $action,
            'diagnostic_scope' => 'route_closure_start_endpoint',
            'last_diagnostic_step' => $lastDiagnosticStep,
            'route_name' => optional($request->route())->getName(),
            'url' => $request->fullUrl(),
            'exception' => [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ],
            'submitted_fields' => $request->only(woo_import_request_fields()),
            'submitted_keys' => woo_import_safe_request_keys($request),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'memory_usage' => memory_get_usage(true),
            'memory_peak_usage' => memory_get_peak_usage(true),
            'php' => [
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
            ],
            'expected_folder_path' => $directory,
            'diagnostic_file' => $diagnosticPath,
            'classes' => [
                'controller' => class_exists(WooProductImportRunController::class),
                'import_service' => class_exists(WooProductImport::class),
                'manual_file_resolver' => class_exists(ManualImportFileResolver::class),
                'run_repository' => class_exists(WooProductImportRunRepository::class),
            ],
        ];

        $content = json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($diagnosticPath, ($content === false ? var_export($diagnostic, true) : $content).PHP_EOL, LOCK_EX);

        return [
            'exception_class' => $diagnostic['exception']['class'],
            'exception_message' => $diagnostic['exception']['message'],
            'expected_folder_path' => $diagnostic['expected_folder_path'],
            'submitted_fields' => $diagnostic['submitted_fields'],
            'diagnostic_file' => $diagnostic['diagnostic_file'],
            'diagnostic_scope' => $diagnostic['diagnostic_scope'],
            'last_diagnostic_step' => $diagnostic['last_diagnostic_step'],
            'classes' => $diagnostic['classes'],
        ];
    }
}

if (! function_exists('woo_import_append_start_ping_context')) {
    /** @param array<string, mixed> $context */
    function woo_import_append_start_ping_context(array $context): void
    {
        try {
            $directory = storage_path('app/imports/manual/woo');

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $lines = ['timestamp: '.date(DATE_ATOM)];

            foreach ($context as $key => $value) {
                $lines[] = $key.': '.(is_scalar($value) || $value === null
                    ? (string) $value
                    : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            $lines[] = '---';

            file_put_contents(
                $directory.DIRECTORY_SEPARATOR.'start_ping.log',
                implode(PHP_EOL, $lines).PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );
        } catch (Throwable) {
            // Context logging is diagnostic-only.
        }
    }
}

if (! function_exists('woo_import_safe_request_keys')) {
    /** @return array<int|string, string> */
    function woo_import_safe_request_keys(Request $request): array
    {
        try {
            return array_keys($request->all());
        } catch (Throwable $exception) {
            return ['__unavailable__' => $exception->getMessage()];
        }
    }
}

if (! function_exists('woo_import_write_fatal_error_diagnostic')) {
    function woo_import_write_fatal_error_diagnostic(Request $request, string $lastDiagnosticStep): void
    {
        $lastError = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

        if ($lastError === null || ! in_array($lastError['type'] ?? null, $fatalTypes, true)) {
            return;
        }

        try {
            $directory = storage_path('app/imports/manual/woo');

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $diagnostic = [
                'timestamp' => date(DATE_ATOM),
                'error_get_last' => $lastError,
                'memory_usage' => memory_get_usage(true),
                'memory_peak_usage' => memory_get_peak_usage(true),
                'php' => [
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => ini_get('max_execution_time'),
                ],
                'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                'submitted_keys' => woo_import_safe_request_keys($request),
                'last_diagnostic_step' => $lastDiagnosticStep,
            ];

            $content = json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            file_put_contents(
                $directory.DIRECTORY_SEPARATOR.'fatal_error.log',
                ($content === false ? var_export($diagnostic, true) : $content).PHP_EOL,
                LOCK_EX,
            );
        } catch (Throwable) {
            // Shutdown diagnostics must never raise another fatal path.
        }
    }
}


Route::get('/tools/debug-allegro-shipment-preview', [ShipmentToolsController::class, 'allegroPreview'])->name('tools.debug-allegro-shipment-preview');
Route::get('/tools/debug-ovoko-shipment-preview', [ShipmentToolsController::class, 'ovokoPreview'])->name('tools.debug-ovoko-shipment-preview');
Route::get('/tools/create-order-shipment', [ShipmentToolsController::class, 'create'])->name('tools.create-order-shipment');
Route::get('/tools/download-shipment-label/{shipment}', [ShipmentToolsController::class, 'download'])->name('tools.download-shipment-label');
Route::delete('/tools/delete-test-shipment/{shipment}', [ShipmentToolsController::class, 'deleteTest'])->name('tools.delete-test-shipment');
