@php
    $part = (isset($part) && $part instanceof \App\Models\Part) ? $part : null;

    if (! $part && isset($getRecord) && is_callable($getRecord)) {
        $candidate = $getRecord();
        $part = $candidate instanceof \App\Models\Part ? $candidate : null;
    } elseif (isset($record) && $record instanceof \App\Models\Part) {
        $part = $record;
    }

    $manualLinkRows = [
        'allegro' => 'Allegro',
        'ovoko' => 'Ovoko',
    ];
@endphp

@once
    <style>
        .gps-manual-marketplace-links{position:relative;display:flex;flex-direction:column;gap:2px;min-width:72px;max-width:92px;color:#64748b;font-size:12px;line-height:1.35}
        .gps-manual-marketplace-links__row{position:relative;display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:22px;white-space:nowrap}
        .gps-manual-marketplace-links__row details{display:inline-flex;align-items:center;line-height:1;vertical-align:middle}
        .gps-manual-marketplace-links__toggle{list-style:none}
        .gps-manual-marketplace-links__toggle::-webkit-details-marker{display:none}
        .gps-manual-marketplace-links__popover{position:absolute;z-index:40;top:22px;left:0;width:250px;padding:12px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;box-shadow:0 16px 32px rgba(15,23,42,.16)}
        .gps-manual-marketplace-links__popover label{display:block;margin-bottom:6px;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.03em}
        .gps-manual-marketplace-links__popover input{width:100%;border:1px solid #cbd5e1;border-radius:9px;padding:8px;color:#0f172a;font-size:13px}
        .gps-manual-marketplace-links__actions{display:flex;align-items:center;gap:8px;margin-top:8px}
        .gps-manual-marketplace-links__popover button{border:1px solid #bfdbfe;border-radius:999px;background:#fff;color:#1d4ed8;font-size:12px;font-weight:700;padding:6px 10px}
        .gps-manual-marketplace-links__message{min-height:14px;color:#dc2626;font-size:11px}
    </style>
@endonce

<div class="gps-manual-marketplace-links" aria-label="Ręczne mapowanie linków marketplace">
    @foreach ($manualLinkRows as $marketplace => $label)
        <div class="gps-manual-marketplace-links__row">
            <span>{{ $label }}</span>
            @if ($part)
                <details>
                    <summary class="gps-manual-marketplace-links__toggle gps-part-mini-plus" title="Dodaj link {{ $label }}" aria-label="Dodaj link {{ $label }}">+</summary>
                    <form class="gps-manual-marketplace-links__popover" wire:submit.prevent="saveManualMarketplaceLink({{ $part->id }}, '{{ $marketplace }}', $event.target.url.value)">
                        <label for="manual-{{ $marketplace }}-url-{{ $part->id }}">URL {{ $label }}</label>
                        <input id="manual-{{ $marketplace }}-url-{{ $part->id }}" name="url" type="url" placeholder="Wklej URL {{ $label }}" required>
                        <div class="gps-manual-marketplace-links__actions">
                            <button type="submit">Zapisz</button>
                            <span class="gps-manual-marketplace-links__message" aria-live="polite"></span>
                        </div>
                    </form>
                </details>
            @else
                <span aria-hidden="true">+</span>
            @endif
        </div>
    @endforeach
</div>
