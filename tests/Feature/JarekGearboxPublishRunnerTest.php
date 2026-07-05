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
            ->assertJsonPath('admin_diagnostics', [])
            ->assertJsonPath('batch_summary.processed_count', 0)
            ->assertJsonPath('completed', false)
            ->assertJsonPath('ready_remaining_count', null);
    }

    public function test_publish_runner_page_loads_with_safe_publish_defaults(): void
    {
        $response = $this->withoutMiddleware()->get('/admin/tools/jarek-gearboxes/publish-runner');

        $response->assertOk()
            ->assertSee('Jarek Gearboxes eBay DE real publish runner')
            ->assertSee('runner zawsze pobiera pierwsze aktualnie gotowe rekordy')
            ->assertSee('value="3"', false)
            ->assertSee('value="10"', false)
            ->assertSee('Ready remaining')
            ->assertSee('dynamic-list-safe offset=0')
            ->assertSee('admin_diagnostics')
            ->assertSee('error_message')
            ->assertSee('request ${url}', false)
            ->assertSee('next_offset=${data.next_offset}', false)
            ->assertSee('ready_remaining=${data.ready_remaining_count', false)
            ->assertSee('raw_response_preview')
            ->assertSee('http_status=${res.status}', false)
            ->assertSee("method:'GET'", false)
            ->assertSee("'X-Requested-With':'XMLHttpRequest'", false)
            ->assertSee("credentials:'same-origin'", false)
            ->assertSee('CSRF/session expired or missing token. Refresh the page and start the runner again.')
            ->assertSee('startRunner')
            ->assertDontSee('START FROM CURRENT OFFSET')
            ->assertDontSee('RESET TO 0')
            ->assertDontSee('total:1507', false);
    }
}
