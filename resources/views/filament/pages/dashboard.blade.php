<x-filament-panels::page>
    <livewire:admin.shop-events />

    <section class="gps-quick-actions" aria-label="Szybkie akcje obsługi">
        <div class="gps-quick-actions__grid">
            <button type="button" class="gps-quick-action" data-gps-local-sale-open>
                <span class="gps-quick-action__icon">↘</span>
                <strong>Sprzedaż lokalna</strong>
            </button>
            <a class="gps-quick-action" href="{{ $this->addPartUrl() }}" wire:navigate>
                <span class="gps-quick-action__icon">＋</span>
                <strong>Dodaj część</strong>
            </a>
            <a class="gps-quick-action" href="{{ $this->ordersUrl() }}" wire:navigate>
                <span class="gps-quick-action__icon">☷</span>
                <strong>Zamówienia</strong>
            </a>
        </div>
    </section>

    <livewire:admin.sales-analytics />

    <div class="gps-local-sale-modal" data-gps-local-sale-modal hidden>
        <div class="gps-local-sale-modal__backdrop" data-gps-local-sale-close></div>
        <div class="gps-local-sale-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="gps-local-sale-title">
            <form data-gps-local-sale-form action="{{ route('admin.local-sales.store') }}" method="post">
                @csrf
                <div class="gps-local-sale-modal__header">
                    <div>
                        <h2 id="gps-local-sale-title">Sprzedaż lokalna</h2>
                        <p>Zdejmij część ze stanu po sprzedaży na miejscu w biurze.</p>
                    </div>
                    <button type="button" class="gps-local-sale-modal__x" data-gps-local-sale-close aria-label="Zamknij">×</button>
                </div>
                <div class="gps-local-sale-modal__body">
                    <div class="gps-local-sale-alert" data-gps-local-sale-alert hidden></div>
                    <input type="hidden" name="part_id" data-gps-local-sale-part-id>
                    <input type="hidden" name="quantity" value="1">
                    <label class="gps-local-sale-field gps-local-sale-search">
                        <span>Część / numer części</span>
                        <input type="search" data-gps-local-sale-search placeholder="Wpisz min. 3 znaki: SKU, nazwę, numer części, OEM..." autocomplete="off">
                        <div class="gps-local-sale-results" data-gps-local-sale-results hidden></div>
                        <div class="gps-local-sale-selected" data-gps-local-sale-selected hidden></div>
                    </label>
                    <label class="gps-local-sale-field">
                        <span>Kwota sprzedaży <em>PLN</em></span>
                        <input type="number" name="amount" data-gps-local-sale-amount min="0.01" step="0.01" required>
                    </label>
                    <label class="gps-local-sale-field">
                        <span>Forma płatności</span>
                        <select name="payment_method" required>
                            <option value="cash">gotówka</option>
                            <option value="bank_transfer">przelew</option>
                        </select>
                    </label>
                    <label class="gps-local-sale-field">
                        <span>Notatka opcjonalna</span>
                        <textarea name="notes" rows="3" placeholder="np. sprzedane klientowi na miejscu"></textarea>
                    </label>
                </div>
                <div class="gps-local-sale-modal__footer">
                    <button type="button" class="gps-local-sale-button gps-local-sale-button--ghost" data-gps-local-sale-close>Anuluj</button>
                    <button type="submit" class="gps-local-sale-button gps-local-sale-button--primary">Zapisz i zdejmij ze stanu</button>
                </div>
            </form>
        </div>
    </div>

</x-filament-panels::page>
