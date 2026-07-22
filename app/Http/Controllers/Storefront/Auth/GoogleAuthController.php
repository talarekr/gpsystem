<?php

namespace App\Http\Controllers\Storefront\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        if (! class_exists(\Laravel\Socialite\Facades\Socialite::class)) {
            return back()->with('warning', __('storefront.google_not_ready'));
        }

        return \Laravel\Socialite\Facades\Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        if (! class_exists(\Laravel\Socialite\Facades\Socialite::class)) {
            return redirect()->route('storefront.login')->with('warning', __('storefront.google_callback_not_ready'));
        }

        $googleUser = \Laravel\Socialite\Facades\Socialite::driver('google')->user();
        $nameParts = preg_split('/\s+/', trim((string) $googleUser->getName()), 2) ?: [];

        $user = User::query()->where('google_id', $googleUser->getId())
            ->orWhere('email', $googleUser->getEmail())
            ->first();

        if ($user) {
            $user->forceFill(['google_id' => $user->google_id ?: $googleUser->getId()])->save();
        } else {
            $user = User::create([
                'name' => $googleUser->getName() ?: $googleUser->getEmail(),
                'first_name' => $nameParts[0] ?? null,
                'last_name' => $nameParts[1] ?? null,
                'email' => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'password' => Hash::make(Str::random(32)),
            ]);
        }

        Auth::login($user, true);

        return redirect()->intended(route('storefront.account'))->with('success', __('storefront.google_login_success'));
    }
}
