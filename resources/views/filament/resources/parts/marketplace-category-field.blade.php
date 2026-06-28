@php
    $channel = $mappingChannels[$key];
    $fieldId = 'marketplace-category-drawer-'.str_replace(['_', '.'], '-', $channel).'-'.$part?->id;
    $pickerId = $fieldId.'-picker';
    $childrenUrl = route('tools.marketplace-category-children', ['token' => 'gps_images_import_2026']);
    $categoryValue = $category['value'] ?? null;
    $categoryDisplayValue = $category['display_name'] ?? $category['leaf_name'] ?? null;

    if (blank($categoryDisplayValue) && filled($categoryValue)) {
        $segments = preg_split('/\s*(?:>|\/)\s*/u', (string) $categoryValue, -1, PREG_SPLIT_NO_EMPTY);
        $categoryDisplayValue = $segments ? trim((string) end($segments)) : $categoryValue;
    }
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
        'value' => $categoryDisplayValue ?: $categoryValue,
        'fallback' => 'Brak wybranej kategorii',
        'triggerTitle' => filled($categoryValue) ? (string) $categoryValue : 'Wybierz kategorię z drzewa '.$labels[$key],
        'triggerAttributes' => [
            'x-on:click.prevent.stop' => 'categoryDrawerOpen = true',
            'x-bind:aria-expanded' => 'categoryDrawerOpen.toString()',
            'title' => filled($categoryValue) ? (string) $categoryValue : 'Wybierz kategorię z drzewa '.$labels[$key],
        ],
    ])

    <template x-teleport="body">
        <div>
            @include('filament.forms.category-drawer-shell', [
                'fieldId' => $fieldId,
                'heading' => 'Kategorie',
                'categories' => [],
                'lazyChildrenUrl' => $childrenUrl,
                'lazyChannel' => $channel,
                'suggestions' => [],
                'saveUrl' => route('tools.part-marketplace-category-mapping.store'),
                'hiddenFields' => ['part_id' => $part?->id, 'channel' => $channel],
                'saveField' => 'external_category_id',
                'pickerId' => $pickerId,
                'treeAttribute' => $channel,
            ])
        </div>
    </template>

</div>
