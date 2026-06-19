<?php

namespace App\Http\Controllers\Storefront\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class CustomerAuthController extends Controller
{
    public function loginForm(): View|RedirectResponse
    {
        return Auth::check() ? redirect()->route('storefront.account') : view('storefront.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required','email'], 'password' => ['required','string']]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Podane dane logowania są nieprawidłowe.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('storefront.account'))->with('success', 'Zalogowano pomyślnie.');
    }

    public function registerForm(): View|RedirectResponse
    {
        return Auth::check() ? redirect()->route('storefront.account') : view('storefront.auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tax_id' => ['nullable','string','max:32'],
            'company_name' => ['nullable','string','max:255'],
            'first_name' => ['required','string','max:120'],
            'last_name' => ['required','string','max:120'],
            'phone' => ['required','string','max:40'],
            'email' => ['required','email','max:255','unique:users,email'],
            'password' => ['required','confirmed', Password::defaults()],
            'terms' => ['accepted'],
        ]);

        $user = User::create([
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'tax_id' => $data['tax_id'] ?? null,
            'company_name' => $data['company_name'] ?? null,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone' => $data['phone'],
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('storefront.account')->with('success', 'Konto zostało utworzone.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('storefront.login')->with('success', 'Wylogowano.');
    }
}
