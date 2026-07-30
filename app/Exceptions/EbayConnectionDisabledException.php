<?php
namespace App\Exceptions;
use RuntimeException;
class EbayConnectionDisabledException extends RuntimeException
{
    public function __construct(public readonly string $blockedAction) { parent::__construct('eBay jest aktualnie wyłączony w ustawieniach integracji. Włącz go w Narzędzia → eBay connection toggle.'); }
}
