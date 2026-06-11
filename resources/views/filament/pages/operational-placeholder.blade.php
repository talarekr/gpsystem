<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 bg-white p-6 text-gray-950 shadow-sm">
        <p class="text-sm font-medium uppercase tracking-wide text-[#0B1F3A]">GPS Product Hub</p>
        <h2 class="mt-2 text-xl font-semibold text-gray-950">{{ $this->getTitle() }}</h2>
        <p class="mt-3 max-w-3xl text-sm leading-6 text-gray-700">
            {{ $this->getPlaceholderDescription() }}
        </p>
        <div class="mt-5 rounded-lg border border-blue-950/10 bg-blue-950/5 p-4 text-sm text-gray-800">
            This page is intentionally a calm, operational placeholder for MVP Ticket 1. No marketplace publishing,
            external API writes, risky automation, or production credentials are connected here.
        </div>
    </div>
</x-filament-panels::page>
