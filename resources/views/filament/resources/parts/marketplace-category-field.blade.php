@php
    $channel = $mappingChannels[$key];
    $fieldId = 'marketplace-category-drawer-'.str_replace(['_', '.'], '-', $channel).'-'.$part?->id;
    $pickerId = $fieldId.'-picker';
    $nodes = $tree->map(function ($node) use ($tree) {
        $parent = $node->parent_external_id ?? null;
        return [
            'id' => (string) $node->external_category_id,
            'parent_id' => filled($parent) ? (string) $parent : null,
            'name' => $node->name ?: ($node->full_path ?: $node->external_category_id),
            'path' => $node->full_path ?: ($node->name ?: $node->external_category_id),
            'full_slug_path' => $node->full_path,
            'has_children' => $tree->contains(fn ($candidate) => (string) ($candidate->parent_external_id ?? '') === (string) $node->external_category_id),
        ];
    })->values()->all();
@endphp

<div
    class="gps-marketplace-category-field fi-fo-field-wrp"
    data-category-chooser-field
    data-marketplace-category-chooser="{{ $key }}"
    data-category-drawer-root="{{ $fieldId }}"
    x-data="{ categoryDrawerOpen: false }"
>
    @include('filament.forms.category-field-shell', [
        'fieldId' => $fieldId,
        'value' => $category['value'] ?? null,
        'fallback' => 'Brak wybranej kategorii',
        'triggerTitle' => 'Wybierz kategorię z drzewa '.$labels[$key],
        'triggerAttributes' => [
            'x-on:click.prevent.stop' => 'categoryDrawerOpen = true',
            'x-bind:aria-expanded' => 'categoryDrawerOpen.toString()',
        ],
    ])

    <div
        id="{{ $fieldId }}-overlay"
        class="fixed inset-0 z-40 bg-gray-950/50"
        data-category-drawer-overlay
        x-cloak
        x-show="categoryDrawerOpen"
        x-on:click="categoryDrawerOpen = false"
    ></div>
    <aside
        id="{{ $fieldId }}"
        class="fixed inset-y-0 right-0 z-50 w-full max-w-xl flex-col bg-white p-6 shadow-xl dark:bg-gray-900 gps-category-picker-modal"
        data-category-drawer
        data-category-drawer-id="{{ $fieldId }}"
        data-marketplace-category-tree="{{ $channel }}"
        x-cloak
        x-show="categoryDrawerOpen"
        x-transition
        x-bind:class="categoryDrawerOpen ? 'flex' : 'hidden'"
        role="dialog"
        aria-modal="true"
        aria-labelledby="{{ $fieldId }}-heading"
    >
        <div class="mb-4 flex items-center justify-between gap-3">
            <h3 id="{{ $fieldId }}-heading" class="text-lg font-semibold text-gray-950 dark:text-white">Kategorie</h3>
            <button type="button" x-on:click.prevent.stop="categoryDrawerOpen = false" class="cursor-pointer rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">Zamknij</button>
        </div>
        @include('filament.forms.category-picker', [
            'categories' => $nodes,
            'suggestions' => [],
            'saveUrl' => route('tools.part-marketplace-category-mapping.store'),
            'hiddenFields' => ['part_id' => $part?->id, 'channel' => $channel],
            'saveField' => 'external_category_id',
            'pickerId' => $pickerId,
        ])
    </aside>
</div>
