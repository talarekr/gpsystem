@props([
    'fieldId',
    'title' => 'Wybierz kategorię z drzewa',
    'triggerAttributes' => [],
])

<button
    type="button"
    class="inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-gray-500 transition hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-primary-600 dark:text-gray-400 dark:hover:text-gray-200"
    title="{{ $title }}"
    aria-label="{{ $title }}"
    aria-controls="{{ $fieldId }}"
    data-category-drawer-trigger
    data-shared-category-trigger
    @foreach ($triggerAttributes as $name => $attributeValue)
        {{ $name }}="{{ $attributeValue }}"
    @endforeach
>
    <x-filament::icon
        icon="heroicon-m-bars-3"
        class="h-5 w-5"
        aria-hidden="true"
    />
</button>
