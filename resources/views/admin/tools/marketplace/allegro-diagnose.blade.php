<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Allegro marketplace diagnose</title>
    <style>
        body{font-family:Inter,ui-sans-serif,system-ui,Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:24px}.wrap{max-width:1280px;margin:auto}.card{background:white;border:1px solid #e2e8f0;border-radius:14px;padding:18px;margin:0 0 16px;box-shadow:0 1px 2px #0000000d}.muted{color:#64748b}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}textarea,input[type=text]{width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:10px;font:inherit;box-sizing:border-box}textarea{min-height:80px}.btn{border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;background:#2563eb;color:white}.row{display:flex;gap:12px;flex-wrap:wrap;align-items:center}.field{margin-bottom:12px}table{width:100%;border-collapse:collapse;margin-top:10px}td,th{border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top;padding:8px;font-size:13px}.badge{display:inline-block;border-radius:999px;padding:3px 9px;background:#e2e8f0;font-weight:700}.ok{background:#dcfce7}.bad{background:#fee2e2}.warn{background:#fef3c7}.safe{background:#ecfdf5;border-color:#bbf7d0}pre{white-space:pre-wrap;word-break:break-word;background:#0f172a;color:#e2e8f0;border-radius:12px;padding:12px;max-height:520px;overflow:auto}.listing{margin-bottom:12px;padding-bottom:12px;border-bottom:1px dashed #cbd5e1}.listing:last-child{border-bottom:0;margin-bottom:0;padding-bottom:0}a{color:#2563eb}
    </style>
</head>
<body>
<div class="wrap">
    <h1>Allegro marketplace diagnose</h1>
    <div class="card safe">
        <p><b>Read-only:</b> narzędzie nie zmienia bazy, nie publikuje, nie kończy ofert, nie usuwa linków i nie zmienia lokalnego statusu części.</p>
        <p class="muted">GET bez parametrów pokazuje tylko ten formularz — bez Allegro API calls i bez mutacji.</p>
        <p>Przykład użycia: <a href="{{ route('admin.tools.marketplace.allegro-diagnose', ['part_id' => 8061, 'offer_id' => '18741244685', 'check_api' => 1]) }}"><span class="mono">/admin/tools/marketplace/allegro-diagnose?part_id=8061&amp;offer_id=18741244685&amp;check_api=1</span></a></p>
    </div>
    <div class="card">
        <form method="get" action="{{ route('admin.tools.marketplace.allegro-diagnose') }}">
            <div class="field">
                <label for="part_id"><b>part_id / part_ids</b> — wklej ID po przecinku, spacji albo w nowych liniach</label>
                <textarea id="part_id" name="part_id" placeholder="8061">{{ old('part_id', $input) }}</textarea>
            </div>
            <div class="field">
                <label for="offer_id"><b>offer_id</b> (opcjonalnie)</label>
                <input id="offer_id" name="offer_id" type="text" value="{{ old('offer_id', $offer_id) }}" placeholder="18741244685">
            </div>
            <div class="row">
                <label><input type="checkbox" name="check_api" value="1" @checked($check_api)> Sprawdź Allegro API</label>
                <button class="btn" type="submit">Sprawdź</button>
                @if($part_ids)
                    <a href="{{ route('admin.tools.marketplace.allegro-diagnose', ['part_id' => implode(',', $part_ids), 'offer_id' => $offer_id, 'check_api' => $check_api ? 1 : 0, 'format' => 'json']) }}" target="_blank" rel="noopener">Pokaż jako JSON</a>
                @endif
            </div>
        </form>
    </div>

    @if($part_ids === [])
        <div class="card muted">Wpisz part_id i kliknij „Sprawdź”.</div>
    @else
        <div class="card">
            <h2>Raport read-only</h2>
            <table>
                <thead><tr><th>część</th><th>resolver Allegro</th><th>marketplace_listings Allegro</th><th>Allegro API</th></tr></thead>
                <tbody>
                @foreach($results as $result)
                    <tr>
                        <td>
                            <div><b>part.id:</b> <span class="mono">{{ $result['part_id'] }}</span></div>
                            @if(! $result['found'])
                                <span class="badge bad">not found</span>
                            @else
                                <div><b>status:</b> <span class="mono">{{ $result['part']['status'] }}</span></div>
                                <div><b>quantity:</b> <span class="mono">{{ $result['part']['quantity'] }}</span></div>
                                <div><b>adminLocalAvailability:</b> <span class="mono">{{ $result['part']['adminLocalAvailability'] }}</span></div>
                            @endif
                        </td>
                        <td>
                            @php($resolver = $result['resolver_allegro'])
                            <div><b>has_link:</b> <span class="mono">{{ $resolver['has_link'] ? 'true' : 'false' }}</span></div>
                            <div><b>url:</b> <span class="mono">{{ $resolver['url'] ?? 'null' }}</span></div>
                            <div><b>is_active:</b> <span class="badge {{ $resolver['is_active'] ? 'ok' : 'bad' }}">{{ $resolver['is_active'] ? 'true' : 'false' }}</span></div>
                            <div><b>icon:</b> <span class="mono">{{ $resolver['icon'] ?? 'null' }}</span></div>
                            <div><b>display_icon:</b> <span class="mono">{{ $resolver['display_icon'] ?? 'null' }}</span></div>
                            <div><b>reason:</b> <span class="mono">{{ $resolver['reason'] ?? 'null' }}</span></div>
                        </td>
                        <td>
                            @forelse($result['marketplace_listings'] as $listing)
                                <div class="listing">
                                    @foreach(['id','marketplace','channel','status','sync_status','match_status','external_offer_id','external_listing_id','url','last_api_status','last_error'] as $key)
                                        <div><b>{{ $key }}:</b> <span class="mono">{{ $listing[$key] ?? 'null' }}</span></div>
                                    @endforeach
                                </div>
                            @empty
                                <span class="badge warn">brak listingów Allegro</span>
                            @endforelse
                        </td>
                        <td>
                            @php($api = $result['allegro_api'])
                            <div><b>checked:</b> <span class="mono">{{ ($api['checked'] ?? false) ? 'true' : 'false' }}</span></div>
                            <div><b>offer_id:</b> <span class="mono">{{ $api['offer_id'] ?? 'null' }}</span></div>
                            <div><b>exists:</b> <span class="mono">{{ array_key_exists('exists', $api) ? (($api['exists'] ?? false) ? 'true' : 'false') : 'null' }}</span></div>
                            <div><b>publication.status:</b> <span class="mono">{{ $api['publication_status'] ?? 'null' }}</span></div>
                            <div><b>stock.available:</b> <span class="mono">{{ $api['stock_available'] ?? 'null' }}</span></div>
                            <div><b>sellingMode:</b> <span class="mono">{{ isset($api['selling_mode']) ? json_encode($api['selling_mode'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : 'null' }}</span></div>
                            <div><b>is_active:</b> <span class="mono">{{ ($api['is_active'] ?? false) ? 'true' : 'false' }}</span></div>
                            <div><b>is_ended:</b> <span class="mono">{{ ($api['is_ended'] ?? false) ? 'true' : 'false' }}</span></div>
                            <div><b>http_status:</b> <span class="mono">{{ $api['http_status'] ?? 'null' }}</span></div>
                            <div><b>error:</b> <span class="mono">{{ $api['error'] ?? 'null' }}</span></div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="card"><h2>JSON</h2><pre>{{ json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></div>
    @endif
</div>
</body>
</html>
