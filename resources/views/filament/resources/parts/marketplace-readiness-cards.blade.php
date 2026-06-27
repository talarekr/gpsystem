@php
    $readiness = $part ? app(\App\Services\Marketplace\PartMarketplaceReadinessService::class)->check($part) : [];
    $labels = ['allegro' => 'Allegro', 'ovoko' => 'Ovoko', 'ebay' => 'eBay'];
    $channels = ['allegro' => 'allegro_main', 'ovoko' => 'ovoko', 'ebay' => 'ebay_de'];
@endphp

<div class="space-y-4">
    <div class="rounded-xl border border-info-200 bg-info-50 p-3 text-sm text-info-800 dark:border-info-800 dark:bg-info-950 dark:text-info-200">
        To jest podgląd przygotowania produktu. Nie wystawia ofert i nie zmienia danych na marketplace.
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        @foreach (['allegro', 'ovoko', 'ebay'] as $key)
            @php
                $result = $readiness[$key] ?? [];
                $presentation = $result['presentation'] ?? [];
                $ready = (bool) ($presentation['ready'] ?? false);
                $category = $presentation['category'] ?? ['value' => 'Brak mapowania', 'mapped' => false];
                $missing = $presentation['missing'] ?? [];
                $prepareUrl = $part ? route('tools.check-part-marketplace-preparation-payload', ['token' => 'gps_images_import_2026', 'part_id' => $part->id, 'channel' => $channels[$key]]) : null;
            @endphp

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $labels[$key] }}</h3>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Praktyczne przygotowanie produktu.</p>
                    </div>
                    <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $ready ? 'bg-success-50 text-success-700 ring-1 ring-success-600/20' : 'bg-warning-50 text-warning-700 ring-1 ring-warning-600/20' }}">
                        {{ $ready ? 'Gotowy' : 'Braki' }}
                    </span>
                </div>

                <div class="mt-4 space-y-4 text-sm">
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Kategoria</div>
                        <div class="mt-1 rounded-lg border {{ ($category['mapped'] ?? false) ? 'border-success-200 bg-success-50 text-success-800 dark:border-success-800 dark:bg-success-950 dark:text-success-200' : 'border-warning-200 bg-warning-50 text-warning-800 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-200' }} p-3">
                            {{ $category['value'] ?? 'Brak mapowania' }}
                        </div>
                    </div>

                    @if ($key === 'ebay')
                        <div class="rounded-lg border border-gray-200 p-3 text-gray-700 dark:border-gray-700 dark:text-gray-300">
                            <div class="font-medium">eBay wymaga przygotowania wersji językowych:</div>
                            <ul class="mt-2 list-disc pl-5">
                                @foreach (($presentation['translations'] ?? []) as $translation)
                                    <li>{{ $translation['label'] }} — {{ ($translation['ready'] ?? false) ? 'gotowe' : 'brak' }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <a href="{{ $prepareUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex w-full items-center justify-center rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500">
                        Przygotuj
                    </a>

                    <div class="rounded-lg {{ $ready ? 'bg-success-50 text-success-800 dark:bg-success-950 dark:text-success-200' : 'bg-gray-50 text-gray-800 dark:bg-gray-800 dark:text-gray-200' }} p-3">
                        <div class="font-medium">{{ $presentation['message'] ?? ($ready ? 'Produkt gotowy' : 'Uzupełnij braki') }}</div>
                        @if (! $ready)
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                @forelse ($missing as $item)
                                    <li>{{ $item }}</li>
                                @empty
                                    <li>Nie udało się przygotować krótkiej listy braków.</li>
                                @endforelse
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
