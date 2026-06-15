<?php

namespace App\Services\ImportMigration;

class ImportReport
{
    public array $warnings = [];
    public array $errors = [];
    public function __construct(public array $counters = []) {}
    public function inc(string $key, int $by = 1): void { $this->counters[$key] = ($this->counters[$key] ?? 0) + $by; }
    public function warning(string $message): void { $this->warnings[] = $message; $this->inc('warnings'); }
    public function error(string $message): void { $this->errors[] = $message; $this->inc('failed_rows'); }
    public function toArray(): array { return ['counters'=>$this->counters,'warnings'=>$this->warnings,'errors'=>$this->errors]; }
}
