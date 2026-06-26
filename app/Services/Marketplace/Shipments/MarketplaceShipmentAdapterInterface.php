<?php

namespace App\Services\Marketplace\Shipments;

use App\Models\Order;
use App\Support\Shipments\ShipmentPreviewResult;

interface MarketplaceShipmentAdapterInterface
{
    public function supports(Order $order): bool;

    public function preview(Order $order, array $input = []): ShipmentPreviewResult;

    public function requiredFields(Order $order): array;

    public function capabilities(Order $order): array;
}
