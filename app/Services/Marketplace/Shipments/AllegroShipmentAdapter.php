<?php

namespace App\Services\Marketplace\Shipments;

use App\Models\Order;
use App\Support\AllegroShipmentPreviewBuilder;
use App\Support\Shipments\ShipmentPreviewResult;
use BadMethodCallException;
use Illuminate\Support\Str;

class AllegroShipmentAdapter implements MarketplaceShipmentAdapterInterface
{
    public function __construct(private readonly AllegroShipmentPreviewBuilder $previewBuilder) {}

    public function supports(Order $order): bool
    {
        return Str::lower((string) $order->marketplace) === 'allegro';
    }

    public function preview(Order $order, array $input = []): ShipmentPreviewResult
    {
        return ShipmentPreviewResult::make($this->previewBuilder->build($order, $input));
    }

    public function requiredFields(Order $order): array
    {
        return ['weight', 'length', 'width', 'height', 'package_type', 'label_reference'];
    }

    public function capabilities(Order $order): array
    {
        return [
            'can_create_shipment' => true,
            'can_download_label' => true,
            'can_order_pickup' => true,
            'requires_package_dimensions' => true,
            'requires_weight' => true,
            'flow' => 'allegro_shipment_management',
        ];
    }

    public function create(Order $order, array $input = []): never
    {
        throw new BadMethodCallException('Real Allegro shipment creation is not implemented in the read-only stage.');
    }
}
