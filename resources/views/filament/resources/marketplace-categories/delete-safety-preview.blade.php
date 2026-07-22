@php
    $category = $safety['local_category'] ?? [];
    $counts = $safety['counts'] ?? [];
    $countSources = $safety['count_sources'] ?? [];
    $samples = $safety['samples'] ?? [];
    $mappings = $safety['mappings'] ?? [];
@endphp

<div class="space-y-4 text-sm">
    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
        <div><strong>id:</strong> {{ $category['id'] ?? '—' }}</div>
        <div><strong>name:</strong> {{ $category['name'] ?? '—' }}</div>
        <div><strong>category_path:</strong> {{ $category['category_path'] ?? '—' }}</div>
    </div>

    <div class="grid gap-2 md:grid-cols-2">
        <div><strong>products_count:</strong> {{ $counts['products_count'] ?? 0 }}</div>
        <div><strong>children_count:</strong> {{ $counts['children_count'] ?? 0 }}</div>
        <div><strong>descendants_products_count:</strong> {{ $counts['descendants_products_count'] ?? 0 }}</div>
        <div><strong>can_delete:</strong> {{ ($safety['can_delete'] ?? false) ? 'true' : 'false' }}</div>
    </div>

    <div>
        <strong>źródła liczników:</strong>
        <div class="ml-3">products_count: {{ $countSources['products_count'] ?? '—' }}</div>
        <div class="ml-3">descendants_products_count: {{ $countSources['descendants_products_count'] ?? '—' }}</div>
        <div class="ml-3">woo_product_count: {{ $countSources['woo_product_count'] ?? '—' }}</div>
    </div>

    <div>
        <strong>produkty blokujące hard delete (sample):</strong>
        @forelse (($samples['blocking_products'] ?? []) as $product)
            <div class="ml-3">
                #{{ $product['product_id'] }}
                — {{ $product['title'] ?: '—' }}
                — current category_id: {{ $product['current_category_id'] ?? '—' }}
                — <a class="text-primary-600 underline" href="{{ $product['edit_url'] }}" wire:navigate>/admin/parts/{{ $product['product_id'] }}/edit</a>
            </div>
        @empty
            <span>—</span>
        @endforelse
    </div>

    <div>
        <strong>children sample:</strong>
        @forelse (($samples['children'] ?? []) as $child)
            <div class="ml-3">#{{ $child['id'] }} — {{ $child['name'] }} — {{ $child['category_path'] }}</div>
        @empty
            <span>—</span>
        @endforelse
    </div>

    <div>
        <strong>mapping flags/details:</strong>
        <div class="ml-3">has_marketplace_mapping: {{ ($mappings['has_marketplace_mapping'] ?? false) ? 'true' : 'false' }}</div>
        <div class="ml-3">has_ovoko_mapping: {{ ($mappings['has_ovoko_mapping'] ?? false) ? 'true' : 'false' }}</div>
        <div class="ml-3">has_allegro_mapping: {{ ($mappings['has_allegro_mapping'] ?? false) ? 'true' : 'false' }}</div>
        <div class="ml-3">has_ebay_mapping: {{ ($mappings['has_ebay_mapping'] ?? false) ? 'true' : 'false' }}</div>
        @foreach (($mappings['items'] ?? []) as $mapping)
            <div class="ml-3">{{ $mapping['channel'] }}: {{ $mapping['external_category_id'] ?? '—' }} {{ $mapping['external_category_name'] ?? '' }} {{ $mapping['external_category_path'] ?? '' }}</div>
        @endforeach
    </div>

    <div>
        <strong>blockers:</strong>
        <span>{{ collect($safety['blockers'] ?? [])->implode(', ') ?: '—' }}</span>
    </div>
</div>
