<?php

namespace App\Enums;

enum UserRole: string
{
    case OwnerAdmin = 'owner_admin';
    case Manager = 'manager';
    case WarehouseProductStaff = 'warehouse_product_staff';
    case PricingStaff = 'pricing_staff';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::OwnerAdmin => 'Owner/Admin',
            self::Manager => 'Manager',
            self::WarehouseProductStaff => 'Warehouse/Product Staff',
            self::PricingStaff => 'Pricing Staff',
            self::Viewer => 'Read-only/Viewer',
        };
    }
}
