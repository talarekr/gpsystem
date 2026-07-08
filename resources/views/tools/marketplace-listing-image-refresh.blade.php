<!doctype html><html><head><meta charset="utf-8"><title>Marketplace listing image refresh</title><style>body{font-family:sans-serif;margin:24px}label{display:block;margin:8px 0}pre{background:#f6f8fa;padding:12px;overflow:auto}.danger{color:#b00020}</style></head><body>
<h1>Marketplace listing image refresh</h1>
<p>Admin-only single-listing tool. Preview is GET/read-only. Apply is POST + CSRF + confirm and only updates existing gallery images.</p>
<form method="GET" action="{{ route('admin.tools.marketplace.listing-image-refresh') }}">
<label>part_id <input name="part_id" value="{{ $partId }}"></label>
<label>channel <select name="channel"><option value="allegro_main" @selected($channel==='allegro_main' || $channel==='allegro')>Allegro</option><option value="ebay_de" @selected($channel==='ebay_de' || $channel==='ebay')>eBay DE</option></select></label>
<button type="submit">Preview</button> <button name="json" value="1">Preview JSON</button>
</form>
@if(($result['blockers'] ?? []) !== [])<p class="danger"><strong>Blockers:</strong> {{ implode(', ', $result['blockers']) }}</p>@endif
<form method="POST" action="{{ route('admin.tools.marketplace.listing-image-refresh') }}" onsubmit="return confirm('Update images on existing marketplace listing only?');">
@csrf
<input type="hidden" name="part_id" value="{{ $partId }}"><input type="hidden" name="channel" value="{{ $channel }}">
<label>confirm <input name="confirm" placeholder="{{ $confirmText }}"></label>
<button type="submit">Apply</button>
</form>
<pre>{{ json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) }}</pre>
</body></html>
