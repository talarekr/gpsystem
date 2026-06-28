@php
    $categories = $categories ?? [];
    $suggestions = $suggestions ?? [];
    $saveUrl = $saveUrl ?? null;
    $saveMethod = strtoupper((string) ($saveMethod ?? 'POST'));
    $hiddenFields = $hiddenFields ?? [];
    $saveField = $saveField ?? 'category_id';
    $pickerId = $pickerId ?? ('gps-category-picker-'.uniqid());
    $lazyLoadOnInit = $lazyLoadOnInit ?? false;
    $lazyChildrenUrl = $lazyChildrenUrl ?? null;
    $lazyChannel = $lazyChannel ?? null;
    $lazyParentKey = $lazyParentKey ?? 'parent_id';
    $lazySearchMinLength = $lazySearchMinLength ?? 2;
    $drawerId = $drawerId ?? null;
    $marketplaceSelectionChannel = $marketplaceSelectionChannel ?? null;
@endphp

@once
    <style>
        .fi-modal:has(.gps-category-picker-modal),
        .fi-modal:has(.gps-category-picker-modal) .fi-modal-wrapper,
        .fi-modal:has(.gps-category-picker-modal) .fi-modal-window-ctn {
            position: fixed !important;
            inset: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            max-height: 100vh !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .fi-modal:has(.gps-category-picker-modal) .fi-modal-window-ctn {
            display: flex !important;
            align-items: stretch !important;
            justify-content: flex-end !important;
        }

        .fi-modal-window.gps-category-picker-modal,
        .gps-category-picker-modal.fi-modal-window {
            display: flex !important;
            flex-direction: column !important;
            height: 100vh !important;
            max-height: 100vh !important;
            min-height: 0 !important;
        }

        @supports (height: 100dvh) {
            .fi-modal:has(.gps-category-picker-modal),
            .fi-modal:has(.gps-category-picker-modal) .fi-modal-wrapper,
            .fi-modal:has(.gps-category-picker-modal) .fi-modal-window-ctn,
            .fi-modal-window.gps-category-picker-modal,
            .gps-category-picker-modal.fi-modal-window {
                height: 100dvh !important;
                max-height: 100dvh !important;
            }
        }

        .fi-modal-window.gps-category-picker-modal .fi-modal-header,
        .gps-category-picker-modal.fi-modal-window .fi-modal-header {
            flex: 0 0 auto !important;
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

        .fi-modal-window.gps-category-picker-modal .fi-modal-footer,
        .gps-category-picker-modal.fi-modal-window .fi-modal-footer {
            display: none !important;
        }

        .fi-modal-window.gps-category-picker-modal .fi-modal-content,
        .gps-category-picker-modal.fi-modal-window .fi-modal-content {
            display: flex !important;
            flex: 1 1 auto !important;
            flex-direction: column !important;
            min-height: 0 !important;
            overflow-x: visible !important;
            overflow-y: visible !important;
        }

        .fi-modal-window.gps-category-picker-modal .fi-modal-content > form,
        .gps-category-picker-modal.fi-modal-window .fi-modal-content > form,
        .fi-modal-window.gps-category-picker-modal .fi-modal-content .fi-fo-component-ctn,
        .gps-category-picker-modal.fi-modal-window .fi-modal-content .fi-fo-component-ctn,
        .fi-modal-window.gps-category-picker-modal .fi-modal-content .fi-fo-field-wrp,
        .gps-category-picker-modal.fi-modal-window .fi-modal-content .fi-fo-field-wrp,
        .fi-modal-window.gps-category-picker-modal .fi-modal-content .fi-fo-field-wrp > div,
        .gps-category-picker-modal.fi-modal-window .fi-modal-content .fi-fo-field-wrp > div {
            display: flex !important;
            flex: 1 1 auto !important;
            flex-direction: column !important;
            min-height: 0 !important;
        }

        .gps-category-picker {
            display: flex !important;
            flex: 1 1 auto !important;
            flex-direction: column !important;
            min-height: 0 !important;
            height: 100% !important;
            max-height: 100% !important;
        }

        .gps-category-picker__search,
        .gps-category-picker__selected {
            flex: 0 0 auto !important;
        }

        .gps-category-picker__content {
            flex: 1 1 auto !important;
            min-height: 0 !important;
            overflow-y: auto !important;
            padding-bottom: 1rem !important;
        }

        .gps-category-picker__actions {
            flex: 0 0 auto !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 100% !important;
            margin-top: 0 !important;
            border-top: 1px solid rgb(229 231 235) !important;
            background: rgb(255 255 255) !important;
            padding: 1rem 0 0 !important;
        }

        .dark .gps-category-picker__actions {
            border-top-color: rgb(55 65 81) !important;
            background: rgb(17 24 39) !important;
        }

        .gps-category-picker__submit {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            min-height: 2.5rem !important;
            border: 1px solid rgb(37 99 235) !important;
            border-radius: 0.5rem !important;
            background: rgb(37 99 235) !important;
            padding: 0.5rem 1rem !important;
            color: #fff !important;
            font-weight: 600 !important;
            line-height: 1.25rem !important;
            text-decoration: none !important;
            box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05) !important;
            transition: background-color 150ms ease, border-color 150ms ease, box-shadow 150ms ease, opacity 150ms ease !important;
        }

        .gps-category-picker__submit:hover:not(:disabled) {
            border-color: rgb(29 78 216) !important;
            background: rgb(29 78 216) !important;
        }

        .gps-category-picker__submit:focus-visible {
            outline: 2px solid rgb(96 165 250) !important;
            outline-offset: 2px !important;
        }

        .gps-category-picker__submit:disabled {
            cursor: not-allowed !important;
            border-color: rgb(209 213 219) !important;
            background: rgb(229 231 235) !important;
            color: rgb(107 114 128) !important;
            opacity: 0.75 !important;
            box-shadow: none !important;
        }

        .gps-category-picker__suggestions {
            border-bottom: 1px solid rgb(229 231 235);
            margin-bottom: 0.75rem;
            padding-bottom: 0.75rem;
        }

        .gps-category-picker__score {
            color: rgb(37 99 235);
            font-size: 0.75rem;
            font-weight: 700;
        }
    </style>
@endonce

<div
    id="{{ $pickerId }}"
    class="gps-category-picker"
    x-data="{
        categories: @js($categories),
        suggestions: @js($suggestions),
        lazyChildrenUrl: @js($lazyChildrenUrl),
        lazyChannel: @js($lazyChannel),
        lazyLoadOnInit: @js($lazyLoadOnInit),
        loadedParents: {},
        childrenCache: {},
        loadingParents: {},
        inFlightChildren: {},
        isLoadingChildren: false,
        pendingParent: null,
        prefetchLimit: 12,
        prefetchConcurrency: 2,
        prefetchQueue: [],
        activePrefetches: 0,
        currentParent: null,
        stack: [],
        search: '',
        selectedId: null,
        selectedName: '',
        isSaving: false,
        drawerId: @js($drawerId),
        marketplaceSelectionChannel: @js($marketplaceSelectionChannel),
        init() {
            if (this.lazyLoadOnInit) {
                this.ensureChildren(null).then((children) => this.prefetchVisibleChildren(children || []));
            }
        },
        cacheKey(parentId = null) {
            const parentKey = parentId === null ? '__root__' : String(parentId);
            return `${this.lazyChannel || 'part'}:${parentKey}`;
        },
        mergeCategories(children = []) {
            const existingIds = new Set(this.categories.map((category) => String(category.id)));
            children.forEach((category) => {
                if (! existingIds.has(String(category.id))) {
                    this.categories.push(category);
                    existingIds.add(String(category.id));
                }
            });
        },
        async ensureChildren(parentId = null, options = {}) {
            if (! this.lazyChildrenUrl) {
                return this.children(parentId);
            }

            const key = this.cacheKey(parentId);
            if (this.childrenCache[key]) {
                return this.childrenCache[key];
            }
            if (this.inFlightChildren[key]) {
                return this.inFlightChildren[key];
            }

            const showLoading = options.showLoading !== false;
            if (showLoading) {
                this.loadingParents[key] = true;
                this.isLoadingChildren = true;
            }

            const url = new URL(this.lazyChildrenUrl, window.location.origin);
            if (this.lazyChannel) {
                url.searchParams.set('channel', this.lazyChannel);
            }
            if (parentId !== null) {
                url.searchParams.set(this.lazyChannel ? 'parent_external_category_id' : 'parent_id', parentId);
            }

            this.inFlightChildren[key] = fetch(url.toString(), { headers: { 'Accept': 'application/json' } })
                .then(async (response) => {
                    if (! response.ok) {
                        return [];
                    }

                    const payload = await response.json();
                    if (payload.search === true) {
                        return payload.children || [];
                    }

                    const children = payload.children || [];
                    this.childrenCache[key] = children;
                    this.mergeCategories(children);
                    this.loadedParents[key] = true;

                    return children;
                })
                .finally(() => {
                    delete this.inFlightChildren[key];
                    delete this.loadingParents[key];
                    this.isLoadingChildren = Object.keys(this.loadingParents).length > 0;
                });

            return this.inFlightChildren[key];
        },
        children(parentId = null) {
            const key = this.cacheKey(parentId);
            if (this.childrenCache[key]) {
                return this.childrenCache[key];
            }

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

            const key = this.cacheKey(category.id);
            if (this.childrenCache[key]) {
                this.stack.push(category.id);
                this.currentParent = category.id;
                this.prefetchVisibleChildren(this.childrenCache[key]);
                return;
            }

            this.pendingParent = category.id;
            this.ensureChildren(category.id, { showLoading: true }).then((children) => {
                if (String(this.pendingParent) !== String(category.id)) {
                    return;
                }

                this.stack.push(category.id);
                this.currentParent = category.id;
                this.pendingParent = null;
                this.prefetchVisibleChildren(children || []);
            });
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

            if (this.marketplaceSelectionChannel) {
                const selected = this.selectedCategory();

                this.$wire.setMarketplaceCategoryFromPicker(
                    this.marketplaceSelectionChannel,
                    this.selectedId,
                    selected?.name || this.selectedName,
                    selected?.path || this.selectedName
                ).then((saved) => {
                    if (saved === true) {
                        this.closeCategoryPicker();
                        this.selectedId = null;
                        this.selectedName = '';
                    }
                }).finally(() => {
                    this.isSaving = false;
                });

                return;
            }

            this.$wire.setPartCategoryFromPicker(this.selectedId)
                .then((saved) => {
                    if (saved === true) {
                        this.closeCategoryPicker();
                        this.selectedId = null;
                        this.selectedName = '';
                    }
                })
                .finally(() => {
                    this.isSaving = false;
                });
        },
        selectSuggestion(suggestion) {
            if (! suggestion?.category_id || this.isSaving) {
                return;
            }

            this.isSaving = true;

            this.$wire.selectSuggestedPartCategory(suggestion.category_id)
                .then((saved) => {
                    if (saved === true) {
                        this.closeCategoryPicker();
                        this.selectedId = null;
                        this.selectedName = '';
                    }
                })
                .finally(() => {
                    this.isSaving = false;
                });
        },
        closeCategoryPicker() {
            const filamentFormComponentActionModalId = 'form-component-action';

            this.$dispatch('close-category-drawer', { drawerId: this.drawerId });

            if (typeof categoryDrawerOpen !== 'undefined') {
                categoryDrawerOpen = false;
            }

            // The main part category picker is a Filament form-component action slide-over.
            // Its open state, backdrop and focus trap are owned by Filament's modal action,
            // so let Filament perform the full unmount/close cycle before emitting a final
            // close-modal event on the next frame. Dispatching close-modal before the
            // Livewire unmount completed removed the panel content while leaving Filament's
            // modal overlay/focus state active until the next user focus event.
            const finishFilamentClose = () => {
                requestAnimationFrame(() => {
                    this.$dispatch('close-modal', { id: filamentFormComponentActionModalId });
                    window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: filamentFormComponentActionModalId } }));
                });
            };

            if (typeof this.$wire.unmountFormComponentAction === 'function') {
                Promise.resolve(this.$wire.unmountFormComponentAction(true, true))
                    .then(finishFilamentClose);

                return;
            }

            finishFilamentClose();
        },
        back() {
            this.stack.pop();
            this.currentParent = this.stack.length ? this.stack[this.stack.length - 1] : null;
        },
        resetTree() {
            this.stack = [];
            this.currentParent = null;
            this.pendingParent = null;
            this.ensureChildren(null).then((children) => this.prefetchVisibleChildren(children || []));
        },
        prefetchVisibleChildren(categories = this.currentChildren()) {
            if (! this.lazyChildrenUrl || this.search.trim().length >= 2) {
                return;
            }

            categories
                .filter((category) => category?.has_children)
                .slice(0, this.prefetchLimit)
                .forEach((category) => this.queuePrefetch(category.id));

            this.runPrefetchQueue();
        },
        queuePrefetch(parentId) {
            const key = this.cacheKey(parentId);
            if (this.childrenCache[key] || this.inFlightChildren[key] || this.prefetchQueue.some((item) => String(item) === String(parentId))) {
                return;
            }

            this.prefetchQueue.push(parentId);
        },
        runPrefetchQueue() {
            while (this.activePrefetches < this.prefetchConcurrency && this.prefetchQueue.length > 0) {
                const parentId = this.prefetchQueue.shift();
                this.activePrefetches++;
                this.ensureChildren(parentId, { showLoading: false })
                    .finally(() => {
                        this.activePrefetches--;
                        this.runPrefetchQueue();
                    });
            }
        },
        async fetchSearchResults() {
            if (! this.lazyChildrenUrl) {
                return;
            }

            const term = this.search.trim();
            if (term.length < 2) {
                return;
            }

            const url = new URL(this.lazyChildrenUrl, window.location.origin);
            if (this.lazyChannel) {
                url.searchParams.set('channel', this.lazyChannel);
            }
            url.searchParams.set('q', term);

            const response = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
            if (! response.ok) {
                return;
            }

            const payload = await response.json();
            const existingIds = new Set(this.categories.map((category) => String(category.id)));
            (payload.children || []).forEach((category) => {
                if (! existingIds.has(String(category.id))) {
                    this.categories.push(category);
                    existingIds.add(String(category.id));
                }
            });
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
    x-effect="if (typeof categoryDrawerOpen !== 'undefined' && categoryDrawerOpen) ensureChildren(null).then((children) => prefetchVisibleChildren(children || [])); if (search.trim().length >= 2) fetchSearchResults()"
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

    <div class="gps-category-picker__content">
        <div class="gps-category-picker__section gps-category-picker__suggestions" x-show="suggestions.length > 0" x-cloak>
            <p class="gps-category-picker__hint">Proponowane</p>

            <template x-for="suggestion in suggestions.slice(0, 5)" :key="`suggested-${suggestion.category_id}`">
                <button type="button" class="gps-category-picker__row" x-on:click="selectSuggestion(suggestion)">
                    <span>
                        <strong x-text="suggestion.category_name"></strong>
                        <small x-text="suggestion.category_path"></small>
                        <small x-text="`Dopasowania: ${suggestion.matched_parts_count}; frazy: ${(suggestion.matched_terms || []).join(', ')}`"></small>
                    </span>
                    <span class="gps-category-picker__score" x-text="Math.round(suggestion.score)"></span>
                </button>
            </template>
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

            <p class="gps-category-picker__empty" x-show="pendingParent !== null || isLoadingChildren">Ładowanie podkategorii...</p>
            <p class="gps-category-picker__empty" x-show="pendingParent === null && ! isLoadingChildren && currentChildren().length === 0">Brak podkategorii.</p>
        </div>
    </div>

    <div class="gps-category-picker__actions" x-ref="actions">
        <button
            type="button"
            class="gps-category-picker__submit fi-btn fi-btn-size-md fi-color-primary"
            x-bind:disabled="! canSave() || isSaving"
            x-on:click="saveSelectedCategory()"
        >
            <span x-show="! isSaving">Wybierz</span>
            <span x-show="isSaving">Zapisywanie...</span>
        </button>
    </div>
</div>
