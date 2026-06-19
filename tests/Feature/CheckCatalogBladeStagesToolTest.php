<?php

namespace Tests\Feature;

use Tests\TestCase;

class CheckCatalogBladeStagesToolTest extends TestCase
{
    public function test_ping_stage_returns_minimal_json(): void
    {
        $this->get('/tools/check-catalog-blade-stages?token=gps_images_import_2026&stage=ping')
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'stage' => 'ping',
            ]);
    }
}
