@php
    use App\Filament\Resources\OrderResource;
    use App\Models\Part;
    use App\Models\Shipment;
    use App\Services\Admin\OrderStatusOptions;
    use Illuminate\Support\Str;

    $order = $record;
    $order->loadMissing(['items.part.images', 'items.part.storageLocation', 'items.marketplaceListing.part.images', 'items.marketplaceListing.part.storageLocation', 'shipments']);

    $externalOrderId = trim((string) ($order->marketplace_order_id ?: $order->order_number));
    $marketplace = trim((string) $order->marketplace) ?: 'Sklep';
    $orderedAt = $order->ordered_at ? $order->ordered_at->format('Y-m-d H:i') : '—';
    $statusOptions = OrderStatusOptions::optionsForOrder($order);
    $selectedStatus = OrderStatusOptions::selectedValueForOrder($order);
    $total = OrderResource::formatOrderTotal($order);
    $currency = $order->currency ?: 'PLN';
    $customerDisplay = \App\Support\OrderCustomerDisplay::forOrder($order);
    $shippingPaymentLines = app(\App\Support\OrderShippingPaymentDisplayResolver::class)->resolve($order);
    $paymentLabel = $shippingPaymentLines['payment'] ?? null;
    $deliveryLabel = $shippingPaymentLines['delivery'] ?? $order->delivery_method;
    $shipment = $order->shipments->first();
    $carrier = $shipment ? (Shipment::CARRIERS[$shipment->carrier] ?? $shipment->carrier) : null;
    $deliveryMethod = trim((string) ($deliveryLabel ?: $carrier ?: $order->delivery_method));
    $deliveryType = trim((string) data_get($order->raw_payload, 'delivery.type', data_get($order->raw_payload, 'delivery_type', data_get($order->raw_payload, 'shipping_type'))));
    $deliveryPhone = trim((string) (data_get($order->raw_payload, 'delivery.address.phoneNumber') ?: data_get($order->raw_payload, 'delivery.address.phone') ?: $order->phone));
    $addressParts = array_values(array_filter([
        trim((string) $order->address_line1),
        trim(implode(' ', array_filter([trim((string) $order->postal_code), trim((string) $order->city)]))),
        trim((string) $order->country),
    ], fn ($value) => $value !== ''));
    $paymentStatus = trim((string) ($paymentLabel ?: $order->payment_status));
    $paymentType = trim((string) (data_get($order->raw_payload, 'payment.type') ?: data_get($order->raw_payload, 'payment_type') ?: data_get($order->raw_payload, 'payment_method')));
    $paymentProvider = trim((string) (data_get($order->raw_payload, 'payment.provider') ?: data_get($order->raw_payload, 'payment_provider')));
    $isPaid = Str::contains(Str::lower($paymentStatus), ['zapłac', 'paid', 'completed', 'finished', 'settled']);
    $technicalItems = array_filter([
        'Status marketplace' => $order->marketplace_status,
        'TEST IMPORT' => $order->test_import ? 'Tak' : null,
        'Batch' => $order->source_batch,
        'NIP' => $order->nip,
    ], fn ($value) => filled($value));
@endphp

<x-filament-panels::page>
    <style>
        .gps-order-detail { display: grid; gap: 18px; }
        .gps-order-detail-card { background: #fff; border: 1px solid rgba(148, 163, 184, .22); border-radius: 22px; box-shadow: 0 14px 36px rgba(15, 23, 42, .06); padding: 22px; width: 100%; }
        .gps-order-detail-summary { display: block; }
        .gps-order-detail-muted { color: #64748b; font-size: 13px; line-height: 1.45; }
        .gps-order-detail-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 28px; align-items: start; }
        .gps-order-detail-fact { min-width: 0; }
        .gps-order-detail-label { border-bottom: 1px solid #e2e8f0; color: #475569; font-size: 12px; font-weight: 800; letter-spacing: .03em; margin-bottom: 12px; padding-bottom: 9px; text-transform: uppercase; }
        .gps-order-detail-value { color: #0f172a; font-size: 13px; font-weight: 400; line-height: 1.45; overflow-wrap: anywhere; }
        .gps-order-detail-date { color: #64748b; font-size: 12px; line-height: 1.45; }
        .gps-order-detail-source-row { color: #475569; font-size: 13px; line-height: 1.45; margin-top: 3px; }
        .gps-order-status-select {
            width: 100%;
            max-width: 150px;
            min-height: 34px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            appearance: none;
            -webkit-appearance: none;
            background-color: #fff;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none'%3E%3Cpath d='M6 8l4 4 4-4' stroke='%23334155' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 8px center;
            background-size: 14px 14px;
            padding: 6px 28px 6px 10px;
            color: #0f172a;
            font-size: 12px;
            font-weight: 400;
            line-height: 1.2;
            text-transform: uppercase;
        }
        .gps-order-status-select::-ms-expand { display: none; }
        .gps-order-detail-section-title { color: #0f172a; font-size: 17px; font-weight: 800; margin: 0 0 14px; }
        .gps-order-detail-items { display: grid; gap: 12px; }
        .gps-order-detail-item { display: grid; grid-template-columns: 74px minmax(0, 1fr) auto; gap: 16px; align-items: center; border: 1px solid #e2e8f0; border-radius: 18px; padding: 14px; background: #fff; }
        .gps-order-detail-thumb, .gps-order-detail-placeholder { width: 74px; height: 74px; border-radius: 14px; object-fit: cover; background: #f1f5f9; display: grid; place-items: center; color: #94a3b8; overflow: hidden; }
        .gps-order-detail-item-name { color: #0f172a; font-size: 15px; font-weight: 800; }
        .gps-order-detail-tags { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 8px; }
        .gps-order-detail-tag { background: #f1f5f9; border-radius: 999px; color: #475569; font-size: 12px; font-weight: 700; padding: 5px 9px; }
        .gps-order-detail-price { text-align: right; color: #0f172a; font-size: 15px; font-weight: 800; white-space: nowrap; }
        .gps-order-detail-two { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
        .gps-order-paid { color: #15803d; }
        .gps-order-tech summary { cursor: pointer; color: #334155; font-weight: 800; }
        .gps-order-tech dl { display: grid; grid-template-columns: max-content minmax(0,1fr); gap: 8px 16px; margin-top: 14px; }
        .gps-order-tech dt { color: #64748b; font-size: 12px; font-weight: 700; } .gps-order-tech dd { color: #0f172a; font-size: 13px; margin: 0; overflow-wrap: anywhere; }
        @media (max-width: 1000px) { .gps-order-detail-summary, .gps-order-detail-two { grid-template-columns: 1fr; } .gps-order-detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 700px) { .gps-order-detail-grid, .gps-order-detail-item { grid-template-columns: 1fr; } .gps-order-detail-price { text-align: left; } }
    </style>

    <div class="gps-order-detail">
        <section class="gps-order-detail-card gps-order-detail-summary">
            <div class="gps-order-detail-grid">
                <div class="gps-order-detail-fact">
                    <div class="gps-order-detail-label">Status</div>
                    <select class="gps-order-status-select" aria-label="Status zamówienia" wire:change="updateOrderStatus($event.target.value)">
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($selectedStatus === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="gps-order-detail-fact">
                    <div class="gps-order-detail-label">Status płatności</div>
                    <div class="gps-order-detail-value {{ $paymentStatus === 'Zapłacono' ? 'gps-order-paid' : '' }}">{{ $paymentStatus !== '' ? $paymentStatus : '—' }}</div>
                </div>
                <div class="gps-order-detail-fact">
                    <div class="gps-order-detail-label">Numer zamówienia</div>
                    <div class="gps-order-detail-value">{{ $externalOrderId !== '' ? $externalOrderId : '—' }}</div>
                </div>
                <div class="gps-order-detail-fact">
                    <div class="gps-order-detail-label">Data i kanał sprzedaży</div>
                    <div class="gps-order-detail-date">{{ $orderedAt }}</div>
                    <div class="gps-order-detail-source-row">Źródło: @include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $marketplace])</div>
                </div>
            </div>
        </section>

        <section class="gps-order-detail-card">
            <h2 class="gps-order-detail-section-title">Sprzedana część</h2>
            <div class="gps-order-detail-items">
                @forelse ($order->items as $item)
                    @php
                        $thumb = \App\Support\OrderItemThumbnailDiagnostics::resolve($order, $item);
                        $thumbPart = $thumb['thumbnail_part'] ?? null;
                        $partId = $thumb['resolved_part_id'] ?? $item->part_id;
                        $lineTotal = $item->line_total !== null ? number_format((float) $item->line_total, 2, ',', ' ').' '.($item->currency ?: $currency) : '—';
                        $unitPrice = $item->unit_price !== null ? number_format((float) $item->unit_price, 2, ',', ' ').' '.($item->currency ?: $currency) : null;
                    @endphp
                    <article class="gps-order-detail-item">
                        @if ($thumbPart instanceof Part && $thumb['thumbnail_source'] === 'admin_parts_thumbnail')
                            @include('filament.resources.parts.table-image', ['part' => $thumbPart])
                        @elseif ($thumb['thumbnail_url'])
                            <img class="gps-order-detail-thumb" src="{{ $thumb['thumbnail_url'] }}" alt="{{ $thumb['display_name'] }}">
                        @else
                            <div class="gps-order-detail-placeholder"><x-heroicon-o-photo class="h-7 w-7" /></div>
                        @endif
                        <div>
                            <div class="gps-order-detail-item-name">{{ $thumb['display_name'] }}</div>
                            <div class="gps-order-detail-tags">
                                <span class="gps-order-detail-tag">Magazyn: {{ $thumb['storage_location'] }}</span>
                                @if ($partId)<span class="gps-order-detail-tag">ID części: #{{ $partId }}</span>@endif
                                @if ($item->sku)<span class="gps-order-detail-tag">SKU: {{ $item->sku }}</span>@endif
                                @if ($item->part_number)<span class="gps-order-detail-tag">Numer: {{ $item->part_number }}</span>@endif
                                <span class="gps-order-detail-tag">Ilość: {{ $item->quantity ?: 1 }}</span>
                            </div>
                        </div>
                        <div class="gps-order-detail-price">{{ $lineTotal }}@if ($unitPrice)<div class="gps-order-detail-muted">{{ $unitPrice }} / szt.</div>@endif</div>
                    </article>
                @empty
                    <div class="gps-order-detail-muted">Brak pozycji zamówienia.</div>
                @endforelse
            </div>
        </section>

        <div class="gps-order-detail-two">
            <section class="gps-order-detail-card"><h2 class="gps-order-detail-section-title">Klient</h2><div class="gps-order-detail-grid">
                <div class="gps-order-detail-fact"><div class="gps-order-detail-label">Nazwa</div><div class="gps-order-detail-value">{{ $customerDisplay['name'] ?: '—' }}</div></div>
                @if ($customerDisplay['phone'] !== '')<div class="gps-order-detail-fact"><div class="gps-order-detail-label">Telefon</div><div class="gps-order-detail-value">{{ $customerDisplay['phone'] }}</div></div>@endif
                @if (filled($order->email))<div class="gps-order-detail-fact"><div class="gps-order-detail-label">E-mail</div><div class="gps-order-detail-value">{{ $order->email }}</div></div>@endif
                @if (filled($order->company_name))<div class="gps-order-detail-fact"><div class="gps-order-detail-label">Firma</div><div class="gps-order-detail-value">{{ $order->company_name }}</div></div>@endif
            </div></section>

            <section class="gps-order-detail-card"><h2 class="gps-order-detail-section-title">Dostawa</h2><div class="gps-order-detail-grid">
                @if ($deliveryMethod !== '')<div class="gps-order-detail-fact"><div class="gps-order-detail-label">Metoda</div><div class="gps-order-detail-value">{{ $deliveryMethod }}</div></div>@endif
                @if ($deliveryType !== '')<div class="gps-order-detail-fact"><div class="gps-order-detail-label">Typ</div><div class="gps-order-detail-value">{{ Str::headline(str_replace(['_', '-'], ' ', $deliveryType)) }}</div></div>@endif
                @if ($addressParts !== [])<div class="gps-order-detail-fact"><div class="gps-order-detail-label">Adres</div><div class="gps-order-detail-value">{!! implode('<br>', array_map('e', $addressParts)) !!}</div></div>@endif
                @if ($deliveryPhone !== '')<div class="gps-order-detail-fact"><div class="gps-order-detail-label">Telefon</div><div class="gps-order-detail-value">{{ $deliveryPhone }}</div></div>@endif
                @if (filled($order->notes))<div class="gps-order-detail-fact"><div class="gps-order-detail-label">Uwagi</div><div class="gps-order-detail-value">{{ $order->notes }}</div></div>@endif
            </div></section>
        </div>

        <section class="gps-order-detail-card"><h2 class="gps-order-detail-section-title">Płatność</h2><div class="gps-order-detail-grid">
            @if ($paymentStatus !== '')<div class="gps-order-detail-fact"><div class="gps-order-detail-label">Status</div><div class="gps-order-detail-value {{ $isPaid ? 'gps-order-paid' : '' }}">{{ $paymentStatus }}</div></div>@endif
            @if ($paymentType !== '')<div class="gps-order-detail-fact"><div class="gps-order-detail-label">Typ</div><div class="gps-order-detail-value">{{ Str::headline(str_replace(['_', '-'], ' ', $paymentType)) }}</div></div>@endif
            <div class="gps-order-detail-fact"><div class="gps-order-detail-label">Kwota</div><div class="gps-order-detail-value">{{ $total }}</div></div>
            <div class="gps-order-detail-fact"><div class="gps-order-detail-label">Waluta</div><div class="gps-order-detail-value">{{ $currency }}</div></div>
            @if ($paymentProvider !== '')<div class="gps-order-detail-fact"><div class="gps-order-detail-label">Provider</div><div class="gps-order-detail-value">{{ Str::headline(str_replace(['_', '-'], ' ', $paymentProvider)) }}</div></div>@endif
        </div></section>

        @if ($technicalItems !== [])
            <section class="gps-order-detail-card gps-order-tech"><details><summary>Dane techniczne</summary><dl>@foreach ($technicalItems as $label => $value)<dt>{{ $label }}</dt><dd>{{ $value }}</dd>@endforeach</dl></details></section>
        @endif
    </div>
</x-filament-panels::page>
