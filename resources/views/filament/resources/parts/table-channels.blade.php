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
        .gps-admin-channels.part-channel-list {
            display: flex;
            flex-direction: column;
            gap: 2px;
            width: 260px;
            min-width: 260px;
            max-width: 260px;
            overflow: visible;
            color: #334155;
        }

        .gps-admin-channels .part-channel-row {
            display: grid;
            grid-template-columns: 62px 100px 28px 18px 18px;
            column-gap: 4px;
            align-items: center;
            width: 230px;
            max-width: none;
            font-size: 12px;
            line-height: 1.35;
            white-space: nowrap;
        }

        .gps-admin-channels .part-channel-label {
            overflow: visible;
            color: #475569;
            font-weight: 400;
            text-overflow: clip;
            white-space: nowrap;
        }

        .gps-admin-channels .part-channel-price {
            overflow: visible;
            color: #1e293b;
            text-overflow: clip;
            white-space: nowrap;
        }

        .gps-admin-channels .part-channel-note {
            overflow: visible;
            color: #94a3b8;
            font-size: 11px;
            font-weight: 400;
            text-transform: lowercase;
            white-space: nowrap;
        }

        .gps-admin-channels .part-channel-status,
        .gps-admin-channels .part-channel-link,
        .gps-admin-channels .part-channel-link-placeholder {
            display: inline-block;
            width: 18px;
            overflow: visible;
            text-align: center;
            white-space: nowrap;
        }

        .gps-admin-channels .part-channel-status {
            font-size: 12px;
            font-weight: 500;
        }

        .gps-admin-channels .part-channel-status--ok {
            color: #16a34a;
        }

        .gps-admin-channels .part-channel-status--missing {
            color: #dc2626;
        }

        .gps-admin-channels .part-channel-link {
            color: #2563eb;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            text-decoration: none;
        }

        .gps-admin-channels .part-channel-link:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }

        .gps-admin-channels .part-channel-status-link {
            display: inline-flex;
            gap: 3px;
            align-items: center;
            white-space: nowrap;
        }
    </style>
@endonce

<div class="gps-admin-part-cell gps-admin-channels part-channel-list">
    @if (! $part)
        <div class="part-channel-row">
            <span class="part-channel-label">—</span>
            <span class="part-channel-price">—</span>
            <span class="part-channel-note"></span>
            <span class="part-channel-status part-channel-status--missing" title="Brak rekordu">✕</span>
            <span class="part-channel-link-placeholder" aria-hidden="true"></span>
        </div>
    @else
        @foreach ($rows as $row)
            <div class="part-channel-row">
                <span class="part-channel-label">{{ $row['label'] }}:</span>
                <span class="part-channel-price">{{ $row['price'] }}</span>
                <span class="part-channel-note">{{ $row['note'] ?: '' }}</span>
                <span
                    class="part-channel-status {{ $row['listed'] ? 'part-channel-status--ok' : 'part-channel-status--missing' }}"
                    title="{{ $row['title'] }}"
                    aria-label="{{ $row['title'] }}"
                >{{ $row['listed'] ? '✓' : '✕' }}</span>
                @if ($row['url'])
                    <a
                        class="part-channel-link"
                        href="{{ $row['url'] }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        title="Otwórz ofertę {{ $row['label'] }} w nowej karcie"
                        aria-label="Otwórz ofertę {{ $row['label'] }} w nowej karcie"
                    >↗</a>
                @else
                    <span class="part-channel-link-placeholder" aria-hidden="true"></span>
                @endif
            </div>
        @endforeach
    @endif
</div>
