<?php

namespace Tests\Unit;

use App\Services\JarekGearboxes\AllegroJarekImportService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AllegroJarekImportServiceTest extends TestCase
{
    public function test_maps_primary_image_before_gallery_without_duplicates(): void
    {
        $service = new AllegroJarekImportService();
        $method = new ReflectionMethod($service, 'mapOffer');
        $method->setAccessible(true);

        $mapped = $method->invoke($service, [
            'id' => '18717293813',
            'name' => 'Skrzynia biegów',
            'primaryImage' => ['url' => 'https://a.allegroimg.com/original/primary'],
            'images' => [
                ['url' => 'https://a.allegroimg.com/original/primary'],
                ['url' => 'https://a.allegroimg.com/original/gallery-1'],
            ],
            'gallery' => [
                ['url' => 'https://a.allegroimg.com/original/gallery-1'],
                ['url' => 'https://a.allegroimg.com/original/gallery-2'],
            ],
            'sellingMode' => ['price' => ['amount' => '6500', 'currency' => 'PLN']],
            'stock' => ['available' => 4],
            'publication' => ['status' => 'ACTIVE'],
        ]);

        $this->assertSame('https://a.allegroimg.com/original/primary', $mapped['main_image_url']);
        $this->assertSame([
            'https://a.allegroimg.com/original/primary',
            'https://a.allegroimg.com/original/gallery-1',
            'https://a.allegroimg.com/original/gallery-2',
        ], $mapped['images']);
    }
}
