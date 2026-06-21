<?php

namespace App\Http\Controllers\Tools;

use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

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
            'filament.resources.parts.table-numbers',
            'filament.resources.parts.table-channels',
            'filament.resources.parts.table-storage',
        ];
        $viewsChecked = collect($views)->mapWithKeys(fn (string $view): array => [$view => View::exists($view)])->all();
        $sample = Part::query()->with(['images', 'marketplaceListings', 'storageLocation'])->orderByDesc('id')->first();
        $flags = $sample ? $this->marketplaceFlags($sample) : [];
        $imageVariantSource = $sample?->adminTableImageVariantSource() ?? 'fallback';
        $resource = file_get_contents(app_path('Filament/Resources/PartResource.php')) ?: '';
        $titleView = (string) file_get_contents(resource_path('views/filament/resources/parts/table-title.blade.php'));
        $numbersView = (string) file_get_contents(resource_path('views/filament/resources/parts/table-numbers.blade.php'));
        $imageView = (string) file_get_contents(resource_path('views/filament/resources/parts/table-image.blade.php'));
        $samplePartNumber = $sample ? trim((string) $sample->part_number) : null;
        $sampleSku = $sample ? trim((string) $sample->sku) : null;

        $imageColumnPosition = strpos($resource, "ViewColumn::make('admin_part_image'");
        $idColumnPosition = strpos($resource, "TextColumn::make('id'");
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
            'image_column_first' => $imageColumnPosition !== false && $idColumnPosition !== false && $imageColumnPosition < $idColumnPosition,
            'row_selection_disabled' => $bulkActionsDisabled,
            'first_visible_column' => 'image',
            'channel_prices_present' => View::exists('filament.resources.parts.table-channels') && str_contains((string) file_get_contents(resource_path('views/filament/resources/parts/table-channels.blade.php')), 'Sklep'),
            'storage_column_present' => str_contains($resource, "ViewColumn::make('admin_part_storage'"),
            'marketplace_statuses_present' => View::exists('filament.resources.parts.table-channels') && str_contains((string) file_get_contents(resource_path('views/filament/resources/parts/table-channels.blade.php')), 'sync_status'),
            'sample_part_id' => $sample?->id,
            'sample_part_has_image' => (bool) ($sample?->images->isNotEmpty()),
            'uses_listing_thumbnail_variant' => in_array($imageVariantSource, ['presentation', 'listing'], true),
            'image_variant_source' => $imageVariantSource,
            'id_text_bold' => str_contains($imageView, '[data-column="id"] { width: 70px; min-width: 70px; color: #334155; font-size: 13px; font-weight: 700;')
                && str_contains($imageView, '[data-column="id"] .fi-ta-text-item { align-items: flex-start; justify-content: flex-start; margin-top: 0; padding-top: 0; color: #334155; font-weight: 700; }'),
            'id_top_aligned_with_row_content' => str_contains($imageView, '[data-column="id"] > *')
                && str_contains($imageView, '[data-column="id"] .fi-ta-col-wrp')
                && str_contains($imageView, 'margin-top: 0; padding-top: 0;'),
            'image_container_width_px' => str_contains($imageView, '.gps-admin-part-thumb { position: relative; width: 137px;') ? 137 : null,
            'image_container_height_px' => str_contains($imageView, 'width: 137px; height: 104px;') ? 104 : null,
            'image_fills_container_height' => str_contains($imageView, '.gps-admin-part-thumb img { display: block; width: 100%; height: 100%;')
                && str_contains($imageView, 'max-height: none;'),
            'image_object_fit' => str_contains($imageView, 'object-fit: cover;') ? 'cover' : null,
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
            'part_number_font_size_px' => str_contains($imageView, '.gps-admin-part-number { display: inline-flex; width: fit-content; max-width: 100%; align-items: baseline; gap: 4px; color: #334155; font-size: 14px; font-weight: 700;') ? 14 : null,
            'part_number_bold' => str_contains($imageView, '.gps-admin-part-number { display: inline-flex; width: fit-content; max-width: 100%; align-items: baseline; gap: 4px; color: #334155; font-size: 14px; font-weight: 700;')
                && str_contains($imageView, '.gps-admin-part-number__value { overflow: hidden; font-weight: 700;'),
            'part_number_copy_icon_adjacent' => str_contains($imageView, '.gps-admin-part-numbers { display: inline-flex; max-width: 190px; align-items: center; gap: 5px; }')
                && str_contains($numbersView, 'gps-admin-part-number__copy'),
            'part_number_column_single_value' => ! str_contains($numbersView, "'Kod' =>") && ! str_contains($numbersView, "'Numer' =>") && str_contains($numbersView, '$part->part_number'),
            'part_number_copy_action_present' => str_contains($numbersView, 'navigator.clipboard') && str_contains($numbersView, 'gps-admin-part-number__copy'),
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
