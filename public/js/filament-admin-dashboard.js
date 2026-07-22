(function () {
    const storageKey = 'gps_shop_event_sound_enabled';

    function playTestBeep() {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        const context = new AudioContext();
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(880, context.currentTime);
        gain.gain.setValueAtTime(0.0001, context.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.12, context.currentTime + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.16);
        oscillator.connect(gain);
        gain.connect(context.destination);
        oscillator.start();
        oscillator.stop(context.currentTime + 0.18);
        oscillator.addEventListener('ended', function () { context.close(); });
    }

    function initShopEventSoundToggle() {
        document.querySelectorAll('[data-gps-shop-event-sound-toggle]:not([data-gps-spa-bound])').forEach(function (toggle) {
            toggle.dataset.gpsSpaBound = '1';
            toggle.checked = localStorage.getItem(storageKey) === '1';
            toggle.addEventListener('change', function () {
                localStorage.setItem(storageKey, toggle.checked ? '1' : '0');
                if (toggle.checked) playTestBeep();
            });
        });
    }

    function initLocalSaleModal() {
        const modal = document.querySelector('[data-gps-local-sale-modal]');
        const form = document.querySelector('[data-gps-local-sale-form]');
        if (!modal || !form || form.dataset.gpsSpaBound) return;
        form.dataset.gpsSpaBound = '1';

        const search = form.querySelector('[data-gps-local-sale-search]');
        const results = form.querySelector('[data-gps-local-sale-results]');
        const selected = form.querySelector('[data-gps-local-sale-selected]');
        const partId = form.querySelector('[data-gps-local-sale-part-id]');
        const amount = form.querySelector('[data-gps-local-sale-amount]');
        const alertBox = form.querySelector('[data-gps-local-sale-alert]');
        let timer = null;

        function csrf() { return form.querySelector('input[name="_token"]')?.value || document.querySelector('meta[name="csrf-token"]')?.content || ''; }
        function showAlert(message, type) { alertBox.textContent = message; alertBox.className = 'gps-local-sale-alert gps-local-sale-alert--' + (type || 'error'); alertBox.hidden = false; }
        function clearAlert() { alertBox.hidden = true; alertBox.textContent = ''; }
        function resetForm() { form.reset(); partId.value = ''; results.hidden = true; results.innerHTML = ''; selected.hidden = true; selected.innerHTML = ''; clearAlert(); }
        function openModal() { resetForm(); modal.hidden = false; document.body.classList.add('gps-local-sale-open'); setTimeout(() => search.focus(), 50); }
        function closeModal() { modal.hidden = true; document.body.classList.remove('gps-local-sale-open'); }
        function escapeHtml(value) { return String(value).replace(/[&<>'"]/g, (char) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'}[char])); }
        function renderPart(part) {
            const img = part.image || part.thumbnail;
            const location = part.storage_location || 'Brak lokalizacji';
            const details = ['Magazyn: ' + location, part.part_number || '—'].filter(Boolean).join(' · ');
            return '<button type="button" class="gps-local-sale-result" data-part-id="' + part.id + '">' +
                (img ? '<img src="' + escapeHtml(img) + '" alt="">' : '<span class="gps-local-sale-result__placeholder">GPS</span>') +
                '<span class="gps-local-sale-result__main"><span class="gps-local-sale-result__title">' + escapeHtml(part.name || 'Bez nazwy') + '</span>' +
                '<small>' + escapeHtml(details) + '</small></span>' +
                '<span class="gps-local-sale-result__meta"><span class="gps-local-sale-result__price">' + escapeHtml(part.price || 'brak ceny') + '</span><span class="gps-local-sale-result__status">' + escapeHtml(part.status || '—') + '</span></span>' +
                '</button>';
        }
        function selectPart(part) {
            if (['sold', 'archived'].includes(part.status_value) || Number(part.quantity) <= 0) { showAlert('Ta część nie jest dostępna do sprzedaży.', 'error'); return; }
            partId.value = part.id;
            if (part.price_value !== null && part.price_value !== undefined) amount.value = Number(part.price_value).toFixed(2);
            const location = part.storage_location || 'Brak lokalizacji';
            const partNumber = part.part_number || '—';
            search.value = part.name || 'Bez nazwy';
            selected.innerHTML = '<span class="gps-local-sale-selected__main"><strong>Wybrano: ' + escapeHtml(part.name || 'Bez nazwy') + '</strong><span>Magazyn: ' + escapeHtml(location) + ' · ' + escapeHtml(partNumber) + '</span></span><span class="gps-local-sale-selected__price">' + escapeHtml(part.price || 'brak ceny') + '</span>';
            selected.hidden = false; results.hidden = true; clearAlert();
        }
        function doSearch() {
            const q = search.value.trim(); partId.value = ''; selected.hidden = true;
            if (q.length < 3) { results.hidden = true; results.innerHTML = ''; return; }
            fetch('/admin/search/parts?q=' + encodeURIComponent(q), {headers: {'Accept': 'application/json'}})
                .then((r) => r.json()).then((json) => {
                    const parts = json.data || [];
                    results.innerHTML = parts.length ? parts.map(renderPart).join('') : '<div class="gps-local-sale-results__empty">Brak wyników.</div>';
                    results.hidden = false;
                    parts.forEach((part) => {
                        const button = results.querySelector('[data-part-id="' + part.id + '"]');
                        if (button) button.addEventListener('click', () => selectPart(part), {once: true});
                    });
                });
        }

        document.querySelectorAll('[data-gps-local-sale-open]:not([data-gps-spa-bound])').forEach((button) => { button.dataset.gpsSpaBound = '1'; button.addEventListener('click', openModal); });
        modal.querySelectorAll('[data-gps-local-sale-close]:not([data-gps-spa-bound])').forEach((button) => { button.dataset.gpsSpaBound = '1'; button.addEventListener('click', closeModal); });
        search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(doSearch, 300); });
        form.addEventListener('submit', function (event) {
            event.preventDefault(); clearAlert();
            if (!partId.value) { showAlert('Wybierz część z listy wyników.', 'error'); return; }
            const submit = form.querySelector('button[type="submit"]'); submit.disabled = true;
            fetch(form.action, {method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf()}, body: new FormData(form)})
                .then(async (response) => { const json = await response.json().catch(() => ({})); if (!response.ok) throw new Error((json.errors ? Object.values(json.errors).flat()[0] : null) || json.message || 'Nie udało się zapisać sprzedaży lokalnej.'); showAlert(json.message || 'Sprzedaż lokalna została zapisana, a część zdjęta ze stanu.', 'success'); setTimeout(() => window.location.reload(), 900); })
                .catch((error) => showAlert(error.message || 'Nie udało się zapisać sprzedaży lokalnej.', 'error'))
                .finally(() => { submit.disabled = false; });
        });
    }

    function closeTransientState() { document.body.classList.remove('gps-local-sale-open'); }
    function initGpsAdminDashboard() { initShopEventSoundToggle(); initLocalSaleModal(); }

    document.addEventListener('keydown', (event) => {
        const modal = document.querySelector('[data-gps-local-sale-modal]');
        if (event.key === 'Escape' && modal && !modal.hidden) { modal.hidden = true; closeTransientState(); }
    });
    document.addEventListener('livewire:navigating', closeTransientState);
    document.addEventListener('livewire:navigated', initGpsAdminDashboard);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initGpsAdminDashboard, {once: true});
    else initGpsAdminDashboard();
})();
