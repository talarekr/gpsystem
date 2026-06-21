<div class="gps-admin-storage">
    <strong>{{ $record->storageLocation?->name ?: 'Brak lokalizacji' }}</strong>
    @if ($record->storageLocation?->description)
        <span>{{ $record->storageLocation->description }}</span><br>
    @endif
    <span class="gps-admin-chip">Ilość: {{ $record->quantity ?? 0 }}</span>
</div>
