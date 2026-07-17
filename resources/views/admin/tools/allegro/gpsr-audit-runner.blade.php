@extends('layouts.admin')

@section('content')
<div data-page-marker="{{ $pageMarker }}">
    <h1>Allegro GPSR Audit Runner</h1>
    <p>Read-only diagnose runner. Uses only GET /sale/product-offers/{offerId} and optional GET /sale/products/{productId}.</p>
    <pre id="allegro-gpsr-audit-status">{{ json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
</div>
<script>
setInterval(() => fetch('{{ route('admin.tools.allegro.gpsr-audit-runner.status') }}').then(r => r.json()).then(j => { document.getElementById('allegro-gpsr-audit-status').textContent = JSON.stringify(j, null, 2); }).catch(() => {}), 5000);
</script>
@endsection
