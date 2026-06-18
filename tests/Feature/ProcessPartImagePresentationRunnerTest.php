<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProcessPartImagePresentationRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        putenv('PRODUCT_IMAGES_IMPORT_TOKEN=test-runner-token');
        $_ENV['PRODUCT_IMAGES_IMPORT_TOKEN'] = 'test-runner-token';
        $_SERVER['PRODUCT_IMAGES_IMPORT_TOKEN'] = 'test-runner-token';
    }

    protected function tearDown(): void
    {
        putenv('PRODUCT_IMAGES_IMPORT_TOKEN');
        unset($_ENV['PRODUCT_IMAGES_IMPORT_TOKEN'], $_SERVER['PRODUCT_IMAGES_IMPORT_TOKEN']);

        parent::tearDown();
    }

    public function test_runner_requires_valid_token(): void
    {
        $this->get('/tools/process-part-image-presentation-runner')
            ->assertForbidden();

        $this->get('/tools/process-part-image-presentation-runner?token=wrong')
            ->assertForbidden();
    }

    public function test_runner_without_auto_shows_safe_start_page(): void
    {
        $this->get('/tools/process-part-image-presentation-runner?token=test-runner-token')
            ->assertOk()
            ->assertSee('Ten adres bez <code>auto=1</code> niczego nie uruchamia', false)
            ->assertSee('ALL IMPORTED', false)
            ->assertSee('missing_only=0', false)
            ->assertSee('/tools/process-part-image-presentation-runner?token=test-runner-token&amp;auto=1', false);
    }

    public function test_auto_runner_renders_js_with_safe_defaults_and_real_next_offset_handling(): void
    {
        $this->get('/tools/process-part-image-presentation-runner?token=test-runner-token&auto=1&offset=16020')
            ->assertOk()
            ->assertSee('TRYB: ALL IMPORTED', false)
            ->assertSee('"limit":50', false)
            ->assertSee('"offset":16020', false)
            ->assertSee('"dryRun":false', false)
            ->assertSee('"onlyImported":true', false)
            ->assertSee('"missingOnly":false', false)
            ->assertSee('"force":true', false)
            ->assertSee("offset = Number(json.next_offset || offset);", false)
            ->assertSee("params.set('missing_only', boolParam(config.missingOnly));", false);
    }
}
