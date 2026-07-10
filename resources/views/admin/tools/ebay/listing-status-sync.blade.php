<x-filament-panels::page>
    <div data-marker="{{ $pageMarker }}" class="space-y-6">
        <h1 class="text-2xl font-bold">eBay listing status sync dry-run</h1>
        @if(session('runner_message'))<div class="text-green-700">{{ session('runner_message') }}</div>@endif
        @if(session('runner_error'))<div class="text-red-700">{{ session('runner_error') }}</div>@endif
        <pre class="p-4 bg-gray-100 overflow-auto">{{ json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        <form method="POST" action="/admin/tools/ebay/listing-status-sync/start">@csrf
            <input type="hidden" name="confirm" value="start-ebay-listing-status-sync"><input type="hidden" name="scope" value="products_with_ebay_item_id"><input type="hidden" name="dry_run" value="1">
            <label>Batch size <input name="batch_size" value="10" type="number" min="1" max="20"></label>
            <label>Delay seconds <input name="delay_seconds" value="5" type="number" min="5"></label>
            <button type="submit">Start dry-run</button>
        </form>
        <form method="POST" action="/admin/tools/ebay/listing-status-sync/run-next-batch">@csrf<input type="hidden" name="confirm" value="run-next-ebay-listing-status-sync-batch"><button type="submit">Uruchom następny batch</button></form>
        <form method="POST" action="/admin/tools/ebay/listing-status-sync/stop">@csrf<input type="hidden" name="confirm" value="stop-ebay-listing-status-sync"><button type="submit">Stop</button></form>
    </div>
</x-filament-panels::page>
