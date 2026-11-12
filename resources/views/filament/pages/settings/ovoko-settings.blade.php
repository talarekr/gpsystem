<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <x-filament-actions::actions :actions="$this->getFormActions()" />
    </form>
    @if(auth()->user()?->hasRole(\App\Enums\UserRole::OwnerAdmin->value))
        <a href="{{ route('admin.tools.ovoko-credentials') }}" class="text-primary-600 underline">
            Odczytaj zapisane dane API Ovoko (OwnerAdmin, bez zapisu)
        </a>
    @endif
</x-filament-panels::page>
