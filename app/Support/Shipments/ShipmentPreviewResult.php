<?php

namespace App\Support\Shipments;

use JsonSerializable;

class ShipmentPreviewResult implements JsonSerializable
{
    public function __construct(private readonly array $data) {}

    public static function make(array $data): self
    {
        return new self($data);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
