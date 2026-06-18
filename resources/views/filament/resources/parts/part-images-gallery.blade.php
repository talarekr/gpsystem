@php
    $images = $part?->images?->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values() ?? collect();
@endphp

@if ($images->isEmpty())
    <div class="text-sm text-gray-500 dark:text-gray-400">Brak zdjęć części.</div>
@else
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
        @foreach ($images as $image)
            @php($src = $image->absolutePublicUrl())
            <a href="{{ $src }}" target="_blank" rel="noopener noreferrer" class="block overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm transition hover:opacity-90 dark:border-gray-700 dark:bg-gray-900">
                <img src="{{ $src }}" alt="{{ $image->alt_text ?: ($part?->name ?? 'Zdjęcie części') }}" class="aspect-square h-full w-full object-cover" loading="lazy">
            </a>
        @endforeach
    </div>
@endif
