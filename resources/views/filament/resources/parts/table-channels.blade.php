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

<div class="gps-admin-part-cell gps-admin-channels part-channel-list">
    @if (! $part)
        <div class="gps-admin-channel">
            <span class="gps-admin-channel__name">—</span>
            <span class="gps-admin-channel__price">—</span>
            <span class="gps-admin-channel__state gps-admin-channel__state--missing" title="Brak rekordu">✕</span>
            <span class="gps-admin-channel__link-placeholder" aria-hidden="true"></span>
        </div>
    @else
        @foreach ($rows as $row)
            <div class="gps-admin-channel">
                <span class="gps-admin-channel__name">{{ $row['label'] }}:</span>
                <span class="gps-admin-channel__price">
                    {{ $row['price'] }}
                    @if ($row['note'])
                        <span class="gps-admin-channel__calc">{{ $row['note'] }}</span>
                    @endif
                </span>
                <span
                    class="gps-admin-channel__state {{ $row['listed'] ? 'gps-admin-channel__state--ok' : 'gps-admin-channel__state--missing' }}"
                    title="{{ $row['title'] }}"
                    aria-label="{{ $row['title'] }}"
                >{{ $row['listed'] ? '✓' : '✕' }}</span>
                @if ($row['url'])
                    <a
                        class="gps-admin-channel__link"
                        href="{{ $row['url'] }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        title="Otwórz ofertę {{ $row['label'] }} w nowej karcie"
                        aria-label="Otwórz ofertę {{ $row['label'] }} w nowej karcie"
                    >↗</a>
                @else
                    <span class="gps-admin-channel__link-placeholder" aria-hidden="true"></span>
                @endif
            </div>
        @endforeach
    @endif
</div>
