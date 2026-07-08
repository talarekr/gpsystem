<?php

namespace App\Http\Controllers\Storefront\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return redirect()->route('storefront.login')
                ->with('warning', 'Logowanie Google nie jest jeszcze skonfigurowane. Uzupełnij GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET i GOOGLE_REDIRECT_URI.');
        }

        return \Laravel\Socialite\Facades\Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return redirect()->route('storefront.login')
                ->with('warning', 'Logowanie Google nie jest jeszcze skonfigurowane. Uzupełnij GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET i GOOGLE_REDIRECT_URI.');
        }

        try {
            $googleUser = \Laravel\Socialite\Facades\Socialite::driver('google')->user();
        } catch (\Laravel\Socialite\Two\InvalidStateException) {
            return redirect()->route('storefront.login')
                ->with('warning', 'Sesja logowania Google wygasła albo jest nieprawidłowa. Spróbuj ponownie.');
        } catch (\Throwable) {
            return redirect()->route('storefront.login')
                ->with('warning', 'Nie udało się zalogować przez Google. Spróbuj ponownie lub użyj logowania e-mail/hasło.');
        }

        $googleId = (string) $googleUser->getId();
        $email = Str::lower((string) $googleUser->getEmail());

        if ($googleId === '' || $email === '') {
            return redirect()->route('storefront.login')
                ->with('warning', 'Google nie przekazał wymaganego adresu e-mail. Użyj logowania e-mail/hasło.');
        }

        if ($this->emailIsExplicitlyUnverified($googleUser->user)) {
            return redirect()->route('storefront.login')
                ->with('warning', 'Adres e-mail konta Google nie jest zweryfikowany. Zweryfikuj e-mail w Google albo użyj logowania e-mail/hasło.');
        }

        $userByGoogleId = User::query()->where('google_id', $googleId)->first();
        $userByEmail = User::query()->where('email', $email)->first();

        if ($userByGoogleId && $userByEmail && $userByGoogleId->isNot($userByEmail)) {
            return redirect()->route('storefront.login')
                ->with('warning', 'To konto Google jest już powiązane z innym kontem klienta. Zaloguj się e-mail/hasło albo skontaktuj się z obsługą.');
        }

        if ($userByEmail && $userByEmail->google_id && $userByEmail->google_id !== $googleId) {
            return redirect()->route('storefront.login')
                ->with('warning', 'Dla tego adresu e-mail istnieje już konto powiązane z innym kontem Google. Zaloguj się e-mail/hasło albo skontaktuj się z obsługą.');
        }

        $user = $userByGoogleId ?: $userByEmail;

        if ($user) {
            $this->updateGoogleProfile($user, $googleId, $googleUser->getAvatar());
        } else {
            $user = $this->createGoogleUser($googleUser, $googleId, $email);
        }

        Auth::login($user, true);

        return redirect()->intended(route('storefront.account'))->with('success', 'Zalogowano przez Google.');
    }

    private function isConfigured(): bool
    {
        return class_exists('Laravel\\Socialite\\Facades\\Socialite')
            && filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    private function emailIsExplicitlyUnverified(array $payload): bool
    {
        return array_key_exists('email_verified', $payload) && $payload['email_verified'] === false;
    }

    private function createGoogleUser(\Laravel\Socialite\Contracts\User $googleUser, string $googleId, string $email): User
    {
        $name = trim((string) $googleUser->getName()) ?: $email;
        $nameParts = preg_split('/\s+/', $name, 2) ?: [];

        $attributes = [
            'name' => $name,
            'first_name' => $nameParts[0] ?? null,
            'last_name' => $nameParts[1] ?? null,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make(Str::random(40)),
        ];

        if (Schema::hasColumn('users', 'google_id')) {
            $attributes['google_id'] = $googleId;
        }

        if (Schema::hasColumn('users', 'avatar')) {
            $attributes['avatar'] = $googleUser->getAvatar();
        }

        return User::create($attributes);
    }

    private function updateGoogleProfile(User $user, string $googleId, ?string $avatar): void
    {
        $attributes = [];

        if (Schema::hasColumn('users', 'google_id') && blank($user->google_id)) {
            $attributes['google_id'] = $googleId;
        }

        if (Schema::hasColumn('users', 'avatar') && $avatar) {
            $attributes['avatar'] = $avatar;
        }

        if (Schema::hasColumn('users', 'email_verified_at') && blank($user->email_verified_at)) {
            $attributes['email_verified_at'] = now();
        }

        if ($attributes !== []) {
            $user->forceFill($attributes)->save();
        }
    }
}
