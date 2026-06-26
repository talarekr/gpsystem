@php
    use App\Filament\Resources\OrderResource;
    use App\Models\Order;

    $orders = $this->orders;
@endphp

<x-filament-panels::page>
    <style>
        .gps-orders-toolbar {
            display: grid;
            grid-template-columns: minmax(240px, 1fr) repeat(5, minmax(140px, auto));
            gap: 12px;
            align-items: end;
            margin-bottom: 20px;
        }

        .gps-orders-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
        }

        .gps-orders-field label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
        }

        .gps-orders-field input,
        .gps-orders-field select {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            background: #fff;
            padding: 9px 12px;
            font-size: 14px;
            color: #0f172a;
        }

        .gps-orders-filter-summary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            border-radius: 10px;
            background: #eff6ff;
            color: #1d4ed8;
            font-weight: 700;
            padding: 0 12px;
            white-space: nowrap;
        }

        .gps-orders-reset-button {
            min-height: 40px;
            border-radius: 10px;
            border: 1px solid #d1d5db;
            padding: 0 14px;
            font-weight: 700;
            color: #334155;
            background: #fff;
        }

        .gps-orders-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            width: 100%;
        }

        .gps-admin-orders-grid {
            display: grid;
            grid-template-columns:
                minmax(430px, 2fr)
                minmax(170px, 0.85fr)
                minmax(120px, 0.6fr)
                minmax(170px, 0.85fr)
                minmax(130px, 0.65fr)
                minmax(180px, 0.85fr);
            gap: 20px;
            width: 100%;
            align-items: center;
        }

        .gps-orders-list-header {
            padding: 0 18px 4px;
            color: #64748b;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .gps-order-card {
            width: 100%;
            min-height: 140px;
            display: block;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .06);
            padding: 18px;
        }

        .gps-order-col {
            min-width: 0;
        }

        .gps-order-value {
            color: #1e293b;
            font-size: 13px;
            font-weight: 400;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }

        .gps-order-number {
            color: #1e293b;
            font-size: 13px;
            font-weight: 500;
            line-height: 1.35;
        }

        .gps-order-muted {
            color: #64748b;
            font-size: 13px;
            margin-top: 5px;
            overflow-wrap: anywhere;
        }

        .gps-order-badges,
        .gps-order-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }

        .gps-order-source-row {
            margin-top: 7px;
            color: #64748b;
            font-size: 12px;
            line-height: 1.25;
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

        .gps-order-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            background: #e2e8f0;
            color: #334155;
            font-size: 11px;
            font-weight: 800;
            line-height: 1;
            padding: 6px 8px;
        }

        .gps-order-badge--status { background: #e0f2fe; color: #075985; }

        .gps-order-total {
            color: #1e293b;
            font-size: 13px;
            font-weight: 500;
            line-height: 1.35;
            white-space: nowrap;
        }

        .gps-order-sold-part {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .gps-order-part-thumb,
        .gps-order-part-placeholder {
            flex: 0 0 150px;
            width: 150px;
            height: 112px;
            border-radius: 6px;
            background: #f1f5f9;
        }

        .gps-order-part-thumb {
            display: block;
            object-fit: cover;
        }

        .gps-order-sold-part .gps-admin-part-thumb {
            flex: 0 0 150px;
        }

        .gps-order-part-placeholder {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            color: #94a3b8;
            border: 1px solid #e2e8f0;
        }

        .gps-order-part-info {
            min-width: 0;
        }

        .gps-order-action {
            border-radius: 999px;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 800;
            padding: 7px 10px;
            text-decoration: none;
            white-space: nowrap;
        }

        .gps-order-empty {
            border: 1px dashed #cbd5e1;
            border-radius: 18px;
            padding: 32px;
            text-align: center;
            color: #64748b;
            background: #fff;
        }

        .gps-orders-pagination {
            margin-top: 18px;
        }

        @media (max-width: 1200px) {
            .gps-orders-toolbar,
            .gps-admin-orders-grid {
                grid-template-columns: 1fr 1fr;
            }

            .gps-orders-list-header {
                display: none;
            }
        }

        @media (max-width: 700px) {
            .gps-orders-toolbar,
            .gps-admin-orders-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="gps-orders-toolbar">
        <div class="gps-orders-field">
            <label for="orders-search">Szukaj</label>
            <input id="orders-search" type="search" wire:model.live.debounce.500ms="search" placeholder="Numer, klient, telefon, e-mail...">
        </div>

        <div class="gps-orders-field">
            <label for="orders-marketplace">Marketplace</label>
            <select id="orders-marketplace" wire:model.live="marketplace">
                <option value="">Wszystkie</option>
                <option value="allegro">Allegro</option>
                <option value="ebay">eBay</option>
                <option value="ovoko">Ovoko</option>
                <option value="sklep">Sklep</option>
            </select>
        </div>

        <div class="gps-orders-field">
            <label for="orders-status">Status</label>
            <select id="orders-status" wire:model.live="status">
                <option value="">Wszystkie</option>
                @foreach (Order::statusOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="gps-orders-field">
            <label for="orders-test">TEST IMPORT</label>
            <select id="orders-test" wire:model.live="testImport">
                <option value="">Wszystkie</option>
                <option value="1">Tylko TEST</option>
                <option value="0">Bez TEST</option>
            </select>
        </div>

        <div class="gps-orders-field">
            <label for="orders-batch">Batch</label>
            <select id="orders-batch" wire:model.live="sourceBatch">
                <option value="">Wszystkie</option>
                @foreach ($this->sourceBatchOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="gps-orders-field">
            <label for="orders-sort">Sortowanie</label>
            <select id="orders-sort" wire:model.live="sortDirection">
                <option value="desc">Sprzedaż: najnowsze</option>
                <option value="asc">Sprzedaż: najstarsze</option>
            </select>
        </div>

        <div class="gps-orders-filter-summary">Filtry: {{ $this->activeFiltersCount }}</div>
        <button class="gps-orders-reset-button" type="button" wire:click="resetFilters">Wyczyść filtry</button>
    </div>

    <div class="gps-orders-list">
        <div class="gps-orders-list-header gps-admin-orders-grid">
            <div>Sprzedana część</div>
            <div>Numer zamówienia</div>
            <div>Status</div>
            <div>Klient</div>
            <div>Kwota</div>
            <div>Kurier</div>
        </div>

        @forelse ($orders as $order)
            @php
                $displayNumber = OrderResource::displayOrderNumber($order);
                $marketplace = $order->marketplace ?: 'Sklep';
                $statusLabel = Order::statusOptions()[$order->status] ?? ($order->status ?: '—');
                $marketplaceStatus = $order->marketplace_status ?: null;
                $buyerName = $order->customer_name ?: $order->company_name ?: $order->email ?: '—';
                $phone = $order->phone ?: '—';
                $total = OrderResource::formatOrderTotal($order);
                $orderedAt = $order->ordered_at ? $order->ordered_at->format('Y-m-d H:i') : '—';
                $firstItem = $order->items->first();
                $itemsCount = $order->items->count();
                $thumbnailDebug = \App\Support\OrderItemThumbnailDiagnostics::resolve($order, $firstItem);
                $firstItemName = $thumbnailDebug['display_name'];
                $storageLocation = $thumbnailDebug['storage_location'];
                $partImageUrl = $thumbnailDebug['thumbnail_url'];
                $thumbnailSource = $thumbnailDebug['thumbnail_source'];
                $thumbnailPart = $thumbnailDebug['thumbnail_part'] ?? null;
                $thumbnailDebugAttribute = \App\Support\OrderItemThumbnailDiagnostics::attribute($thumbnailDebug);
                $shipment = $order->shipments->first();
                $carrier = $shipment?->carrier ?: $order->delivery_method;
                $trackingNumber = $shipment?->tracking_number;
                $shipmentStatus = $shipment?->shipment_status;
            @endphp

            <div class="gps-order-card">
                <div class="gps-admin-orders-grid">
                    <div class="gps-order-col gps-order-col-item">
                        <div class="gps-order-sold-part" @if (config('app.debug')) data-thumbnail-debug="{!! $thumbnailDebugAttribute !!}" @endif>
                            @if ($thumbnailPart instanceof \App\Models\Part && $thumbnailSource === 'admin_parts_thumbnail')
                                @include('filament.resources.parts.table-image', ['part' => $thumbnailPart])
                            @elseif ($partImageUrl)
                                <img class="gps-order-part-thumb" src="{{ $partImageUrl }}" alt="{{ $firstItemName }}">
                            @else
                                <div class="gps-order-part-placeholder" aria-hidden="true" @if (config('app.debug')) title="{!! $thumbnailDebugAttribute !!}" @endif>
                                    <x-heroicon-o-photo class="h-7 w-7" />
                                </div>
                            @endif

                            <div class="gps-order-part-info">
                                <div class="gps-order-value">{{ $firstItemName }}</div>
                                <div class="gps-order-muted">Magazyn: {{ $storageLocation }}</div>
                                @if ($itemsCount > 1)
                                    <div class="gps-order-muted">+ {{ $itemsCount - 1 }} więcej</div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="gps-order-col gps-order-col-number">
                        <div class="gps-order-value gps-order-number">{{ $displayNumber }}</div>
                        <div class="gps-order-muted">{{ $orderedAt }}</div>
                        <div class="gps-order-source-row">Źródło: @include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $marketplace])</div>
                    </div>

                    <div class="gps-order-col gps-order-col-status">
                        <div class="gps-order-value">{{ $statusLabel }}</div>
                        @if ($marketplaceStatus)
                            <div class="gps-order-badges"><span class="gps-order-badge gps-order-badge--status">{{ $marketplaceStatus }}</span></div>
                        @endif
                    </div>

                    <div class="gps-order-col gps-order-col-buyer">
                        <div class="gps-order-value">{{ $buyerName }}</div>
                        <div class="gps-order-muted">{{ $phone }}</div>
                    </div>

                    <div class="gps-order-col gps-order-col-amount">
                        <div class="gps-order-total">{{ $total }}</div>
                    </div>

                    <div class="gps-order-col gps-order-col-shipping">
                        @if ($shipment || $carrier)
                            <div class="gps-order-value">{{ $carrier ?: '—' }}</div>
                            @if ($trackingNumber)
                                <div class="gps-order-muted">{{ $trackingNumber }}</div>
                            @endif
                            @if ($shipmentStatus)
                                <div class="gps-order-badges"><span class="gps-order-badge">{{ $shipmentStatus }}</span></div>
                            @endif
                        @else
                            <div class="gps-order-muted">Brak przesyłki</div>
                        @endif

                        <div class="gps-order-actions">
                            <a class="gps-order-action" href="{{ OrderResource::getUrl('view', ['record' => $order]) }}">Szczegóły</a>
                            <a class="gps-order-action" href="{{ OrderResource::getUrl('edit', ['record' => $order]) }}">Zmień status</a>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="gps-order-empty">Brak zamówień pasujących do wybranych kryteriów.</div>
        @endforelse
    </div>

    <div class="gps-orders-pagination">
        {{ $orders->links() }}
    </div>
</x-filament-panels::page>
