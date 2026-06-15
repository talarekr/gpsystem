<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament-panels::form wire:submit="runImport">
            {{ $this->form }}
        </x-filament-panels::form>

        <div wire:loading.flex wire:target="runImport" class="items-center gap-3 rounded-xl border border-primary-200 bg-primary-50 p-4 text-sm font-medium text-primary-700 dark:border-primary-800 dark:bg-primary-950 dark:text-primary-300">
            <x-filament::loading-indicator class="h-5 w-5" />
            <span>Przetwarzanie importu Ovoko…</span>
        </div>

        @if ($importError)
            <x-filament::section heading="Import Ovoko nie został uruchomiony">
                <div class="text-sm font-medium text-danger-600 dark:text-danger-400">
                    {{ $importError }}
                </div>
            </x-filament::section>
        @endif

        @if ($report)
            @php($counters = $report['counters'] ?? [])
            <x-filament::section heading="Raport importu Ovoko">
                <dl class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    @foreach ([
                        'total_rows' => 'Łącznie wierszy',
                        'created' => 'Do utworzenia',
                        'updated' => 'Do aktualizacji',
                        'skipped_existing' => 'Pominięte',
                        'failed_rows' => 'Błędy',
                        'conflicts' => 'Konflikty',
                        'warnings' => 'Ostrzeżenia',
                        'max_imported_ovoko_id' => 'Max imported Ovoko ID',
                        'missing_readable_model' => 'Brak czytelnego modelu',
                        'missing_readable_fuel' => 'Brak czytelnego paliwa',
                        'missing_readable_gearbox' => 'Brak czytelnej skrzyni',
                        'missing_readable_body_type' => 'Brak czytelnego nadwozia',
                        'missing_readable_color' => 'Brak czytelnego koloru',
                        'diagnostic_total_imported_ovoko_cars' => 'Diagnostyka: zaimportowane Ovoko',
                        'diagnostic_max_local_car_id' => 'Diagnostyka: max lokalne cars.id',
                        'diagnostic_max_external_id' => 'Diagnostyka: max external_id',
                        'diagnostic_ovoko_source_count' => 'Diagnostyka: source_system=ovoko',
                        'diagnostic_id_mismatch_count' => 'Diagnostyka: niezgodne ID',
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


        @if ($cleanupReport)
            @php($cleanupCounters = $cleanupReport['counters'] ?? [])
            <x-filament::section heading="Cleanup importu Ovoko">
                <dl class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    @foreach ([
                        'deleted' => 'Usunięte samochody Ovoko',
                        'diagnostic_total_imported_ovoko_cars' => 'Pozostałe samochody Ovoko',
                        'diagnostic_id_mismatch_count' => 'Pozostałe niezgodne ID',
                    ] as $key => $label)
                        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                            <dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $cleanupCounters[$key] ?? 0 }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if (! empty($cleanupReport['warnings']))
                    <div class="mt-6">
                        <h3 class="text-sm font-semibold">Ostrzeżenia cleanup</h3>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                            @foreach ($cleanupReport['warnings'] as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
