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

        return response("<!doctype html><html lang=\"pl\"><head><meta charset=\"utf-8\"><title>Audyt drzewa kategorii Woo</title></head><body><h1>Audyt drzewa kategorii Woo</h1><p>Raport zapisano w <code>storage/app/imports/manual/woo/category_tree_audit.json</code>.</p><pre>{$json}</pre></body></html>")
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function import(WooCategoryTreeImport $import): Response
    {
        $report = $import->import();
        $json = e(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return response("<!doctype html><html lang=\"pl\"><head><meta charset=\"utf-8\"><title>Import drzewa kategorii Woo</title></head><body><h1>Import drzewa kategorii Woo</h1><p>Log zapisano w <code>storage/app/imports/manual/woo/category_tree_import.log</code>.</p><pre>{$json}</pre></body></html>")
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
