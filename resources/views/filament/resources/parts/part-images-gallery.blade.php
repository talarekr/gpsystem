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
        @foreach ($images as $image)
            @php($src = $image->absolutePublicUrl())
            <div class="relative inline-flex w-fit overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <a href="{{ $src }}" target="_blank" rel="noopener noreferrer" class="block transition hover:opacity-90" title="Otwórz zdjęcie w nowej karcie">
                    <img src="{{ $src }}" alt="{{ $image->alt_text ?: ($part?->name ?? 'Zdjęcie części') }}" class="{{ $thumbnailClasses }}" loading="lazy">
                </a>

                @if ($editable)
                    <button
                        type="button"
                        wire:click="deletePartImage({{ $image->getKey() }})"
                        wire:confirm="Usunąć to zdjęcie części?"
                        class="absolute right-1 top-1 inline-flex h-7 w-7 items-center justify-center rounded-full bg-danger-600 text-sm font-bold leading-none text-white shadow transition hover:bg-danger-500 focus:outline-none focus:ring-2 focus:ring-danger-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
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
