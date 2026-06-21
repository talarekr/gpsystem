@php
    $part = (isset($part) && $part instanceof \App\Models\Part) ? $part : null;

    if (! $part && isset($getRecord) && is_callable($getRecord)) {
        $candidate = $getRecord();
        $part = $candidate instanceof \App\Models\Part ? $candidate : null;
    } elseif (isset($record) && $record instanceof \App\Models\Part) {
        $part = $record;
    }

    $images = ($part instanceof \App\Models\Part && $part->relationLoaded('images')) ? $part->images : collect();
    $imageUrl = $part instanceof \App\Models\Part ? $part->adminTableImageUrl() : null;
    $imageCount = $images->count();
@endphp

@once
    <style>
        .fi-ta-table { font-size: 13px; }
        .fi-ta-table thead { position: sticky; top: 0; z-index: 10; }
        .fi-ta-table thead th { color: #475569; font-size: 12px; font-weight: 600; }
        .fi-ta-table tbody tr { min-height: 124px; border-color: #eef2f7; }
        .fi-ta-table tbody tr:hover { background: rgba(148, 163, 184, .10); }
        .fi-ta-table:has(.gps-col-image) th.gps-col-image,
        .fi-ta-table:has(.gps-col-image) td.gps-col-image { padding-left: 16px !important; padding-right: 16px !important; }
        .fi-ta-table:has(.gps-col-image) th.gps-col-id,
        .fi-ta-table:has(.gps-col-image) td.gps-col-id { padding-left: 16px !important; padding-right: 16px !important; }
        .fi-ta-table:has(.gps-col-image) th.gps-col-title,
        .fi-ta-table:has(.gps-col-image) td.gps-col-title { padding-left: 16px !important; padding-right: 16px !important; }
        .fi-ta-table:has(.gps-col-image) th.gps-col-number,
        .fi-ta-table:has(.gps-col-image) td.gps-col-number { padding-left: 16px !important; padding-right: 16px !important; }
        .fi-ta-table:has(.gps-col-image) th.gps-col-channels,
        .fi-ta-table:has(.gps-col-image) td.gps-col-channels { padding-left: 16px !important; padding-right: 16px !important; }
        .fi-ta-table:has(.gps-col-image) th.gps-col-storage,
        .fi-ta-table:has(.gps-col-image) td.gps-col-storage { padding-left: 16px !important; padding-right: 16px !important; }
        .fi-ta-table:has(.gps-col-image) th.gps-col-status,
        .fi-ta-table:has(.gps-col-image) td.gps-col-status { padding-left: 16px !important; padding-right: 16px !important; }
        .gps-col-title > *,
        .gps-col-number > *,
        .gps-col-channels > *,
        .gps-col-storage > * { margin-left: 0 !important; padding-left: 0 !important; }
        .gps-col-title .fi-ta-col-wrp,
        .gps-col-number .fi-ta-col-wrp,
        .gps-col-channels .fi-ta-col-wrp,
        .gps-col-storage .fi-ta-col-wrp,
        .gps-col-title-content,
        .gps-col-number-content,
        .gps-col-channels-content,
        .gps-col-storage-content { margin-left: 0 !important; padding-left: 0 !important; }
        .fi-ta-table:has(.gps-col-image) tbody tr > td { vertical-align: top; padding-top: 12px; padding-bottom: 12px; }
        .fi-ta-table:has([data-column="admin_part_image"]) tbody tr > td > .fi-ta-col-wrp,
        .fi-ta-table:has([data-column="admin_part_image"]) tbody tr > td .fi-ta-text,
        .fi-ta-table:has([data-column="admin_part_image"]) tbody tr > td .fi-ta-text-item,
        .fi-ta-table:has([data-column="admin_part_image"]) tbody tr > td .fi-ta-actions,
        .fi-ta-table:has([data-column="admin_part_image"]) tbody tr > td .fi-ta-actions > *,
        .fi-ta-table:has([data-column="admin_part_image"]) [data-column="id"] > *,
        .fi-ta-table:has([data-column="admin_part_image"]) [data-column="admin_part_title"] > *,
        .fi-ta-table:has([data-column="admin_part_image"]) [data-column="admin_part_numbers"] > *,
        .fi-ta-table:has([data-column="admin_part_image"]) [data-column="admin_part_channels"] > *,
        .fi-ta-table:has([data-column="admin_part_image"]) [data-column="admin_part_storage"] > *,
        .fi-ta-table:has([data-column="admin_part_image"]) [data-column="status"] > *,
        .fi-ta-table:has([data-column="admin_part_image"]) tbody tr > td:last-child > * { align-items: flex-start; justify-content: flex-start; }
        .fi-ta-table:has([data-column="admin_part_image"]) [data-column^="admin_part_"] > .fi-ta-col-wrp,
        .fi-ta-table:has([data-column="admin_part_image"]) [data-column^="admin_part_"] .fi-ta-col-wrp,
        .gps-admin-part-cell,
        .gps-admin-part-title,
        .gps-admin-part-numbers,
        .gps-admin-channels,
        .gps-admin-storage { margin-left: 0; padding-left: 0; }
        .fi-ta-table tbody td, .fi-ta-table tbody td * { font-weight: 400; }
        .fi-ta-table [data-column="admin_part_image"] { width: 150px; min-width: 150px; }
        .fi-ta-table [data-column="id"] { width: 70px; min-width: 70px; color: #334155; font-size: 13px; font-weight: 700; vertical-align: top; }
        .fi-ta-table:has([data-column="admin_part_image"]) [data-column="id"] > *,
        .fi-ta-table:has([data-column="admin_part_image"]) [data-column="id"] .fi-ta-col-wrp,
        .fi-ta-table:has([data-column="admin_part_image"]) [data-column="id"] .fi-ta-text,
        .fi-ta-table:has([data-column="admin_part_image"]) [data-column="id"] .fi-ta-text-item { align-items: flex-start !important; justify-content: flex-start !important; margin-top: 0 !important; padding-top: 0 !important; color: #334155; font-weight: 700; }
        .gps-admin-part-id { display: block; padding-top: 0; margin-top: 0; color: #334155; font-size: 13px; font-weight: 700; line-height: 1.25; }
        .fi-ta-table [data-column="admin_part_title"] { width: 380px; min-width: 360px; max-width: 380px; }
        .fi-ta-table [data-column="admin_part_numbers"] { width: 190px; min-width: 170px; }
        .fi-ta-table [data-column="admin_part_channels"] { width: 270px; min-width: 250px; }
        .fi-ta-table [data-column="admin_part_storage"] { width: 160px; min-width: 140px; }
        .gps-admin-part-thumb { position: relative; width: 137px; height: 104px; border: 1px solid #e5e7eb; border-radius: 6px; background: #ffffff; display: block; overflow: hidden; padding: 0; }
        .gps-admin-part-thumb,
        .gps-admin-part-thumb > * { box-sizing: border-box; }
        .gps-admin-part-thumb > :not(.gps-admin-part-thumb__badge) { width: 100%; height: 100%; padding: 0; margin: 0; }
        .gps-admin-part-thumb img { display: block; width: 100%; height: 100%; min-width: 100%; min-height: 100%; max-width: none !important; max-height: none !important; object-fit: cover; padding: 0; margin: 0; }
        .gps-admin-part-thumb__placeholder { color: #94a3b8; font-size: 11px; font-weight: 400; text-align: center; line-height: 1.25; }
        .gps-admin-part-thumb__badge { position: absolute; right: 5px; bottom: 5px; min-width: 18px; border-radius: 999px; background: rgba(248, 250, 252, .88); border: 1px solid rgba(226, 232, 240, .9); color: #64748b; padding: 1px 5px; font-size: 10px; font-weight: 500; line-height: 1.25; text-align: center; }
        .gps-admin-part-title { width: 360px; max-width: 360px; }
        .gps-admin-part-title a { display: -webkit-box; overflow: hidden; color: #1e293b; font-size: 13px; font-weight: 400; line-height: 1.35; text-decoration: none; text-overflow: ellipsis; white-space: normal; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
        .gps-admin-part-title a:hover { color: #2563eb; text-decoration: underline; }
        .gps-admin-part-title small { display: block; margin-top: 6px; color: #64748b; font-size: 12px; font-weight: 400; line-height: 1.35; }
        .gps-admin-part-numbers { display: inline-flex; max-width: 190px; align-items: center; gap: 0; }
        .gps-admin-part-number { display: inline-flex; width: fit-content; max-width: 100%; align-items: baseline; gap: 4px; color: #334155; font-size: 14px; font-weight: 700; line-height: 1.25; }
        .gps-admin-part-number-value { overflow: hidden; color: #334155; font-size: 14px; font-weight: 700; line-height: 1.25; text-overflow: ellipsis; white-space: nowrap; }
        .gps-admin-part-number__label { color: #64748b; font-size: 11px; font-weight: 500; }
        .gps-admin-part-number__value { overflow: hidden; font-size: 14px; font-weight: 700; line-height: 1.25; text-overflow: ellipsis; white-space: nowrap; }
        .gps-admin-part-number__copy { display: inline-flex; width: 18px; height: 18px; align-items: center; justify-content: center; margin-left: 5px; border: 0; border-radius: 999px; background: transparent; color: #64748b; cursor: pointer; padding: 0; vertical-align: middle; }
        .gps-admin-part-number__copy:hover { background: #e2e8f0; color: #1e293b; }
        .gps-admin-part-number__copy svg { width: 13px; height: 13px; }
        .gps-admin-part-number--empty { color: #94a3b8; }
        .gps-admin-channels { display: grid; gap: 3px; min-width: 250px; color: #334155; }
        .gps-admin-channel { display: grid; grid-template-columns: 58px minmax(110px, 1fr) 18px; align-items: baseline; gap: 8px; font-size: 12px; line-height: 1.35; }
        .gps-admin-channel__name { color: #475569; font-weight: 400; }
        .gps-admin-channel__price { color: #1e293b; white-space: nowrap; }
        .gps-admin-channel__calc { margin-left: 4px; color: #94a3b8; font-size: 10px; font-weight: 400; text-transform: lowercase; }
        .gps-admin-channel__state { justify-self: end; font-size: 12px; font-weight: 500; }
        .gps-admin-channel__state--ok { color: #16a34a; }
        .gps-admin-channel__state--empty { color: #94a3b8; }
        .gps-admin-channel__state--warn { color: #ca8a04; font-size: 11px; }
        .gps-admin-storage { min-width: 140px; color: #334155; font-size: 12px; line-height: 1.4; }
        .gps-admin-storage__location { display: block; color: #334155; font-size: 13px; font-weight: 400; }
        .gps-admin-storage__description, .gps-admin-storage__quantity { display: block; margin-top: 3px; color: #64748b; font-size: 12px; font-weight: 400; }
        @media (max-width: 1100px) { .fi-ta-table [data-column="admin_part_title"] { min-width: 330px; } .gps-admin-part-title { width: 330px; max-width: 330px; } }
    </style>
@endonce

<div class="gps-admin-part-cell gps-admin-part-thumb">
    @if (! $part)
        <span class="gps-admin-part-thumb__placeholder">—</span>
    @elseif ($imageUrl)
        <img src="{{ $imageUrl }}" alt="Zdjęcie części #{{ $part->id }}" loading="lazy">
        @if ($imageCount > 1)
            <span class="gps-admin-part-thumb__badge">{{ $imageCount }}</span>
        @endif
    @else
        <span class="gps-admin-part-thumb__placeholder">Brak<br>zdjęcia</span>
    @endif
</div>
