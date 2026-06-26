@php
    use App\Filament\Resources\OrderResource;
    use App\Models\Shipment;
    use App\Services\Admin\OrderStatusOptions;

    $displayNumber = OrderResource::displayOrderNumber($order);
    $fullNumber = trim((string) ($order->marketplace_order_id ?: $order->order_number ?: $displayNumber));
    $marketplace = trim((string) $order->marketplace) ?: 'sklep';
    $buyerName = trim((string) ($order->customer_name ?: $order->company_name ?: '—'));
    $phone = trim((string) $order->phone);
    $email = trim((string) $order->email);
    $orderedAt = $order->ordered_at ? $order->ordered_at->format('Y-m-d H:i') : '—';
    $statusOptions = OrderStatusOptions::optionsForOrder($order);
    $selectedStatus = OrderStatusOptions::selectedValueForOrder($order);
    $total = OrderResource::formatOrderTotal($order);
    $viewUrl = OrderResource::getUrl('view', ['record' => $order]);
    $editUrl = OrderResource::getUrl('edit', ['record' => $order]);

    $items = $order->items;
    $firstItem = $items->first();
    $itemsCount = $items->count();
    $firstItemName = trim((string) ($firstItem?->product_name ?: $firstItem?->part_number ?: $firstItem?->sku ?: ''));

    $shipment = $order->shipments->first();
    $carrierLabels = Shipment::CARRIERS;
    $carrier = $shipment ? ($carrierLabels[$shipment->carrier] ?? $shipment->carrier) : null;
    $trackingNumber = $shipment ? trim((string) $shipment->tracking_number) : '';
    $shipmentStatus = $shipment ? trim((string) $shipment->shipment_status) : '';
@endphp

@once
    <style>
        .fi-ta-table:has(.gps-admin-order-card) {
            border-collapse: separate;
            border-spacing: 0 10px;
            background: transparent;
        }

        .fi-ta-table:has(.gps-admin-order-card) thead th {
            padding: 0 18px 8px !important;
            color: #475569;
            font-size: 12px;
            font-weight: 600;
        }

        .fi-ta-table:has(.gps-admin-order-card) thead th .fi-ta-header-cell-label,
        .fi-ta-table:has(.gps-admin-order-card) thead th .fi-ta-header-cell-label > span {
            display: block;
            width: 100%;
        }

        .gps-admin-order-card-wrapper,
        .gps-admin-order-card,
        .gps-admin-order-card > .gps-admin-orders-grid {
            display: block;
            width: 100%;
            max-width: none;
        }

        .gps-admin-orders-grid,
        .gps-admin-order-card-grid {
            display: grid;
            grid-template-columns:
                minmax(0, 1.15fr)
                minmax(0, 0.8fr)
                minmax(0, 1fr)
                minmax(0, 0.75fr)
                minmax(0, 1.45fr)
                minmax(0, 0.95fr);
            gap: 20px;
            align-items: center;
            width: 100%;
            max-width: none;
            min-width: 0;
        }

        .gps-admin-order-card-grid {
            display: grid;
            width: 100%;
            max-width: none;
            grid-template-columns:
                minmax(0, 1.15fr)
                minmax(0, 0.8fr)
                minmax(0, 1fr)
                minmax(0, 0.75fr)
                minmax(0, 1.45fr)
                minmax(0, 0.95fr);
            gap: 20px;
            align-items: center;
        }

        .gps-admin-order-card-grid > .gps-admin-order-col {
            min-width: 0;
        }

        .gps-admin-orders-header {
            color: #475569;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .02em;
            line-height: 1.2;
            text-transform: uppercase;
        }

        .gps-admin-orders-header > div,
        .gps-admin-order-col {
            min-width: 0;
        }

        .fi-ta-table:has(.gps-admin-order-card) tbody tr,
        .fi-ta-table:has(.gps-admin-order-card) tbody tr:hover {
            background: transparent;
            border: 0;
        }

        .fi-ta-table:has(.gps-admin-order-card) tbody td {
            padding: 0 !important;
            border: 0;
            background: transparent;
        }

        .gps-admin-orders-view-column,
        .gps-admin-orders-view-column *,
        .fi-ta-cell.gps-admin-orders-view-column,
        .fi-ta-cell.gps-admin-orders-view-column > *,
        .fi-ta-cell.gps-admin-orders-view-column .fi-ta-text,
        .fi-ta-cell.gps-admin-orders-view-column .fi-ta-text-item,
        .fi-ta-cell.gps-admin-orders-view-column .fi-ta-col-wrp {
            max-width: none;
        }

        .gps-admin-orders-view-column,
        .gps-admin-orders-view-column > *,
        .gps-admin-orders-view-column .fi-ta-text,
        .gps-admin-orders-view-column .fi-ta-text-item,
        .gps-admin-orders-view-column .fi-ta-col-wrp,
        .fi-ta-cell.gps-admin-orders-view-column,
        .fi-ta-cell.gps-admin-orders-view-column > *,
        .fi-ta-cell.gps-admin-orders-view-column .fi-ta-text,
        .fi-ta-cell.gps-admin-orders-view-column .fi-ta-text-item,
        .fi-ta-cell.gps-admin-orders-view-column .fi-ta-col-wrp,
        .fi-ta-cell:has(.gps-admin-order-card),
        .fi-ta-cell:has(.gps-admin-order-card) .fi-ta-text,
        .fi-ta-cell:has(.gps-admin-order-card) .fi-ta-text-item,
        .fi-ta-cell:has(.gps-admin-order-card) .fi-ta-col-wrp {
            display: block;
            width: 100%;
            max-width: none;
        }

        .fi-ta-cell:has(.gps-admin-order-card).whitespace-nowrap,
        .fi-ta-cell:has(.gps-admin-order-card) .whitespace-nowrap {
            white-space: normal;
        }

        .gps-admin-order-card {
            display: block;
            width: 100%;
            min-height: 140px;
            padding: 16px 18px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #ffffff;
            color: #1e293b;
            font-size: 13px;
            font-weight: 400;
            line-height: 1.35;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
            transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
        }

        .gps-admin-order-card .gps-admin-orders-grid {
            align-items: start;
        }

        .gps-admin-order-card:hover {
            border-color: #cbd5e1;
            background: #fbfdff;
            box-shadow: 0 8px 18px rgba(15, 23, 42, .06);
        }

        .gps-admin-order-card__section {
            display: grid;
            min-width: 0;
            max-width: 100%;
            gap: 7px;
            align-content: start;
        }

        .gps-admin-order-card__value {
            min-width: 0;
            color: #1e293b;
            font-size: 13px;
            font-weight: 400;
            line-height: 1.35;
        }

        .gps-admin-order-card__number,
        .gps-admin-order-card__buyer-name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .gps-admin-order-card__number {
            font-weight: 600;
        }

        .gps-admin-order-card__badges,
        .gps-admin-order-card__actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }

        .gps-admin-order-card__badge {
            display: inline-flex;
            max-width: 100%;
            min-width: 0;
            align-items: center;
            overflow-wrap: anywhere;
            border-radius: 999px;
            background: #f1f5f9;
            padding: 3px 8px;
            color: #334155;
            font-size: 11px;
            font-weight: 500;
            line-height: 1.2;
        }

        .gps-admin-order-card__badge--status { background: #e0f2fe; color: #075985; }
        .gps-admin-order-card__badge--local { background: #dcfce7; color: #166534; }

        .gps-admin-order-card__status-select {
            width: 100%;
            max-width: 160px;
            min-height: 34px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            appearance: none;
            -webkit-appearance: none;
            background-color: #f8fafc;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none'%3E%3Cpath d='M6 8l4 4 4-4' stroke='%23334155' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 8px center;
            background-size: 14px 14px;
            padding: 6px 28px 6px 10px;
            color: #0f172a;
            font-size: 12px;
            font-weight: 800;
            line-height: 1.2;
            text-transform: uppercase;
        }

        .gps-admin-order-card__status-select:disabled {
            cursor: default;
            opacity: 1;
        }

        .gps-admin-order-card__status-select::-ms-expand {
            display: none;
        }

        .gps-admin-order-card__source-row {
            color: #64748b;
            font-size: 12px;
            line-height: 1.25;
            white-space: nowrap;
        }

        .gps-order-source {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            border: 0;
            border-radius: 0;
            padding: 0;
            background: transparent;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.25;
            vertical-align: baseline;
        }

        .gps-order-source--allegro {
            color: #ff5a00;
            font-family: "Open Sans", Arial, sans-serif;
        }

        .gps-order-source--ovoko {
            color: #FF7A00;
            font-family: Inter, Arial, Helvetica, sans-serif;
        }

        .gps-order-source--ebay {
            font-family: "Market Sans", Arial, "Helvetica Neue", sans-serif;
            letter-spacing: -.02em;
        }

        .gps-order-source--local {
            color: #334155;
            font-family: inherit;
            font-weight: 600;
        }

        .gps-admin-order-card__muted {
            overflow: hidden;
            color: #64748b;
            font-size: 12px;
            font-weight: 400;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .gps-admin-order-card__total {
            color: #0f172a;
            font-size: 15px;
            font-weight: 700;
            line-height: 1.25;
            white-space: nowrap;
        }

        .gps-admin-order-card__part-name {
            display: -webkit-box;
            overflow: hidden;
            color: #1e293b;
            font-size: 13px;
            font-weight: 400;
            line-height: 1.35;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }

        .gps-admin-order-card__action {
            border-radius: 999px;
            border: 1px solid #cbd5e1;
            padding: 5px 10px;
            color: #2563eb;
            font-size: 12px;
            font-weight: 500;
            line-height: 1.2;
            text-decoration: none;
            white-space: nowrap;
        }

        .gps-admin-order-card__action:hover {
            border-color: #2563eb;
            background: #eff6ff;
            color: #1d4ed8;
        }

        @media (max-width: 1280px) {
            .gps-admin-orders-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            .gps-admin-orders-grid {
                grid-template-columns: 1fr;
            }

            .gps-admin-orders-header {
                display: none;
            }
        }
    </style>
@endonce

<div class="gps-admin-order-card-wrapper">
    <div class="gps-admin-order-card" title="{{ $fullNumber }}">
        <div class="gps-admin-orders-grid gps-admin-order-card-grid">
            <div class="gps-admin-order-card__section gps-admin-order-col gps-admin-order-col-number">
                <div class="gps-admin-order-card__value gps-admin-order-card__number" title="{{ $fullNumber }}">{{ $displayNumber }}</div>
                <div class="gps-admin-order-card__muted">{{ $orderedAt }}</div>
                <div class="gps-admin-order-card__source-row">Źródło: @include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $marketplace])</div>
            </div>

            <div class="gps-admin-order-card__section gps-admin-order-col gps-admin-order-col-status">
                <select class="gps-admin-order-card__status-select" aria-label="Status zamówienia" disabled>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}" @selected($selectedStatus === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="gps-admin-order-card__section gps-admin-order-col gps-admin-order-col-buyer" title="{{ $email }}">
                <div class="gps-admin-order-card__value gps-admin-order-card__buyer-name">{{ $buyerName }}</div>
                @if($phone !== '')
                    <div class="gps-admin-order-card__muted">{{ $phone }}</div>
                @endif
            </div>

            <div class="gps-admin-order-card__section gps-admin-order-col gps-admin-order-col-amount">
                <div class="gps-admin-order-card__total">{{ $total }}</div>
            </div>

            <div class="gps-admin-order-card__section gps-admin-order-col gps-admin-order-col-item">
                @if($firstItemName !== '')
                    <div class="gps-admin-order-card__part-name" title="{{ $firstItemName }}">{{ $firstItemName }}</div>
                    @if($itemsCount > 1)
                        <div class="gps-admin-order-card__muted">+ {{ $itemsCount - 1 }} więcej</div>
                    @endif
                @else
                    <div class="gps-admin-order-card__muted">Brak danych</div>
                @endif
            </div>

            <div class="gps-admin-order-card__section gps-admin-order-col gps-admin-order-col-shipping">
                @if($shipment)
                    <div class="gps-admin-order-card__value">{{ $carrier ?: '—' }}</div>
                    @if($trackingNumber !== '')
                        <div class="gps-admin-order-card__muted">{{ $trackingNumber }}</div>
                    @endif
                    @if($shipmentStatus !== '')
                        <div><span class="gps-admin-order-card__badge">{{ $shipmentStatus }}</span></div>
                    @endif
                @else
                    <div class="gps-admin-order-card__muted">Brak przesyłki</div>
                @endif
                <div class="gps-admin-order-card__actions">
                    <a class="gps-admin-order-card__action" href="{{ $viewUrl }}">Szczegóły</a>
                    <a class="gps-admin-order-card__action" href="{{ $editUrl }}">Zmień status</a>
                </div>
            </div>
        </div>
    </div>
</div>
