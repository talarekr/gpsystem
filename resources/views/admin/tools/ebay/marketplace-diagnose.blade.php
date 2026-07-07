<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>eBay marketplace diagnose</title>
    <style>body{font-family:system-ui,sans-serif;margin:24px}fieldset{margin:16px 0;padding:16px}table{border-collapse:collapse;width:100%;margin-top:16px}td,th{border:1px solid #ddd;padding:6px;font-size:12px}button{margin:4px;padding:8px 12px}.warn{color:#9a3412}.ok{color:#166534}.muted{color:#666}.controls{display:flex;gap:12px;flex-wrap:wrap;align-items:end}</style>
</head>
<body>
<h1>eBay marketplace diagnose</h1>
<p class="warn">Wejście na tę stronę jest read-only: bez parametrów nie uruchamia audytu, nie woła eBay API i nie zmienia bazy.</p>

<fieldset>
    <legend>Sprawdź podane part_id</legend>
    <form method="get" action="{{ route('admin.tools.ebay.marketplace-diagnose') }}">
        <input type="hidden" name="action" value="part">
        <label>part_id / part_ids <input name="part_ids" value="{{ implode(',', $input['part_ids'] ?? []) }}" placeholder="886,887"></label>
        <label><input type="checkbox" name="check_api" value="1"> Sprawdź eBay API</label>
        <button type="submit">Sprawdź podane part_id</button>
    </form>
</fieldset>

<fieldset>
    <legend>Uruchom audyt masowy</legend>
    <form method="get" action="{{ route('admin.tools.ebay.marketplace-diagnose') }}">
        <input type="hidden" name="action" value="bulk">
        <label>Limit <input name="limit" type="number" min="1" max="500" value="20"></label>
        <label><input type="checkbox" name="check_api" value="1"> Sprawdź eBay API</label>
        <button type="submit">Uruchom audyt masowy</button>
    </form>
</fieldset>

<fieldset>
    <legend>Auto-runner masowego audytu (read-only)</legend>
    <div class="controls">
        <label>Batch <input id="batchSize" type="number" min="1" max="100" value="20"></label>
        <label>Delay ms <input id="delayMs" type="number" min="0" value="5000"></label>
        <button id="runnerStart" type="button">Uruchom auto-runner</button>
        <button id="runnerPause" type="button">Pauza</button>
        <button id="runnerStop" type="button">Stop</button>
        <a id="downloadJson" href="#" download="ebay-marketplace-diagnose.json">Pobierz JSON</a>
        <a id="downloadCsv" href="#" download="ebay-marketplace-diagnose.csv">Pobierz CSV</a>
    </div>
    <p>Status: <strong id="runnerStatus">idle</strong></p>
    <p id="runnerProgress" class="muted">processed / total: 0 / 0, current batch: 0</p>
    <p id="runnerCounters" class="muted">active OK: 0, ended/stale: 0, not_found: 0, api_error: 0, needs_review: 0, duplicate_guard_would_block: 0</p>
</fieldset>

@if(!empty($ran))
<fieldset>
    <legend>Oznacz zakończone jako historyczne</legend>
    <form method="post" action="{{ route('admin.tools.ebay.marketplace-diagnose') }}" onsubmit="return confirm('Potwierdzasz oznaczenie zakończonych listingów jako historyczne?');">
        @csrf
        <input type="hidden" name="action" value="apply_inactive">
        <input type="hidden" name="check_api" value="1">
        <input type="hidden" name="confirm_apply_inactive" value="1">
        <input type="hidden" name="part_ids" value="{{ implode(',', $input['part_ids'] ?? []) }}">
        <button type="submit">Oznacz zakończone jako historyczne</button>
    </form>
</fieldset>
@endif

<h2>Wyniki</h2>
<table id="resultsTable">
<thead><tr><th>part_id</th><th>SKU</th><th>ebay_de_status</th><th>ebay_de_url</th><th>ebay_fr_status</th><th>ebay_fr_url</th><th>listing_exists</th><th>endPast</th><th>public_item_id</th><th>seller_offer_id</th><th>seller_listing_id</th><th>requested_listing_id</th><th>offer_listing_id</th><th>seller_listing_id_matches_public_item_id</th><th>public_item_end_date</th><th>public_item_end_date_source</th><th>public_item_end_past</th><th>seller_listing_status</th><th>seller_offer_status</th><th>needs_ebay_de_publish</th><th>classification</th><th>duplicate_guard_would_block</th><th>eBay statusy</th><th>resolver</th></tr></thead>
<tbody>
@foreach(($rows ?? []) as $row)
<tr><td>{{ $row['part']['id'] }}</td><td>{{ $row['part']['sku'] }}</td><td>{{ $row['ebay_de_status'] ?? '' }}</td><td>{{ $row['ebay_de_url'] ?? '' }}</td><td>{{ $row['ebay_fr_status'] ?? '' }}</td><td>{{ $row['ebay_fr_url'] ?? '' }}</td><td>{{ collect($row['marketplace_listings'])->whereIn('marketplace', ['ebay_de', 'ebay'])->contains('listing_exists', true) ? 'yes' : 'no' }}</td><td>{{ collect($row['marketplace_listings'])->whereIn('marketplace', ['ebay_de', 'ebay'])->map(fn($l) => json_encode($l['api']['end_date_is_past'] ?? null))->implode('; ') }}</td><td>{{ $row['public_item_id'] ?? '' }}</td><td>{{ $row['seller_offer_id'] ?? '' }}</td><td>{{ $row['seller_listing_id'] ?? '' }}</td><td>{{ $row['requested_listing_id'] ?? '' }}</td><td>{{ $row['offer_listing_id'] ?? '' }}</td><td>{{ json_encode($row['seller_listing_id_matches_public_item_id'] ?? null) }}</td><td>{{ $row['public_item_end_date'] ?? '' }}</td><td>{{ $row['public_item_end_date_source'] ?? '' }}</td><td>{{ json_encode($row['public_item_end_past'] ?? null) }}</td><td>{{ $row['seller_listing_status'] ?? '' }}</td><td>{{ $row['seller_offer_status'] ?? '' }}</td><td>{{ ($row['needs_ebay_de_publish'] ?? false) ? 'true' : 'false' }}</td><td>{{ $row['audit_classification'] }}</td><td>{{ $row['duplicate_guard_would_block'] ? 'yes' : 'no' }}</td><td>{{ collect($row['marketplace_listings'])->map(fn($l) => ($l['api']['api_listing_status'] ?? '').' endPast='.json_encode($l['api']['end_date_is_past'] ?? null))->implode('; ') }}</td><td>{{ $row['resolver_ebay']['display_icon'] ?? '' }} {{ $row['resolver_ebay']['reason'] ?? '' }}</td></tr>
@endforeach
</tbody>
</table>

<script>
let running=false, paused=false, stopped=false, offset=0, total=0, batchNo=0, allRows=[];
const counters={active_OK:0,ended_stale:0,not_found:0,api_error:0,needs_review:0,duplicate_guard_would_block:0};
function setStatus(s){document.getElementById('runnerStatus').textContent=s}
function updateDownloads(){
 const json=JSON.stringify(allRows,null,2); document.getElementById('downloadJson').href=URL.createObjectURL(new Blob([json],{type:'application/json'}));
 const csv=['part_id,sku,ebay_de_status,ebay_de_url,ebay_fr_status,ebay_fr_url,listing_exists,endPast,public_item_id,seller_offer_id,seller_listing_id,requested_listing_id,offer_listing_id,seller_listing_id_matches_public_item_id,public_item_end_date,public_item_end_date_source,public_item_end_past,seller_listing_status,seller_offer_status,needs_ebay_de_publish,ebay_overall,classification,duplicate_guard_would_block'].concat(allRows.map(r=>[r.part.id,r.part.sku,r.ebay_de_status,r.ebay_de_url,r.ebay_fr_status,r.ebay_fr_url,channelListings(r).some(l=>l.listing_exists),channelListings(r).map(l=>l.api?.end_date_is_past??'').join('; '),r.public_item_id,r.seller_offer_id,r.seller_listing_id,r.requested_listing_id,r.offer_listing_id,r.seller_listing_id_matches_public_item_id,r.public_item_end_date,r.public_item_end_date_source,r.public_item_end_past,r.seller_listing_status,r.seller_offer_status,r.needs_ebay_de_publish,r.ebay_overall,r.audit_classification,r.duplicate_guard_would_block].map(v=>'"'+String(v??'').replaceAll('"','""')+'"').join(','))).join('\n');
 document.getElementById('downloadCsv').href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));
}
function channelListings(r){return (r.marketplace_listings||[]).filter(l=>['ebay_de','ebay'].includes(l.marketplace));}
function renderProgress(){document.getElementById('runnerProgress').textContent=`processed / total: ${offset} / ${total}, current batch: ${batchNo}`;document.getElementById('runnerCounters').textContent=`active OK: ${counters.active_OK}, ended/stale: ${counters.ended_stale}, not_found: ${counters.not_found}, api_error: ${counters.api_error}, needs_review: ${counters.needs_review}, duplicate_guard_would_block: ${counters.duplicate_guard_would_block}`;}
function addRows(rows){const tbody=document.querySelector('#resultsTable tbody'); rows.forEach(r=>{allRows.push(r); if(r.audit_classification.includes('active OK'))counters.active_OK++; if(r.audit_classification.includes('ended/stale'))counters.ended_stale++; if(r.audit_classification.includes('not_found'))counters.not_found++; if(r.audit_classification.includes('api_error'))counters.api_error++; if(r.audit_classification.includes('needs_review'))counters.needs_review++; if(r.duplicate_guard_would_block)counters.duplicate_guard_would_block++; const tr=document.createElement('tr'); tr.innerHTML=`<td>${r.part.id}</td><td>${r.part.sku??''}</td><td>${r.ebay_de_status??''}</td><td>${r.ebay_de_url??''}</td><td>${r.ebay_fr_status??''}</td><td>${r.ebay_fr_url??''}</td><td>${channelListings(r).some(l=>l.listing_exists)?'yes':'no'}</td><td>${channelListings(r).map(l=>l.api?.end_date_is_past??'').join('; ')}</td><td>${r.public_item_id??''}</td><td>${r.seller_offer_id??''}</td><td>${r.seller_listing_id??''}</td><td>${r.requested_listing_id??''}</td><td>${r.offer_listing_id??''}</td><td>${r.seller_listing_id_matches_public_item_id??''}</td><td>${r.public_item_end_date??''}</td><td>${r.public_item_end_date_source??''}</td><td>${r.public_item_end_past??''}</td><td>${r.seller_listing_status??''}</td><td>${r.seller_offer_status??''}</td><td>${r.needs_ebay_de_publish?'true':'false'}</td><td>${r.audit_classification}</td><td>${r.duplicate_guard_would_block?'yes':'no'}</td><td>${r.marketplace_listings.map(l=>(l.api.api_listing_status||'')+' endPast='+l.api.end_date_is_past).join('; ')}</td><td>${r.resolver_ebay.display_icon??''} ${r.resolver_ebay.reason??''}</td>`; tbody.appendChild(tr);}); updateDownloads();}
async function runBatch(){ if(!running||paused||stopped) return; setStatus('running'); batchNo++; const limit=document.getElementById('batchSize').value||20; const res=await fetch(`{{ route('admin.tools.ebay.marketplace-diagnose') }}?format=json&action=bulk&check_api=1&limit=${limit}&offset=${offset}`,{headers:{'Accept':'application/json'}}); const data=await res.json(); total=data.progress.total; offset=data.progress.processed; addRows(data.rows||[]); renderProgress(); if(data.progress.completed){running=false; setStatus('completed'); return;} setTimeout(runBatch, parseInt(document.getElementById('delayMs').value||5000,10)); }
document.getElementById('runnerStart').onclick=()=>{if(running&&paused){paused=false;runBatch();return;} running=true; paused=false; stopped=false; offset=0; total=0; batchNo=0; allRows=[]; Object.keys(counters).forEach(k=>counters[k]=0); document.querySelector('#resultsTable tbody').innerHTML=''; runBatch();};
document.getElementById('runnerPause').onclick=()=>{paused=true; setStatus('paused')};
document.getElementById('runnerStop').onclick=()=>{stopped=true; running=false; setStatus('stopped')};
</script>
</body>
</html>
