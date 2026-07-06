@php
    $cars = $cars ?? [];
    $selectedCarId = $selectedCarId ?? null;
    $search = trim((string) ($search ?? ''));
    $pickerId = 'gps-car-picker-'.uniqid();
@endphp

@once
    <style>
        .gps-car-picker { display: flex; flex-direction: column; gap: .75rem; }
        .gps-car-picker__hint { color: rgb(107 114 128); font-size: .875rem; margin: 0; }
        .dark .gps-car-picker__hint { color: rgb(156 163 175); }
        .gps-car-picker__list { display: flex; flex-direction: column; gap: .5rem; }
        .gps-car-picker__row {
            display: flex; width: 100%; align-items: flex-start; gap: .75rem; border: 1px solid rgb(229 231 235);
            border-radius: .75rem; background: rgb(255 255 255); padding: .75rem .875rem; text-align: left;
            box-shadow: 0 1px 2px 0 rgb(0 0 0 / .04); transition: border-color 150ms ease, background 150ms ease, box-shadow 150ms ease;
        }
        .dark .gps-car-picker__row { border-color: rgb(55 65 81); background: rgb(17 24 39); }
        .gps-car-picker__row:hover { border-color: rgb(59 130 246); background: rgb(239 246 255); }
        .dark .gps-car-picker__row:hover { border-color: rgb(96 165 250); background: rgb(30 41 59); }
        .gps-car-picker__row.is-selected { border-color: rgb(37 99 235); background: rgb(219 234 254); box-shadow: 0 0 0 1px rgb(37 99 235); }
        .dark .gps-car-picker__row.is-selected { border-color: rgb(96 165 250); background: rgb(30 58 138 / .35); box-shadow: 0 0 0 1px rgb(96 165 250); }
        .gps-car-picker__icon { flex: 0 0 auto; font-size: 1.25rem; line-height: 1.5rem; }
        .gps-car-picker__body { min-width: 0; }
        .gps-car-picker__title { display: block; color: rgb(17 24 39); font-weight: 700; line-height: 1.25rem; }
        .dark .gps-car-picker__title { color: rgb(243 244 246); }
        .gps-car-picker__details { display: block; color: rgb(75 85 99); font-size: .8125rem; line-height: 1.25rem; margin-top: .125rem; }
        .dark .gps-car-picker__details { color: rgb(209 213 219); }
        .gps-car-picker__empty { border: 1px dashed rgb(209 213 219); border-radius: .75rem; color: rgb(107 114 128); padding: 1rem; text-align: center; }
        .dark .gps-car-picker__empty { border-color: rgb(75 85 99); color: rgb(156 163 175); }
    </style>
@endonce

<div
    id="{{ $pickerId }}"
    class="gps-car-picker"
    x-data="{ selectedCarId: @js($selectedCarId) }"
    x-init="$watch('selectedCarId', value => $wire.set('mountedActionsData.0.selected_car_id', value))"
>
    <p class="gps-car-picker__hint">
        @if ($search !== '')
            Wyniki wyszukiwania (maks. 10).
        @else
            Najczęściej używane samochody według liczby przypisanych części (maks. 10). Ostatnio wybrany samochód jest na górze, jeśli nadal istnieje.
        @endif
    </p>

    @if (count($cars) > 0)
        <div class="gps-car-picker__list">
            @foreach ($cars as $car)
                <button
                    type="button"
                    class="gps-car-picker__row"
                    x-bind:class="{ 'is-selected': String(selectedCarId || '') === '{{ $car['id'] }}' }"
                    x-on:click="selectedCarId = {{ (int) $car['id'] }}"
                >
                    <span class="gps-car-picker__icon" aria-hidden="true">🚗</span>
                    <span class="gps-car-picker__body">
                        <span class="gps-car-picker__title">{{ $car['title'] }}</span>
                        <span class="gps-car-picker__details">{{ implode(' · ', $car['details']) }}</span>
                    </span>
                </button>
            @endforeach
        </div>
    @else
        <div class="gps-car-picker__empty">Nie znaleziono samochodów</div>
    @endif
</div>
