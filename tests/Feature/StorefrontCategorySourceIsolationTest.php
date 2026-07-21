<?php

namespace Tests\Feature;

use App\Models\PartCategory;
use App\Repositories\Legacy\LegacyPartCategoryReadRepository;
use App\Services\Storefront\CategoryTreeService;
use App\Support\Tenancy\StorefrontHostContext;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StorefrontCategorySourceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staging_central_storefront_home_renders_without_tenant_context(): void
    {
        config(['storefront.central_hosts' => ['staging.wystawczesc.pl']]);

        $this->get('https://staging.wystawczesc.pl/')
            ->assertOk();
    }

    public function test_central_storefront_uses_legacy_repository(): void
    {
        Cache::flush();
        config(['storefront.central_hosts' => ['staging.wystawczesc.pl']]);
        $fake = new class extends LegacyPartCategoryReadRepository {
            public int $calls = 0;
            public function publicCategories(): EloquentCollection
            {
                $this->calls++;
                return new EloquentCollection();
            }
        };
        $this->app->instance(LegacyPartCategoryReadRepository::class, $fake);

        $this->get('https://staging.wystawczesc.pl/')
            ->assertOk();

        $this->assertSame(1, $fake->calls);
    }

    public function test_central_storefront_does_not_require_tenant_context_or_tenant_part_category_query(): void
    {
        Cache::flush();
        $context = new StorefrontHostContext(Request::create('https://staging.wystawczesc.pl/'));
        $service = new CategoryTreeService(new class extends LegacyPartCategoryReadRepository {
            public function publicCategories(): EloquentCollection { return new EloquentCollection(); }
        }, $context);

        $this->assertFalse($context->hasTenantContext());
        $this->assertSame('central_storefront', $context->hostType());
        $this->assertSame('legacy_explicit_connection', $service->categorySource());
        $this->assertSame('storefront:legacy:category-tree', $service->cacheKey());
        $this->assertSame([], $service->all()->all());
    }

    public function test_tenant_host_uses_tenant_part_category_model(): void
    {
        Cache::flush();
        $request = Request::create('https://tenant-a.example.test/');
        $request->attributes->set('tenant_uuid', 'tenant-a');
        $context = new StorefrontHostContext($request);
        PartCategory::query()->create(['name' => 'Silniki', 'slug' => 'silniki', 'source_system' => 'woo', 'woo_product_count' => 1, 'is_visible' => true]);

        $service = new CategoryTreeService(new class extends LegacyPartCategoryReadRepository {
            public function publicCategories(): EloquentCollection { throw new \RuntimeException('legacy repository must not be used for tenant source'); }
        }, $context);

        $this->assertSame('tenant_model', $service->categorySource());
        $this->assertSame('storefront:tenant:tenant-a:category-tree', $service->cacheKey());
        $this->assertCount(1, $service->roots());
    }

    public function test_tenant_and_legacy_cache_keys_are_isolated(): void
    {
        $tenantTwo = Request::create('https://tenant-2.example.test/');
        $tenantTwo->attributes->set('tenant_uuid', 'tenant-2');
        $tenantThree = Request::create('https://tenant-3.example.test/');
        $tenantThree->attributes->set('tenant_uuid', 'tenant-3');

        $repo = new LegacyPartCategoryReadRepository();

        $legacy = new CategoryTreeService($repo, new StorefrontHostContext(Request::create('https://staging.wystawczesc.pl/')));
        $two = new CategoryTreeService($repo, new StorefrontHostContext($tenantTwo));
        $three = new CategoryTreeService($repo, new StorefrontHostContext($tenantThree));

        $this->assertSame('storefront:legacy:category-tree', $legacy->cacheKey());
        $this->assertSame('storefront:tenant:tenant-2:category-tree', $two->cacheKey());
        $this->assertSame('storefront:tenant:tenant-3:category-tree', $three->cacheKey());
        $this->assertNotSame($two->cacheKey(), $three->cacheKey());
        $this->assertNotSame($legacy->cacheKey(), $two->cacheKey());
    }

    public function test_diagnostics_endpoint_reports_read_only_storefront_category_sources(): void
    {
        config(['app.env' => 'testing']);
        putenv('TENANCY_DIAGNOSTICS_ENABLED=true');
        putenv('TENANCY_DIAGNOSTICS_TOKEN=secret-token');
        $_ENV['TENANCY_DIAGNOSTICS_ENABLED'] = 'true';
        $_ENV['TENANCY_DIAGNOSTICS_TOKEN'] = 'secret-token';

        $this->withHeader('Authorization', 'Bearer secret-token')
            ->getJson('/admin/tools/tenancy/storefront-category-source-diagnose')
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'status' => 'diagnosed',
                'read_only' => true,
                'database_write' => false,
                'marketplace_write' => false,
                'external_requests' => false,
                'central_storefront_source' => 'legacy_explicit_connection',
                'tenant_storefront_source' => 'tenant_model',
                'central_host_requires_tenant_context' => false,
                'tenant_cache_isolated' => true,
                'legacy_cache_isolated' => true,
                'tenant_model_fallback_added' => false,
            ]);
    }

    public function test_legacy_repository_source_remains_read_only(): void
    {
        $source = file_get_contents(app_path('Repositories/Legacy/LegacyPartCategoryReadRepository.php'));

        foreach (['insert', 'update', 'delete', 'upsert', 'save', 'create', 'Http::', 'http('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
        $this->assertStringContainsString('DB::connection($connection)->table', $source);
        $this->assertStringContainsString('->select($available)', $source);
    }
}
