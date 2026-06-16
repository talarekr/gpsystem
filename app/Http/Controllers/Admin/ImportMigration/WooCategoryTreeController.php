<?php

namespace App\Http\Controllers\Admin\ImportMigration;

use App\Http\Controllers\Controller;
use App\Services\ImportMigration\WooCategoryTreeImport;
use Illuminate\Http\Response;

class WooCategoryTreeController extends Controller
{
    public function audit(WooCategoryTreeImport $import): Response
    {
        $report = $import->audit();
        $json = e(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $auditPath = e(storage_path('app/imports/manual/woo/category_tree_audit.json'));
        $importUrl = e(route('admin.import-migration.woo-products.category-tree.import'));
        $backUrl = e(url('/admin/import-migracyjny/produkty-woo'));
        $csrf = e(csrf_token());
        $status = ($report['csv_readable'] ?? false) && ($report['required_columns_present'] ?? false) ? 'Gotowe do importu' : 'Wymaga uwagi';
        $statusClass = $status === 'Gotowe do importu' ? 'status-ok' : 'status-warning';

        return response(<<<HTML
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audyt drzewa kategorii Woo</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 0; background: #f8fafc; color: #0f172a; }
        main { max-width: 1120px; margin: 0 auto; padding: 32px 20px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 24px; box-shadow: 0 1px 2px rgba(15, 23, 42, .04); }
        .header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
        h1 { margin: 0; font-size: 28px; }
        p { color: #475569; line-height: 1.55; }
        code { background: #f1f5f9; border-radius: 6px; padding: 2px 6px; }
        pre { overflow: auto; padding: 16px; border-radius: 12px; background: #0f172a; color: #e2e8f0; line-height: 1.45; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; margin: 20px 0; }
        .button { appearance: none; border: 0; border-radius: 10px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; padding: 10px 14px; text-decoration: none; }
        .button-primary { background: #2563eb; color: white; }
        .button-secondary { background: #e2e8f0; color: #0f172a; }
        .status { border-radius: 999px; font-size: 13px; font-weight: 700; padding: 6px 10px; white-space: nowrap; }
        .status-ok { background: #dcfce7; color: #166534; }
        .status-warning { background: #fef3c7; color: #92400e; }
        .grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin: 20px 0; }
        .metric { border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px; }
        .metric dt { color: #64748b; font-size: 13px; }
        .metric dd { font-size: 24px; font-weight: 800; margin: 4px 0 0; }
    </style>
</head>
<body>
    <main>
        <section class="card">
            <div class="header">
                <div>
                    <h1>Audyt drzewa kategorii Woo</h1>
                    <p>Raport zapisano w <code>{$auditPath}</code>.</p>
                </div>
                <span class="status {$statusClass}">{$status}</span>
            </div>

            <dl class="grid">
                <div class="metric"><dt>Wiersze CSV</dt><dd>{$report['row_count']}</dd></div>
                <div class="metric"><dt>Kategorie główne</dt><dd>{$report['root_categories']}</dd></div>
                <div class="metric"><dt>Maks. głębokość</dt><dd>{$report['max_depth']}</dd></div>
                <div class="metric"><dt>Z produktami</dt><dd>{$report['categories_with_products']}</dd></div>
                <div class="metric"><dt>Z mapowaniem eBay</dt><dd>{$report['categories_with_ebay_category_id']}</dd></div>
                <div class="metric"><dt>Już w bazie</dt><dd>{$report['existing_woo_categories_in_part_categories']}</dd></div>
            </dl>

            <div class="actions">
                <form method="POST" action="{$importUrl}" onsubmit="return confirm('Uruchomić import drzewa kategorii Woo?');">
                    <input type="hidden" name="_token" value="{$csrf}">
                    <button class="button button-primary" type="submit">Uruchom import drzewa kategorii</button>
                </form>
                <a class="button button-secondary" href="{$backUrl}">Wróć do importu produktów Woo</a>
            </div>

            <h2>Pełny raport JSON</h2>
            <pre>{$json}</pre>
        </section>
    </main>
</body>
</html>
HTML)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function import(WooCategoryTreeImport $import): Response
    {
        $report = $import->import();
        $json = e(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return response("<!doctype html><html lang=\"pl\"><head><meta charset=\"utf-8\"><title>Import drzewa kategorii Woo</title></head><body><h1>Import drzewa kategorii Woo</h1><p>Log zapisano w <code>storage/app/imports/manual/woo/category_tree_import.log</code>.</p><p><a href=\"".e(route('admin.import-migration.woo-products.category-tree.audit'))."\">Wróć do audytu</a></p><pre>{$json}</pre></body></html>")
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
