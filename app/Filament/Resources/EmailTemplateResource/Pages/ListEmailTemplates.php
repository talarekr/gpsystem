<?php

namespace App\Filament\Resources\EmailTemplateResource\Pages;

use App\Enums\EmailTemplateType;
use App\Filament\Resources\EmailTemplateResource;
use App\Models\EmailTemplate;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class ListEmailTemplates extends Page
{
    use WithPagination;

    protected static string $resource = EmailTemplateResource::class;

    protected static string $view = 'filament.resources.email-templates.pages.list-email-templates';

    #[Url(as: 'search')]
    public string $search = '';

    #[Url(as: 'active')]
    public ?string $active = null;

    public int $perPage = 10;

    public function mount(): void
    {
        $this->ensureDefaultTemplatesExist();
    }

    public function getTitle(): string|Htmlable
    {
        return '';
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'active', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->active = null;
        $this->resetPage();
    }

    public function getActiveFiltersCountProperty(): int
    {
        return collect([$this->search, $this->active])->filter(fn (?string $value): bool => filled($value))->count();
    }

    public function getTemplatesProperty(): LengthAwarePaginator
    {
        return EmailTemplate::query()
            ->when(filled($this->search), function (Builder $query): void {
                $search = trim($this->search);
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('template_key', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%");
                });
            })
            ->when(filled($this->active), fn (Builder $query): Builder => $query->where('is_active', $this->active === '1'))
            ->orderByRaw($this->templateOrderSql())
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    private function templateOrderSql(): string
    {
        $cases = collect(EmailTemplateType::cases())
            ->map(fn (EmailTemplateType $type, int $index): string => "WHEN '".str_replace("'", "''", $type->value)."' THEN ".$index)
            ->implode(' ');

        return "CASE template_key {$cases} ELSE 999 END";
    }

    private function ensureDefaultTemplatesExist(): void
    {
        foreach (EmailTemplateType::defaults() as $key => $defaults) {
            EmailTemplate::query()->firstOrCreate(['template_key' => $key], $defaults);
        }
    }
}
