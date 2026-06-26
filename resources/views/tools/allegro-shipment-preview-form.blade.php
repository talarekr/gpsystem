@php
    $audit = $result['audit'] ?? [];
    $receiver = $audit['receiver'] ?? [];
    $sender = $audit['sender'] ?? [];
    $delivery = $audit['delivery_method'] ?? [];
    $cod = $audit['cash_on_delivery'] ?? [];
    $options = $audit['parcel_size_options'] ?? ['mode' => 'manual', 'options' => []];
    $selectedSize = request('size_code');
    $weight = old('weight', $input['weight'] ?? 2);
    $labelReference = old('label_reference', $input['label_reference'] ?? '');
@endphp
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dodaj przesyłkę Allegro — podgląd</title>
    <style>
        body{margin:0;background:#f8fafc;color:#0f172a;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1180px;margin:32px auto;padding:0 18px}.card{background:#fff;border:1px solid rgba(148,163,184,.28);border-radius:22px;box-shadow:0 14px 36px rgba(15,23,42,.06);padding:22px;margin-bottom:18px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.fact{background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:14px}.label{color:#64748b;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}.value{font-size:14px;font-weight:700;margin-top:5px}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}label span{display:block;color:#334155;font-size:13px;font-weight:800;margin-bottom:6px}input,select{width:100%;border:1px solid #cbd5e1;border-radius:12px;padding:10px 12px;font-size:14px}.btn{background:#0f172a;color:#fff;border:0;border-radius:12px;padding:11px 16px;font-weight:900;cursor:pointer}.muted{color:#64748b;font-size:13px;line-height:1.45}.safe{background:#ecfdf5;border-color:#bbf7d0;color:#166534}.warn{background:#fffbeb;border-color:#fde68a;color:#92400e}.pre{overflow:auto;background:#020617;color:#e2e8f0;border-radius:16px;padding:16px;font-size:12px;line-height:1.5}.full{grid-column:1/-1}@media(max-width:800px){.grid,.form-grid{grid-template-columns:1fr}}
    </style>
</head>
<body><main class="wrap">
    <h1>Dodaj przesyłkę Allegro — formularz dry-run</h1>
    <p class="muted">Formularz uzupełnia dane paczki i buduje preview payloadu. Nie wykonuje żadnych zapisów ani requestów tworzących przesyłkę.</p>

    <section class="card"><h2>Dane z preview (read-only)</h2><div class="grid">
        <div class="fact"><div class="label">Odbiorca</div><div class="value">{{ $receiver['name'] ?? '—' }}</div></div>
        <div class="fact"><div class="label">Adres</div><div class="value">{{ trim(($receiver['street'] ?? '').', '.($receiver['postalCode'] ?? '').' '.($receiver['city'] ?? '').' '.($receiver['countryCode'] ?? ''), ', ') ?: '—' }}</div></div>
        <div class="fact"><div class="label">Telefon</div><div class="value">{{ $receiver['phone'] ?? '—' }}</div></div>
        <div class="fact"><div class="label">E-mail</div><div class="value">{{ $receiver['email'] ?? '—' }}</div></div>
        <div class="fact"><div class="label">Metoda dostawy</div><div class="value">{{ $delivery['name'] ?? '—' }}</div></div>
        <div class="fact"><div class="label">Pobranie / kwota</div><div class="value">{{ ($cod['is_cod'] ?? false) ? (($cod['amount'] ?? '—').' '.($cod['currency'] ?? 'PLN')) : 'Nie' }}</div></div>
        <div class="fact"><div class="label">Nadawca</div><div class="value">{{ $sender['name'] ?? '—' }} — {{ $sender['street'] ?? '—' }}</div></div>
        <div class="fact"><div class="label">Numer referencyjny</div><div class="value">{{ data_get($result, 'payload_preview.body.input.referenceNumber', '—') }}</div></div>
    </div></section>

    <section class="card"><h2>Dane paczki</h2><form method="get"><input type="hidden" name="order_id" value="{{ $order?->id }}"><div class="form-grid">
        <label><span>Waga paczki (kg)</span><input type="number" step="0.01" min="0" name="weight" value="{{ $weight }}"></label>
        @if (($options['mode'] ?? null) === 'size_code')
            <label><span>Gabaryt / rozmiar paczki</span><select name="size_code"><option value="">Wybierz gabaryt</option>@foreach ($options['options'] as $option)<option value="{{ $option['code'] }}" @selected($selectedSize === $option['code'])>{{ $option['code'] }} — {{ $option['length'] }} × {{ $option['width'] }} × {{ $option['height'] }} cm</option>@endforeach</select></label>
        @else
            <label><span>Długość cm</span><input type="number" step="1" min="0" name="length" value="{{ request('length') }}"></label><label><span>Szerokość cm</span><input type="number" step="1" min="0" name="width" value="{{ request('width') }}"></label><label><span>Wysokość cm</span><input type="number" step="1" min="0" name="height" value="{{ request('height') }}"></label>
        @endif
        <label class="full"><span>Dodatkowa informacja na etykiecie (max 20 znaków)</span><input maxlength="20" name="label_reference" value="{{ $labelReference }}"></label>
    </div><p><button class="btn" type="submit">Podgląd payloadu / Sprawdź dane</button></p></form>
    @foreach (($result['warnings'] ?? []) as $warning)<div class="fact warn">{{ $warning }}</div>@endforeach
    <div class="fact safe">Tryb podglądu — nie tworzy przesyłki. Safety flags: Allegro write=false, marketplace write=false, shipment_created=false, label_created=false, pickup_ordered=false.</div></section>

    <section class="card"><h2>Payload preview</h2><pre class="pre">{{ json_encode($result['payload_preview'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></section>
</main></body></html>
