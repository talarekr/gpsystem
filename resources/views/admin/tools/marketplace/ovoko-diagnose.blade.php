<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ovoko diagnose</title>
    <style>
        body{font-family:Inter,ui-sans-serif,system-ui,Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:24px}.wrap{max-width:1280px;margin:auto}.card{background:white;border:1px solid #e2e8f0;border-radius:14px;padding:18px;margin:0 0 16px;box-shadow:0 1px 2px #0000000d}.muted{color:#64748b}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}textarea{width:100%;min-height:90px;border:1px solid #cbd5e1;border-radius:10px;padding:10px;font:inherit}.btn{border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;background:#2563eb;color:white}.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}table{width:100%;border-collapse:collapse;margin-top:10px}td,th{border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top;padding:8px;font-size:13px}.badge{display:inline-block;border-radius:999px;padding:3px 9px;background:#e2e8f0;font-weight:700}.ok{background:#dcfce7}.bad{background:#fee2e2}.warn{background:#fef3c7}pre{white-space:pre-wrap;word-break:break-word;background:#0f172a;color:#e2e8f0;border-radius:12px;padding:12px;max-height:520px;overflow:auto}.listing{margin-bottom:12px;padding-bottom:12px;border-bottom:1px dashed #cbd5e1}.listing:last-child{border-bottom:0;margin-bottom:0;padding-bottom:0}
    </style>
</head>
<body>
<div class="wrap">
    <h1>Ovoko diagnose</h1>
    <div class="card">
        <p><b>Read-only:</b> narzędzie nie zmienia linków, nie uruchamia relistingu i nie zapisuje zmian na marketplace.</p>
        <form method="get" action="{{ route('admin.tools.marketplace.ovoko-diagnose') }}">
            <label for="part_id"><b>part_id</b> — wklej ID po przecinku, spacji albo w nowych liniach</label>
            <textarea id="part_id" name="part_id" placeholder="7190, 7841, 7195, 7196">{{ old('part_id', $input) }}</textarea>
            <div class="row" style="margin-top:10px">
                <button class="btn" type="submit">Sprawdź</button>
                @if($part_ids)
                    <a href="{{ route('admin.tools.marketplace.ovoko-diagnose', ['part_id' => implode(',', $part_ids), 'format' => 'json']) }}" target="_blank" rel="noopener">Pokaż jako JSON</a>
                @endif
            </div>
        </form>
    </div>

    @if($part_ids === [])
        <div class="card muted">Wpisz part_id i kliknij „Sprawdź”.</div>
    @else
        <div class="card">
            <h2>Wynik</h2>
            <table>
                <thead>
                <tr>
                    <th>part</th>
                    <th>resolver Ovoko</th>
                    <th>marketplace_listings marketplace=ovoko</th>
                </tr>
                </thead>
                <tbody>
                @foreach($results as $result)
                    <tr>
                        <td>
                            <div><b>part.id:</b> <span class="mono">{{ $result['part_id'] }}</span></div>
                            @if(! $result['found'])
                                <span class="badge bad">not found</span>
                            @else
                                <div><b>part.status:</b> <span class="mono">{{ $result['part']['status'] }}</span></div>
                                <div><b>part.quantity:</b> <span class="mono">{{ $result['part']['quantity'] }}</span></div>
                                <div><b>adminLocalAvailability():</b> <span class="mono">{{ $result['part']['admin_local_availability'] }}</span></div>
                                <div><b>needs_listing:</b> <span class="mono">{{ $result['part']['needs_listing'] ? 'true' : 'false' }}</span></div>
                            @endif
                        </td>
                        <td>
                            @php($resolver = $result['resolver'])
                            <div><b>has_link:</b> <span class="mono">{{ $resolver['has_link'] ? 'true' : 'false' }}</span></div>
                            <div><b>url:</b> <span class="mono">{{ $resolver['url'] ?? 'null' }}</span></div>
                            <div><b>is_active:</b> <span class="badge {{ $resolver['is_active'] ? 'ok' : 'bad' }}">{{ $resolver['is_active'] ? 'true' : 'false' }}</span></div>
                            <div><b>icon:</b> <span class="mono">{{ $resolver['icon'] ?? 'null' }}</span></div>
                            <div><b>display_icon:</b> <span class="mono">{{ $resolver['display_icon'] ?? 'null' }}</span></div>
                            <div><b>reason:</b> <span class="mono">{{ $resolver['reason'] ?? 'null' }}</span></div>
                            <div><b>title:</b> <span class="mono">{{ $resolver['title'] ?? 'null' }}</span></div>
                        </td>
                        <td>
                            @forelse($result['marketplace_listings'] as $listing)
                                <div class="listing">
                                    <div><b>id:</b> <span class="mono">{{ $listing['id'] }}</span></div>
                                    <div><b>marketplace:</b> <span class="mono">{{ $listing['marketplace'] }}</span></div>
                                    <div><b>status:</b> <span class="mono">{{ $listing['status'] ?? 'null' }}</span></div>
                                    <div><b>sync_status:</b> <span class="mono">{{ $listing['sync_status'] ?? 'null' }}</span></div>
                                    <div><b>match_status:</b> <span class="mono">{{ $listing['match_status'] ?? 'null' }}</span></div>
                                    <div><b>last_api_status:</b> <span class="mono">{{ $listing['last_api_status'] ?? 'null' }}</span></div>
                                    <div><b>last_error:</b> <span class="mono">{{ $listing['last_error'] ?? 'null' }}</span></div>
                                    <div><b>external_offer_id:</b> <span class="mono">{{ $listing['external_offer_id'] ?? 'null' }}</span></div>
                                    <div><b>external_listing_id:</b> <span class="mono">{{ $listing['external_listing_id'] ?? 'null' }}</span></div>
                                    <div><b>url:</b> <span class="mono">{{ $listing['url'] ?? 'null' }}</span></div>
                                    <div><b>resolved listingUrl():</b> <span class="mono">{{ $listing['resolved_listing_url'] ?? 'null' }}</span></div>
                                    <div><b>resolved externalOfferId():</b> <span class="mono">{{ $listing['resolved_external_offer_id'] ?? 'null' }}</span></div>
                                </div>
                            @empty
                                <span class="badge warn">brak listingów Ovoko</span>
                            @endforelse
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="card">
            <h2>JSON</h2>
            <pre>{{ json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    @endif
</div>
</body>
</html>
