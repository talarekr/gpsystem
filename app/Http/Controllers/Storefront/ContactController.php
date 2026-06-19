<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

class ContactController extends Controller
{
    public function show(): View
    {
        return view('storefront.contact', [
            'metaTitle' => 'Kontakt - GPSwiss',
            'metaDescription' => 'Dane kontaktowe firmy GREGOR swiss GRZEGORZ PACIOREK oraz formularz kontaktowy GPSwiss.',
            'breadcrumbs' => [
                ['label' => 'Strona główna', 'url' => route('storefront.home')],
                ['label' => 'Kontakt'],
            ],
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email:rfc,dns', 'max:160'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            Mail::raw($this->messageBody($data), function ($message) use ($data): void {
                $message->to('biuro@gpswiss.pl')
                    ->replyTo($data['email'], $data['name'] ?: null)
                    ->subject('Wiadomość z formularza kontaktowego GPSwiss');
            });
        } catch (Throwable $exception) {
            Log::warning('Storefront contact form mail failed.', [
                'email' => $data['email'],
                'exception' => $exception->getMessage(),
            ]);

            return back()
                ->withInput()
                ->with('error', 'Nie udało się wysłać wiadomości. Spróbuj ponownie lub napisz bezpośrednio na biuro@gpswiss.pl.');
        }

        return back()->with('success', 'Dziękujemy. Wiadomość została wysłana.');
    }

    /**
     * @param  array{name: string|null, email: string, message: string|null}  $data
     */
    private function messageBody(array $data): string
    {
        return implode("\n", [
            'Nowa wiadomość z formularza kontaktowego GPSwiss',
            '',
            'Imię i nazwisko: '.($data['name'] ?: '—'),
            'Email: '.$data['email'],
            '',
            'Wiadomość:',
            $data['message'] ?: '—',
        ]);
    }
}
