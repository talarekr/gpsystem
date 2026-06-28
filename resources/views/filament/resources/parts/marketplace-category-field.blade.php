@php
    $channel = $mappingChannels[$key];
    $fieldId = 'marketplace-category-drawer-'.str_replace(['_', '.'], '-', $channel).'-'.$part?->id;
    $pickerId = $fieldId.'-picker';
    $childrenUrl = route('tools.marketplace-category-children', ['token' => 'gps_images_import_2026']);
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
