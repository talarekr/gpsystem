<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\PartResource;
use App\Http\Controllers\Controller;
use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 3) {
            return response()->json(['data' => []]);
        }

        $parts = Part::query()
            ->availableForLocalSale()
            ->with('images')
            ->where(function ($builder) use ($query): void {
                foreach (['sku', 'name', 'part_number', 'oem_number', 'manufacturer_code'] as $column) {
                    $builder->orWhere($column, 'like', '%'.$query.'%');
                }
            })
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => $parts->map(fn (Part $part): array => [
                'id' => $part->id,
                'name' => $part->name,
                'sku' => $part->sku,
                'part_number' => $part->part_number,
                'price' => $part->price !== null ? number_format((float) $part->price, 2, ',', ' ').' '.($part->currency ?: 'PLN') : null,
                'price_value' => $part->price !== null ? (float) $part->price : null,
                'currency' => $part->currency ?: 'PLN',
                'status' => Part::statusOptions()[$part->status] ?? $part->status,
                'status_value' => $part->status,
                'quantity' => (int) $part->quantity,
                'image' => $part->listingImageUrl() ?: $part->primaryImageUrl(),
                'thumbnail' => $part->listingImageUrl() ?: $part->primaryImageUrl(),
                'edit_url' => PartResource::getUrl('edit', ['record' => $part]),
                'url' => PartResource::getUrl('edit', ['record' => $part]),
            ])->values(),
        ]);
    }
}
