<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public function test_role_labels_match_mvp_roles(): void
    {
        $this->assertSame('Owner/Admin', UserRole::OwnerAdmin->label());
        $this->assertSame('Manager', UserRole::Manager->label());
        $this->assertSame('Warehouse/Product Staff', UserRole::WarehouseProductStaff->label());
        $this->assertSame('Pricing Staff', UserRole::PricingStaff->label());
        $this->assertSame('Read-only/Viewer', UserRole::Viewer->label());
    }
}
