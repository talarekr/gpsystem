<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-4">
        @foreach($this->stats() as $label => $value)
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900">
                <div class="text-sm text-gray-500">{{ $label }}</div>
                <div class="text-2xl font-bold">{{ $value }}</div>
            </div>
        @endforeach
    </div>
    <p class="text-sm text-gray-600">Ovoko jest pierwszym aktywnym etapem. Allegro i eBay: coming soon.</p>
</x-filament-panels::page>
