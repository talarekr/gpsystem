<x-filament-panels::page>
    <div class="space-y-6">
        {{ $this->form }}
        @if ($report)
            <x-filament::section heading="Raport importu Ovoko">
                <pre class="whitespace-pre-wrap text-sm">{{ json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
