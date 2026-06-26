@php
    use App\Filament\Resources\OrderResource;

    $displayNumber = OrderResource::displayOrderNumber($order);
    $orderedAt = $order->ordered_at ? $order->ordered_at->format('Y-m-d H:i') : '—';
    $marketplace = $order->marketplace ?: 'Sklep';
@endphp

@once
    <style>
        .gps-admin-order-number {
            display: grid;
            gap: 5px;
            min-width: 0;
        }

        .gps-admin-order-number__value {
            min-width: 0;
            overflow: hidden;
            color: #1e293b;
            font-weight: 600;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .gps-admin-order-number__date,
        .gps-admin-order-number__source-row {
            color: #64748b;
            font-size: 12px;
            font-weight: 400;
            line-height: 1.25;
        }

        .gps-order-source {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            border-radius: 999px;
            padding: 2px 7px;
            background: #f8fafc;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.25;
            vertical-align: baseline;
        }

        .gps-order-source--allegro { color: #ff5a00; font-family: "Open Sans", Arial, sans-serif; }
        .gps-order-source--ovoko { color: #FF7A00; font-family: Inter, Arial, Helvetica, sans-serif; }
        .gps-order-source--ebay { font-family: "Market Sans", Arial, "Helvetica Neue", sans-serif; letter-spacing: -.02em; }
        .gps-order-source--local { color: #334155; font-family: inherit; font-weight: 600; }
    </style>
@endonce

<div class="gps-admin-order-number">
    <span class="gps-admin-order-number__value">{{ $displayNumber }}</span>
    <span class="gps-admin-order-number__date">{{ $orderedAt }}</span>
    <span class="gps-admin-order-number__source-row">Źródło: @include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $marketplace])</span>
</div>
