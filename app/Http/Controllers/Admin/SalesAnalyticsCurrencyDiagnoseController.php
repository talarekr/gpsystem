<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Admin\CurrencyConversionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesAnalyticsCurrencyDiagnoseController extends Controller
{
    public function __invoke(Request $request, CurrencyConversionService $conversion): View
    {
        $filters = $request->validate([
            'order_id' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $orders = Order::query()
            ->when($filters['order_id'] ?? null, function ($query, $value): void {
                $query->where(function ($query) use ($value): void {
                    $query->where('id', $value)->orWhere('order_number', $value)->orWhere('marketplace_order_id', $value);
                });
            })
            ->when($filters['currency'] ?? null, fn ($query, $value) => $query->whereRaw('UPPER(currency) = ?', [strtoupper($value)]))
            ->when($filters['date'] ?? null, function ($query, $value): void {
                $query->where(function ($query) use ($value): void {
                    $query->whereDate('ordered_at', $value)->orWhere(fn ($query) => $query->whereNull('ordered_at')->whereDate('created_at', $value));
                });
            })
            ->when(! ($filters['order_id'] ?? null) && ! ($filters['currency'] ?? null), fn ($query) => $query->whereRaw("UPPER(COALESCE(currency, 'PLN')) <> 'PLN'"))
            ->latest('ordered_at')
            ->latest('id')
            ->limit(100)
            ->get();

        $rows = $orders->map(function (Order $order) use ($conversion): array {
            $result = $conversion->toPln($order->total, $order->currency, $order->ordered_at ?: $order->created_at);

            return array_merge(['order' => $order, 'is_foreign_currency' => strtoupper((string) ($order->currency ?: 'PLN')) !== 'PLN', 'analytics_amount_source' => 'orders.total'], $result);
        });

        return view('admin.tools.sales-analytics.currency-conversion-diagnose', [
            'rows' => $rows,
            'filters' => $filters,
            'unconvertedCount' => $rows->where('conversion_status', 'unconverted')->count(),
            'potentialOneToOneCount' => $rows->filter(fn ($row) => $row['is_foreign_currency'] && $row['conversion_status'] !== 'converted')->count(),
        ]);
    }
}
