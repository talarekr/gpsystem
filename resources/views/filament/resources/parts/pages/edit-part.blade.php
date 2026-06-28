<x-filament-panels::page>
    <div class="gps-part-edit-page">
        <div class="gps-part-edit-page-header">
            <h1 class="gps-part-edit-page-header__title">Edytuj część</h1>

            <x-filament-actions::actions
                :actions="$this->getCachedHeaderActions()"
                class="gps-part-edit-page-header__actions"
            />
        </div>

        <x-filament-panels::form wire:submit="save">
            {{ $this->form }}

            <x-filament-panels::form.actions
                :actions="$this->getCachedFormActions()"
                :full-width="$this->hasFullWidthFormActions()"
            />
        </x-filament-panels::form>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
