@php
    $categories = $categories ?? [];
@endphp

<div
    class="gps-category-picker"
    x-data="{
        categories: @js($categories),
        currentParent: null,
        stack: [],
        search: '',
        selectedId: null,
        init() {
            this.selectedId = this.hiddenInput()?.value || null;
        },
        hiddenInput() {
            return this.$root.closest('form')?.querySelector('input[name$=\'[selected_category_id]\']') ?? null;
        },
        children(parentId = null) {
            return this.categories.filter((category) => category.parent_id === parentId);
        },
        currentChildren() {
            return this.children(this.currentParent);
        },
        currentCategory() {
            return this.categories.find((category) => String(category.id) === String(this.currentParent));
        },
        breadcrumb() {
            return this.stack.map((id) => this.categories.find((category) => String(category.id) === String(id))).filter(Boolean);
        },
        open(category) {
            if (category.has_children) {
                this.stack.push(category.id);
                this.currentParent = category.id;
                return;
            }

            this.choose(category.id);
        },
        choose(id) {
            this.selectedId = id;
            const input = this.hiddenInput();

            if (input) {
                input.value = id;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        },
        back() {
            this.stack.pop();
            this.currentParent = this.stack.length ? this.stack[this.stack.length - 1] : null;
        },
        resetTree() {
            this.stack = [];
            this.currentParent = null;
        },
        searchResults() {
            const term = this.search.trim().toLowerCase();

            if (term.length < 2) {
                return [];
            }

            return this.categories
                .filter((category) => (`${category.name} ${category.path} ${category.full_slug_path || ''}`).toLowerCase().includes(term))
                .sort((a, b) => Number(a.has_children) - Number(b.has_children) || a.path.localeCompare(b.path, 'pl'))
                .slice(0, 25);
        },
    }"
>
    <div class="gps-category-picker__search">
        <input
            type="search"
            x-model.debounce.200ms="search"
            placeholder="Szukaj kategorii..."
            class="gps-category-picker__search-input"
        >
    </div>

    <template x-if="search.trim().length >= 2">
        <div class="gps-category-picker__section">
            <p class="gps-category-picker__hint">Wyniki wyszukiwania</p>

            <template x-for="category in searchResults()" :key="`search-${category.id}`">
                <button type="button" class="gps-category-picker__row" x-bind:class="{ 'is-selected': String(selectedId) === String(category.id) }" x-on:click="choose(category.id)">
                    <span>
                        <strong x-text="category.name"></strong>
                        <small x-text="category.path"></small>
                    </span>
                    <span class="gps-category-picker__select-label">Wybierz</span>
                </button>
            </template>

            <p class="gps-category-picker__empty" x-show="searchResults().length === 0">Brak pasujących kategorii.</p>
        </div>
    </template>

    <div class="gps-category-picker__section" x-show="search.trim().length < 2">
        <div class="gps-category-picker__nav">
            <button type="button" class="gps-category-picker__back" x-show="currentParent !== null" x-on:click="back()">← Wstecz</button>
            <button type="button" class="gps-category-picker__crumb" x-show="currentParent !== null" x-on:click="resetTree()">Kategorie</button>
            <template x-for="category in breadcrumb()" :key="`crumb-${category.id}`">
                <span class="gps-category-picker__crumb-current" x-text="`/ ${category.name}`"></span>
            </template>
        </div>

        <template x-for="category in currentChildren()" :key="category.id">
            <button type="button" class="gps-category-picker__row" x-bind:class="{ 'is-selected': String(selectedId) === String(category.id) }" x-on:click="open(category)">
                <span>
                    <strong x-text="category.name"></strong>
                </span>
                <span class="gps-category-picker__arrow" x-text="category.has_children ? '›' : 'Wybierz'"></span>
            </button>
        </template>

        <p class="gps-category-picker__empty" x-show="currentChildren().length === 0">Brak podkategorii.</p>
    </div>
</div>
