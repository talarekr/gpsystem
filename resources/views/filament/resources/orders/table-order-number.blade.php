@php
    use App\Filament\Resources\OrderResource;

    $displayNumber = OrderResource::displayOrderNumber($order);
@endphp

<div class="gps-admin-order-number">
    <span class="gps-admin-order-number__value">{{ $displayNumber }}</span>
    @if($order->test_import)
        <span class="gps-admin-order-number__test-badge">TEST</span>
    @endif
</div>
