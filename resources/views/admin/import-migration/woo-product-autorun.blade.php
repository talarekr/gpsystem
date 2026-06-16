<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Autorunner importu Woo</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; line-height: 1.5; color: #111827; }
        .panel { max-width: 920px; border: 1px solid #d1d5db; border-radius: 12px; padding: 1.25rem; background: #fff; }
        .warning { background: #fffbeb; border: 1px solid #f59e0b; border-radius: 10px; padding: 1rem; margin-bottom: 1rem; }
        .status { background: #f3f4f6; border-radius: 10px; padding: 1rem; margin: 1rem 0; }
        dl { display: grid; grid-template-columns: minmax(180px, 280px) 1fr; gap: .5rem 1rem; }
        dt { font-weight: 700; color: #374151; }
        dd { margin: 0; }
        button, a.button { display: inline-block; margin: .25rem .35rem .25rem 0; padding: .55rem .9rem; border-radius: 8px; border: 1px solid #9ca3af; background: #f9fafb; color: #111827; text-decoration: none; cursor: pointer; }
        button.primary { background: #2563eb; border-color: #1d4ed8; color: #fff; }
        button.danger { background: #fee2e2; border-color: #f87171; }
        button:disabled { opacity: .55; cursor: not-allowed; }
        code { background: #f3f4f6; padding: .1rem .25rem; border-radius: 4px; }
        #lastMessage { white-space: pre-wrap; }
    </style>
</head>
<body>
    <main class="panel">
        <h1>Autorunner importu Woo</h1>
        <p>Ta strona wykonuje lekkie requesty HTTP do <code>next-many</code>. Nie uruchamia się automatycznie — kliknij <strong>Start</strong>, gdy jesteś gotowy.</p>

        @if (($run['mode'] ?? '') === \App\Services\ImportMigration\WooProductImport::MODE_CREATE_ONLY)
            <div class="warning">
                <strong>Ten tryb zapisuje produkty do bazy.</strong> Upewnij się, że dry_run został wcześniej sprawdzony.
            </div>
        @endif

        <div>
            <button id="startButton" type="button" class="primary">Start</button>
            <button id="pauseButton" type="button" class="danger" disabled>Pauza</button>
            <button id="stepButton" type="button">Wykonaj jeden krok</button>
            <a class="button" href="{{ $importUrl }}">Wróć do importu</a>
        </div>

        <section class="status" aria-live="polite">
            <dl>
                <dt>Run ID</dt><dd><code id="runId">{{ $run['run_id'] }}</code></dd>
                <dt>Mode</dt><dd id="mode">{{ $run['mode'] }}</dd>
                <dt>Status</dt><dd id="status">{{ $run['status'] }}</dd>
                <dt>Batch size</dt><dd id="batchSize">{{ $run['batch_size'] }}</dd>
                <dt>Processed rows</dt><dd id="processedRows">{{ $run['total_processed_rows'] }}</dd>
                <dt>Current CSV row</dt><dd id="currentRow">{{ $run['current_row'] }}</dd>
                <dt>Created count</dt><dd id="createdCount">{{ $run['created_count'] }}</dd>
                <dt>Updated count</dt><dd id="updatedCount">{{ $run['updated_count'] }}</dd>
                <dt>Skipped count</dt><dd id="skippedCount">{{ $run['skipped_count'] }}</dd>
                <dt>Error count</dt><dd id="errorCount">{{ $run['error_count'] }}</dd>
                <dt>Ostatni komunikat</dt><dd id="lastMessage">Oczekiwanie na start.</dd>
                <dt>Licznik requestów</dt><dd id="requestCount">0</dd>
                <dt>Czas działania</dt><dd id="runtime">00:00:00</dd>
            </dl>
        </section>
    </main>

    <script>
        const statusUrl = @json($statusUrl);
        const nextManyUrl = @json($nextManyUrl);
        const logUrl = @json($logUrl);
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const delayMs = 750;
        const significantErrorIncrease = 10;
        let shouldRun = false;
        let isRunningRequest = false;
        let requestCount = 0;
        let startedAt = null;
        let runtimeTimer = null;
        let lastErrorCount = {{ (int) $run['error_count'] }};

        const fields = {
            mode: document.getElementById('mode'), status: document.getElementById('status'), batchSize: document.getElementById('batchSize'),
            processedRows: document.getElementById('processedRows'), currentRow: document.getElementById('currentRow'), createdCount: document.getElementById('createdCount'),
            updatedCount: document.getElementById('updatedCount'), skippedCount: document.getElementById('skippedCount'), errorCount: document.getElementById('errorCount'),
            lastMessage: document.getElementById('lastMessage'), requestCount: document.getElementById('requestCount'), runtime: document.getElementById('runtime')
        };
        const startButton = document.getElementById('startButton');
        const pauseButton = document.getElementById('pauseButton');
        const stepButton = document.getElementById('stepButton');

        function updateButtons() {
            startButton.disabled = shouldRun || isRunningRequest;
            pauseButton.disabled = !shouldRun;
            stepButton.disabled = shouldRun || isRunningRequest;
        }

        function render(data) {
            if (!data) return;
            fields.mode.textContent = data.mode ?? '';
            fields.status.textContent = data.status ?? '';
            fields.batchSize.textContent = data.batch_size ?? '';
            fields.processedRows.textContent = data.total_processed_rows ?? '';
            fields.currentRow.textContent = data.current_row ?? '';
            fields.createdCount.textContent = data.created_count ?? '';
            fields.updatedCount.textContent = data.updated_count ?? '';
            fields.skippedCount.textContent = data.skipped_count ?? '';
            fields.errorCount.textContent = data.error_count ?? '';
            fields.lastMessage.textContent = data.message || data.last_error || data.stop_reason || 'OK';
        }

        function updateRuntime() {
            if (!startedAt) return;
            const seconds = Math.floor((Date.now() - startedAt) / 1000);
            const h = String(Math.floor(seconds / 3600)).padStart(2, '0');
            const m = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
            const s = String(seconds % 60).padStart(2, '0');
            fields.runtime.textContent = `${h}:${m}:${s}`;
        }

        async function logEvent(event, message = '') {
            try {
                await fetch(logUrl, {
                    method: 'POST',
                    headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
                    body: JSON.stringify({event, message})
                });
            } catch (e) {}
        }

        async function loadStatus() {
            const response = await fetch(statusUrl, {headers: {'Accept': 'application/json'}});
            const data = await response.json();
            render(data);
            lastErrorCount = Number(data.error_count || 0);
        }

        function stop(reason, event = 'pause') {
            shouldRun = false;
            fields.lastMessage.textContent = reason;
            updateButtons();
            logEvent(event, reason);
        }

        async function runOneRequest(isManualStep = false) {
            if (isRunningRequest) return;
            isRunningRequest = true;
            updateButtons();

            try {
                const response = await fetch(nextManyUrl + '?json=1', {
                    method: 'POST',
                    headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken}
                });
                if (!response.ok) {
                    stop(`Błąd HTTP ${response.status} z next-many.`, 'request_error');
                    return;
                }

                const data = await response.json();
                requestCount += 1;
                fields.requestCount.textContent = requestCount;
                render(data);

                const newErrorCount = Number(data.error_count || 0);
                if (data.status === 'finished') return stop('Import zakończony.', 'completed');
                if (data.status === 'failed') return stop(data.message || 'Import zakończony błędem.', 'failed');
                if (data.ok === false) return stop(data.message || 'next-many zwrócił ok=false.', 'failed');
                if (newErrorCount - lastErrorCount >= significantErrorIncrease) return stop('Autorunner zatrzymany: error_count wzrósł znacząco.', 'failed');
                lastErrorCount = newErrorCount;
                if (isManualStep) await logEvent('step', 'Wykonano jeden krok autorunnera.');
            } catch (error) {
                stop(`Fetch exception: ${error.message}`, 'request_error');
            } finally {
                isRunningRequest = false;
                updateButtons();
            }
        }

        async function loop() {
            while (shouldRun) {
                await runOneRequest(false);
                if (!shouldRun) break;
                await new Promise(resolve => setTimeout(resolve, delayMs));
            }
        }

        startButton.addEventListener('click', async () => {
            if (shouldRun) return;
            shouldRun = true;
            startedAt = startedAt || Date.now();
            runtimeTimer = runtimeTimer || setInterval(updateRuntime, 1000);
            await logEvent('start', 'Autorunner wystartował po ręcznym kliknięciu Start.');
            updateButtons();
            loop();
        });

        pauseButton.addEventListener('click', () => stop('Pauza kliknięta przez użytkownika.', 'pause'));
        stepButton.addEventListener('click', async () => {
            startedAt = startedAt || Date.now();
            runtimeTimer = runtimeTimer || setInterval(updateRuntime, 1000);
            await runOneRequest(true);
        });

        loadStatus().catch(error => {
            fields.lastMessage.textContent = `Nie udało się odczytać statusu: ${error.message}`;
            logEvent('request_error', fields.lastMessage.textContent);
        }).finally(updateButtons);
    </script>
</body>
</html>
