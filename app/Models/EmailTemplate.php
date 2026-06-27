<?php

namespace App\Models;

use App\Enums\EmailTemplateType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_key',
        'name',
        'subject',
        'body',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function type(): ?EmailTemplateType
    {
        return EmailTemplateType::tryFrom($this->template_key);
    }

    public function typeLabel(): string
    {
        return $this->type()?->label() ?? $this->template_key;
    }

    public function groupLabel(): string
    {
        return $this->type()?->groupLabel() ?? 'Szablon lokalny';
    }
}
