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
        'OEM' => $part->oem_number,
        'Kod' => $part->manufacturer_code,
    ])->filter(fn ($value) => filled($value)) : collect();
@endphp
<div class="gps-admin-chip-wrap">
    @if (! $part)
        <span class="gps-admin-chip">—</span>
    @else
        @forelse ($numbers as $label => $number)
            <span class="gps-admin-chip {{ $loop->first ? 'gps-admin-chip--main' : '' }}">{{ $label }}: {{ $number }}</span>
        @empty
            <span class="gps-admin-chip">Brak numeru</span>
        @endforelse
    @endif
</div>
