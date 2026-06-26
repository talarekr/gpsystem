@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Paginacja" class="fi-pagination grid gap-y-3 sm:flex sm:items-center sm:justify-between">
        <div class="text-sm text-gray-700 dark:text-gray-200">
            @if ($paginator->firstItem())
                <span>Wyświetlono</span>
                <span class="font-medium">{{ $paginator->firstItem() }}</span>
                <span>–</span>
                <span class="font-medium">{{ $paginator->lastItem() }}</span>
                <span>z</span>
                <span class="font-medium">{{ $paginator->total() }}</span>
            @else
                <span>Brak wyników</span>
            @endif
        </div>

        <div class="flex items-center gap-x-1">
            @if ($paginator->onFirstPage())
                <span aria-disabled="true" aria-label="Poprzednia strona" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-400 dark:border-gray-600">Poprzednia</span>
            @else
                <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" rel="prev" aria-label="Poprzednia strona" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">Poprzednia</button>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span aria-disabled="true" class="px-2 py-2 text-sm text-gray-500">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page" class="rounded-lg border border-primary-600 bg-primary-600 px-3 py-2 text-sm font-semibold text-white">{{ $page }}</span>
                        @else
                            <button type="button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">{{ $page }}</button>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" rel="next" aria-label="Następna strona" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">Następna</button>
            @else
                <span aria-disabled="true" aria-label="Następna strona" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-400 dark:border-gray-600">Następna</span>
            @endif
        </div>
    </nav>
@else
    <div class="text-sm text-gray-700 dark:text-gray-200">
        @if ($paginator->firstItem())
            Wyświetlono <span class="font-medium">{{ $paginator->firstItem() }}</span>–<span class="font-medium">{{ $paginator->lastItem() }}</span> z <span class="font-medium">{{ $paginator->total() }}</span>
        @else
            Brak wyników
        @endif
    </div>
@endif
