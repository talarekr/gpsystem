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
            'metaTitle' => __('storefront.contact_title'),
            'metaDescription' => __('storefront.contact_desc'),
            'breadcrumbs' => [
                ['label' => __('storefront.home'), 'url' => route('storefront.home')],
                ['label' => __('storefront.contact')],
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
                    ->subject(__('storefront.contact_mail_subject'));
            });
        } catch (Throwable $exception) {
            Log::warning('Storefront contact form mail failed.', [
                'email' => $data['email'],
                'exception' => $exception->getMessage(),
            ]);

            return back()
                ->withInput()
                ->with('error', __('storefront.send_failed'));
        }

        return back()->with('success', __('storefront.message_sent'));
    }

    /**
     * @param  array{name: string|null, email: string, message: string|null}  $data
     */
    private function messageBody(array $data): string
    {
        return implode("\n", [
            __('storefront.contact_mail_intro'),
            '',
            __('storefront.contact_name').': '.($data['name'] ?: '—'),
            'Email: '.$data['email'],
            '',
            __('storefront.contact_message').':',
            $data['message'] ?: '—',
        ]);
    }
}
