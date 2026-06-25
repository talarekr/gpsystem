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
        selectedName: '',
        isSaving: false,
        init() {
            this.$nextTick(() => this.mountActionsInModalFooter());
        },
        mountActionsInModalFooter() {
            const actions = this.$refs.actions;
            const modal = this.$root.closest('.fi-modal-window');
            const footer = modal?.querySelector('.fi-modal-footer');

            if (! actions || ! footer || footer.contains(actions)) {
                return;
            }

            footer.prepend(actions);
            actions.classList.add('is-in-modal-footer');
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
        selectedCategory() {
            return this.categories.find((category) => String(category.id) === String(this.selectedId));
        },
        nameFor(id) {
            return this.categories.find((category) => String(category.id) === String(id))?.path || '';
        },
        breadcrumb() {
            return this.stack.map((id) => this.categories.find((category) => String(category.id) === String(id))).filter(Boolean);
        },
        open(category) {
            if (! category.has_children) {
                return;
            }

            this.stack.push(category.id);
            this.currentParent = category.id;
        },
        choose(category) {
            const id = category?.id ?? category;

            this.selectedId = id;
            this.selectedName = this.nameFor(id);
        },
        saveSelectedCategory() {
            if (! this.selectedId || this.isSaving) {
                return;
            }

            this.isSaving = true;

            this.$wire.setPartCategoryFromPicker(this.selectedId)
                .finally(() => {
                    this.isSaving = false;
                });
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

    <div class="gps-category-picker__selected" x-show="selectedId" x-cloak>
        <strong>Wybrano:</strong>
        <span x-text="selectedName || selectedCategory()?.name"></span>
    </div>

    <template x-if="search.trim().length >= 2">
        <div class="gps-category-picker__section">
            <p class="gps-category-picker__hint">Wyniki wyszukiwania</p>

            <template x-for="category in searchResults()" :key="`search-${category.id}`">
                <button type="button" class="gps-category-picker__row" x-bind:class="{ 'is-selected': String(selectedId) === String(category.id) }" x-on:click="choose(category)">
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
            <div
                role="button"
                tabindex="0"
                class="gps-category-picker__row"
                x-bind:class="{ 'is-selected': String(selectedId) === String(category.id) }"
                x-on:click="choose(category)"
                x-on:keydown.enter.prevent="choose(category)"
                x-on:keydown.space.prevent="choose(category)"
            >
                <span>
                    <strong x-text="category.name"></strong>
                </span>
                <button
                    type="button"
                    class="gps-category-picker__arrow"
                    x-show="category.has_children"
                    x-on:click.stop="open(category)"
                    x-bind:aria-label="`Pokaż podkategorie: ${category.name}`"
                >›</button>
                <span class="gps-category-picker__select-label" x-show="! category.has_children">Wybierz</span>
            </div>
        </template>

        <p class="gps-category-picker__empty" x-show="currentChildren().length === 0">Brak podkategorii.</p>
    </div>

    <div class="gps-category-picker__actions" x-ref="actions">
        <button
            type="button"
            class="fi-btn fi-btn-size-md fi-color-primary"
            x-bind:disabled="! selectedId || isSaving"
            x-on:click="saveSelectedCategory()"
        >
            <span x-show="! isSaving">Ustaw kategorię</span>
            <span x-show="isSaving">Zapisywanie...</span>
        </button>
        <p class="gps-category-picker__empty" x-show="! selectedId">Wybierz kategorię przed zapisaniem.</p>
    </div>
</div>
