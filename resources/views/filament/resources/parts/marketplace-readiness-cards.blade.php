@php
    $readiness = $part ? app(\App\Services\Marketplace\PartMarketplaceReadinessService::class)->check($part) : [];
    $labels = ['allegro' => 'Allegro', 'ovoko' => 'Ovoko', 'ebay' => 'eBay'];
    $channels = ['allegro' => 'allegro_main', 'ovoko' => 'ovoko', 'ebay' => 'ebay_de'];
    $mappingChannels = ['allegro' => 'allegro_main', 'ovoko' => 'ovoko', 'ebay' => 'ebay_de'];
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
                $allegroPreviewUrl = $part ? route('tools.allegro-listing-preview', ['token' => 'gps_images_import_2026', 'part_id' => $part->id]) : null;
                $ebayPreviewUrls = $part ? [
                    'ebay_de' => route('tools.ebay-listing-preview', ['token' => 'gps_images_import_2026', 'part_id' => $part->id, 'channel' => 'ebay_de']),
                    'ebay_fr' => route('tools.ebay-listing-preview', ['token' => 'gps_images_import_2026', 'part_id' => $part->id, 'channel' => 'ebay_fr']),
                ] : [];
            @endphp

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900" data-marketplace-card="{{ $key }}">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $labels[$key] }}</h3>
                </div>

                <div class="mt-4 space-y-4 text-sm">
                    @include('filament.resources.parts.marketplace-category-field', compact('part', 'key', 'labels', 'category', 'mappingChannels'))

                    <a href="{{ $prepareUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex w-full items-center justify-center rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500">Przygotuj</a>

                    @if ($ready)
                        <div class="text-sm font-medium text-success-700 dark:text-success-300">Aukcja przygotowana</div>
                    @else
                        <div class="rounded-lg bg-gray-50 p-3 text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                            <div class="font-medium">Uzupełnij braki:</div>
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                @forelse ($missing as $item)
                                    <li>{{ $item }}</li>
                                @empty
                                    <li>Nie udało się przygotować krótkiej listy braków.</li>
                                @endforelse
                            </ul>
                        </div>
                    @endif

                    <div class="space-y-2">
                        @if ($key === 'allegro')
                            <a href="{{ $allegroPreviewUrl ?? '#' }}" target="_blank" rel="noopener noreferrer" class="inline-flex w-full items-center justify-center rounded-lg border border-primary-200 bg-white px-3 py-2 text-sm font-semibold text-primary-700 shadow-sm hover:bg-primary-50 dark:border-primary-700 dark:bg-gray-900 dark:text-primary-300">Podgląd aukcji</a>
                        @elseif ($key === 'ebay')
                            <div class="grid gap-2 sm:grid-cols-2">
                                <a href="{{ $ebayPreviewUrls['ebay_de'] ?? '#' }}" target="_blank" rel="noopener noreferrer" class="inline-flex w-full items-center justify-center rounded-lg border border-primary-200 bg-white px-3 py-2 text-sm font-semibold text-primary-700 shadow-sm hover:bg-primary-50 dark:border-primary-700 dark:bg-gray-900 dark:text-primary-300">Podgląd aukcji DE</a>
                                <a href="{{ $ebayPreviewUrls['ebay_fr'] ?? '#' }}" target="_blank" rel="noopener noreferrer" class="inline-flex w-full items-center justify-center rounded-lg border border-primary-200 bg-white px-3 py-2 text-sm font-semibold text-primary-700 shadow-sm hover:bg-primary-50 dark:border-primary-700 dark:bg-gray-900 dark:text-primary-300">Podgląd aukcji FR</a>
                            </div>
                        @endif
                    </div>

                    <details class="text-xs text-gray-500 dark:text-gray-400">
                        <summary>Szczegóły techniczne</summary>
                        <div class="mt-2 space-y-1">
                            <div>Tryb lokalny: bez publish i bez marketplace API write.</div>
                            <div>Kanał techniczny: {{ $channels[$key] }}</div>
                        </div>
                    </details>
                </div>
            </div>
        @endforeach
    </div>
</div>
