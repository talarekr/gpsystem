<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament-panels::form wire:submit="runImport">
            {{ $this->form }}
        </x-filament-panels::form>

        <div wire:loading.flex wire:target="runImport" class="items-center gap-3 rounded-xl border border-primary-200 bg-primary-50 p-4 text-sm font-medium text-primary-700 dark:border-primary-800 dark:bg-primary-950 dark:text-primary-300">
            <x-filament::loading-indicator class="h-5 w-5" />
            <span>Przetwarzanie importu Woo…</span>
        </div>

        @if ($importError)
            <x-filament::section heading="Import Woo nie został uruchomiony">
                <div class="text-sm font-medium text-danger-600 dark:text-danger-400">
                    {{ $importError }}
                </div>
            </x-filament::section>
        @endif

        @if ($report)
            @php($counters = $report['counters'] ?? [])
            <x-filament::section heading="Raport importu Woo">
                <dl class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    @foreach ([
                        'total_rows' => 'Łącznie wierszy',
                        'created' => 'Do utworzenia',
                        'updated' => 'Do aktualizacji',
                        'skipped_existing' => 'Pominięte',
                        'skipped_duplicates' => 'Pominięte duplikaty',
                        'failed_rows' => 'Błędy',
                        'warnings' => 'Ostrzeżenia',
                        'images_linked' => 'Obrazy połączone',
                        'categories_created' => 'Kategorie do utworzenia',
                        'categories_matched' => 'Kategorie dopasowane',
                        'products_without_ovoko_car_id' => 'Bez Ovoko car ID',
                        'products_with_missing_car_reference' => 'Brak samochodu lokalnego',
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
