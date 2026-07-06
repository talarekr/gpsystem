@php
    $readiness = $part ? app(\App\Services\Marketplace\PartMarketplaceReadinessService::class)->check($part, $categoryId ?? null) : [];
    $labels = ['allegro' => 'Allegro', 'ovoko' => 'Ovoko', 'ebay' => 'eBay'];
    $channels = ['allegro' => 'allegro_main', 'ovoko' => 'ovoko', 'ebay' => 'ebay_de'];
    $mappingChannels = ['allegro' => 'allegro_main', 'ovoko' => 'ovoko', 'ebay' => 'ebay_de'];
    $marketplaceCategorySelections = $marketplaceCategorySelections ?? [];
    $preparedStatusChecked = array_fill_keys((array) ($preparedStatusChecked ?? []), true);
    $durablePreparedChannels = (array) data_get((array) ($part?->review_metadata ?: []), 'marketplace_prepared_channels', []);
    $humanizeMissing = function (?string $message, string $key): string {
        return match ($message) {
            'cena', 'cena Allegro', 'cena Ovoko' => 'Uzupełnij cenę',
            'cena eBay' => 'Uzupełnij cenę eBay',
            'mapowanie kategorii Allegro', 'mapowanie kategorii Ovoko', 'mapowanie kategorii eBay' => 'Wybierz kategorię',
            'allegro_required_category_parameters_missing' => 'Brakuje wymaganych parametrów Allegro',
            'prepared_translations', 'tłumaczenie eBay DE', 'Brak przygotowanego tłumaczenia eBay DE' => 'Brak przygotowanego tłumaczenia eBay DE',
            'tłumaczenie eBay FR', 'Brak przygotowanego tłumaczenia eBay FR' => 'Brak przygotowanego tłumaczenia eBay FR',
            'category_shipping_group', 'Brak grupy wysyłkowej dla kategorii' => 'Brak grupy wysyłkowej dla kategorii',
            'shipping_policy_mapping', 'Brak mapowania polityki wysyłki' => 'Brak mapowania polityki wysyłki',
            default => filled($message) ? (string) $message : 'Wymaga uzupełnienia',
        };
    };
@endphp

<div class="space-y-4" data-marketplace-preparation-panel>
    <div class="grid gap-4 md:grid-cols-3">
        @foreach (['allegro', 'ovoko', 'ebay'] as $key)
            @php
                $result = $readiness[$key] ?? [];
                $presentation = $result['presentation'] ?? [];
                $ready = (bool) ($presentation['ready'] ?? false);
                $category = $presentation['category'] ?? ['value' => 'Brak wybranej kategorii', 'mapped' => false];
                $missing = $presentation['missing'] ?? [];
                $blockingMissing = $ready ? [] : $missing;
                $durablyPrepared = in_array($key, $durablePreparedChannels, true) || data_get((array) ($part?->review_metadata ?: []), 'marketplace_prepare_results.'.$key.'.status') === 'ready';
                $showInitialStatus = $durablyPrepared && $ready;
                $statusChecked = (bool) ($preparedStatusChecked[$key] ?? false);
                $missingMessage = $humanizeMissing($blockingMissing[0] ?? null, $key);
                $prepareUrl = $part ? route('tools.prepare-part-marketplace-card', ['token' => 'gps_images_import_2026', 'part_id' => $part->id, 'channel' => $key]) : null;
            @endphp

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900" data-marketplace-card="{{ $key }}" x-data="{ preparedStatusChecked: @js($statusChecked || $showInitialStatus), preparing: false, publishing: false, prepareReady: @js($ready), prepareMessage: @js($ready ? 'Gotowe' : $missingMessage), publishMessage: '', async prepareMarketplace() { if (! @js((bool) $prepareUrl) || this.preparing) return; this.preparing = true; try { const response = await fetch(@js($prepareUrl), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }); const data = await response.json(); this.prepareReady = !!data.ready; this.prepareMessage = data.message || (this.prepareReady ? 'Gotowe' : 'Wymaga uzupełnienia'); } catch (error) { this.prepareReady = false; this.prepareMessage = 'Nie udało się przygotować kanału. Spróbuj ponownie.'; } finally { this.preparedStatusChecked = true; this.preparing = false; } }, async publishMarketplaceChannel() { if (! this.preparedStatusChecked || ! this.prepareReady || this.publishing) return; this.publishing = true; this.publishMessage = ''; try { await $wire.publishMarketplaceChannel(@js($key)); await this.prepareMarketplace(); } catch (error) { this.publishMessage = 'Nie udało się wystawić tego kanału. Spróbuj ponownie.'; } finally { this.publishing = false; } } }">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">@include('filament.resources.orders.partials.source-wordmark', ['marketplace' => $key])</h3>
                </div>

                <div class="mt-4 space-y-4 text-sm">
                    @include('filament.resources.parts.marketplace-category-field', compact('part', 'key', 'labels', 'category', 'mappingChannels'))

                    <div class="space-y-2">
                        <button type="button" x-on:click.prevent="prepareMarketplace()" x-bind:disabled="preparing" class="inline-flex w-full items-center justify-center rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 disabled:cursor-wait disabled:opacity-70">
                            <span x-text="preparing ? 'Przygotowuję...' : 'Przygotuj'"></span>
                        </button>

                        <button type="button" x-cloak x-show="preparedStatusChecked && prepareReady" x-on:click.prevent="publishMarketplaceChannel()" x-bind:disabled="publishing || !prepareReady" class="inline-flex w-full items-center justify-center rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 disabled:cursor-wait disabled:opacity-70">
                            <span x-text="publishing ? 'Wystawiam...' : 'Wystaw'"></span>
                        </button>
                    </div>

                    <template x-if="publishMessage">
                        <div class="flex min-h-10 items-center justify-center rounded-lg bg-transparent px-3 py-2 text-center text-sm font-medium" style="border: 1px solid rgb(var(--danger-500)); color: rgb(var(--danger-700));" data-marketplace-publish-result="error" x-text="publishMessage"></div>
                    </template>

                    <template x-if="preparedStatusChecked">
                        <div class="flex min-h-10 items-center justify-center rounded-lg bg-transparent px-3 py-2 text-center text-sm font-medium" x-bind:style="prepareReady ? 'border: 1px solid rgb(var(--success-500)); color: rgb(var(--success-700));' : 'border: 1px solid rgb(var(--danger-500)); color: rgb(var(--danger-700));'" x-bind:data-marketplace-prepare-result="prepareReady ? 'ready' : 'blocked'" x-text="prepareReady ? 'Gotowe' : prepareMessage"></div>
                    </template>

                </div>
            </div>
        @endforeach
    </div>
</div>
