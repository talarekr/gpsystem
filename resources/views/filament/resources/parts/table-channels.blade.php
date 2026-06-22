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

        .gps-admin-channels .part-channel-price {
            flex: 0 1 auto;
            min-width: 0;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: clip;
        }


        .gps-admin-channels .part-channel-status,
        .gps-admin-channels .part-channel-status-link {
            flex: 0 0 auto;
            line-height: 1;
            padding: 0;
            margin: 0;
            font-size: 12px;
            font-weight: 500;
            text-decoration: none;
            white-space: nowrap;
        }

        .gps-admin-channels .part-channel-status-link:hover {
            text-decoration: underline;
        }

        .gps-admin-channels .part-channel-status--ok {
            color: #16a34a;
        }

        .gps-admin-channels .part-channel-status--missing {
            color: #dc2626;
        }

    </style>
@endonce

<div class="gps-admin-part-cell gps-admin-channels part-channel-list">
    @if (! $part)
        <div class="part-channel-row">
            <span class="part-channel-label">—</span>
            <span class="part-channel-price">—</span>
            <span class="part-channel-status part-channel-status--missing" title="Brak rekordu">✕</span>
        </div>
    @else
        @foreach ($rows as $row)
            <div class="part-channel-row">
                <span class="part-channel-label">{{ $row['label'] }}:</span>
                <span class="part-channel-price">{{ $row['price'] }}</span>
                @if ($row['listed'] && $row['url'])
                    <a
                        class="part-channel-status part-channel-status-link part-channel-status--ok"
                        href="{{ $row['url'] }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        title="{{ $row['title'] }}"
                        aria-label="{{ $row['title'] }}"
                    >✓</a>
                @else
                    <span
                        class="part-channel-status {{ $row['listed'] ? 'part-channel-status--ok' : 'part-channel-status--missing' }}"
                        title="{{ $row['title'] }}"
                        aria-label="{{ $row['title'] }}"
                    >{{ $row['listed'] ? '✓' : '✕' }}</span>
                @endif
            </div>
        @endforeach
    @endif
</div>
