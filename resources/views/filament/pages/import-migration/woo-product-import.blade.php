<x-filament-panels::page>
    <div class="space-y-6">
        <form method="POST" action="{{ route('admin.import-migration.woo-products.start') }}" class="space-y-6">
            @csrf
            {{ $this->form }}

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    class="fi-btn fi-color-primary fi-btn-color-primary fi-size-md inline-flex items-center justify-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm outline-none transition duration-75 hover:bg-primary-500 focus-visible:ring-2 focus-visible:ring-primary-600 disabled:pointer-events-none disabled:opacity-70 dark:bg-primary-500 dark:hover:bg-primary-400 dark:focus-visible:ring-primary-500"
                >
                    Uruchom import
                </button>

                @if ($isImportRunning)
                    <span class="text-sm text-gray-600 dark:text-gray-300">Import trwa — kolejną partię uruchamia zwykły endpoint HTTP poniżej.</span>
                @endif
            </div>
        </form>

        @if ($runImportStartedAt || $firstBatchStartedAt || $lastError)
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <div>runImport started at: {{ $runImportStartedAt ?: '—' }}</div>
                <div>first batch started at: {{ $firstBatchStartedAt ?: '—' }}</div>
                <div>last error: {{ $lastError ?: '—' }}</div>
            </div>
        @endif

        @if ($isImportRunning)
            <div class="rounded-xl border border-primary-200 bg-primary-50 p-4 text-sm text-primary-800 dark:border-primary-800 dark:bg-primary-950 dark:text-primary-200">
                <div class="flex items-center gap-3 font-medium">
                    <x-filament::loading-indicator class="h-5 w-5" />
                    <span>Import trwa… przetwarzanie w partiach po {{ $batchSize }} produktów przez zwykłą trasę Laravel.</span>
                </div>

                <div class="mt-4 h-3 overflow-hidden rounded-full bg-white dark:bg-gray-800">
                    <div class="h-3 rounded-full bg-primary-600 transition-all" style="width: {{ $totalRows > 0 ? min(100, round(($processedRows / $totalRows) * 100, 2)) : 0 }}%"></div>
                </div>

                <div class="mt-2 text-xs">
                    Przetworzono {{ $processedRows }} z {{ $totalRows }} wierszy ({{ $totalRows > 0 ? min(100, round(($processedRows / $totalRows) * 100, 1)) : 0 }}%).
                </div>


                @if (! empty($importRun['id']))
                    <form method="POST" action="{{ route('admin.import-migration.woo-products.next', ['runId' => $importRun['id']]) }}" class="mt-4">
                        @csrf
                        <button
                            type="submit"
                            class="fi-btn fi-color-primary fi-btn-color-primary fi-size-md inline-flex items-center justify-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm outline-none transition duration-75 hover:bg-primary-500 focus-visible:ring-2 focus-visible:ring-primary-600 dark:bg-primary-500 dark:hover:bg-primary-400 dark:focus-visible:ring-primary-500"
                        >
                            Przetwórz następną partię
                        </button>
                    </form>
                @endif

                <details class="mt-4 rounded-lg border border-primary-200 bg-white/70 p-3 text-xs dark:border-primary-800 dark:bg-gray-900/60" open>
                    <summary class="cursor-pointer font-semibold">Diagnostyka batch importu Woo</summary>
                    <dl class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ([
                            'isImportRunning' => $isImportRunning ? 'true' : 'false',
                            'currentOffset' => $currentOffset,
                            'totalRows' => $totalRows,
                            'lastBatchProcessed' => $lastBatchProcessed,
                            'lastBatchStartedAt' => $lastBatchStartedAt ?: '—',
                            'lastBatchFinishedAt' => $lastBatchFinishedAt ?: '—',
                            'runImportStartedAt' => $runImportStartedAt ?: '—',
                            'firstBatchStartedAt' => $firstBatchStartedAt ?: '—',
                            'lastError' => $lastError ?: '—',
                            'pollTickCount' => $pollTickCount,
                        ] as $label => $value)
                            <div>
                                <dt class="font-medium">{{ $label }}</dt>
                                <dd class="break-words">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </details>
            </div>
        @endif

        @if ($importError)
            <x-filament::section heading="Import Woo nie został ukończony">
                <div class="text-sm font-medium text-danger-600 dark:text-danger-400">
                    {{ $importError }}
                </div>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    Import zatrzymano. Dotychczasowy raport i diagnostyka są widoczne poniżej, jeśli przetworzono już część pliku.
                </p>
            </x-filament::section>
        @endif

        @if ($report)
            @php($counters = $report['counters'] ?? [])
            <x-filament::section heading="Raport importu Woo">
                <dl class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    @foreach ([
                        'processed_rows' => 'Przetworzone wiersze',
                        'total_rows' => 'Wiersze w raporcie',
                        'created' => 'Do utworzenia / utworzone',
                        'updated' => 'Zaktualizowane',
                        'skipped_existing' => 'Pominięte istniejące',
                        'skipped_duplicates' => 'Pominięte duplikaty',
                        'failed_rows' => 'Błędy',
                        'warnings' => 'Ostrzeżenia',
                        'images_linked' => 'Obrazy połączone',
                        'categories_created' => 'Kategorie do utworzenia / utworzone',
                        'categories_matched' => 'Kategorie dopasowane',
                        'products_without_ovoko_car_id' => 'Bez Ovoko car ID',
                        'products_with_missing_car_reference' => 'Brak samochodu lokalnego',
                        'last_batch_rows' => 'Ostatnia partia',
                        'last_batch_seconds' => 'Sekundy ostatniej partii',
                        'elapsed_seconds' => 'Sekundy łącznie',
                        'memory_peak_mb' => 'Szczyt pamięci MB',
                        'memory_current_mb' => 'Pamięć bieżąca MB',
                    ] as $key => $label)
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                            <dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $counters[$key] ?? 0 }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if (! empty($report['warnings']))
                    <div class="mt-6">
                        <h3 class="text-sm font-semibold">Ostrzeżenia</h3>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                            @foreach ($report['warnings'] as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (! empty($report['errors']))
                    <div class="mt-6">
                        <h3 class="text-sm font-semibold text-danger-600 dark:text-danger-400">Błędy</h3>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-danger-600 dark:text-danger-400">
                            @foreach ($report['errors'] as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
