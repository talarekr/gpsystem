@php
    $buyerName = trim((string) ($order->customer_name ?: $order->company_name ?: '—'));
    $phone = trim((string) $order->phone);
@endphp

<div class="gps-admin-order-buyer">
    <div class="gps-admin-order-buyer__name">{{ $buyerName }}</div>
    @if($phone !== '')
        <div class="gps-admin-order-buyer__phone">{{ $phone }}</div>
    @endif
</div>
