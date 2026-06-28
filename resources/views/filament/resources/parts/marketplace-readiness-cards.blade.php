@php
    $readiness = $part ? app(\App\Services\Marketplace\PartMarketplaceReadinessService::class)->check($part, $categoryId ?? null) : [];
    $labels = ['allegro' => 'Allegro', 'ovoko' => 'Ovoko', 'ebay' => 'eBay'];
    $channels = ['allegro' => 'allegro_main', 'ovoko' => 'ovoko', 'ebay' => 'ebay_de'];
    $mappingChannels = ['allegro' => 'allegro_main', 'ovoko' => 'ovoko', 'ebay' => 'ebay_de'];
    $marketplaceCategorySelections = $marketplaceCategorySelections ?? [];
@endphp

<div class="space-y-4" data-marketplace-preparation-panel>
    <div class="grid gap-4 md:grid-cols-3">
        @foreach (['allegro', 'ovoko', 'ebay'] as $key)
            @php
                $result = $readiness[$key] ?? [];
                $presentation = $result['presentation'] ?? [];
                $ready = (bool) ($presentation['ready'] ?? false);
                $category = $presentation['category'] ?? ['value' => 'Brak wybranej kategorii', 'mapped' => false];
                $missing = $presentation['missing'] ?? [];
                $prepareUrl = $part ? route('tools.check-part-marketplace-preparation-payload', ['token' => 'gps_images_import_2026', 'part_id' => $part->id, 'channel' => $channels[$key]]) : null;
                if ($key === 'ebay' && $part) {
                    $prepareUrl = route('tools.prepare-ebay-listing-translations-all', ['token' => 'gps_images_import_2026', 'part_id' => $part->id]);
                }
            @endphp

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900" data-marketplace-card="{{ $key }}">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">@include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $key])</h3>
                </div>

                <div class="mt-4 space-y-4 text-sm">
                    @include('filament.resources.parts.marketplace-category-field', compact('part', 'key', 'labels', 'category', 'mappingChannels'))

                    <a href="{{ $prepareUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex w-full items-center justify-center rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500">Przygotuj</a>

                    @if ($ready)
                        <div class="rounded-lg border border-success-200 bg-success-50/60 px-3 py-2 text-sm font-medium text-success-700 dark:border-success-700/60 dark:bg-success-900/10 dark:text-success-300" data-marketplace-prepare-result="ready">Gotowe</div>
                    @else
                        <div class="rounded-lg border border-danger-200 bg-danger-50/50 px-3 py-2 text-sm font-medium text-danger-700 dark:border-danger-700/60 dark:bg-danger-900/10 dark:text-danger-300" data-marketplace-prepare-result="blocked">
                            {{ $missing[0] ?? 'Nie udało się przygotować krótkiej listy braków.' }}
                        </div>
                    @endif

                </div>
            </div>
        @endforeach
    </div>
</div>
