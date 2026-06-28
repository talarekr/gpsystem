@php
    $channel = $mappingChannels[$key];
    $fieldId = 'marketplace-category-drawer-'.str_replace(['_', '.'], '-', $channel).'-'.$part?->id;
    $pickerId = $fieldId.'-picker';
    $childrenUrl = route('tools.marketplace-category-children', ['token' => 'gps_images_import_2026']);
    $categoryValue = $category['value'] ?? null;
    $categoryDisplayValue = $category['display_name'] ?? $category['leaf_name'] ?? null;
    $categoryFullValue = $categoryValue ?: $categoryDisplayValue;

    if (blank($categoryDisplayValue) && filled($categoryValue)) {
        $segments = preg_split('/\s*(?:>|\/)\s*/u', (string) $categoryValue, -1, PREG_SPLIT_NO_EMPTY);
        $categoryDisplayValue = $segments ? trim((string) end($segments)) : $categoryValue;
        $categoryFullValue = $categoryValue ?: $categoryDisplayValue;
    }

    $categoryVisibleValue = $category['short_display_name'] ?? null;

    if (blank($categoryVisibleValue) && filled($categoryDisplayValue)) {
        $words = preg_split('/\s+/u', trim((string) $categoryDisplayValue), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $categoryVisibleValue = count($words) > 2 || mb_strlen((string) $categoryDisplayValue) > 28
            ? rtrim(implode(' ', array_slice($words, 0, 2)), ' .,;:-').'...'
            : $categoryDisplayValue;
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
        'value' => $categoryVisibleValue ?: $categoryDisplayValue ?: $categoryValue,
        'fallback' => 'Brak wybranej kategorii',
        'triggerTitle' => filled($categoryFullValue) ? (string) $categoryFullValue : 'Wybierz kategorię z drzewa '.$labels[$key],
        'triggerAttributes' => [
            'x-on:click.prevent.stop' => 'categoryDrawerOpen = true',
            'x-bind:aria-expanded' => 'categoryDrawerOpen.toString()',
            'title' => filled($categoryFullValue) ? (string) $categoryFullValue : 'Wybierz kategorię z drzewa '.$labels[$key],
        ],
    ])

    <template x-teleport="body">
        <div class="fixed inset-0 z-40 pointer-events-none" data-marketplace-category-drawer-portal>
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
