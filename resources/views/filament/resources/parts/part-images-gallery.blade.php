@php
    $images = $part?->images?->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values() ?? collect();
    $editable = (bool) ($editable ?? false);
    $thumbnailClasses = $editable
        ? 'h-full w-full object-contain'
        : 'aspect-square h-full w-full object-cover';
@endphp

@if ($images->isEmpty() && ! $editable)
    <div class="text-sm text-gray-500 dark:text-gray-400">Brak zdjęć części.</div>
@elseif ($images->isNotEmpty())
    <div class="gps-part-images-gallery {{ $editable ? 'gps-part-images-gallery--editable' : 'grid gap-3 grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6' }}">
        @foreach ($images as $image)
            @php($src = $image->absolutePublicUrl())
            <div class="gps-part-image-tile relative inline-flex overflow-hidden rounded-lg border border-gray-200 bg-transparent shadow-sm dark:border-gray-700">
                <a href="{{ $src }}" target="_blank" rel="noopener noreferrer" class="flex h-full w-full items-center justify-center transition hover:opacity-90" title="Otwórz zdjęcie w nowej karcie">
                    <img src="{{ $src }}" alt="{{ $image->alt_text ?: ($part?->name ?? 'Zdjęcie części') }}" class="{{ $thumbnailClasses }}" loading="lazy">
                </a>

                @if ($editable)
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
