<?php

namespace App\Services\Storefront;

use App\Models\JarekFrProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class JarekFrCatalog
{
    public function query(?Request $request = null)
    {
        $query = JarekFrProduct::query()->active()->with('gearbox.translations');
        $q = trim((string) $request?->input('q', ''));
        $number = trim((string) $request?->input('part_number', ''));
        foreach (array_filter([$q, $number]) as $term) {
            foreach (preg_split('/\s+/u', $term) ?: [] as $token) {
                $query->whereHas('gearbox', fn ($source) => $source->where(fn ($text) => $text->where('title', 'like', '%'.$token.'%')
                    ->orWhereHas('translations', fn ($fr) => $fr->where('locale', 'fr')->whereIn('status', ['translated', 'reviewed'])->where('title', 'like', '%'.$token.'%'))));
            }
        }

        return $query;
    }

    public function listing(Request $request)
    {
        if (! Schema::hasTable('jarek_fr_products')) {
            return null;
        }
        $query = $this->query($request);
        if (in_array($request->input('sort'), ['price_asc', 'price_desc'], true)) {
            $query->join('jarek_gearboxes', 'jarek_gearboxes.id', '=', 'jarek_fr_products.jarek_gearbox_id')
                ->select('jarek_fr_products.*')->orderBy('jarek_gearboxes.price', $request->input('sort') === 'price_asc' ? 'asc' : 'desc');
        } else {
            $query->latest('jarek_fr_products.id');
        }

        return $query->paginate(30, ['*'], 'jarek_page')->withQueryString();
    }
}
