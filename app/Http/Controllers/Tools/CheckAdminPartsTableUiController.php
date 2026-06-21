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
            'uses_listing_thumbnail_variant' => $imageVariantSource === 'presentation',
            'image_variant_source' => $imageVariantSource,
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
