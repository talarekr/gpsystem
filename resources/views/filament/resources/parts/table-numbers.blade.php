@php
    $part = (isset($part) && $part instanceof \App\Models\Part) ? $part : null;

    if (! $part && isset($getRecord) && is_callable($getRecord)) {
        $candidate = $getRecord();
        $part = $candidate instanceof \App\Models\Part ? $candidate : null;
    } elseif (isset($record) && $record instanceof \App\Models\Part) {
        $part = $record;
    }

    $number = $part ? trim((string) $part->part_number) : '';
@endphp
<div class="gps-admin-part-cell gps-admin-part-numbers">
    @if (! $part)
        <span class="gps-admin-part-number gps-admin-part-number--empty">—</span>
    @else
        @if (filled($number))
            <span class="gps-admin-part-number">
                <span class="gps-admin-part-number__value">{{ $number }}</span>
            </span>
            <button
                type="button"
                class="gps-admin-part-number__copy"
                title="Kopiuj numer części"
                aria-label="Kopiuj numer części {{ $number }}"
                onclick="navigator.clipboard?.writeText(@js($number))"
            >
                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M7 3.5A2.5 2.5 0 0 1 9.5 1h6A2.5 2.5 0 0 1 18 3.5v6a2.5 2.5 0 0 1-2.5 2.5h-6A2.5 2.5 0 0 1 7 9.5v-6Z" />
                    <path d="M4.5 6A2.5 2.5 0 0 0 2 8.5v8A2.5 2.5 0 0 0 4.5 19h8a2.5 2.5 0 0 0 2.5-2.5V14h-2v2.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-8a.5.5 0 0 1 .5-.5H7V6H4.5Z" />
                </svg>
            </button>
        @else
            <span class="gps-admin-part-number gps-admin-part-number--empty">—</span>
        @endif
    @endif
</div>
