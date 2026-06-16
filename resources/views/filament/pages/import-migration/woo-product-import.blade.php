<x-filament-panels::page>
    <div class="space-y-6">
        <form method="POST" action="{{ route('admin.import-migration.woo-products.start') }}" class="space-y-6">
            @csrf

            <x-filament::section heading="Import migracyjny" description="Tymczasowe narzędzie izolowane od codziennego workflow Części. Nie łączy się z Woo API.">
                <div class="space-y-6">
                    <div>
                        <h3 class="text-sm font-medium text-gray-950 dark:text-white">Instrukcja wgrywania plików</h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            Wgraj pliki CSV/JSON przez DirectAdmin lub File Manager do folderu storage/app/imports/manual/woo/, a następnie wpisz albo wybierz poniżej same nazwy plików. products.csv jest wymagany. Pola opcjonalne możesz wyczyścić, jeśli nie chcesz używać danego pliku. Oczekiwany folder na serwerze: {{ $routeDiagnostics['manual_folder_path'] ?? storage_path('app/imports/manual/woo') }}
                        </p>
                    </div>

                    @foreach ([
                        ['products_filename', 'products.csv', 'csv', 'Wymagany plik produktów. Musi już istnieć na serwerze w storage/app/imports/manual/woo/.', true],
                        ['categories_filename', 'product_categories.csv', 'csv', 'Opcjonalny plik kategorii z folderu storage/app/imports/manual/woo/.', false],
                        ['meta_filename', 'product_meta.csv', 'csv', 'Opcjonalny plik metadanych z folderu storage/app/imports/manual/woo/.', false],
                        ['attributes_filename', 'product_attributes.csv', 'csv', 'Opcjonalny plik atrybutów z folderu storage/app/imports/manual/woo/.', false],
                        ['summary_filename', 'export_summary.json', 'json', 'Opcjonalny plik podsumowania z folderu storage/app/imports/manual/woo/.', false],
                        ['images_filename', 'product_images.csv', 'csv', 'Opcjonalny plik obrazów z folderu storage/app/imports/manual/woo/.', false],
                    ] as [$name, $label, $extension, $helperText, $required])
                        <div>
                            <label for="{{ $name }}" class="block text-sm font-medium text-gray-950 dark:text-white">{{ $label }}</label>
                            <input
                                id="{{ $name }}"
                                name="{{ $name }}"
                                type="text"
                                value="{{ $this->fieldValue($name) }}"
                                placeholder="{{ $label }}"
                                list="{{ $this->datalistId($extension) }}"
                                @if ($required) required @endif
                                maxlength="255"
                                class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:focus:border-primary-500"
                            >
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $helperText }}</p>
                        </div>
                    @endforeach

                    @foreach (['csv', 'json'] as $extension)
                        <datalist id="{{ $this->datalistId($extension) }}">
                            @foreach ($this->availableFilesForExtension($extension) as $filename)
                                <option value="{{ $filename }}"></option>
                            @endforeach
                        </datalist>
                    @endforeach

                    <div>
                        <label for="mode" class="block text-sm font-medium text-gray-950 dark:text-white">Tryb importu</label>
                        <select id="mode" name="mode" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:focus:border-primary-500">
                            @foreach ([
                                \App\Services\ImportMigration\WooProductImport::MODE_DRY_RUN => 'Dry run — tylko raport',
                                \App\Services\ImportMigration\WooProductImport::MODE_CREATE_ONLY => 'Utwórz tylko brakujące',
                                \App\Services\ImportMigration\WooProductImport::MODE_UPDATE_EXISTING => 'Aktualizuj istniejące bezpieczne pola',
                            ] as $value => $label)
                                <option value="{{ $value }}" @selected($this->modeValue() === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </x-filament::section>

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

        <x-filament::section heading="Szybka diagnostyka trasy Woo">
            <dl class="grid gap-2 text-xs md:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    'route_exists' => 'Trasa strony istnieje',
                    'controller_class_exists' => 'Kontroler importu istnieje',
                    'manual_folder_exists' => 'Folder manualny istnieje',
                    'manual_folder_writable' => 'Folder manualny jest zapisywalny',
                    'products_csv_exists' => 'products.csv istnieje',
                    'products_csv_readable' => 'products.csv jest czytelny',
                ] as $key => $label)
                    <div>
                        <dt class="font-medium">{{ $label }}</dt>
                        <dd class="break-words">{{ ($routeDiagnostics[$key] ?? false) ? 'tak' : 'nie' }}</dd>
                    </div>
                @endforeach
                <div>
                    <dt class="font-medium">Endpoint start</dt>
                    <dd class="break-words">{{ $routeDiagnostics['start_route'] ?? '/admin/import-migracyjny/produkty-woo/start' }}</dd>
                </div>
                <div>
                    <dt class="font-medium">Endpoint diagnostyki</dt>
                    <dd class="break-words">{{ $routeDiagnostics['diagnostics_route'] ?? '/admin/import-migracyjny/produkty-woo/diagnostyka' }}</dd>
                </div>
                <div>
                    <dt class="font-medium">Folder manualny</dt>
                    <dd class="break-words">{{ $routeDiagnostics['manual_folder_path'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-medium">Plik awaryjny</dt>
                    <dd class="break-words">{{ $routeDiagnostics['last_error_log_path'] ?? '—' }}</dd>
                </div>
            </dl>
        </x-filament::section>

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

        @if (! empty($submittedValues))
            <x-filament::section heading="Ostatnio odebrane wartości POST Woo">
                <dl class="grid gap-2 text-sm md:grid-cols-2 xl:grid-cols-4">
                    @foreach ([
                        'products_filename',
                        'categories_filename',
                        'meta_filename',
                        'attributes_filename',
                        'summary_filename',
                        'images_filename',
                        'mode',
                    ] as $key)
                        <div>
                            <dt class="font-medium">submitted {{ $key }}</dt>
                            <dd class="break-words">{{ ($submittedValues[$key] ?? '') !== '' ? $submittedValues[$key] : '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-filament::section>
        @endif

        @if ($importError)
            <x-filament::section heading="Import Woo nie został ukończony">
                <div class="text-sm font-medium text-danger-600 dark:text-danger-400">
                    {{ $importError }}
                </div>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    Import zatrzymano. Dotychczasowy raport i diagnostyka są widoczne poniżej, jeśli przetworzono już część pliku.
                </p>

                @if (! empty($importDebug))
                    <div class="mt-4 rounded-lg border border-danger-200 bg-danger-50 p-3 text-xs text-danger-900 dark:border-danger-800 dark:bg-danger-950 dark:text-danger-100">
                        <div class="font-semibold">Bezpieczne szczegóły diagnostyczne</div>
                        <dl class="mt-3 grid gap-2 md:grid-cols-2">
                            <div>
                                <dt class="font-medium">Klasa wyjątku</dt>
                                <dd class="break-words">{{ $importDebug['exception_class'] ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium">Komunikat wyjątku</dt>
                                <dd class="break-words">{{ $importDebug['exception_message'] ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium">Oczekiwany folder</dt>
                                <dd class="break-words">{{ $importDebug['expected_folder_path'] ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium">Plik diagnostyczny</dt>
                                <dd class="break-words">{{ $importDebug['diagnostic_file'] ?? '—' }}</dd>
                            </div>
                        </dl>

                        <div class="mt-3 font-medium">Przesłane nazwy plików</div>
                        <dl class="mt-2 grid gap-2 md:grid-cols-2 xl:grid-cols-4">
                            @foreach (($importDebug['submitted_fields'] ?? []) as $key => $value)
                                <div>
                                    <dt class="font-medium">{{ $key }}</dt>
                                    <dd class="break-words">{{ filled($value) ? $value : '—' }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endif
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
