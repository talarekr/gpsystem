@php
    use App\Filament\Resources\OrderResource;
    use App\Models\Order;

    $displayNumber = OrderResource::displayOrderNumber($order);
    $fullNumber = trim((string) ($order->marketplace_order_id ?: $order->order_number ?: $displayNumber));
    $marketplace = trim((string) $order->marketplace) ?: 'sklep';
    $marketplaceStatus = trim((string) $order->marketplace_status);
    $buyerName = trim((string) ($order->customer_name ?: $order->company_name ?: '—'));
    $phone = trim((string) $order->phone);
    $email = trim((string) $order->email);
    $orderedAt = $order->ordered_at ? $order->ordered_at->format('Y-m-d H:i') : '—';
    $statusLabel = Order::statusOptions()[$order->status] ?? $order->status;
    $total = OrderResource::formatOrderTotal($order);
    $viewUrl = OrderResource::getUrl('view', ['record' => $order]);
    $editUrl = OrderResource::getUrl('edit', ['record' => $order]);
@endphp

@once
    <style>
        .fi-ta-table:has(.gps-admin-order-card) {
            border-collapse: separate;
            border-spacing: 0 10px;
            background: transparent;
        }

        .fi-ta-table:has(.gps-admin-order-card) thead th {
            color: #475569;
            font-size: 12px;
            font-weight: 600;
        }

        .fi-ta-table:has(.gps-admin-order-card) tbody tr {
            background: transparent;
            border: 0;
        }

        .fi-ta-table:has(.gps-admin-order-card) tbody tr:hover {
            background: transparent;
        }

        .fi-ta-table:has(.gps-admin-order-card) tbody td {
            padding: 0 !important;
            border: 0;
            background: transparent;
        }

        .fi-ta-table:has(.gps-admin-order-card) tbody td > .fi-ta-col-wrp,
        .fi-ta-table:has(.gps-admin-order-card) tbody td .fi-ta-text,
        .fi-ta-table:has(.gps-admin-order-card) tbody td .fi-ta-text-item {
            display: block;
            width: 100%;
            max-width: none;
        }

        .gps-admin-order-card {
            display: grid;
            grid-template-columns: minmax(190px, 260px) minmax(280px, 1fr) minmax(190px, 240px);
            min-height: 140px;
            gap: 18px;
            align-items: stretch;
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

        .gps-admin-order-card:hover {
            border-color: #cbd5e1;
            background: #fbfdff;
            box-shadow: 0 8px 18px rgba(15, 23, 42, .06);
        }

        .gps-admin-order-card__section {
            min-width: 0;
        }

        .gps-admin-order-card__left,
        .gps-admin-order-card__middle,
        .gps-admin-order-card__right {
            display: flex;
            min-width: 0;
            flex-direction: column;
            justify-content: space-between;
            gap: 10px;
        }

        .gps-admin-order-card__number {
            display: -webkit-box;
            overflow: hidden;
            color: #1e293b;
            font-size: 15px;
            font-weight: 600;
            line-height: 1.3;
            overflow-wrap: anywhere;
            text-overflow: ellipsis;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
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
            align-items: center;
            border-radius: 999px;
            background: #f1f5f9;
            padding: 3px 8px;
            color: #334155;
            font-size: 11px;
            font-weight: 500;
            line-height: 1.2;
        }

        .gps-admin-order-card__badge--marketplace { background: #dbeafe; color: #1e40af; }
        .gps-admin-order-card__badge--status { background: #e0f2fe; color: #075985; }
        .gps-admin-order-card__badge--local { background: #dcfce7; color: #166534; }
        .gps-admin-order-card__badge--test { background: #fef3c7; color: #92400e; font-weight: 700; letter-spacing: .035em; }

        .gps-admin-order-card__buyer {
            display: grid;
            gap: 4px;
            min-width: 0;
        }

        .gps-admin-order-card__buyer-name {
            overflow: hidden;
            color: #1e293b;
            font-size: 14px;
            font-weight: 500;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .gps-admin-order-card__muted {
            overflow: hidden;
            color: #64748b;
            font-size: 12px;
            font-weight: 400;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .gps-admin-order-card__date {
            color: #334155;
            font-size: 13px;
            font-weight: 400;
        }

        .gps-admin-order-card__total {
            color: #0f172a;
            font-size: 18px;
            font-weight: 700;
            line-height: 1.2;
            text-align: right;
            white-space: nowrap;
        }

        .gps-admin-order-card__right {
            align-items: flex-end;
            text-align: right;
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
        }

        .gps-admin-order-card__action:hover {
            border-color: #2563eb;
            background: #eff6ff;
            color: #1d4ed8;
        }

        @media (max-width: 900px) {
            .gps-admin-order-card {
                grid-template-columns: 1fr;
                min-height: 140px;
            }

            .gps-admin-order-card__right {
                align-items: flex-start;
                text-align: left;
            }

            .gps-admin-order-card__total {
                text-align: left;
            }
        }
    </style>
@endonce

<article class="gps-admin-order-card" title="{{ $fullNumber }}">
    <section class="gps-admin-order-card__section gps-admin-order-card__left">
        <div class="gps-admin-order-card__number">{{ $displayNumber }}</div>
        <div class="gps-admin-order-card__badges">
            <span class="gps-admin-order-card__badge gps-admin-order-card__badge--marketplace">{{ $marketplace }}</span>
            @if($marketplaceStatus !== '')
                <span class="gps-admin-order-card__badge gps-admin-order-card__badge--status">{{ $marketplaceStatus }}</span>
            @endif
            @if($order->test_import)
                <span class="gps-admin-order-card__badge gps-admin-order-card__badge--test">TEST</span>
            @endif
        </div>
    </section>

    <section class="gps-admin-order-card__section gps-admin-order-card__middle">
        <div class="gps-admin-order-card__buyer">
            <div class="gps-admin-order-card__buyer-name">{{ $buyerName }}</div>
            @if($phone !== '')
                <div class="gps-admin-order-card__muted">{{ $phone }}</div>
            @endif
            @if($email !== '')
                <div class="gps-admin-order-card__muted">{{ $email }}</div>
            @endif
        </div>
        <div class="gps-admin-order-card__date">Data sprzedaży: {{ $orderedAt }}</div>
    </section>

    <section class="gps-admin-order-card__section gps-admin-order-card__right">
        <div class="gps-admin-order-card__total">{{ $total }}</div>
        <span class="gps-admin-order-card__badge gps-admin-order-card__badge--local">{{ $statusLabel }}</span>
        <div class="gps-admin-order-card__actions">
            <a class="gps-admin-order-card__action" href="{{ $viewUrl }}">Szczegóły</a>
            <a class="gps-admin-order-card__action" href="{{ $editUrl }}">Zmień status</a>
        </div>
    </section>
</article>
