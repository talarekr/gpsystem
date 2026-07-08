@extends('layouts.admin')

@section('content')
<div class="container">
    <h1>Ovoko: odpięcie starego/importowanego powiązania</h1>
    <p><small>Tool marker: <code>{{ $code_marker ?? 'missing-code-marker' }}</code></small></p>
    <p><b>GET jest read-only.</b> POST zmienia wyłącznie lokalny rekord marketplace_listings; nie wysyła requestów do Ovoko i nie usuwa produktu na Ovoko.</p>

    <form method="get" action="{{ route('admin.tools.ovoko.unlink-stale-listing') }}">
        <label>part_id <input name="part_id" value="{{ $part_id ?? request('part_id') }}"></label>
        <button type="submit">Preview</button>
        @if(!empty($part_id))
            <a href="{{ route('admin.tools.ovoko.unlink-stale-listing', ['part_id' => $part_id, 'json' => 1]) }}" target="_blank" rel="noopener">JSON</a>
        @endif
    </form>

    @if(!empty($error))
        <h2>Błąd apply</h2>
        <pre>{{ json_encode(['code_marker' => $code_marker ?? null, 'exception_class' => $exception_class ?? null, 'message' => $message ?? null, 'failed_step' => $failed_step ?? null, 'part_id' => $part_id ?? null, 'marketplace_listing_id' => $marketplace_listing_id ?? null, 'request_has_csrf' => $request_has_csrf ?? null, 'confirm_received' => $confirm_received ?? null, 'route_name' => $route_name ?? null, 'controller_action' => $controller_action ?? null], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
    @endif

    @if(!empty($applied))
        <h2>Apply wykonany</h2>
        <pre>{{ json_encode($changed ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
    @endif

    @if(!empty($local_part))
        <h2>Część lokalna</h2>
        <pre>{{ json_encode($local_part, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>

        <h2>Ovoko marketplace_listings</h2>
        <table class="table">
            <thead><tr><th>ID</th><th>status</th><th>sync</th><th>match</th><th>Ovoko ID</th><th>URL</th><th>sent_price</th><th>active sale?</th><th>kwalifikuje?</th><th>powody</th><th>blockery</th></tr></thead>
            <tbody>
            @foreach($marketplace_listings ?? [] as $listing)
                <tr>
                    <td>{{ $listing['id'] }}</td>
                    <td>{{ $listing['status'] }}</td>
                    <td>{{ $listing['sync_status'] }}</td>
                    <td>{{ $listing['match_status'] }}</td>
                    <td>{{ $listing['external_offer_id'] ?: $listing['external_listing_id'] }}</td>
                    <td>{{ $listing['url'] }}</td>
                    <td>{{ $listing['sent_price'] }}</td>
                    <td>{{ $listing['active_sale_by_local_logic'] ? 'tak' : 'nie' }}</td>
                    <td>{{ $listing['qualifies_for_unlink'] ? 'tak' : 'nie' }}</td>
                    <td><code>{{ implode(', ', $listing['reasons'] ?? []) }}</code></td>
                    <td><code>{{ implode(', ', $listing['safety_blockers'] ?? []) }}</code></td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <h2>Decyzja</h2>
        <pre>{{ json_encode(['duplicate_guard_currently_would_block' => $duplicate_guard_currently_would_block ?? null, 'what_changes_after_apply' => $what_changes_after_apply ?? null, 'decision_after_apply' => $decision_after_apply ?? null], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>

        @if(!empty($qualified_listings))
            <h2>Apply</h2>
            @foreach($qualified_listings as $qualifiedListing)
                <form method="post" action="{{ route('admin.tools.ovoko.unlink-stale-listing') }}">
                    @csrf
                    <input type="hidden" name="part_id" value="{{ $part_id }}">
                    <input type="hidden" name="marketplace_listing_id" value="{{ $qualifiedListing['id'] }}">
                    <input type="hidden" name="confirm" value="unlink-stale-ovoko-listing">
                    <button type="submit">Odepnij lokalnie listing #{{ $qualifiedListing['id'] }}</button>
                    <p><small>POST: <code>method=POST</code>, action <code>/admin/tools/ovoko/unlink-stale-listing</code>, CSRF included, part_id <code>{{ $part_id }}</code>, marketplace_listing_id <code>{{ $qualifiedListing['id'] }}</code>, confirm <code>unlink-stale-ovoko-listing</code>. For JSON response add <code>?return_json=1</code> to the form action or submit <code>return_json=1</code>.</small></p>
                </form>
            @endforeach
        @endif
    @elseif(($part_id ?? null))
        <p>Nie znaleziono części.</p>
    @else
        <p>Podaj part_id, np. <code>/admin/tools/ovoko/unlink-stale-listing?part_id=7498</code>.</p>
    @endif
</div>
@endsection
