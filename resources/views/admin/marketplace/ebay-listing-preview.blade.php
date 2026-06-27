<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Podgląd aukcji {{ strtoupper($channel) }} · część #{{ $part->id }}</title>
    <style>
        body{font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f8fafc;color:#0f172a;margin:0}.wrap{max-width:1280px;margin:0 auto;padding:24px}.notice{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:12px;padding:14px 16px;margin-bottom:18px;font-weight:600}.blocked{background:#fff7ed;border-color:#fed7aa;color:#9a3412}.grid{display:grid;grid-template-columns:minmax(320px,420px) 1fr;gap:20px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px;box-shadow:0 1px 2px rgba(15,23,42,.04)}h1{font-size:26px;margin:0 0 6px}h2{font-size:18px;margin:0 0 14px}.kv{display:grid;grid-template-columns:170px 1fr;gap:8px 12px;font-size:14px}.k{color:#64748b}.v{font-weight:600;overflow-wrap:anywhere}.pill{display:inline-block;border-radius:999px;padding:3px 9px;font-size:12px;font-weight:700}.ok{background:#dcfce7;color:#166534}.warn{background:#fef3c7;color:#92400e}.bad{background:#fee2e2;color:#991b1b}ul{margin:8px 0 0;padding-left:20px}.images{display:flex;flex-wrap:wrap;gap:8px}.images img{width:86px;height:86px;object-fit:cover;border:1px solid #e2e8f0;border-radius:10px}.json{white-space:pre-wrap;background:#0f172a;color:#e2e8f0;border-radius:10px;padding:12px;font-size:12px;overflow:auto}.preview-frame{width:100%;height:780px;border:1px solid #cbd5e1;border-radius:12px;background:#fff}.empty{border:1px dashed #cbd5e1;border-radius:12px;padding:32px;text-align:center;color:#64748b;background:#fff}@media(max-width:900px){.grid{grid-template-columns:1fr}.preview-frame{height:620px}.kv{grid-template-columns:1fr}}
    </style>
</head>
<body>
@php
    $businessPolicies = $preview['business_policies'] ?? [];
    $shippingResolution = $preview['shipping_policy_resolution'] ?? [];
    $exchangeRate = $preview['exchange_rate'] ?? [];
    $ready = (bool) ($readiness['can_prepare'] ?? false);
@endphp
<div class="wrap">
    <div class="notice">To jest tylko podgląd. Nie wystawia oferty i nie wykonuje żadnego zapisu do marketplace.</div>
    @if (! $ready)
        <div class="notice blocked">Aukcja nie jest gotowa do wystawienia. Sprawdź missing_fields, warnings i blockers poniżej.</div>
    @endif

    <h1>Podgląd aukcji eBay</h1>
    <p>Część #{{ $part->id }} · kanał {{ $channel }} · read-only / dry-run</p>

    <div class="grid">
        <section class="card">
            <h2>Panel diagnostyczny</h2>
            <div class="kv">
                <div class="k">Tytuł aukcji</div><div class="v">{{ $preview['title'] ?? $part->name ?? '—' }}</div>
                <div class="k">Kanał</div><div class="v">{{ $channel }}</div>
                <div class="k">Readiness</div><div class="v"><span class="pill {{ $ready ? 'ok' : 'bad' }}">{{ $ready ? 'gotowa' : 'niegotowa' }}</span></div>
                <div class="k">Dry-run</div><div class="v"><span class="pill ok">dry_run={{ ($preview['dry_run'] ?? false) ? 'true' : 'false' }}</span></div>
                <div class="k">Marketplace request</div><div class="v"><span class="pill ok">will_make_marketplace_request=false</span></div>
                <div class="k">Waluta</div><div class="v">{{ $preview['currency'] ?? $readiness['currency'] ?? 'EUR' }}</div>
                <div class="k">Cena źródłowa PLN</div><div class="v">{{ $preview['price_source_pln'] ?? $preview['price_pln'] ?? '—' }}</div>
                <div class="k">Cena EUR</div><div class="v">{{ $preview['price_eur'] ?? '—' }}</div>
                <div class="k">Kurs NBP</div><div class="v">{{ $exchangeRate['rate'] ?? '—' }} @if(!empty($exchangeRate['effective_date']))({{ $exchangeRate['effective_date'] }})@endif</div>
                <div class="k">Kategoria eBay</div><div class="v">{{ $preview['category_id'] ?? '—' }}</div>
                <div class="k">Lokalna kategoria</div><div class="v">{{ $shippingResolution['local_category_name'] ?? '—' }} @if(!empty($shippingResolution['local_category_id']))(#{{ $shippingResolution['local_category_id'] }})@endif</div>
                <div class="k">Shipping group</div><div class="v">{{ $shippingResolution['shipping_group'] ?? '—' }}</div>
                <div class="k">Fulfillment policy</div><div class="v">{{ $businessPolicies['selected_fulfillment_policy_id'] ?? '—' }} {{ $businessPolicies['selected_fulfillment_policy_name'] ?? '' }}</div>
                <div class="k">Payment policy</div><div class="v">{{ $businessPolicies['selected_payment_policy_id'] ?? '—' }} {{ $businessPolicies['selected_payment_policy_name'] ?? '' }}</div>
                <div class="k">Return policy</div><div class="v">{{ $businessPolicies['selected_return_policy_id'] ?? '—' }} {{ $businessPolicies['selected_return_policy_name'] ?? '' }}</div>
            </div>

            <h2 style="margin-top:20px">Zdjęcia w kolejności marketplace preview</h2>
            <div class="images">
                @forelse (($preview['image_urls'] ?? []) as $url)
                    <img src="{{ $url }}" alt="Zdjęcie aukcji {{ $loop->iteration }}">
                @empty
                    <span class="pill warn">brak zdjęć</span>
                @endforelse
            </div>

            <h2 style="margin-top:20px">Readiness</h2>
            <p><strong>missing_fields</strong></p>
            <ul>@forelse (($readiness['missing_fields'] ?? []) as $item)<li>{{ $item }}</li>@empty<li>brak</li>@endforelse</ul>
            <p><strong>warnings</strong></p>
            <ul>@forelse (($readiness['warnings'] ?? []) as $item)<li>{{ $item }}</li>@empty<li>brak</li>@endforelse</ul>
            <p><strong>blockers</strong></p>
            <ul>@forelse (($readiness['blockers'] ?? []) as $item)<li>{{ $item }}</li>@empty<li>brak</li>@endforelse</ul>

            <h2 style="margin-top:20px">shipping_policy_resolution</h2>
            <pre class="json">{{ json_encode($shippingResolution, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            <h2 style="margin-top:20px">business_policies</h2>
            <pre class="json">{{ json_encode($businessPolicies, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </section>

        <section class="card">
            <h2>Wyrenderowany HTML opisu</h2>
            <p><code>description_rendered_html</code> — źródło treści, która trafi do <code>offer.listingDescription</code>.</p>
            @if (filled(trim($html)))
                <iframe class="preview-frame" sandbox srcdoc="{{ $html }}"></iframe>
            @else
                <div class="empty">Brak wyrenderowanego szablonu/opisu eBay dla tego podglądu.</div>
            @endif
        </section>
    </div>
</div>
</body>
</html>
