@php
    use App\Models\MarketplaceCategory;

    $readiness = $part ? app(\App\Services\Marketplace\PartMarketplaceReadinessService::class)->check($part) : [];
    $labels = ['allegro' => 'Allegro', 'ovoko' => 'Ovoko', 'ebay' => 'eBay'];
    $channels = ['allegro' => 'allegro_main', 'ovoko' => 'ovoko', 'ebay' => 'ebay_de'];
    $mappingChannels = ['allegro' => 'allegro_main', 'ovoko' => 'ovoko', 'ebay' => 'ebay_de'];
    $marketplaceTrees = MarketplaceCategory::query()
        ->whereIn('channel', array_values($mappingChannels))
        ->orderBy('channel')
        ->orderBy('level')
        ->orderBy('full_path')
        ->limit(900)
        ->get()
        ->groupBy('channel');
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
                $tree = $marketplaceTrees->get($mappingChannels[$key], collect());
            @endphp

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900" data-marketplace-card="{{ $key }}">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $labels[$key] }}</h3>
                    <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $ready ? 'bg-success-50 text-success-700 ring-1 ring-success-600/20' : 'bg-warning-50 text-warning-700 ring-1 ring-warning-600/20' }}">
                        {{ $ready ? 'Gotowy' : 'Braki' }}
                    </span>
                </div>

                <div class="mt-4 space-y-4 text-sm">
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate font-medium text-gray-950 dark:text-white">{{ $category['value'] ?? 'Brak wybranej kategorii' }}</div>
                                @if (! empty($category['id']))
                                    <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">ID kategorii: {{ $category['id'] }}</div>
                                @endif
                            </div>
                            <details class="relative shrink-0">
                                <summary class="list-none rounded-lg border border-gray-300 bg-white px-2.5 py-2 text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200" title="Zmień kategorię z drzewa {{ $labels[$key] }}">☰</summary>
                                <div class="absolute right-0 z-10 mt-2 w-80 rounded-xl border border-gray-200 bg-white p-3 shadow-xl dark:border-gray-700 dark:bg-gray-900">
                                    <div class="mb-2 font-medium">Drzewo kategorii {{ $labels[$key] }}</div>
                                    <form method="POST" action="{{ route('tools.part-marketplace-category-mapping.store') }}" class="space-y-2">
                                        @csrf
                                        <input type="hidden" name="part_id" value="{{ $part?->id }}">
                                        <input type="hidden" name="channel" value="{{ $mappingChannels[$key] }}">
                                        <select name="external_category_id" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800">
                                            @forelse ($tree as $node)
                                                <option value="{{ $node->external_category_id }}">{{ str_repeat('— ', max(0, (int) $node->level)) }}{{ $node->name ?: $node->full_path }}</option>
                                            @empty
                                                <option value="">Brak pobranego drzewa kategorii</option>
                                            @endforelse
                                        </select>
                                        <button type="submit" class="w-full rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-500">Zapisz kategorię lokalnie</button>
                                    </form>
                                </div>
                            </details>
                        </div>
                    </div>

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
