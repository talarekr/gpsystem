<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LocalSale;
use App\Models\Part;
use App\Models\ShopEvent;
use App\Services\Marketplace\PartAvailabilityEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocalSaleController extends Controller
{
    public function store(Request $request, PartAvailabilityEventService $availabilityEvents): JsonResponse
    {
        $data = $request->validate([
            'part_id' => ['required', 'integer', 'exists:parts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'in:cash,bank_transfer'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['quantity'] = 1;

        try {
            $localSale = DB::transaction(function () use ($data, $request): LocalSale {
                /** @var Part|null $part */
                $part = Part::query()->whereKey($data['part_id'])->lockForUpdate()->first();

                if (! $part || in_array($part->status, ['sold', 'archived'], true) || (int) $part->quantity <= 0) {
                    throw ValidationException::withMessages([
                        'part_id' => 'Ta część nie jest dostępna do sprzedaży.',
                    ]);
                }

                $previousQuantity = (int) $part->quantity;
                $newQuantity = max(0, $previousQuantity - 1);

                $partSnapshot = [
                    'id' => $part->id,
                    'name' => $part->name,
                    'sku' => $part->sku,
                    'part_number' => $part->part_number,
                    'oem_number' => $part->oem_number,
                    'manufacturer_code' => $part->manufacturer_code,
                    'price' => $part->price,
                    'currency' => $part->currency,
                    'status' => $part->status,
                    'quantity' => $previousQuantity,
                ];

                $part->quantity = $newQuantity;

                $soldAt = now();

                if ($newQuantity <= 0) {
                    $part->markSoldViaLocalSale($soldAt);
                }

                $part->save();

                $localSale = LocalSale::query()->create([
                    'part_id' => $part->id,
                    'part_snapshot' => $partSnapshot,
                    'amount' => $data['amount'],
                    'currency' => 'PLN',
                    'payment_method' => $data['payment_method'],
                    'quantity' => 1,
                    'sold_at' => $soldAt,
                    'created_by' => $request->user()?->id,
                    'notes' => $data['notes'] ?? null,
                    'marketplace_sync_status' => 'pending',
                    'marketplace_sync_payload' => [
                        'reason' => 'local_sale',
                        'part_id' => $part->id,
                        'previous_part_quantity' => $previousQuantity,
                        'new_part_quantity' => (int) $part->quantity,
                        'part_status' => $part->status,
                    ],
                ]);

                $paymentLabel = $data['payment_method'] === 'cash' ? 'gotówka' : 'przelew';
                $payload = [
                    'local_sale_id' => $localSale->id,
                    'part_id' => $part->id,
                    'part_name' => $part->name,
                    'sku' => $part->sku,
                    'amount' => (float) $localSale->amount,
                    'currency' => $localSale->currency,
                    'payment_method' => $localSale->payment_method,
                    'quantity' => $localSale->quantity,
                    'previous_part_quantity' => $previousQuantity,
                    'new_part_quantity' => (int) $part->quantity,
                    'marketplace_sync_status' => $localSale->marketplace_sync_status,
                ];

                ShopEvent::query()->create([
                    'source' => 'manual',
                    'event_type' => 'order',
                    'title' => 'Sprzedaż lokalna',
                    'description' => sprintf('Sprzedano lokalnie: %s, kwota: %s PLN, płatność: %s', $part->name, number_format((float) $localSale->amount, 2, ',', ' '), $paymentLabel),
                    'occurred_at' => now(),
                    'severity' => 'success',
                    'requires_action' => false,
                    'customer_name' => null,
                    'external_reference' => 'LOCAL-'.$localSale->id,
                    'url' => null,
                    'payload' => $payload,
                ]);

                return $localSale;
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            return response()->json(['message' => 'Nie udało się zapisać sprzedaży lokalnej.'], 500);
        }

        $marketplaceSummary = $availabilityEvents->sold([
            'source_channel' => 'local_sale',
            'part_id' => $localSale->part_id,
            'source_local_sale_id' => $localSale->id,
        ]);

        return response()->json([
            'message' => 'Sprzedaż lokalna została zapisana, a część zdjęta ze stanu.',
            'local_sale_id' => $localSale->id,
            'marketplace_summary' => $marketplaceSummary,
        ]);
    }
}
