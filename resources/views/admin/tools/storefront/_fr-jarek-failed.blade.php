<section class="card" id="jarek-failed-section" aria-labelledby="jarek-failed-title">
    <h2 id="jarek-failed-title">Jarek — brakujące / błędne tłumaczenia</h2>
    <p>Tylko zapisane failed FR Jarka; translated i reviewed są wykluczone. Podgląd nie wywołuje Google ani nie zapisuje danych. To osobne małe recovery, bez resetu lub przesuwania kursora pełnego runu.</p>
    <div class="grid">
        <div class="metric"><span>Pozostałe failed (cały Jarek)</span><strong id="jarek-failed-count">{{ $jarekFailedReport['total_failed'] }}</strong></div>
        <div class="metric"><span>Naprawiono w tym retry</span><strong id="jarek-failed-fixed">{{ $jarekFailedStatus['technical_retry_translated'] ?? 0 }}</strong></div>
        <div class="metric"><span>Nieudane w tym retry / do kontroli</span><strong id="jarek-failed-manual">{{ $jarekFailedStatus['technical_retry_failed'] ?? 0 }}</strong></div>
    </div>
    <label for="jarek-failed-filter">Zakres ponowienia</label>
    <select id="jarek-failed-filter"><option value="all">Wszystkie failed Jarka</option><option value="technical_code_changed">Tylko technical_code_changed</option><option value="google_translate_failed">Tylko google_translate_failed — osobne retry</option></select>
    <button type="button" class="button" id="jarek-failed-preview">Sprawdź brakujące tłumaczenia Jarka</button>
    <pre id="jarek-failed-report">{{ json_encode($jarekFailedReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    <p>Apply może generować koszt Google API. Ochrona kodów pozostaje włączona; uszkodzone odpowiedzi pozostają failed.</p>
    <label for="jarek-failed-confirm">Wpisz <code>TRANSLATE-MISSING-JAREK-FR</code></label>
    <input id="jarek-failed-confirm" autocomplete="off" spellcheck="false">
    <button type="button" class="button primary" id="jarek-failed-start" disabled>Przetłumacz tylko brakujące/błędne</button>
    <div class="row">
        <button type="button" class="button" id="jarek-failed-pause">Pauza</button>
        <button type="button" class="button" id="jarek-failed-resume">Wznów recovery</button>
        <button type="button" class="button" id="jarek-failed-stop">Stop</button>
        <button type="button" class="button" id="jarek-failed-refresh">Odśwież pozostałe błędy</button>
    </div>
    <p id="jarek-failed-message" role="status">Po powrocie kliknij Wznów recovery; wejście na stronę nie uruchamia Google.</p>
    <pre id="jarek-failed-status">{{ json_encode($jarekFailedStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
</section>
<script>
(() => {
    'use strict';
    const root = document.getElementById('jarek-failed-section');
    const el = suffix => root.querySelector('#jarek-failed-' + suffix);
    const url = @json(route('admin.tools.storefront.fr-translations.jarek-failed', [], false));
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let run = @json($jarekFailedStatus), previewId = null, enabled = false, busy = false, controlling = false, timer = null, version = 0;
    function render(report) {
        if (report) { el('count').textContent = report.total_failed; el('report').textContent = JSON.stringify(report, null, 2); }
        el('fixed').textContent = run.technical_retry_translated || 0;
        el('manual').textContent = report?.manual_review_count ?? (run.technical_retry_failed || 0);
        el('status').textContent = JSON.stringify(run, null, 2);
        const active = ['running','paused','stopped_on_error','failed'].includes(run.status);
        el('start').disabled = busy || active || !previewId || el('confirm').value !== 'TRANSLATE-MISSING-JAREK-FR';
        el('pause').disabled = controlling || run.status !== 'running';
        el('resume').disabled = busy || !active;
        el('stop').disabled = controlling || !active;
        el('preview').disabled = busy;
        el('refresh').disabled = busy;
    }
    async function request(action, write = false) {
        const control = action === 'pause' || action === 'stop';
        if ((busy && !control) || controlling) return;
        if (control) { controlling = true; enabled = false; clearTimeout(timer); } else busy = true;
        const operationVersion = ++version;
        render();
        try {
            const data = {action, filter: el('filter').value, run_id: run.run_id, preview_id: previewId, confirm: el('confirm').value};
            const response = await fetch(write ? url : url + '?' + new URLSearchParams({action: action || '', filter: data.filter}), {
                method: write ? 'POST' : 'GET', credentials: 'same-origin',
                headers: {'Accept':'application/json', ...(write ? {'Content-Type':'application/json','X-CSRF-TOKEN':csrf} : {})},
                ...(write ? {body:JSON.stringify(data)} : {})
            });
            const result = await response.json();
            if (!response.ok || !result.ok) { enabled = false; throw new Error(result.run?.error || 'Nie udało się wykonać akcji. Odśwież status.'); }
            if (operationVersion !== version && (result.run.revision || 0) < (run.revision || 0)) return;
            if ((result.run.revision || 0) >= (run.revision || 0) || !run.run_id || result.run.run_id !== run.run_id) run = result.run;
            if (action === 'preview') previewId = result.report.preview_id;
            if (action === 'start' || action === 'resume') { enabled = true; previewId = null; }
            if (action === 'pause' || action === 'stop' || run.status !== 'running') enabled = false;
            render(result.report);
            el('message').textContent = 'Status: ' + run.status + '. Pozostałe failed: ' + result.report.total_failed;
        } catch (error) { enabled = false; el('message').textContent = error.message; }
        finally {
            if (control) controlling = false; else busy = false;
            render(); clearTimeout(timer);
            if (enabled && run.status === 'running') timer = setTimeout(() => request('tick', true), 3000);
        }
    }
    ['start','pause','resume','stop'].forEach(action => el(action).addEventListener('click', () => request(action, true)));
    el('preview').addEventListener('click', () => request('preview'));
    el('refresh').addEventListener('click', () => request(''));
    el('confirm').addEventListener('input', () => render());
    el('filter').addEventListener('change', () => { previewId = null; render(); });
    render();
})();
</script>
