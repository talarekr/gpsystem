<?php

namespace Database\Seeders;

use App\Enums\EmailTemplateType;
use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (EmailTemplateType::defaults() as $key => $defaults) {
            EmailTemplate::query()->firstOrCreate(
                ['template_key' => $key],
                $defaults,
            );
        }
    }
}
