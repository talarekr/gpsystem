@php
    $fieldId = 'marketplace-category-drawer-'.$key.'-'.$part?->id;
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

<div class="gps-marketplace-category-field fi-fo-field-wrp" data-category-chooser-field data-marketplace-category-chooser="{{ $key }}">
    <fieldset class="gps-shared-category-field fi-input-wrp rounded-lg border border-gray-300 bg-white px-3 pb-1.5 pt-0 shadow-sm ring-1 ring-gray-950/10 transition duration-75 focus-within:border-primary-600 focus-within:ring-primary-600 dark:border-gray-600 dark:bg-gray-900 dark:ring-white/20 dark:focus-within:border-primary-500 dark:focus-within:ring-primary-500" data-shared-category-input>
        <legend class="gps-shared-category-field__legend px-1 text-xs font-medium leading-4 text-gray-500 dark:text-gray-400">Kategoria</legend>
        <div class="flex min-h-10 items-center gap-2">
            <button type="button" class="min-w-0 flex-1 truncate py-1.5 text-left text-sm text-gray-950 outline-none dark:text-white" onclick="document.getElementById('{{ $fieldId }}-toggle').checked = true">
                {{ $category['value'] ?? 'Brak wybranej kategorii' }}
            </button>
            <label for="{{ $fieldId }}-toggle" class="inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-gray-500 transition hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-600 dark:text-gray-400 dark:hover:text-gray-200" title="Wybierz kategorię z drzewa {{ $labels[$key] }}" data-category-drawer-trigger>
                ☰
            </label>
        </div>
    </fieldset>

    <input id="{{ $fieldId }}-toggle" type="checkbox" class="peer sr-only" data-category-drawer-toggle>
    <div class="fixed inset-0 z-40 hidden bg-gray-950/50 peer-checked:block" data-category-drawer-overlay>
        <label for="{{ $fieldId }}-toggle" class="absolute inset-0 cursor-pointer" aria-label="Zamknij wybór kategorii"></label>
    </div>
    <aside class="fixed inset-y-0 right-0 z-50 hidden w-full max-w-xl flex-col bg-white p-6 shadow-xl peer-checked:flex dark:bg-gray-900 gps-category-picker-modal" data-category-drawer data-marketplace-category-tree="{{ $mappingChannels[$key] }}">
        <div class="mb-4 flex items-center justify-between gap-3">
            <h3 class="text-lg font-semibold text-gray-950 dark:text-white">Kategorie</h3>
            <label for="{{ $fieldId }}-toggle" class="cursor-pointer rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">Zamknij</label>
        </div>
        @include('filament.forms.category-picker', [
            'categories' => $nodes,
            'suggestions' => [],
            'saveUrl' => route('tools.part-marketplace-category-mapping.store'),
            'hiddenFields' => ['part_id' => $part?->id, 'channel' => $mappingChannels[$key]],
            'saveField' => 'external_category_id',
            'pickerId' => $pickerId,
        ])
    </aside>
</div>
