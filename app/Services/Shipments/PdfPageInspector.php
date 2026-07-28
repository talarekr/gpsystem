<?php

namespace App\Services\Shipments;

class PdfPageInspector
{
    /** @return array{width_points:float,height_points:float,width_mm:float,height_mm:float,box:string}|null */
    public function inspect(string $pdf): ?array
    {
        if (! str_starts_with($pdf, '%PDF-')) {
            return null;
        }

        if (preg_match('/\/(?:CropBox|MediaBox)\s*\[\s*(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s*\]/', $pdf, $matches) !== 1) {
            return null;
        }

        $width = abs((float) $matches[3] - (float) $matches[1]);
        $height = abs((float) $matches[4] - (float) $matches[2]);

        return [
            'width_points' => round($width, 2),
            'height_points' => round($height, 2),
            'width_mm' => round($width * 25.4 / 72, 2),
            'height_mm' => round($height * 25.4 / 72, 2),
            'box' => str_contains($matches[0], '/CropBox') ? 'CropBox' : 'MediaBox',
        ];
    }
}
