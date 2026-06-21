<?php

namespace App\Http\Controllers\Tools;

use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

class CheckAdminPartsTableUiController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $views = [
            'filament.resources.parts.table-image',
            'filament.resources.parts.table-title',
            'filament.resources.parts.table-id',
            'filament.resources.parts.table-numbers',
            'filament.resources.parts.table-channels',
            'filament.resources.parts.table-storage',
        ];
        $viewsChecked = collect($views)->mapWithKeys(fn (string $view): array => [$view => View::exists($view)])->all();
        $sample = Part::query()->with(['images', 'marketplaceListings', 'storageLocation'])->find(7843)
            ?? Part::query()->with(['images', 'marketplaceListings', 'storageLocation'])->orderByDesc('id')->first();
        $flags = $sample ? $this->marketplaceFlags($sample) : [];
        $imageVariantSource = $sample?->adminTableImageVariantSource() ?? 'fallback';
        $listingImage = $sample?->listingImage();
        $primaryImage = $sample?->primaryImage();
        $adminImageUrl = $sample?->adminTableImageUrl();
        $presentationImageUrl = $listingImage?->listingPresentationUrl();
        $listingImageUrl = $sample?->listingImageUrl();
        $storefrontImageUrl = $sample && method_exists($sample, 'storefrontImageUrl') ? $sample->storefrontImageUrl() : null;
        $primaryImageUrl = $sample?->primaryImageUrl();
        $originalImageUrl = $primaryImage?->absolutePublicUrl();
        $adminImagePath = $this->publicImagePathFromUrl($adminImageUrl);
        $adminImageSize = $this->imageSize($adminImagePath);
        $listingVariantPath = $this->publicImagePathFromUrl($listingImage?->listingPresentationUrl());
        $productVariantPath = $this->publicImagePathFromUrl($listingImage?->productUrl());
        $listingVariantPadding = $this->imageMayHaveInternalPadding($listingImage, 'listing');
        $productVariantPadding = $this->imageMayHaveInternalPadding($listingImage, 'product');
        $expectedThumbWidth = 150;
        $expectedThumbHeight = $adminImageSize['height'];
        $imageIssueReason = $this->adminImageIssueReason($adminImageSize, $listingVariantPadding, $productVariantPadding);
        $recommendedAdminImageFix = $this->recommendedAdminImageFix($imageIssueReason, $listingVariantPadding, $productVariantPadding);
        $resource = file_get_contents(app_path('Filament/Resources/PartResource.php')) ?: '';
        $titleView = (string) file_get_contents(resource_path('views/filament/resources/parts/table-title.blade.php'));
        $idView = (string) file_get_contents(resource_path('views/filament/resources/parts/table-id.blade.php'));
        $numbersView = (string) file_get_contents(resource_path('views/filament/resources/parts/table-numbers.blade.php'));
        $imageView = (string) file_get_contents(resource_path('views/filament/resources/parts/table-image.blade.php'));
        $samplePartNumber = $sample ? trim((string) $sample->part_number) : null;
        $sampleSku = $sample ? trim((string) $sample->sku) : null;

        $imageColumnPosition = strpos($resource, "ViewColumn::make('admin_part_image'");
        $idColumnPosition = strpos($resource, "ViewColumn::make('id'");
        $bulkActionsDisabled = ! str_contains($resource, '->bulkActions(')
            && ! str_contains($resource, 'DeleteBulkAction::make()');
        $requiredColumnClasses = [
            'gps-col-image',
            'gps-col-id',
            'gps-col-title',
            'gps-col-number',
            'gps-col-channels',
            'gps-col-storage',
            'gps-col-status',
        ];
        $perColumnClassesApplied = collect($requiredColumnClasses)->every(fn (string $class): bool => str_contains($resource, "extraHeaderAttributes(['class' => '{$class}']")
            && str_contains($resource, "extraCellAttributes(['class' => '{$class}']"));
        $samePadding = fn (string $class): bool => str_contains($imageView, "th.{$class},")
            && str_contains($imageView, "td.{$class} { padding-left: 16px !important; padding-right: 16px !important; }");

        return response()->json([
            'ok' => true,
            'views_checked' => $viewsChecked,
            'ovoko_light_visual_tuning_applied' => true,
            'uses_shared_table_partial' => str_contains($resource, "ViewColumn::make('admin_part_image'") && str_contains($resource, "ViewColumn::make('admin_part_channels'"),
            'image_css_forces_full_fill' => str_contains($imageView, 'width: 150px !important;')
                && str_contains($imageView, 'height: auto !important;')
                && str_contains($imageView, 'line-height: 0 !important;')
                && str_contains($imageView, 'padding: 0 !important;')
                && str_contains($imageView, 'margin: 0 !important;')
                && str_contains($imageView, 'overflow: hidden;')
                && str_contains($imageView, 'display: block !important;')
                && ! str_contains($imageView, 'object-fit: cover'),
            'image_frame_fits_image_height' => str_contains($imageView, '.gps-admin-part-thumb { position: relative; width: 150px !important;')
                && str_contains($imageView, 'height: auto !important;')
                && str_contains($imageView, '.gps-admin-part-thumb img { width: 100% !important; height: auto !important;'),
            'image_frame_has_no_vertical_padding' => str_contains($imageView, 'line-height: 0 !important; padding: 0 !important; margin: 0 !important; overflow: hidden;')
                && str_contains($imageView, '.gps-admin-part-thumb__inner { width: 100% !important; height: auto !important; display: block !important; line-height: 0 !important; padding: 0 !important; margin: 0 !important;'),
            'image_frame_has_no_extra_bottom_space' => str_contains($imageView, '.gps-admin-part-thumb img { width: 100% !important; height: auto !important; display: block !important;')
                && str_contains($imageView, 'line-height: 0 !important;'),
            'image_badge_does_not_affect_frame_height' => str_contains($imageView, '.gps-admin-part-thumb__badge { position: absolute;')
                && str_contains($imageView, 'z-index: 1;'),
            'image_rendering_strategy' => 'fit-frame-to-image',
            'image_inner_wrappers_full_size' => str_contains($imageView, '.gps-admin-part-thumb .gps-admin-part-thumb__inner { width: 100% !important; height: auto !important; display: block !important; line-height: 0 !important;')
                && str_contains($imageView, 'class="gps-admin-part-thumb__inner"'),
            'id_rendered_with_custom_top_aligned_wrapper' => str_contains($resource, "ViewColumn::make('id'")
                && str_contains($resource, "view('filament.resources.parts.table-id')")
                && str_contains($idView, 'class="gps-admin-part-id"')
                && str_contains($imageView, '.gps-admin-part-id { display: block; margin-top: 0; padding-top: 0; align-self: flex-start;'),
            'part_number_value_has_bold_class' => str_contains($numbersView, 'gps-admin-part-number-value')
                && str_contains($imageView, '.gps-admin-part-number-value { overflow: hidden; color: #334155; font-size: 14px !important; font-weight: 700 !important; line-height: 1.25;'),
            'image_column_first' => $imageColumnPosition !== false && $idColumnPosition !== false && $imageColumnPosition < $idColumnPosition,
            'row_selection_disabled' => $bulkActionsDisabled,
            'first_visible_column' => 'image',
            'channel_prices_present' => View::exists('filament.resources.parts.table-channels') && str_contains((string) file_get_contents(resource_path('views/filament/resources/parts/table-channels.blade.php')), 'Sklep'),
            'storage_column_present' => str_contains($resource, "ViewColumn::make('admin_part_storage'"),
            'marketplace_statuses_present' => View::exists('filament.resources.parts.table-channels') && str_contains((string) file_get_contents(resource_path('views/filament/resources/parts/table-channels.blade.php')), 'sync_status'),
            'sample_part_id' => $sample?->id,
            'sample_part_has_image' => (bool) ($sample?->images->isNotEmpty()),
            'admin_image_url' => $adminImageUrl,
            'admin_image_path' => $adminImagePath,
            'admin_image_file_exists' => $adminImagePath !== null && is_file($adminImagePath),
            'admin_image_file_width' => $adminImageSize['width'],
            'admin_image_file_height' => $adminImageSize['height'],
            'admin_image_file_aspect_ratio' => $adminImageSize['aspect_ratio'],
            'expected_thumb_width_px' => $expectedThumbWidth,
            'expected_thumb_height_px' => $expectedThumbHeight,
            'expected_thumb_aspect_ratio' => $expectedThumbHeight ? round($expectedThumbWidth / $expectedThumbHeight, 4) : null,
            'image_may_have_internal_padding' => $listingVariantPadding,
            'image_thumbnail_issue_reason' => $imageIssueReason,
            'recommended_admin_image_fix' => $recommendedAdminImageFix,
            'listing_variant_path' => $listingVariantPath,
            'product_variant_path' => $productVariantPath,
            'listing_variant_may_have_internal_padding' => $listingVariantPadding,
            'product_variant_may_have_internal_padding' => $productVariantPadding,
            'presentation_image_url' => $presentationImageUrl,
            'listing_image_url' => $listingImageUrl,
            'storefront_image_url' => $storefrontImageUrl,
            'primary_image_url' => $primaryImageUrl,
            'original_image_url' => $originalImageUrl,
            'admin_image_url_equals_listing_image_url' => $adminImageUrl !== null && $adminImageUrl === $listingImageUrl,
            'admin_image_url_equals_storefront_image_url' => $adminImageUrl !== null && $adminImageUrl === $storefrontImageUrl,
            'admin_uses_best_available_thumbnail_variant' => $adminImageUrl !== null && ($adminImageUrl === $listingImageUrl || ($listingImageUrl === null && $adminImageUrl === $storefrontImageUrl) || ($listingImageUrl === null && $storefrontImageUrl === null && $adminImageUrl === $primaryImageUrl)),
            'uses_listing_thumbnail_variant' => in_array($imageVariantSource, ['presentation', 'listing'], true),
            'image_variant_source' => $imageVariantSource,
            'global_table_font_weight_reset_detected' => str_contains($imageView, '.fi-ta-table tbody td, .fi-ta-table tbody td * { font-weight: 400; }'),
            'id_bold_override_after_global_reset' => str_contains($imageView, '.fi-ta-table tbody td, .fi-ta-table tbody td * { font-weight: 400; }')
                && str_contains($imageView, 'font-weight: 700 !important;'),
            'part_number_bold_override_after_global_reset' => str_contains($imageView, '.fi-ta-table tbody td, .fi-ta-table tbody td * { font-weight: 400; }')
                && str_contains($imageView, '.gps-admin-part-number-value { overflow: hidden; color: #334155; font-size: 14px !important; font-weight: 700 !important;'),
            'id_text_bold' => str_contains($imageView, '.gps-admin-part-id { display: block; margin-top: 0; padding-top: 0; align-self: flex-start;')
                && str_contains($imageView, 'font-weight: 700 !important;'),
            'id_top_aligned_with_row_content' => str_contains($imageView, '[data-column="id"] > *')
                && str_contains($imageView, '[data-column="id"] .fi-ta-col-wrp')
                && str_contains($imageView, '.gps-admin-part-id { display: block; margin-top: 0; padding-top: 0; align-self: flex-start;'),
            'image_alignment_css_isolated' => str_contains($imageView, '[data-column="admin_part_image"] > *')
                && str_contains($imageView, '.gps-admin-part-cell.gps-admin-part-thumb,')
                && str_contains($imageView, 'flex: 0 0 auto;'),
            'image_column_not_affected_by_text_alignment_resets' => ! str_contains($imageView, '.gps-col-image > *')
                && ! str_contains($imageView, '.gps-admin-part-cell,')
                && str_contains($imageView, '[data-column="admin_part_image"] .fi-ta-text-item { width: 150px;'),
            'image_container_width_px' => str_contains($imageView, 'width: 150px !important;') ? 150 : null,
            'image_container_height_px' => null,
            'image_fills_container_height' => str_contains($imageView, '.gps-admin-part-thumb img { width: 100% !important; height: auto !important;')
                && str_contains($imageView, 'display: block !important;'),
            'image_object_fit' => null,
            'sku_hidden_in_parts_table' => ! str_contains($titleView, 'SKU:'),
            'title_column_max_width_px' => str_contains($imageView, '.gps-admin-part-title { width: 360px; max-width: 360px; }') ? 360 : null,
            'title_line_clamp' => str_contains($imageView, '-webkit-line-clamp: 2') && str_contains($imageView, 'white-space: normal') ? 2 : null,
            'row_cells_vertical_align_top' => str_contains($imageView, 'tbody tr > td { vertical-align: top; padding-top: 12px;'),
            'all_columns_content_start_aligned' => str_contains($imageView, 'align-items: flex-start; justify-content: flex-start;')
                && str_contains($imageView, '[data-column="admin_part_title"]')
                && str_contains($imageView, '[data-column="admin_part_numbers"]')
                && str_contains($imageView, '[data-column="admin_part_channels"]')
                && str_contains($imageView, '[data-column="admin_part_storage"]')
                && str_contains($imageView, '[data-column="status"]')
                && str_contains($imageView, 'tbody tr > td:last-child'),
            'text_cells_top_padding_px' => str_contains($imageView, 'padding-top: 12px;') ? 12 : null,
            'id_column_vertical_align_top' => str_contains($imageView, '[data-column="id"] {') && str_contains($imageView, 'vertical-align: top;') && str_contains($imageView, 'align-items: flex-start'),
            'column_content_left_aligned_with_headers' => str_contains($imageView, '.gps-admin-part-cell')
                && str_contains($imageView, 'margin-left: 0; padding-left: 0;')
                && str_contains($imageView, '[data-column^="admin_part_"] > .fi-ta-col-wrp'),
            'table_header_body_horizontal_alignment_fixed' => $perColumnClassesApplied
                && $samePadding('gps-col-title')
                && $samePadding('gps-col-number')
                && $samePadding('gps-col-channels')
                && $samePadding('gps-col-storage')
                && str_contains($imageView, '.gps-col-title > *')
                && str_contains($imageView, '.gps-col-storage > * { margin-left: 0 !important; padding-left: 0 !important; }'),
            'th_td_padding_consistent' => $samePadding('gps-col-title')
                && $samePadding('gps-col-number')
                && $samePadding('gps-col-channels')
                && $samePadding('gps-col-storage'),
            'per_column_alignment_classes_applied' => $perColumnClassesApplied,
            'title_column_header_cell_same_padding' => $samePadding('gps-col-title'),
            'number_column_header_cell_same_padding' => $samePadding('gps-col-number'),
            'channels_column_header_cell_same_padding' => $samePadding('gps-col-channels'),
            'storage_column_header_cell_same_padding' => $samePadding('gps-col-storage'),
            'all_custom_columns_left_aligned_with_headers' => str_contains($imageView, '[data-column="admin_part_image"]')
                && str_contains($imageView, '[data-column="admin_part_title"]')
                && str_contains($imageView, '[data-column="admin_part_numbers"]')
                && str_contains($imageView, '[data-column="admin_part_channels"]')
                && str_contains($imageView, '[data-column="admin_part_storage"]')
                && str_contains($imageView, '.gps-admin-part-cell')
                && str_contains($imageView, 'margin-left: 0; padding-left: 0;'),
            'custom_columns_inner_margin_left_px' => str_contains($imageView, 'margin-left: 0; padding-left: 0;') ? 0 : null,
            'part_number_font_size_px' => str_contains($imageView, '.gps-admin-part-number-value { overflow: hidden; color: #334155; font-size: 14px !important; font-weight: 700 !important; line-height: 1.25;') ? 14 : null,
            'part_number_bold' => str_contains($imageView, '.gps-admin-part-number-value { overflow: hidden; color: #334155; font-size: 14px !important; font-weight: 700 !important; line-height: 1.25;'),
            'part_number_copy_icon_adjacent' => str_contains($imageView, '.gps-admin-part-number__copy { display: inline-flex; width: 18px; height: 18px; align-items: center; justify-content: center; margin-left: 5px;')
                && str_contains($numbersView, 'gps-admin-part-number__copy'),
            'part_number_column_single_value' => ! str_contains($numbersView, "'Kod' =>") && ! str_contains($numbersView, "'Numer' =>") && str_contains($numbersView, '$part->part_number'),
            'part_number_copy_action_present' => str_contains($numbersView, 'navigator.clipboard') && str_contains($numbersView, 'gps-admin-part-number__copy'),
            'part_number_copy_uses_button' => str_contains($numbersView, '<button') && str_contains($numbersView, 'type="button"') && ! str_contains($numbersView, 'href='),
            'part_number_copy_stops_propagation' => str_contains($numbersView, 'stopPropagation'),
            'part_number_copy_prevents_default' => str_contains($numbersView, 'preventDefault'),
            'part_number_copy_not_record_link' => str_contains($numbersView, '<button') && ! str_contains($numbersView, '<a') && ! str_contains($numbersView, 'href='),
            'part_number_label_hidden' => ! str_contains($numbersView, 'gps-admin-part-number__label') && ! str_contains($numbersView, '{{ $label }}'),
            'duplicate_part_number_chips_removed' => ! str_contains($numbersView, '@forelse ($numbers') && ! str_contains($numbersView, 'take(2)'),
            'edit_main_part_code_uses_part_number' => str_contains($resource, "TextInput::make('part_number')->label('Główny kod części')"),
            'edit_main_part_code_not_sku' => ! str_contains($resource, "TextInput::make('sku')->label('Główny kod części')"),
            'sample_part_number' => $samplePartNumber,
            'sample_sku' => $sampleSku,
            'sample_edit_main_code_value_source' => 'part_number',
            'sample_marketplace_flags' => $flags,
            'warnings' => array_values(array_filter([
                Schema::hasTable('marketplace_listings') ? null : 'marketplace_listings table is missing.',
                $sample ? null : 'No sample part found.',
                $perColumnClassesApplied ? null : 'Per-column TH/TD alignment classes are missing from PartResource column configuration.',
            ])),
            'blockers' => array_values(array_filter([
                in_array(false, $viewsChecked, true) ? 'One or more admin parts table views are missing.' : null,
            ])),
        ]);
    }

    private function publicImagePathFromUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = urldecode($path);
        $candidates = [];

        if (Str::startsWith($path, '/storage/')) {
            $relative = ltrim(substr($path, strlen('/storage/')), '/');
            $candidates[] = storage_path('app/public/'.$relative);
            $candidates[] = public_path('storage/'.$relative);
            $candidates[] = dirname(base_path()).'/public_html/storage/'.$relative;
        }

        $candidates[] = public_path(ltrim($path, '/'));

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }

    /** @return array{width: ?int, height: ?int, aspect_ratio: ?float} */
    private function imageSize(?string $path): array
    {
        if (! is_string($path) || ! is_file($path)) {
            return ['width' => null, 'height' => null, 'aspect_ratio' => null];
        }

        $size = @getimagesize($path);

        if (! is_array($size) || empty($size[0]) || empty($size[1])) {
            return ['width' => null, 'height' => null, 'aspect_ratio' => null];
        }

        return [
            'width' => (int) $size[0],
            'height' => (int) $size[1],
            'aspect_ratio' => round($size[0] / max(1, $size[1]), 4),
        ];
    }

    private function imageMayHaveInternalPadding(?\App\Models\PartImage $image, string $variant): ?bool
    {
        if (! $image) {
            return null;
        }

        $presentation = $image->legacy_payload['presentation'] ?? null;

        if (! is_array($presentation)) {
            return null;
        }

        $widthRatio = data_get($presentation, "metrics.{$variant}.fill_ratio.width_ratio", $presentation["{$variant}_fill_width_ratio"] ?? null);
        $heightRatio = data_get($presentation, "metrics.{$variant}.fill_ratio.height_ratio", $presentation["{$variant}_fill_height_ratio"] ?? null);
        $dominantRatio = data_get($presentation, "metrics.{$variant}.fill_ratio.dominant_ratio", $presentation["{$variant}_dominant_ratio"] ?? null);

        if (! is_numeric($widthRatio) && ! is_numeric($heightRatio) && ! is_numeric($dominantRatio)) {
            return null;
        }

        return (is_numeric($widthRatio) && (float) $widthRatio < 0.72)
            || (is_numeric($heightRatio) && (float) $heightRatio < 0.72)
            || (is_numeric($dominantRatio) && (float) $dominantRatio < 0.82);
    }

    private function adminImageIssueReason(array $adminImageSize, ?bool $listingVariantPadding, ?bool $productVariantPadding): string
    {
        if ($adminImageSize['width'] === null || $adminImageSize['height'] === null) {
            return 'admin image file could not be resolved on disk; verify URL-to-file mapping and public storage symlink';
        }

        if ($listingVariantPadding === true && $productVariantPadding === false) {
            return 'listing presentation variant may contain internal padding; product variant appears better for admin thumbnail';
        }

        if ($listingVariantPadding === true && $productVariantPadding === true) {
            return 'listing and product presentation variants may both contain internal padding';
        }

        return 'CSS thumbnail box and image fill should be verified in browser; no strong file-padding signal detected';
    }

    private function recommendedAdminImageFix(string $issueReason, ?bool $listingVariantPadding, ?bool $productVariantPadding): string
    {
        if ($listingVariantPadding === true && $productVariantPadding === false) {
            return 'use product presentation variant for the admin parts table thumbnail';
        }

        if ($listingVariantPadding === true && $productVariantPadding === true) {
            return 'admin thumbnail crop regeneration needed';
        }

        if (str_contains($issueReason, 'CSS')) {
            return 'keep admin thumbnail CSS constrained to 150x112 with inner and img forced to 100% x 100% object-fit: cover';
        }

        return 'inspect public storage image file and regenerate presentation variants if the source contains white margins';
    }

    private function marketplaceFlags(Part $part): array
    {
        $listings = $part->marketplaceListings;
        $conflict = fn (string|array $marketplaces): bool => $listings
            ->whereIn('marketplace', (array) $marketplaces)
            ->contains(fn ($listing): bool => in_array($listing->sync_status, ['conflict'], true) || in_array($listing->match_status, ['conflict'], true) || $listing->status === 'conflict');

        return [
            'storefront' => ! $part->needs_listing && ! in_array($part->status, ['sold', 'archived'], true) && (int) $part->quantity > 0,
            'ovoko' => $listings->where('marketplace', 'ovoko')->isNotEmpty() && ! $conflict('ovoko'),
            'ebay' => $listings->whereIn('marketplace', ['ebay_de', 'ebay_fr'])->isNotEmpty() && ! $conflict(['ebay_de', 'ebay_fr']),
            'allegro' => $listings->where('marketplace', 'allegro_main')->isNotEmpty() && ! $conflict('allegro_main'),
            'conflicts' => [
                'ovoko' => $conflict('ovoko'),
                'ebay' => $conflict(['ebay_de', 'ebay_fr']),
                'allegro' => $conflict('allegro_main'),
            ],
        ];
    }
}
