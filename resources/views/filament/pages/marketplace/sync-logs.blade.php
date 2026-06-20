<x-filament-panels::page>
    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900">
        <table class="w-full text-sm">
            <thead><tr><th class="p-3 text-left">Data</th><th class="p-3 text-left">Akcja</th><th class="p-3 text-left">Status</th><th class="p-3 text-left">Komunikat</th></tr></thead>
            <tbody>@foreach($this->logs() as $log)<tr class="border-t"><td class="p-3">{{ $log->created_at }}</td><td class="p-3">{{ $log->action }}</td><td class="p-3">{{ $log->status }}</td><td class="p-3">{{ $log->message }}</td></tr>@endforeach</tbody>
        </table>
    </div>
</x-filament-panels::page>
