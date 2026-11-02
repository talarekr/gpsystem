<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Kurier Allegro — dry-run</title>
    <style>
        body{font:16px system-ui,sans-serif;color:#18302d;background:#f5f7f7;margin:0;padding:28px;max-width:1500px}h1{margin-top:0}button,a{margin:5px}button{padding:10px 16px;cursor:pointer}button:disabled{cursor:default}section{background:white;border:1px solid #cbd7d4;border-radius:8px;padding:18px;margin:18px 0;overflow:auto}table{border-collapse:collapse;width:100%;font-size:14px}th,td{text-align:left;padding:8px;border-bottom:1px solid #dce4e2}pre{white-space:pre-wrap}#status{font-weight:600}caption{text-align:left;padding:8px}details{margin:10px 0}
    </style>
</head>
<body>
<h1>Kurier Allegro — dry-run</h1>
<p>Odczyt aktualnych ofert i cenników Allegro. Bez aktualizacji produktów, ofert, logów i sesji. Brak APPLY.</p>
<p>Raport powstaje partiami dla wszystkich Part, także sprzedanych i archiwalnych. Sprawdzamy zdalny status wszystkich znanych powiązań Allegro. Brak powiązania oznacza brak znanej aktywnej oferty; nie wyszukujemy produktów po SKU.</p>
<p>Postęp i eksport są przechowywane tylko w pamięci tej karty. Zamknięcie karty usuwa raport. Odczyt jest rozłożony w czasie, nie jest snapshotem transakcyjnym.</p>
<button id="diagnose">Diagnozuj połączenie Maxhendler</button><button id="catalog">Sprawdź cenniki Allegro</button><button id="run">Globalny dry-run</button><button id="stop" disabled>Zatrzymaj po bieżącej partii</button>
<button id="json" disabled>Pobierz JSON</button><button id="csv" disabled>Pobierz CSV wszystkich produktów</button><button id="updates" disabled>Pobierz CSV do uzupełnienia</button>
<p id="status" role="status">Gotowe do odczytu.</p>
<section><h2>Summary</h2><pre id="summary">Uruchom globalny dry-run.</pre><p>Liczniki liczby aktywnych ofert obejmują tylko produkty, dla których odczyt wszystkich powiązań był rozstrzygnięty. Błędy odczytu nie oznaczają braku oferty.</p></section>
<section><h2>Diagnostyka Maxhendler (2 / allegro_main)</h2><pre id="diagnostics">Nie wykonano odczytu.</pre><p>GET /me potwierdza login właściciela tokenu. HTTP — oznacza, że żądanie nie zostało wykonane. Bez odświeżania OAuth.</p></section>
<section><h2>Aktualne cenniki kont</h2><div id="rates"></div></section>
<section><h2>Produkty według cennika</h2><div id="breakdown"></div><h3>Siedem lokalnych opcji</h3><div id="local-counts"></div><p>Każdy produkt liczony raz: tylko jednoznaczna aktywna oferta z rozpoznanym cennikiem. Produkty z blokadą niejednoznaczności nie są przypisywane do grup.</p></section>
<section><h2>Produkty wymagające uzupełnienia</h2><div id="products"></div><p>Podgląd pokazuje maksymalnie 200 propozycji. Pełne dane, konflikty i blokady znajdują się w eksporcie.</p></section>
<script>
(() => {
    const el = id => document.getElementById(id);
    let report = null, running = false, stop = false;
    const fields = ['part_id','sku','part_number','allegro_offer_id','marketplace_account_id','allegro_account_code','provider_offer_status','shipping_rate_id','shipping_rate_name','current_allegro_shipping_rate_name','proposed_allegro_shipping_rate_name','reason_code','action','provider_read_unavailable','active_offer_count','active_offer_count_complete'];
    function table(target, columns, rows) {
        const node = el(target); node.replaceChildren();
        const t = document.createElement('table'), head = t.createTHead().insertRow();
        columns.forEach(key => {const th = document.createElement('th'); th.textContent = key; head.append(th);});
        const body = t.createTBody();
        rows.forEach(row => {const tr = body.insertRow(); columns.forEach(key => {tr.insertCell().textContent = row[key] ?? '—';});});
        node.append(t);
    }
    async function read(params) {
        const url = new URL(window.location.href); url.search = new URLSearchParams(params);
        const response = await fetch(url, {headers: {'Accept':'application/json'}, credentials:'same-origin', cache:'no-store', redirect:'error'});
        if (!response.ok) throw new Error('Odczyt panelu: HTTP ' + response.status);
        const data = await response.json();
        for (const key of ['database_writes','allegro_writes','marketplace_writes','product_writes']) if (data[key] !== false) throw new Error('Nieprawidłowa odpowiedź audytu.');
        return data;
    }
    function showCatalog(catalog) {
        el('diagnostics').textContent = JSON.stringify(catalog.map(a => ({marketplace_account_id:a.marketplace_account_id,allegro_account_code:a.allegro_account_code,reason_code:a.reason_code,...a.diagnostics})), null, 2);
        table('rates', ['marketplace_account_id','allegro_account_code','ok','http_status','reason_code','shipping_rate_id','shipping_rate_name','local_option'], catalog.flatMap(a => a.shipping_rates.length ? a.shipping_rates.map(r => ({...a,...r})) : [a]));
    }
    function render() {
        el('summary').textContent = JSON.stringify({...report.summary, unresolved_active_offer_count: report.rows.filter(r => !r.active_offer_count_complete).length, ...report.writes}, null, 2);
        showCatalog(report.catalog);
        const groups = new Map();
        report.catalog.forEach(a => a.shipping_rates.forEach(r => groups.set(a.marketplace_account_id+':'+r.shipping_rate_id, {account:a.allegro_account_code,...r,products_count:0})));
        report.rows.forEach(r => {
            if (r.active_offer_count !== 1 || !r.active_offer_count_complete || r.shipping_rate_name === null) return;
            const key = r.marketplace_account_id+':'+r.shipping_rate_id;
            if (!groups.has(key)) groups.set(key, {account:r.allegro_account_code,shipping_rate_id:r.shipping_rate_id,shipping_rate_name:r.shipping_rate_name,products_count:0});
            groups.get(key).products_count++;
        });
        report.breakdown = [...groups.values()];
        report.local_option_counts = Object.fromEntries(report.local_options.map(name => [name, report.breakdown.filter(g => g.local_option === name || g.shipping_rate_name === name).reduce((n,g) => n+g.products_count,0)]));
        table('breakdown',['account','shipping_rate_id','shipping_rate_name','products_count'],report.breakdown);
        table('local-counts',['local_option','products_count'],Object.entries(report.local_option_counts).map(([local_option,products_count]) => ({local_option,products_count})));
        table('products',fields.slice(0,13),report.rows.filter(r => r.action === 'SET_EMPTY_FIELD').slice(0,200));
    }
    function busy(value) {
        running = value; el('diagnose').disabled = value; el('catalog').disabled = value; el('run').disabled = value; el('stop').disabled = !value;
        ['json','csv','updates'].forEach(id => el(id).disabled = value || !report?.complete);
    }
    el('stop').onclick = () => {stop = true; el('status').textContent = 'Zatrzymuję po bieżącej partii. Raport pozostanie niepełny.';};
    el('diagnose').onclick = async () => {
        busy(true);
        try {el('status').textContent = 'Diagnoza Maxhendler…'; const data = await read({mode:'diagnose'}); el('diagnostics').textContent = JSON.stringify(data,null,2); el('status').textContent = 'Diagnoza zakończona. Nie uruchomiono globalnego dry-runu.';}
        catch(error) {el('status').textContent = error.message;} finally {busy(false);}
    };
    el('catalog').onclick = async () => {
        busy(true);
        try {el('status').textContent = 'Odczyt cenników…'; const data = await read({mode:'catalog'}); showCatalog(data.catalog); el('status').textContent = 'Odczyt cenników zakończony. Wynik nie zmienia wcześniejszego raportu produktów.';}
        catch(error) {el('status').textContent = error.message;} finally {busy(false);}
    };
    el('run').onclick = async () => {
        stop = false; report = null; busy(true);
        try {
            let after = 0, through;
            do {
                el('status').textContent = 'Odczyt produktów: ' + (report?.rows.length ?? 0) + '. Trwa partia…';
                const params = {mode:'batch',after}; if (through !== undefined) params.through = through;
                const data = await read(params);
                if (!report) {
                    through = data.through_id;
                    report = {started_at:data.observed_at,finished_at:null,through_id:through,bounded_total_parts:data.bounded_total_parts,complete:false,writes:{database_writes:false,allegro_writes:false,marketplace_writes:false,product_writes:false},summary:Object.fromEntries(Object.keys(data.summary).map(k => [k,0])),catalog:[],catalog_observations:[],local_options:data.local_options,rows:[]};
                }
                if (!data.complete && data.next_after_id <= after) throw new Error('Brak postępu audytu.');
                report.rows.push(...data.rows.map(row => ({...row,observed_at:data.observed_at})));
                Object.keys(data.summary).forEach(k => report.summary[k] += data.summary[k]);
                report.catalog = data.catalog;
                report.catalog_observations.push({observed_at:data.observed_at,after_id:after,catalog:data.catalog});
                after = data.next_after_id;
                report.complete = data.complete;
                report.finished_at = data.observed_at;
                report.coverage_count_changed = report.bounded_total_parts !== data.bounded_total_parts || (data.complete && report.rows.length !== report.bounded_total_parts);
                render();
            } while (!report.complete && !stop);
            el('status').textContent = report.complete ? 'Dry-run zakończony: ' + report.rows.length + ' produktów. Pobierz raport i sprawdź blokady.' + (report.coverage_count_changed ? ' UWAGA: liczba produktów zmieniła się podczas audytu; uruchom go ponownie.' : '') : 'Zatrzymano. Raport jest niepełny; eksport wyłączony. Uruchom ponownie, aby uzyskać pełny raport.';
        } catch(error) {if(report) report.complete = false; el('status').textContent = error.message + '. Raport niepełny; uruchom ponownie.';} finally {busy(false);}
    };
    function download(content, type, filename) {
        const url = URL.createObjectURL(new Blob([content], {type})), a = document.createElement('a');
        a.href = url; a.download = filename; a.click(); setTimeout(() => URL.revokeObjectURL(url),1000);
    }
    // Neutralize spreadsheet formulas in all externally supplied CSV cells.
    function cell(value) {let s = String(value ?? ''); if (/^[\s\u0000-\u001f]*[=+@-]/u.test(s)) s = "'"+s; return '"'+s.replaceAll('"','""')+'"';}
    function csv(rows) {const columns = [...fields,'observed_at']; return '\uFEFF'+[columns,...rows.map(r => columns.map(k => r[k]))].map(row => row.map(cell).join(';')).join('\r\n');}
    el('json').onclick = () => download(JSON.stringify(report,null,2),'application/json','allegro-shipping-rate-audit.json');
    el('csv').onclick = () => download(csv(report.rows),'text/csv;charset=utf-8','allegro-shipping-rate-audit.csv');
    el('updates').onclick = () => download(csv(report.rows.filter(r => r.action === 'SET_EMPTY_FIELD')),'text/csv;charset=utf-8','allegro-shipping-rate-proposals.csv');
})();
</script>
</body>
</html>
