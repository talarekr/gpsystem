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
            ->assertJsonPath('ready_remaining_count', null)
            ->assertJsonPath('scan_next_offset', 0)
            ->assertJsonPath('scan_exhausted', false)
            ->assertJsonPath('found_ready_count', 0);
    }

    public function test_publish_runner_page_loads_with_safe_publish_defaults(): void
    {
        $response = $this->withoutMiddleware()->get('/admin/tools/jarek-gearboxes/publish-runner');

        $response->assertOk()
            ->assertSee('Jarek Gearboxes eBay DE publish runner')
            ->assertSee('find-next-ready')
            ->assertSee('value="3"', false)
            ->assertSee('value="10"', false)
            ->assertSee('Ready remaining')
            ->assertSee('Scan remaining ready')
            ->assertSee('START SCAN REMAINING')
            ->assertSee('publish-runner-scan-batch')
            ->assertSee('admin_diagnostics')
            ->assertSee('error_message')
            ->assertSee('request ${url}', false)
            ->assertSee('next_offset=${d.next_offset}', false)
            ->assertSee('scan_next_offset=${d.scan_next_offset??d.next_offset}', false)
            ->assertSee('ready_remaining=not_counted')
            ->assertSee('raw_response_preview')
            ->assertSee('http_status=${http}', false)
            ->assertSee("'X-Requested-With':'XMLHttpRequest'", false)
            ->assertSee("credentials:'same-origin'", false)
            ->assertSee('CSRF/session expired. Refresh page.')
            ->assertSee('function runner')
            ->assertDontSee('START FROM CURRENT OFFSET')
            ->assertDontSee('RESET TO 0')
            ->assertDontSee('total:1507', false);
    }
}
