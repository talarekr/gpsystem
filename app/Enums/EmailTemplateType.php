<?php

namespace App\Enums;

enum EmailTemplateType: string
{
    case StoreOrderNew = 'store_order_new';
    case StoreOrderProcessing = 'store_order_processing';
    case StoreOrderShipped = 'store_order_shipped';
    case StoreOrderCancelled = 'store_order_cancelled';
    case StoreOrderAwaitingPayment = 'store_order_awaiting_payment';
    case CustomerRegistered = 'customer_registered';
    case PasswordReset = 'password_reset';
    case OrderReturn = 'order_return';

    public function label(): string
    {
        return match ($this) {
            self::StoreOrderNew => 'Zamówienie: nowe',
            self::StoreOrderProcessing => 'Zamówienie: w realizacji',
            self::StoreOrderShipped => 'Zamówienie: wysłane',
            self::StoreOrderCancelled => 'Zamówienie: anulowane',
            self::StoreOrderAwaitingPayment => 'Zamówienie: oczekuje na płatność',
            self::CustomerRegistered => 'Klient: rejestracja konta',
            self::PasswordReset => 'Klient: przypomnienie/reset hasła',
            self::OrderReturn => 'Klient: zwrot do zamówienia',
        };
    }

    public function groupLabel(): string
    {
        return str_starts_with($this->value, 'store_order_') ? 'Status zamówienia sklepu' : 'Wiadomość klienta';
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])->all();
    }

    public static function defaults(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type): array => [$type->value => [
            'name' => $type->label(),
            'subject' => $type->defaultSubject(),
            'body' => $type->defaultBody(),
            'is_active' => true,
        ]])->all();
    }

    public function defaultSubject(): string
    {
        return match ($this) {
            self::StoreOrderNew => 'Potwierdzenie zamówienia {order_number}',
            self::StoreOrderProcessing => 'Zamówienie {order_number} jest w realizacji',
            self::StoreOrderShipped => 'Zamówienie {order_number} zostało wysłane',
            self::StoreOrderCancelled => 'Zamówienie {order_number} zostało anulowane',
            self::StoreOrderAwaitingPayment => 'Oczekujemy na płatność za zamówienie {order_number}',
            self::CustomerRegistered => 'Witamy w sklepie GPSwiss',
            self::PasswordReset => 'Reset hasła do konta GPSwiss',
            self::OrderReturn => 'Informacje o zwrocie zamówienia {order_number}',
        };
    }

    public function defaultBody(): string
    {
        return match ($this) {
            self::StoreOrderNew => "Dzień dobry {customer_name},\n\nDziękujemy za złożenie zamówienia {order_number}. Wartość zamówienia: {order_total}.\n\nPozdrawiamy,\nZespół GPSwiss",
            self::StoreOrderProcessing => "Dzień dobry {customer_name},\n\nZamówienie {order_number} jest w realizacji. Poinformujemy o wysyłce w kolejnej wiadomości.\n\nPozdrawiamy,\nZespół GPSwiss",
            self::StoreOrderShipped => "Dzień dobry {customer_name},\n\nZamówienie {order_number} zostało wysłane. Numer śledzenia: {tracking_number}.\n\nPozdrawiamy,\nZespół GPSwiss",
            self::StoreOrderCancelled => "Dzień dobry {customer_name},\n\nZamówienie {order_number} zostało anulowane. W razie pytań prosimy o kontakt z obsługą sklepu.\n\nPozdrawiamy,\nZespół GPSwiss",
            self::StoreOrderAwaitingPayment => "Dzień dobry {customer_name},\n\nOczekujemy na płatność za zamówienie {order_number} na kwotę {order_total}. Link do płatności: {payment_url}.\n\nPozdrawiamy,\nZespół GPSwiss",
            self::CustomerRegistered => "Dzień dobry {customer_name},\n\nTwoje konto w sklepie GPSwiss zostało utworzone. Dziękujemy za rejestrację.\n\nPozdrawiamy,\nZespół GPSwiss",
            self::PasswordReset => "Dzień dobry {customer_name},\n\nOtrzymaliśmy prośbę o reset hasła. Skorzystaj z linku: {password_reset_url}.\n\nJeśli to nie Ty, zignoruj tę wiadomość.",
            self::OrderReturn => "Dzień dobry {customer_name},\n\nPrzyjęliśmy zgłoszenie zwrotu dla zamówienia {order_number}. Szczegóły znajdziesz tutaj: {return_url}.\n\nPozdrawiamy,\nZespół GPSwiss",
        };
    }
}
