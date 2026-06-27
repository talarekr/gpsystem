@php
    $images = $part?->images?->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values() ?? collect();
    $editable = (bool) ($editable ?? false);
    $thumbnailClasses = $editable
        ? 'h-32 w-32 sm:h-36 sm:w-36 object-cover'
        : 'aspect-square h-full w-full object-cover';
@endphp

@if ($images->isEmpty())
    <div class="text-sm text-gray-500 dark:text-gray-400">Brak zdjęć części.</div>
@else
    <div class="grid gap-3 {{ $editable ? 'grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6' : 'grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6' }}">
        @foreach ($images as $index => $image)
            @php($src = $image->absolutePublicUrl())
            <div class="relative inline-flex w-fit overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <a href="{{ $src }}" target="_blank" rel="noopener noreferrer" class="block transition hover:opacity-90" title="Otwórz zdjęcie w nowej karcie">
                    <img src="{{ $src }}" alt="{{ $image->alt_text ?: ($part?->name ?? 'Zdjęcie części') }}" class="{{ $thumbnailClasses }}" loading="lazy">
                </a>

                @if ($editable)
                    <div class="absolute bottom-1 left-1 z-10 flex gap-1">
                        <button type="button" wire:click="movePartImage({{ $image->getKey() }}, 'left')" @disabled($index === 0) class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-gray-200 bg-white/90 text-sm font-bold shadow-sm disabled:cursor-not-allowed disabled:opacity-40" aria-label="Przesuń zdjęcie w lewo" title="Przesuń w lewo">←</button>
                        <button type="button" wire:click="movePartImage({{ $image->getKey() }}, 'right')" @disabled($index === $images->count() - 1) class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-gray-200 bg-white/90 text-sm font-bold shadow-sm disabled:cursor-not-allowed disabled:opacity-40" aria-label="Przesuń zdjęcie w prawo" title="Przesuń w prawo">→</button>
                    </div>
                    <div class="absolute left-1 top-1 z-10 rounded-full bg-white/90 px-2 py-1 text-xs font-semibold text-gray-700 shadow-sm">{{ $index === 0 ? 'Główne' : $index + 1 }}</div>
                    <button
                        type="button"
                        wire:click="deletePartImage({{ $image->getKey() }})"
                        wire:confirm="Usunąć to zdjęcie części?"
                        style="right: 0.25rem; color: var(--gps-admin-navy);"
                        class="absolute top-1 z-10 inline-flex h-7 w-7 items-center justify-center border-0 bg-transparent p-0 text-xl font-bold leading-none shadow-none transition hover:underline focus:outline-none focus-visible:underline"
                        aria-label="Usuń zdjęcie części"
                        title="Usuń zdjęcie części"
                    >
                        ×
                    </button>
                @endif
            </div>
        @endforeach
    </div>
@endif
