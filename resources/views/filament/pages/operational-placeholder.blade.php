<x-filament-panels::page>
    <div class="space-y-6">
        <section class="rounded-xl border border-gray-200 bg-white p-6 text-gray-950 shadow-sm">
            <p class="text-sm font-medium uppercase tracking-wide text-[#0B1F3A]">{{ $this->getPlaceholderEyebrow() }}</p>
            <h2 class="mt-2 text-xl font-semibold text-gray-950">{{ $this->getTitle() }}</h2>
            <p class="mt-3 max-w-3xl text-sm leading-6 text-gray-700">
                {{ $this->getPlaceholderDescription() }}
            </p>
        </section>

        <section class="rounded-xl border border-[#0B1F3A]/10 bg-[#0B1F3A]/[0.03] p-5 text-sm text-gray-800">
            <h3 class="font-semibold text-[#0B1F3A]">Bezpieczny placeholder operacyjny</h3>
            <ul class="mt-3 space-y-2 leading-6">
                @foreach ($this->getPlaceholderDetails() as $detail)
                    <li class="flex gap-2">
                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[#0B1F3A]"></span>
                        <span>{{ $detail }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
</x-filament-panels::page>
