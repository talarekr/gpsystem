<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\OrderItemThumbnailDiagnostics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DebugOrderItemThumbnailController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals('gps_images_import_2026', (string) $request->query('token', ''))) {
            return response()->json(['ok' => false, 'error_message' => 'Invalid diagnostics token.'], 403);
        }

        $orderId = $request->query('order_id');
        if (! $orderId) {
            return response()->json(['ok' => false, 'error_message' => 'Missing order_id.'], 422);
        }

        $order = Order::query()
            ->with(['items.part.images', 'items.part.storageLocation', 'items.marketplaceListing.part.images', 'items.marketplaceListing.part.storageLocation'])
            ->find($orderId);

        if (! $order) {
            return response()->json(['ok' => false, 'error_message' => 'Order not found.'], 404);
        }

        $items = $order->items;
        if ($request->filled('order_item_id')) {
            $items = $items->where('id', (int) $request->query('order_item_id'))->values();

            if ($items->isEmpty()) {
                return response()->json(['ok' => false, 'error_message' => 'Order item not found for this order.'], 404);
            }
        }

        return response()->json([
            'ok' => true,
            'mode' => 'read_only_diagnostics',
            'safety_flags' => [
                'read_only' => true,
                'db_writes' => false,
                'marketplace_writes' => false,
                'downloads_missing_images' => false,
                'changes_import_or_parts' => false,
            ],
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'marketplace' => $order->marketplace,
                'marketplace_order_id' => $order->marketplace_order_id,
                'status' => $order->status,
            ],
            'items' => $items->map(fn (OrderItem $item): array => $this->itemPayload($order, $item))->values(),
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function itemPayload(Order $order, OrderItem $item): array
    {
        $debug = OrderItemThumbnailDiagnostics::resolve($order, $item);
        $listing = $item->marketplaceListing;
        $part = $item->part ?: $listing?->part;
        $firstImage = $part?->primaryImage();

        return [
            'item' => [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'part_id' => $item->part_id,
                'marketplace' => $item->marketplace,
                'marketplace_order_id' => $item->marketplace_order_id,
                'marketplace_item_id' => $item->marketplace_item_id,
                'offer_id' => $item->offer_id,
                'sku' => $item->sku,
                'external_product_id' => $item->external_product_id,
            ],
            'listing_resolution' => [
                'listing_found' => $debug['listing_found'],
                'listing_id' => $debug['listing_id'],
                'listing_part_id' => $listing?->part_id,
                'external_offer_id' => $listing?->external_offer_id,
                'external_listing_id' => $listing?->external_listing_id,
                'sku' => $listing?->sku,
                'title' => $listing?->title,
            ],
            'part_resolution' => [
                'part_found' => $debug['part_found'],
                'part_id' => $debug['part_id'],
                'local_part_id' => $debug['local_part_id'],
                'marketplace_listing_part_id' => $debug['marketplace_listing_part_id'],
                'name' => $part?->name,
            ],
            'image_resolution' => [
                'part_has_images' => $debug['part_has_images'],
                'first_image_id' => $firstImage?->id,
                'first_image_path' => $firstImage?->path,
                'first_image_path_present' => $debug['first_image_path_present'],
                'local_part_image_url_present' => $debug['local_part_image_url_present'],
                'marketplace_listing_part_image_url_present' => $debug['marketplace_listing_part_image_url_present'],
                'marketplace_snapshot_image_url_present' => $debug['marketplace_snapshot_image_url_present'],
                'resolved_thumbnail_url' => $debug['thumbnail_url'],
                'resolved_thumbnail_url_present' => $debug['resolved_thumbnail_url_present'],
            ],
            'storage_location_resolution' => [
                'storage_location' => $debug['storage_location'],
                'storage_location_present' => $debug['storage_location_present'],
            ],
            'final_thumbnail_source' => $debug['thumbnail_source'],
            'final_display_name_source' => $debug['display_name_source'],
            'final_storage_location_source' => $debug['storage_location_source'],
        ];
    }
}
