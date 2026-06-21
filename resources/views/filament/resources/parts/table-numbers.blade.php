@php
    $part = (isset($part) && $part instanceof \App\Models\Part) ? $part : null;

    if (! $part && isset($getRecord) && is_callable($getRecord)) {
        $candidate = $getRecord();
        $part = $candidate instanceof \App\Models\Part ? $candidate : null;
    } elseif (isset($record) && $record instanceof \App\Models\Part) {
        $part = $record;
    }

    $numbers = $part ? collect([
        'Numer' => $part->part_number,
        'Kod' => $part->manufacturer_code,
        'OEM' => $part->oem_number,
    ])->filter(fn ($value) => filled($value))->take(2) : collect();
@endphp
<div class="gps-admin-part-numbers">
    @if (! $part)
        <span class="gps-admin-part-number gps-admin-part-number--empty">—</span>
    @else
        @forelse ($numbers as $label => $number)
            <span class="gps-admin-part-number">
                <span class="gps-admin-part-number__label">{{ $label }}:</span>
                <span class="gps-admin-part-number__value">{{ $number }}</span>
            </span>
        @empty
            <span class="gps-admin-part-number gps-admin-part-number--empty">Brak numeru</span>
        @endforelse
    @endif
</div>
