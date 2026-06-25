@php
    $categories = $categories ?? [];
@endphp

@once
    <style>
        .fi-modal-window.gps-category-picker-modal .fi-modal-header,
        .gps-category-picker-modal.fi-modal-window .fi-modal-header {
            position: relative !important;
            align-items: center !important;
            padding-right: 3rem !important;
        }

        .fi-modal-window.gps-category-picker-modal .fi-modal-header :where(.fi-modal-close-btn, .fi-modal-close-button, [aria-label="Close"], [aria-label="Zamknij"]),
        .gps-category-picker-modal.fi-modal-window .fi-modal-header :where(.fi-modal-close-btn, .fi-modal-close-button, [aria-label="Close"], [aria-label="Zamknij"]) {
            position: absolute !important;
            top: 50% !important;
            right: 1rem !important;
            bottom: auto !important;
            margin: 0 !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            line-height: 1 !important;
            transform: translateY(calc(-50% - 3px)) !important;
        }
    </style>
@endonce

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
            if (! category?.has_children) {
                return;
            }

            this.stack.push(category.id);
            this.currentParent = category.id;
        },
        openFromSearch(category) {
            if (! category?.has_children) {
                return;
            }

            this.stack = this.ancestorIds(category);
            this.currentParent = category.id;
            this.search = '';
        },
        ancestorIds(category) {
            const ids = [];
            let parentId = category?.parent_id ?? null;

            while (parentId !== null) {
                const parent = this.categories.find((item) => String(item.id) === String(parentId));

                if (! parent) {
                    break;
                }

                ids.unshift(parent.id);
                parentId = parent.parent_id;
            }

            ids.push(category.id);

            return ids;
        },
        choose(category) {
            if (! category || category.has_children) {
                this.openFromSearch(category);
                return;
            }

            this.selectedId = category.id;
            this.selectedName = category.path || category.name;
        },
        activate(category) {
            if (category?.has_children) {
                this.open(category);
                return;
            }

            this.choose(category);
        },
        canSave() {
            return Boolean(this.selectedId && this.selectedCategory() && ! this.selectedCategory().has_children);
        },
        saveSelectedCategory() {
            if (! this.canSave() || this.isSaving) {
                return;
            }

            this.isSaving = true;

            this.$wire.setPartCategoryFromPicker(this.selectedId)
                .then(() => this.closePicker())
                .finally(() => {
                    this.isSaving = false;
                });
        },
        closePicker() {
            const modal = this.$root.closest('.fi-modal-window');
            const closeButton = modal?.querySelector('.fi-modal-close-btn, .fi-modal-close-button, [aria-label="Close"], [aria-label="Zamknij"]');

            closeButton?.click();
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

    <div class="gps-category-picker__selected" x-show="canSave()" x-cloak>
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
                    <span class="gps-category-picker__arrow" x-show="category.has_children" aria-hidden="true">›</span>
                    <span class="gps-category-picker__select-label" x-show="! category.has_children">Wybierz</span>
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
                x-on:click="activate(category)"
                x-on:keydown.enter.prevent="activate(category)"
                x-on:keydown.space.prevent="activate(category)"
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
            x-bind:disabled="! canSave() || isSaving"
            x-on:click="saveSelectedCategory()"
        >
            <span x-show="! isSaving">Wybierz</span>
            <span x-show="isSaving">Zapisywanie...</span>
        </button>
    </div>
</div>
