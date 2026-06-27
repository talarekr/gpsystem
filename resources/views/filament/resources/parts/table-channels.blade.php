@php
    $part = (isset($part) && $part instanceof \App\Models\Part) ? $part : null;

    if (! $part && isset($getRecord) && is_callable($getRecord)) {
        $candidate = $getRecord();
        $part = $candidate instanceof \App\Models\Part ? $candidate : null;
    } elseif (isset($record) && $record instanceof \App\Models\Part) {
        $part = $record;
    }

    $rows = $part instanceof \App\Models\Part
        ? app(\App\Services\Admin\PartMarketplaceStatusResolver::class)->rowsForPart($part)
        : [];
@endphp

@once
    <style>
        .gps-admin-channels,
        .gps-admin-channels.part-channel-list {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 2px;
            width: 260px;
            min-width: 260px;
            max-width: 260px;
            overflow: hidden;
            color: #334155;
        }

        .gps-admin-channels .part-channel-row {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 4px;
            width: 100%;
            max-width: 100%;
            white-space: nowrap;
            overflow: hidden;
            font-size: 12px;
            line-height: 1.35;
        }

        .gps-admin-channels .part-channel-label {
            flex: 0 0 auto;
            color: #475569;
            font-weight: 400;
            white-space: nowrap;
        }

        .gps-admin-channels .part-channel-label.is-storefront-label {
            color: #000;
        }

        .gps-admin-channels .part-channel-price {
            flex: 0 1 auto;
            min-width: 0;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: clip;
        }


        .gps-admin-channels .part-channel-status {
            display: inline;
            flex: 0 0 auto;
            width: auto;
            min-width: 0;
            height: auto;
            padding: 0;
            margin: 0;
            border: 0;
            background: transparent;
            line-height: inherit;
            font: inherit;
            text-decoration: none;
            white-space: nowrap;
            vertical-align: baseline;
        }

        .gps-admin-channels .part-channel-status.is-listed {
            color: #16a34a;
        }

        .gps-admin-channels .part-channel-status.is-not-listed {
            color: #dc2626;
        }

    </style>
@endonce

<div class="gps-admin-part-cell gps-admin-channels part-channel-list">
    @if (! $part)
        <div class="part-channel-row">
            <span class="part-channel-label">—</span>
            <span class="part-channel-price">—</span>
            <span class="part-channel-status is-not-listed" title="Brak rekordu">✕</span>
        </div>
    @else
        @foreach ($rows as $row)
            <div class="part-channel-row">
                <span class="part-channel-label {{ ($row['key'] ?? '') === 'storefront' ? 'is-storefront-label' : '' }}">
                    @if (in_array($row['key'] ?? '', ['allegro', 'ovoko', 'ebay'], true))
                        @include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $row['key']])
                    @else
                        {{ $row['label'] }}
                    @endif
                    :
                </span>
                <span class="part-channel-price">{{ $row['price'] }}</span>
                <span
                    class="part-channel-status {{ $row['listed'] ? 'is-listed' : 'is-not-listed' }}"
                    title="{{ $row['title'] }}"
                    aria-label="{{ $row['title'] }}"
                >{{ $row['listed'] ? '✓' : '✕' }}</span>
            </div>
        @endforeach
    @endif
</div>
