@php
    $readiness = $part ? app(\App\Services\Marketplace\PartMarketplaceReadinessService::class)->check($part) : [];
    $labels = ['allegro' => 'Allegro', 'ovoko' => 'Ovoko', 'ebay' => 'eBay'];
    $statusLabels = ['ready' => 'kompletne', 'missing' => 'brakuje danych', 'api_error' => 'błąd API', 'not_configured' => 'nieobsługiwane'];
@endphp

<div class="grid gap-4 md:grid-cols-3">
    @foreach ($readiness as $key => $result)
        @php
            $status = $result['status'] ?? 'not_configured';
            $ready = (bool) ($result['ready'] ?? false);
        @endphp
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $labels[$key] ?? $key }}</h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Podgląd gotowości — bez wystawiania oferty.</p>
                </div>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $ready ? 'bg-success-50 text-success-700 ring-1 ring-success-600/20' : 'bg-danger-50 text-danger-700 ring-1 ring-danger-600/20' }}">
                    {{ $statusLabels[$status] ?? $status }}
                </span>
            </div>

            <div class="mt-4 space-y-3 text-sm">
                <div>
                    <div class="font-medium text-success-700 dark:text-success-400">OK</div>
                    <ul class="mt-1 list-disc space-y-1 pl-5 text-gray-700 dark:text-gray-300">
                        @forelse (($result['ok'] ?? []) as $item)
                            <li>{{ $item }}</li>
                        @empty
                            <li>Brak potwierdzonych danych.</li>
                        @endforelse
                    </ul>
                </div>

                <div>
                    <div class="font-medium text-danger-700 dark:text-danger-400">Do uzupełnienia</div>
                    <ul class="mt-1 list-disc space-y-1 pl-5 text-gray-700 dark:text-gray-300">
                        @forelse (($result['missing'] ?? []) as $item)
                            <li>{{ $item }}</li>
                        @empty
                            <li>Brak braków blokujących.</li>
                        @endforelse
                    </ul>
                </div>

                <div>
                    <div class="font-medium text-warning-700 dark:text-warning-400">Ostrzeżenia</div>
                    <ul class="mt-1 list-disc space-y-1 pl-5 text-gray-700 dark:text-gray-300">
                        @foreach (($result['warnings'] ?? []) as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                        <li>will_make_marketplace_request = {{ ($result['will_make_marketplace_request'] ?? true) ? 'true' : 'false' }}</li>
                    </ul>
                </div>
            </div>
        </div>
    @endforeach
</div>
