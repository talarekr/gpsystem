@php
    $numbers = collect([
        'Numer' => $record->part_number,
        'OEM' => $record->oem_number,
        'Kod' => $record->manufacturer_code,
    ])->filter(fn ($value) => filled($value));
@endphp
<div class="gps-admin-chip-wrap">
    @forelse ($numbers as $label => $number)
        <span class="gps-admin-chip {{ $loop->first ? 'gps-admin-chip--main' : '' }}">{{ $label }}: {{ $number }}</span>
    @empty
        <span class="gps-admin-chip">Brak numeru</span>
    @endforelse
</div>
