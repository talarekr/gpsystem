<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Storefront\FrenchPriceAudit;
use Illuminate\Http\Request;

class FrenchPriceAuditController extends Controller
{
    public function __invoke(Request $request, FrenchPriceAudit $audit)
    {
        $input = $request->validate(['format' => ['nullable', 'in:html,json,csv'], 'run' => ['nullable', 'boolean']]);
        if (($input['format'] ?? '') === 'csv') {
            return response()->streamDownload(function () use ($audit): void {
                $stream = fopen('php://output', 'w');
                $columns = ['part_id', 'sku', 'part_number', 'slug', 'parts_price', 'parts_currency', 'ebay_price_pln',
                    'gpswiss_fr_display_price', 'gpswiss_fr_price_source', 'ebay_de_listing_id', 'ebay_de_price', 'ebay_de_currency',
                    'price_difference_abs', 'price_difference_percent', 'reason_code', 'previous_policy_price', 'previous_policy_source', 'previous_reason_code'];
                fputcsv($stream, $columns, ',', '"', '');
                foreach ($audit->rows() as $row) {
                    $values = array_map(fn ($key) => $row[$key], $columns);
                    // Spreadsheet formula injection is not allowed in source names/SKUs/slugs.
                    $values = array_map(fn ($value) => is_string($value) && preg_match('/^[=+@-]/', $value) ? "'".$value : $value, $values);
                    fputcsv($stream, $values, ',', '"', '');
                }
                fclose($stream);
            }, 'gpswiss-fr-vs-ebay-de.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
        }
        $report = ($input['run'] ?? false) || ($input['format'] ?? '') === 'json' ? $audit->report() : null;
        if (($input['format'] ?? '') === 'json') {
            return response()->json($report)->header('Cache-Control', 'private, no-store');
        }

        return response()->view('admin.tools.storefront.fr-price-audit', compact('report'))->header('Cache-Control', 'private, no-store');
    }
}
