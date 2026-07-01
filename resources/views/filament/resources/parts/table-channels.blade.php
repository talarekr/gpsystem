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
            display: grid;
            grid-template-columns: 58px 76px 14px 18px 18px;
            align-items: center;
            justify-content: flex-start;
            column-gap: 5px;
            width: 100%;
            max-width: 100%;
            position: relative;
            white-space: nowrap;
            overflow: hidden;
            font-size: 12px;
            line-height: 1.35;
        }

        .gps-admin-channels .part-channel-label {
            min-width: 58px;
            color: #475569;
            font-weight: 400;
            white-space: nowrap;
        }

        .gps-admin-channels .part-channel-label.is-storefront-label {
            color: #000;
        }

        .gps-admin-channels .part-channel-price {
            width: 76px;
            min-width: 0;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: clip;
        }

        .gps-admin-channels .part-channel-status {
            display: inline;
            width: 14px;
            min-width: 14px;
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

        .gps-admin-channels .part-channel-link-slot {
            display: inline-flex;
            width: 18px;
            min-width: 18px;
            height: 14px;
            align-items: center;
            justify-content: center;
        }

        .gps-admin-channels .part-channel-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 14px;
            height: 14px;
            color: #2563eb;
            line-height: 1;
            text-decoration: none;
            vertical-align: middle;
        }

        .gps-admin-channels .part-channel-link:hover,
        .gps-admin-channels .part-channel-link:focus-visible {
            color: #1d4ed8;
            text-decoration: none;
        }

        .gps-admin-channels .part-channel-link svg {
            display: block;
            width: 12px;
            height: 12px;
            stroke-width: 2;
        }

        .gps-admin-channels .part-channel-map-slot {
            position: relative;
            display: inline-flex;
            width: 18px;
            min-width: 18px;
            height: 16px;
            align-items: center;
            justify-content: center;
        }

        .gps-admin-channels .part-channel-map__trigger {
            display: inline-flex;
            width: 15px;
            height: 15px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #64748b;
            border: 1px solid #cbd5e1;
            border-radius: 999px;
            background: #fff;
            font-size: 11px;
            line-height: 1;
            padding: 0;
        }

        .gps-admin-channels .part-channel-map__trigger:hover,
        .gps-admin-channels .part-channel-map__trigger:focus-visible {
            color: #2563eb;
            border-color: #93c5fd;
            outline: none;
        }

        .gps-admin-channels .part-channel-map__popover {
            position: absolute;
            z-index: 20;
            top: 18px;
            right: 0;
            display: flex;
            flex-direction: column;
            gap: 6px;
            width: 260px;
            padding: 8px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 10px 25px rgba(15, 23, 42, .16);
        }

        .gps-admin-channels .part-channel-map__popover input {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 5px 7px;
            font-size: 12px;
        }

        .gps-admin-channels [x-cloak] { display: none !important; }

        .gps-admin-channels .part-channel-map__error {
            color: #dc2626;
            font-size: 11px;
            line-height: 1.2;
        }

        .gps-admin-channels .part-channel-map__popover button {
            align-self: flex-end;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 4px 8px;
            background: #f8fafc;
            color: #334155;
            font-size: 12px;
        }

    </style>
@endonce

<div class="gps-admin-part-cell gps-admin-channels part-channel-list">
    @if (! $part)
        <div class="part-channel-row">
            <span class="part-channel-label">—</span>
            <span class="part-channel-price">—</span>
            <span class="part-channel-status is-not-listed" title="Brak rekordu">✕</span>
            <span class="part-channel-link-slot" aria-hidden="true"></span>
            <span class="part-channel-map-slot" aria-hidden="true"></span>
        </div>
    @else
        @foreach ($rows as $row)
            <div class="part-channel-row">
                <span class="part-channel-label {{ ($row['key'] ?? '') === 'storefront' ? 'is-storefront-label' : '' }}">@if (in_array($row['key'] ?? '', ['allegro', 'ovoko', 'ebay'], true))@include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $row['key']])@else{{ $row['label'] }}@endif:</span>
                <span class="part-channel-price">{{ $row['price'] }}</span>
                <span
                    class="part-channel-status {{ $row['listed'] ? 'is-listed' : 'is-not-listed' }}"
                    title="{{ $row['title'] }}"
                    aria-label="{{ $row['title'] }}"
                >{{ $row['listed'] ? '✓' : '✕' }}</span>
                <span class="part-channel-link-slot">
                    @if ($row['listed'] && filled($row['url'] ?? null))
                        <a
                            class="part-channel-link"
                            href="{{ $row['url'] }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            title="Otwórz aukcję {{ $row['label'] }}"
                            aria-label="Otwórz aukcję {{ $row['label'] }}"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true" focusable="false">
                                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                            </svg>
                        </a>
                    @endif
                </span>
                <span
                    class="part-channel-map-slot"
                    @if (in_array($row['key'] ?? '', ['allegro', 'ovoko'], true))
                        x-data="{ open: false, error: '' }"
                        @keydown.escape.window="open = false"
                    @endif
                >
                    @if (in_array($row['key'] ?? '', ['allegro', 'ovoko'], true))
                        <button
                            id="manual-link-trigger-{{ $part->id }}-{{ $row['key'] }}"
                            type="button"
                            class="part-channel-map__trigger"
                            title="Dodaj ręczne mapowanie {{ $row['label'] }}"
                            aria-label="Dodaj ręczne mapowanie {{ $row['label'] }}"
                            :aria-expanded="open.toString()"
                            @click.prevent.stop="open = ! open; error = ''; $nextTick(() => open && $refs.url?.focus())"
                        >+</button>
                        <form
                            id="manual-link-popover-{{ $part->id }}-{{ $row['key'] }}"
                            class="part-channel-map__popover"
                            x-cloak
                            x-show="open"
                            @click.stop
                            wire:submit.prevent="saveMarketplaceLinkMapping({{ $part->id }}, '{{ $row['key'] }}', $event.target.marketplace_url.value)"
                        >
                            <input
                                id="manual-link-url-{{ $part->id }}-{{ $row['key'] }}"
                                x-ref="url"
                                name="marketplace_url"
                                type="url"
                                placeholder="Wklej link aukcji {{ $row['label'] }}"
                                required
                            >
                            <div class="part-channel-map__error" x-text="error" x-show="error"></div>
                            <button type="submit">Zapisz</button>
                        </form>
                    @endif
                </span>
            </div>
        @endforeach
    @endif
</div>
