const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const blade = fs.readFileSync('resources/views/admin/tools/storefront/_fr-jarek-failed.blade.php', 'utf8');
let script = blade.split('<script>')[1].split('</script>')[0]
    .replace(/@json\(route\([^\n]+\)\)/, JSON.stringify('/recovery'))
    .replace('@json($jarekFailedStatus)', JSON.stringify({status: 'idle', run_id: null}));
const elements = new Map();
const element = id => {
    if (!elements.has(id)) elements.set(id, {value: '', textContent: '', disabled: false, handlers: {}, addEventListener(event, fn) { this.handlers[event] = fn; }});
    return elements.get(id);
};
const root = {querySelector: selector => element(selector.slice(1))};
let requests = [], timers = [], deferred;
let run = {status: 'running', run_id: 'small-retry', revision: 1, technical_retry_translated: 0};
const report = {total_failed: 2, breakdown: {technical_code_changed: 1, google_translate_failed: 1}, manual_review_count: 0};
const context = {
    document: {getElementById: () => root, querySelector: () => ({content: 'fake-csrf'})},
    URLSearchParams, JSON, console,
    setTimeout: (fn, delay) => { timers.push({fn, delay}); return timers.length; }, clearTimeout: () => { timers = []; },
    fetch: async (url, options) => {
        requests.push({url, options});
        const data = options.body ? JSON.parse(options.body) : Object.fromEntries(new URLSearchParams(url.split('?')[1]));
        if (data.action === 'preview') return {ok: true, json: async () => ({ok: true, run: {status: 'idle', run_id: null}, report: {...report, preview_id: 'approved-failed-snapshot'}})};
        if (data.action === 'tick') return new Promise(resolve => { deferred = resolve; });
        if (data.action === 'pause') run = {...run, status: 'paused', revision: 3};
        return {ok: true, json: async () => ({ok: true, run, report})};
    }
};
vm.runInNewContext(script, context);
(async () => {
    assert.equal(requests.length, 0, 'Page entry must never start Google/retry');
    element('jarek-failed-filter').value = 'google_translate_failed';
    await element('jarek-failed-preview').handlers.click();
    assert.equal(requests[0].options.method, 'GET');
    assert.match(requests[0].url, /filter=google_translate_failed/);
    element('jarek-failed-confirm').value = 'TRANSLATE-MISSING-JAREK-FR';
    element('jarek-failed-confirm').handlers.input();
    assert.equal(element('jarek-failed-start').disabled, false);
    await element('jarek-failed-start').handlers.click();
    assert.equal(JSON.parse(requests[1].options.body).preview_id, 'approved-failed-snapshot');
    assert.equal(timers.length, 1);
    assert.equal(timers[0].delay, 3000);
    const pending = timers[0].fn();
    assert.equal(element('jarek-failed-pause').disabled, false, 'Pause must remain available during provider work');
    await element('jarek-failed-pause').handlers.click();
    deferred({ok: true, json: async () => ({ok: true, run: {...run, status: 'running', revision: 2}, report})});
    await pending;
    assert.equal(JSON.parse(element('jarek-failed-status').textContent).status, 'paused', 'A stale tick cannot undo Pause');
    assert.equal(timers.length, 0, 'Pause must stop the timer');
    console.log('OK: failed-only preview, explicit token, filter, bounded timer, in-flight Pause and stale response guard.');
})().catch(error => { console.error(error); process.exitCode = 1; });
