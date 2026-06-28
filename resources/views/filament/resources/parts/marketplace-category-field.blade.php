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
    <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3" for="{{ $fieldId }}-toggle">
        <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">Kategoria</span>
    </label>

    <div class="mt-2 flex min-h-10 items-center rounded-lg border border-gray-300 bg-white shadow-sm ring-1 ring-gray-950/10 dark:border-gray-600 dark:bg-gray-900 dark:ring-white/20" data-shared-category-input>
        <button type="button" class="min-w-0 flex-1 truncate px-3 py-2 text-left text-sm text-gray-950 dark:text-white" onclick="document.getElementById('{{ $fieldId }}-toggle').checked = true">
            {{ $category['value'] ?? 'Brak wybranej kategorii' }}
        </button>
        <label for="{{ $fieldId }}-toggle" class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-r-lg border-l border-gray-200 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800" title="Wybierz kategorię z drzewa {{ $labels[$key] }}" data-category-drawer-trigger>
            ☰
        </label>
    </div>

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
