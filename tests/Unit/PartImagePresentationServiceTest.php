<?php

namespace Tests\Unit;

use App\Models\PartImage;
use App\Services\Images\PartImagePresentationService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PartImagePresentationServiceTest extends TestCase
{
    public function test_process_mirrors_presentation_variants_to_public_html_storage_and_replaces_stale_warnings(): void
    {
        if (! extension_loaded('gd') || ! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension is not available.');
        }

        Storage::fake('public');

        $sourcePath = 'parts/photos/imported/test-source.jpg';
        $this->writeTestSourceImage(Storage::disk('public')->path($sourcePath));

        $partImage = new PartImage([
            'path' => $sourcePath,
            'legacy_payload' => [
                'presentation' => [
                    'warnings' => ['Source image file does not exist or is not readable.'],
                ],
            ],
        ]);
        $partImage->id = 16059;
        $partImage->part_id = 7172;

        $payload = app(PartImagePresentationService::class)->process($partImage, true);
        $presentation = $payload['presentation'];

        try {
            $this->assertTrue(Storage::disk('public')->exists($presentation['listing_path']));
            $this->assertTrue(Storage::disk('public')->exists($presentation['product_path']));
            $this->assertFileExists(dirname(base_path()).'/public_html/storage/'.$presentation['listing_path']);
            $this->assertFileExists(dirname(base_path()).'/public_html/storage/'.$presentation['product_path']);
            $this->assertNotContains('Source image file does not exist or is not readable.', $presentation['warnings'] ?? []);
        } finally {
            @unlink(dirname(base_path()).'/public_html/storage/'.$presentation['listing_path']);
            @unlink(dirname(base_path()).'/public_html/storage/'.$presentation['product_path']);
        }
    }

    private function writeTestSourceImage(string $path): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $image = imagecreatetruecolor(120, 80);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 20, 20, 20);
        imagefill($image, 0, 0, $white);
        imagefilledrectangle($image, 20, 15, 100, 65, $black);
        imagejpeg($image, $path, 90);
        imagedestroy($image);
    }
}
