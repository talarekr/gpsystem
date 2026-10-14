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
                $columns = ['source_type', 'source_id', 'part_id', 'sku', 'part_number', 'slug', 'parts_price', 'parts_currency',
                    'source_jarek_price_pln', 'applied_margin', 'price_pln_after_margin', 'nbp_eur_rate', 'final_fr_price_eur',
                    'ebay_de_price', 'price_difference_abs', 'reason_code', 'warnings'];
                fputcsv($stream, $columns, ',', '"', '');
                foreach ($audit->rows() as $row) {
                    $values = array_map(fn ($key) => is_array($row[$key]) ? json_encode($row[$key], JSON_UNESCAPED_UNICODE) : $row[$key], $columns);
                    // Spreadsheet formula injection is not allowed in source names/SKUs/slugs.
                    $values = array_map(fn ($value) => is_string($value) && preg_match('/^[=+@-]/', $value) ? "'".$value : $value, $values);
                    fputcsv($stream, $values, ',', '"', '');
                }
                fclose($stream);
            }, 'gpswiss-fr-final-prices.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
        }
        $report = ($input['run'] ?? false) || ($input['format'] ?? '') === 'json' ? $audit->report() : null;
        if (($input['format'] ?? '') === 'json') {
            return response()->json($report)->header('Cache-Control', 'private, no-store');
        }

        return response()->view('admin.tools.storefront.fr-price-audit', compact('report'))->header('Cache-Control', 'private, no-store');
    }
}
