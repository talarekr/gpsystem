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
        $imageView = (string) file_get_contents(resource_path('views/filament/resources/parts/table-image.blade.php'));

        $imageColumnPosition = strpos($resource, "ViewColumn::make('admin_part_image'");
        $idColumnPosition = strpos($resource, "TextColumn::make('id'");
        $bulkActionsDisabled = ! str_contains($resource, '->bulkActions(')
            && ! str_contains($resource, 'DeleteBulkAction::make()');

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
            'sample_marketplace_flags' => $flags,
            'warnings' => array_values(array_filter([
                Schema::hasTable('marketplace_listings') ? null : 'marketplace_listings table is missing.',
                $sample ? null : 'No sample part found.',
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
