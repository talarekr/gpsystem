@php
    use App\Filament\Resources\EmailTemplateResource;

    $templates = $this->templates;
@endphp

<x-filament-panels::page>
    <style>
        .gps-email-toolbar { display: grid; grid-template-columns: minmax(260px, 1fr) minmax(140px, 180px) auto auto; gap: 12px; align-items: end; margin-bottom: 12px; }
        .gps-email-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .gps-email-field label { font-size: 12px; font-weight: 600; color: #64748b; }
        .gps-email-field input, .gps-email-field select { width: 100%; border: 1px solid #d1d5db; border-radius: 10px; background: #fff; padding: 9px 12px; font-size: 14px; color: #0f172a; }
        .gps-email-filter-summary { display: inline-flex; align-items: center; justify-content: center; min-height: 40px; border-radius: 10px; background: #eff6ff; color: #1d4ed8; font-weight: 700; padding: 0 12px; white-space: nowrap; }
        .gps-email-reset-button { min-height: 40px; border-radius: 10px; border: 1px solid #d1d5db; padding: 0 14px; font-weight: 700; color: #334155; background: #fff; }
        .gps-email-list { display: flex; flex-direction: column; gap: 12px; width: 100%; }
        .gps-email-grid { display: grid; grid-template-columns: minmax(260px, 1.2fr) minmax(220px, 1.4fr) minmax(140px, .7fr) minmax(150px, .7fr) minmax(110px, .5fr); gap: 20px; width: 100%; align-items: center; }
        .gps-email-list-header { padding: 0 18px 4px; color: #64748b; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
        .gps-email-card { width: 100%; display: block; border: 1px solid #e5e7eb; border-radius: 18px; background: #fff; box-shadow: 0 10px 24px rgba(15, 23, 42, .06); padding: 18px; }
        .gps-email-value { color: #1e293b; font-size: 13px; font-weight: 500; line-height: 1.35; overflow-wrap: anywhere; }
        .gps-email-muted { color: #64748b; font-size: 13px; margin-top: 5px; overflow-wrap: anywhere; }
        .gps-email-key { display: inline-flex; margin-top: 8px; border-radius: 999px; background: #f1f5f9; color: #334155; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11px; font-weight: 800; padding: 6px 8px; }
        .gps-email-badge { display: inline-flex; align-items: center; border-radius: 999px; background: #e0f2fe; color: #075985; font-size: 11px; font-weight: 800; line-height: 1; padding: 6px 8px; }
        .gps-email-badge--off { background: #fee2e2; color: #991b1b; }
        .gps-email-action { border-radius: 999px; border: 1px solid #bfdbfe; color: #1d4ed8; font-size: 12px; font-weight: 800; padding: 7px 10px; text-decoration: none; white-space: nowrap; }
        .gps-email-empty { border: 1px dashed #cbd5e1; border-radius: 18px; padding: 32px; text-align: center; color: #64748b; background: #fff; }
        .gps-email-note { border: 1px solid #bfdbfe; border-radius: 16px; background: #eff6ff; color: #1e3a8a; padding: 14px 16px; margin-bottom: 14px; font-size: 13px; }
        .gps-email-pagination { margin-top: 18px; }
        @media (max-width: 1100px) { .gps-email-toolbar, .gps-email-grid { grid-template-columns: 1fr 1fr; } .gps-email-list-header { display: none; } }
        @media (max-width: 700px) { .gps-email-toolbar, .gps-email-grid { grid-template-columns: 1fr; } }
    </style>

    <div class="gps-email-note">
        <strong>Wiadomości E-mail</strong> to lokalne szablony sklepu. Moduł nie wysyła e-maili i nie komunikuje się z marketplace API. Placeholdery można wpisywać w temacie i treści: <code>{customer_name}</code>, <code>{order_number}</code>, <code>{order_total}</code>, <code>{payment_url}</code>, <code>{tracking_number}</code>, <code>{return_url}</code>.
    </div>

    <div class="gps-email-toolbar">
        <div class="gps-email-field">
            <label for="email-template-search">Szukaj</label>
            <input id="email-template-search" type="search" wire:model.live.debounce.500ms="search" placeholder="Klucz, nazwa, temat...">
        </div>
        <div class="gps-email-field">
            <label for="email-template-active">Aktywność</label>
            <select id="email-template-active" wire:model.live="active">
                <option value="">Wszystkie</option>
                <option value="1">Aktywne</option>
                <option value="0">Nieaktywne</option>
            </select>
        </div>
        <div class="gps-email-filter-summary">Filtry: {{ $this->activeFiltersCount }}</div>
        <button class="gps-email-reset-button" type="button" wire:click="resetFilters">Wyczyść filtry</button>
    </div>

    <div class="gps-email-list">
        <div class="gps-email-list-header gps-email-grid">
            <div>Typ</div><div>Temat</div><div>Status</div><div>Aktualizacja</div><div>Akcje</div>
        </div>
        @forelse ($templates as $template)
            <div class="gps-email-card">
                <div class="gps-email-grid">
                    <div>
                        <div class="gps-email-value">{{ $template->name }}</div>
                        <div class="gps-email-muted">{{ $template->groupLabel() }}</div>
                        <span class="gps-email-key">{{ $template->template_key }}</span>
                    </div>
                    <div>
                        <div class="gps-email-value">{{ $template->subject }}</div>
                        <div class="gps-email-muted">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $template->body), 120) }}</div>
                    </div>
                    <div><span class="gps-email-badge {{ $template->is_active ? '' : 'gps-email-badge--off' }}">{{ $template->is_active ? 'Aktywny' : 'Nieaktywny' }}</span></div>
                    <div class="gps-email-muted">{{ $template->updated_at?->format('Y-m-d H:i') ?? '—' }}</div>
                    <div><a class="gps-email-action" href="{{ EmailTemplateResource::getUrl('edit', ['record' => $template]) }}" wire:navigate>Edytuj</a></div>
                </div>
            </div>
        @empty
            <div class="gps-email-empty">Brak szablonów e-mail pasujących do wybranych kryteriów.</div>
        @endforelse
    </div>

    <div class="gps-email-pagination">{{ $templates->links() }}</div>
</x-filament-panels::page>
