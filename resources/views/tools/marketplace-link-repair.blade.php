<!doctype html>
<html lang="pl">
<head><meta charset="utf-8"><title>Marketplace link repair</title></head>
<body>
<h1>Marketplace link repair</h1>
<p>Preview jest read-only. Apply wykonuje tylko lokalne create/update marketplace_listings, bez API write, sync i relistingu.</p>
<form method="GET" action="{{ route('admin.tools.marketplace.link-repair') }}">
    <label>Kanał <select name="channel"><option value="both">oba</option><option value="ovoko">Ovoko</option><option value="allegro">Allegro</option></select></label>
    <label>part_id <input name="part_id" value="{{ request('part_id') }}"></label>
    <label>limit <input name="limit" value="{{ $limit }}"></label>
    <label><input type="checkbox" name="ready_only" value="1" @checked(request()->boolean('ready_only'))> ready + quantity &gt; 0</label>
    <label><input type="checkbox" name="only_resolver_broken" value="1" @checked(request()->boolean('only_resolver_broken'))> resolver broken only</label>
    <button type="submit">Preview</button>
</form>
<form method="POST" action="{{ route('admin.tools.marketplace.link-repair') }}" onsubmit="return confirm('Apply local marketplace_listings repair?');">
    @csrf
    @foreach($filters as $key => $value)
        @if($value !== null)<input type="hidden" name="{{ $key }}" value="{{ is_bool($value) ? (int) $value : $value }}">@endif
    @endforeach
    <input type="hidden" name="confirm" value="apply-marketplace-link-repair">
    <button type="submit">Apply repair</button>
</form>
<table border="1" cellpadding="4">
<thead><tr><th>part_id</th><th>channel</th><th>current ID/link</th><th>listing</th><th>missing</th><th>planned</th><th>before</th><th>after</th><th>actual after write</th><th>action</th></tr></thead>
<tbody>
@foreach($rows as $row)
<tr>
<td>{{ $row['part_id'] }}</td><td>{{ $row['channel'] }}</td><td><pre>{{ json_encode($row['current_id_link'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre></td><td>{{ $row['existing_listing_id'] ?? 'brak' }}</td><td>{{ implode(', ', $row['missing_fields']) }}</td><td><pre>{{ json_encode($row['planned_changes'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre></td><td><pre>{{ json_encode($row['resolver_before'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre></td><td><pre>{{ json_encode($row['resolver_after'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre></td><td><pre>{{ json_encode($row['actual_after_write'] ?? null, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre></td><td>{{ $row['action'] }} {{ $row['reason'] ?? '' }} @if(isset($row['error']))<pre>{{ json_encode($row['error'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>@endif</td>
</tr>
@endforeach
</tbody></table>
</body>
</html>
