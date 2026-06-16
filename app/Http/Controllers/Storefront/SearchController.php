<?php
namespace App\Http\Controllers\Storefront;
use App\Http\Controllers\Controller; use Illuminate\Http\RedirectResponse; use Illuminate\Http\Request;
class SearchController extends Controller { public function index(Request $request): RedirectResponse { return redirect()->route('storefront.catalog',$request->query()); } }
