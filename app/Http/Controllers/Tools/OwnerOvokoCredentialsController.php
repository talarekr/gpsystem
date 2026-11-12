<?php

namespace App\Http\Controllers\Tools;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class OwnerOvokoCredentialsController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless($request->user()?->hasRole(UserRole::OwnerAdmin->value), 403);
        $headers = [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, noarchive',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ];

        if ($request->isMethod('get')) {
            return response()->view('admin.tools.ovoko-credentials', [], 200, $headers);
        }

        $request->validate(['action' => ['required', 'in:read_saved_credentials']]);

        try {
            // Query Builder avoids models, observers and firstOrCreate. No provider is invoked.
            $row = DB::table('marketplace_accounts')->where('code', 'ovoko_main')->first(['api_credentials']);
            if (! $row) {
                return response()->json(['error' => 'ovoko_account_not_found'], 404, $headers);
            }
            // Same representation as MarketplaceAccount's encrypted:array cast.
            $credentials = empty($row->api_credentials) ? [] : json_decode(
                Crypt::decryptString($row->api_credentials), true, 512, JSON_THROW_ON_ERROR
            );
            if (! is_array($credentials)) {
                return response()->json(['error' => 'ovoko_credentials_invalid_format'], 422, $headers);
            }
            $values = [];
            foreach (['username', 'password', 'user_token'] as $key) {
                $values[$key] = is_string($credentials[$key] ?? null) ? $credentials[$key] : null;
            }

            return response()->json(['credentials' => $values], 200, $headers);
        } catch (DecryptException|\JsonException $e) {
            return response()->json(['error' => 'ovoko_credentials_cannot_be_decrypted'], 422, $headers);
        } catch (\Throwable $e) {
            // Never log or return exception text, ciphertext, credentials or traces.
            return response()->json(['error' => 'ovoko_credentials_read_failed'], 500, $headers);
        }
    }
}
