<?php

namespace App\Http\Controllers\Storefront\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use Throwable;

class PasswordResetController extends Controller
{
    public function requestForm(): View { return view('storefront.auth.forgot-password'); }

    public function sendLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required','email']]);
        try { Password::sendResetLink($request->only('email')); }
        catch (Throwable) { /* hide mail transport problems */ }
        return back()->with('success', __('storefront.password_link_sent'));
    }

    public function resetForm(string $token, Request $request): View
    {
        return view('storefront.auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate(['token'=>['required'], 'email'=>['required','email'], 'password'=>['required','confirmed', PasswordRule::defaults()]]);
        $status = Password::reset($request->only('email','password','password_confirmation','token'), function ($user, $password): void {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });
        return $status === Password::PASSWORD_RESET ? redirect()->route('storefront.login')->with('success',__('storefront.password_changed')) : back()->withErrors(['email'=>__('storefront.password_reset_failed')])->onlyInput('email');
    }
}
