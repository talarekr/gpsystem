<section id="new-products-translations" class="card" aria-labelledby="new-products-title">
    <h2 id="new-products-title">Nowe/zmienione produkty — tłumaczenia FR</h2>
    <p>Zapis produktu automatycznie zgłasza tłumaczenie bez Google i bez kolejki/workera. Produkty widoczne w PL są od razu widoczne także w FR z fallbackiem. Ta sekcja nie zmienia cen, dostawy, widoczności, stocku ani marketplace.</p>
    <p>Jedna zatwierdzona partia: maksymalnie 100 produktów i 50 000 znaków (konfiguracja może obniżyć limity). Jeden produkt na tick. Import nie uruchamia automatycznie kolejnych partii. Reviewed wymaga ręcznej kontroli. Błędy zatrzymują run, bez automatycznych powtórzeń Google.</p>
    @if(! $newProductsOverview['schema_ready'])<p role="alert">Najpierw wdróż migrację zgłoszeń produktów.</p>@endif
    <label><input id="new-products-retry" type="checkbox" style="width:auto"> Włącz failed do nowego dry-run dopiero po usunięciu przyczyny błędu.</label>
    <button id="new-products-preview" class="button" type="button">Dry-run nowych/zmienionych</button>
    <details><summary>Raport dry-run i wybrane produkty</summary><pre id="new-products-report">Najpierw wykonaj dry-run.</pre></details>
    <details><summary>Naprawa zgłoszenia / import bez zdarzeń Eloquent</summary>
        <p>Podaj ID produktu z panelu. Ponowne zgłoszenie odczytuje aktualny source hash i zapisuje tylko stan tłumaczenia; nie zmienia produktu i nie wywołuje Google.</p>
        <input id="new-products-part-id" type="number" min="1" aria-label="ID produktu">
        <button id="new-products-register" class="button" type="button">Ponownie zgłoś ID</button>
    </details>
    <label for="new-products-confirm">Token: TRANSLATE-NEW-CHANGED-GPSWISS-FR</label>
    <input id="new-products-confirm" autocomplete="off">
    <div class="row">
        <button id="new-products-start" class="button primary" type="button" disabled>Start zatwierdzonej partii</button>
        <button id="new-products-pause" class="button" type="button">Pauza</button>
        <button id="new-products-resume" class="button" type="button">Wznów</button>
        <button id="new-products-stop" class="button danger" type="button">Stop</button>
    </div>
    <p>Zostaw stronę otwartą. Po odświeżeniu kliknij Wznów dla istniejącego runu. Niepewna, przerwana próba Google nie jest powtarzana automatycznie; sprawdź raport. Kategoria z brakującym tłumaczeniem nadal korzysta z osobnego runnera całego katalogu.</p>
    <p id="new-products-message" role="status"></p>
    <pre id="new-products-status">{{ json_encode($newProductsOverview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
</section>
<script>
(() => {
    const base = @json(url('/admin/tools/storefront/fr-translations/new-products'));
    const element = name => document.getElementById('new-products-' + name);
    let report = null, run = @json($newProductsOverview['status']), active = false, busy = false, version = 0;
    const show = value => { element('status').textContent = JSON.stringify(value, null, 2); };
    const message = value => { element('message').textContent = value; };
    async function request(path, input) {
        const response = await fetch(base + path, {method: input ? 'POST' : 'GET', credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
            ...(input ? {body: JSON.stringify(input)} : {})});
        const value = await response.json();
        if (!response.ok) throw new Error(Object.values(value.errors || {}).flat().join(' ') || value.message || 'Błąd żądania. Sprawdź status przed ponowieniem.');
        return value;
    }
    function buttons() { for (const action of ['pause','resume','stop']) element(action).disabled = busy || !run.run_id || ['completed','stopped'].includes(run.status); element('start').disabled = !report || element('confirm').value !== 'TRANSLATE-NEW-CHANGED-GPSWISS-FR' || busy || ['running','paused','stopped_on_error'].includes(run.status); }
    async function tick() {
        if (!active || busy || run.status !== 'running') return;
        busy = true; buttons();
        try { run = (await request('/process-next-batch', {run_id: run.run_id})).status; show(run); }
        catch (error) { active = false; message(error.message); }
        finally { busy = false; buttons(); if (active && run.status === 'running') setTimeout(tick, 6000); }
    }
    element('confirm').addEventListener('input', buttons);
    element('preview').onclick = async () => {
        if (busy) return;
        version++; busy = true; buttons();
        try { report = (await request('/dry-run', {include_failed: element('retry').checked})).report; element('report').textContent = JSON.stringify(report, null, 2); message('Sprawdź liczniki, koszty i wybrane produkty; potem wpisz token.'); }
        catch (error) { report = null; message(error.message); }
        finally { busy = false; buttons(); }
    };
    element('register').onclick = async () => {
        if (busy) return;
        version++; busy = true; buttons();
        try { await request('/register', {part_id: Number(element('part-id').value)}); report = null; message('Zgłoszenie odświeżone bez Google. Wykonaj nowy dry-run.'); }
        catch (error) { message(error.message); }
        finally { busy = false; buttons(); }
    };
    element('start').onclick = async () => {
        if (busy || !report) return;
        version++; busy = true; buttons();
        try { run = (await request('/start', {dry_run_id: report.dry_run_id, confirm: element('confirm').value})).status; report = null; active = true; show(run); }
        catch (error) { active = false; message(error.message); }
        finally { busy = false; buttons(); if (active) setTimeout(tick, 100); }
    };
    for (const action of ['pause','resume','stop']) element(action).onclick = async () => {
        active = false;
        if (!run.run_id || busy) return;
        version++; busy = true; buttons();
        try { run = (await request('/control', {run_id: run.run_id, action})).status; active = action === 'resume'; show(run); }
        catch (error) { message(error.message); }
        finally { busy = false; buttons(); if (active) setTimeout(tick, 100); }
    };
    request('/status').then(value => {if (version !== 0) return; run = value.status; show(value); buttons();}).catch(error => message(error.message));
    buttons();
})();
</script>
