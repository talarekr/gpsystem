@props([
    'fieldId',
    'heading' => 'Kategorie',
    'lazyChildrenUrl' => null,
    'lazyChannel' => null,
    'categories' => [],
    'suggestions' => [],
    'saveUrl' => null,
    'saveMethod' => 'POST',
    'hiddenFields' => [],
    'saveField' => 'category_id',
    'pickerId' => null,
    'treeAttribute' => null,
    'drawerId' => null,
])

@php
    $drawerId = $drawerId ?? $fieldId;
@endphp

<div
    id="{{ $fieldId }}-overlay"
    class="fixed inset-0 z-40 bg-gray-950/50 pointer-events-auto"
    data-category-drawer-overlay
    x-cloak
    x-show="categoryDrawerOpen"
    x-on:click="categoryDrawerOpen = false"
    x-on:close-category-drawer.window="if (! $event.detail?.drawerId || $event.detail.drawerId === @js($drawerId)) categoryDrawerOpen = false"
></div>
<aside
    id="{{ $fieldId }}"
    class="fixed inset-y-0 right-0 left-auto z-50 ml-auto w-full max-w-xl flex-col bg-white p-6 shadow-xl dark:bg-gray-900 gps-marketplace-category-drawer pointer-events-auto"
    style="top: 0; right: 0; bottom: 0; left: auto; height: 100dvh; max-height: 100dvh; width: min(100vw, 420px); max-width: 440px;"
    data-category-drawer
    data-category-drawer-id="{{ $fieldId }}"
    @if ($treeAttribute) data-marketplace-category-tree="{{ $treeAttribute }}" @endif
    x-cloak
    x-show="categoryDrawerOpen"
    x-transition
    x-bind:class="categoryDrawerOpen ? 'flex' : 'hidden'"
    x-on:close-category-drawer.window="if (! $event.detail?.drawerId || $event.detail.drawerId === @js($drawerId)) categoryDrawerOpen = false"
    x-on:click.stop
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $fieldId }}-heading"
>
    <div class="mb-4 flex items-center justify-between gap-3">
        <h3 id="{{ $fieldId }}-heading" class="text-lg font-semibold text-gray-950 dark:text-white">{{ $heading }}</h3>
        <button type="button" x-on:click.prevent.stop="categoryDrawerOpen = false" class="cursor-pointer rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">Zamknij</button>
    </div>
    @include('filament.forms.category-picker', [
        'categories' => $categories,
        'lazyChildrenUrl' => $lazyChildrenUrl,
        'lazyChannel' => $lazyChannel,
        'suggestions' => $suggestions,
        'saveUrl' => $saveUrl,
        'saveMethod' => $saveMethod,
        'hiddenFields' => $hiddenFields,
        'saveField' => $saveField,
        'pickerId' => $pickerId,
        'drawerId' => $drawerId,
    ])
</aside>
