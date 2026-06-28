@props([
    'fieldId',
    'value' => null,
    'fallback' => 'Brak wybranej kategorii',
    'triggerTitle' => 'Wybierz kategorię z drzewa',
    'triggerAttributes' => [],
])

<fieldset {{ $attributes->merge(['class' => 'gps-shared-category-field fi-input-wrp rounded-lg border border-gray-300 bg-white px-3 pb-1.5 pt-0 shadow-sm ring-1 ring-gray-950/10 transition duration-75 focus-within:border-primary-600 focus-within:ring-primary-600 dark:border-gray-600 dark:bg-gray-900 dark:ring-white/20 dark:focus-within:border-primary-500 dark:focus-within:ring-primary-500', 'data-shared-category-input' => true]) }}>
    <legend class="gps-shared-category-field__legend px-1 text-xs font-medium leading-4 text-gray-500 dark:text-gray-400">Kategoria</legend>
    <div class="flex min-h-10 min-w-0 items-center gap-2 overflow-hidden">
        <button
            type="button"
            class="min-w-0 flex-1 overflow-hidden truncate whitespace-nowrap py-1.5 text-left text-sm text-gray-950 outline-none dark:text-white"
            data-category-drawer-trigger
            @foreach ($triggerAttributes as $name => $attributeValue)
                {{ $name }}="{{ $attributeValue }}"
            @endforeach
        >
            <span class="block min-w-0 overflow-hidden text-ellipsis whitespace-nowrap">{{ $value ?: $fallback }}</span>
        </button>
        @include('filament.forms.partials.category-drawer-trigger', [
            'fieldId' => $fieldId,
            'title' => $triggerTitle,
            'triggerAttributes' => $triggerAttributes,
        ])
    </div>
</fieldset>
