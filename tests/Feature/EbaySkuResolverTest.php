<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Services\Marketplace\EbaySkuResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EbaySkuResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_ebay_sku_is_unique_per_part_and_includes_part_number(): void
    {
        $first = Part::query()->create(['sku' => '3Q0919294F', 'part_number' => '3Q0919294F', 'name' => 'First part']);
        $second = Part::query()->create(['sku' => '3Q0919294F-LOCAL', 'part_number' => '3Q0919294F', 'name' => 'Second part']);

        $resolver = app(EbaySkuResolver::class);

        $this->assertSame('GPS-'.$first->id.'-3Q0919294F', $resolver->resolve($first));
        $this->assertSame('GPS-'.$second->id.'-3Q0919294F', $resolver->resolve($second));
        $this->assertNotSame($resolver->resolve($first), $resolver->resolve($second));
    }
}
