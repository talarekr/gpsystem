<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\CustomerReturn;
use App\Models\ShopEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class CustomerAccountController extends Controller
{
    public function __invoke(): View
    {
        $user = Auth::user();
        $orders = $this->ordersFor($user->email);
        $returnableOrders = $orders->filter(fn (ShopEvent $order): bool => $this->isCompleted($order));
        $returns = CustomerReturn::query()->where('user_id', $user->id)->latest()->get();

        return view('storefront.account.dashboard', compact('user', 'orders', 'returnableOrders', 'returns'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tax_id' => ['nullable','string','max:32'], 'company_name' => ['nullable','string','max:255'],
            'first_name' => ['required','string','max:120'], 'last_name' => ['required','string','max:120'],
            'phone' => ['required','string','max:40'], 'email' => ['required','email','max:255','unique:users,email,'.Auth::id()],
        ]);
        $data['name'] = trim($data['first_name'].' '.$data['last_name']);
        Auth::user()->update($data);
        return back()->with('success', __('storefront.data_saved'));
    }

    public function storeReturn(Request $request): RedirectResponse
    {
        $data = $request->validate(['order_id'=>['required','integer'], 'reason'=>['required','string','max:255'], 'message'=>['nullable','string','max:2000']]);
        $order = $this->ordersFor(Auth::user()->email)->firstWhere('id', (int) $data['order_id']);
        if (! $order || ! $this->isCompleted($order)) {
            return back()->withErrors(['order_id' => __('storefront.return_only_completed')]);
        }
        CustomerReturn::create(['user_id'=>Auth::id(), 'order_id'=>$order->id, 'reason'=>$data['reason'], 'message'=>$data['message'] ?? null]);
        return back()->with('success', __('storefront.return_saved'));
    }

    private function ordersFor(string $email): Collection
    {
        return ShopEvent::query()->where('event_type','order')->where(function ($query) use ($email): void {
            $query->where('payload->customer_email', $email)->orWhere('payload->email', $email)->orWhere('description', 'like', '%'.$email.'%');
        })->latest('occurred_at')->latest()->get();
    }

    private function isCompleted(ShopEvent $order): bool
    {
        $status = mb_strtolower((string) data_get($order->payload, 'status', data_get($order->payload, 'order_status', $order->severity)));
        return in_array($status, ['completed','complete','delivered','zrealizowane','zrealizowany','dostarczone','dostarczony','success'], true);
    }
}
