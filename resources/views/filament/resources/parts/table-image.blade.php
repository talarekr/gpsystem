@php
    $images = $record->relationLoaded('images') ? $record->images : collect();
    $imageUrl = $record->primary_image_url;
    $imageCount = $images->count();
@endphp

@once
    <style>
        .fi-ta-table thead { position: sticky; top: 0; z-index: 10; }
        .fi-ta-table tbody tr { min-height: 118px; }
        .fi-ta-table tbody tr:hover { background: rgba(15, 23, 42, .035); }
        .gps-admin-part-thumb { position: relative; width: 96px; height: 96px; border: 1px solid #dbe3ef; border-radius: 12px; background: #f8fafc; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .gps-admin-part-thumb img { width: 100%; height: 100%; object-fit: contain; padding: 6px; }
        .gps-admin-part-thumb__placeholder { color: #94a3b8; font-size: 12px; font-weight: 700; text-align: center; line-height: 1.15; }
        .gps-admin-part-thumb__badge { position: absolute; right: 6px; bottom: 6px; min-width: 24px; border-radius: 999px; background: rgba(15, 23, 42, .84); color: white; padding: 2px 7px; font-size: 11px; font-weight: 800; text-align: center; }
        .gps-admin-part-title { min-width: 260px; max-width: 420px; }
        .gps-admin-part-title a { color: #0f172a; font-size: 14px; font-weight: 800; line-height: 1.25; }
        .gps-admin-part-title a:hover { color: #2563eb; text-decoration: underline; }
        .gps-admin-part-title small { display: block; margin-top: 5px; color: #64748b; font-size: 12px; }
        .gps-admin-chip-wrap { display: flex; flex-wrap: wrap; gap: 6px; min-width: 180px; }
        .gps-admin-chip { border: 1px solid #cbd5e1; border-radius: 999px; background: #f8fafc; padding: 3px 8px; color: #334155; font-size: 12px; font-weight: 700; }
        .gps-admin-chip--main { background: #e0f2fe; border-color: #7dd3fc; color: #075985; }
        .gps-admin-channels { display: grid; gap: 5px; min-width: 230px; }
        .gps-admin-channel { display: grid; grid-template-columns: 70px 1fr auto; align-items: center; gap: 8px; font-size: 12px; }
        .gps-admin-channel__name { font-weight: 800; color: #334155; }
        .gps-admin-channel__price { color: #0f172a; white-space: nowrap; }
        .gps-admin-channel__calc { margin-left: 4px; color: #64748b; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .gps-admin-channel__state { font-weight: 900; }
        .gps-admin-channel__state--ok { color: #16a34a; }
        .gps-admin-channel__state--empty { color: #94a3b8; }
        .gps-admin-channel__state--warn { color: #d97706; }
        .gps-admin-storage { min-width: 170px; color: #334155; font-size: 12px; }
        .gps-admin-storage strong { display: block; color: #0f172a; font-size: 13px; }
        @media (max-width: 1100px) { .gps-admin-part-thumb { width: 88px; height: 88px; } .gps-admin-part-title { min-width: 220px; } }
    </style>
@endonce

<div class="gps-admin-part-thumb">
    @if ($imageUrl)
        <img src="{{ $imageUrl }}" alt="Zdjęcie części #{{ $record->id }}" loading="lazy">
        @if ($imageCount > 1)
            <span class="gps-admin-part-thumb__badge">{{ $imageCount }}</span>
        @endif
    @else
        <span class="gps-admin-part-thumb__placeholder">Brak<br>zdjęcia</span>
    @endif
</div>
