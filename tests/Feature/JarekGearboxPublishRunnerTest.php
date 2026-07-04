<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JarekGearboxPublishRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_runner_batch_requires_confirm_before_marketplace_write(): void
    {
        $response = $this->withoutMiddleware()->getJson('/admin/tools/jarek-gearboxes/publish-runner-batch?offset=0&limit=1');

        $response->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('marketplace_write', false)
            ->assertJsonPath('blockers.0', 'missing_or_invalid_confirm_token')
            ->assertJsonPath('batch_summary.processed_count', 0);
    }

    public function test_publish_runner_page_loads_with_default_test_batch_size(): void
    {
        $response = $this->withoutMiddleware()->get('/admin/tools/jarek-gearboxes/publish-runner');

        $response->assertOk()
            ->assertSee('Jarek Gearboxes eBay DE real publish runner')
            ->assertSee('value="1"', false)
            ->assertSee('value="10"', false);
    }
}
