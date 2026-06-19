<div data-diagnostic="catalog-simple">
    @forelse($parts as $part)
        <article>{{ $part->name ?? 'Część samochodowa' }}</article>
    @empty
        <p>Brak produktów.</p>
    @endforelse
</div>
