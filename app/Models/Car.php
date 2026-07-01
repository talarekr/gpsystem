<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class Car extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_system',
        'external_id',
        'legacy_payload',
        'vin',
        'make',
        'model',
        'model_variant',
        'production_year',
        'first_registration_year',
        'registration_number',
        'steering_side',
        'mileage_km',
        'fuel_type',
        'engine_power_kw',
        'engine_capacity_cm3',
        'engine_code',
        'drivetrain',
        'gearbox_type',
        'gearbox_code',
        'body_type',
        'color_code',
        'color',
        'interior',
        'purchase_price',
        'includes_vat',
        'status',
        'purchase_date',
        'dismantled_at',
        'defects_notes',
        'seller_name',
        'seller_identifier',
        'seller_address',
        'deregistration_responsibility',
        'documents_storage',
        'payment_method',
        'purchase_place',
        'vehicle_location',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'production_year' => 'integer',
            'first_registration_year' => 'integer',
            'mileage_km' => 'integer',
            'engine_power_kw' => 'integer',
            'engine_capacity_cm3' => 'integer',
            'purchase_price' => 'decimal:2',
            'includes_vat' => 'boolean',
            'purchase_date' => 'date',
            'dismantled_at' => 'date',
            'legacy_payload' => 'array',
        ];
    }


    /**
     * Search cars by a multi-word phrase across visible vehicle identity fields.
     *
     * Every word must match at least one field, but different words may match
     * different fields (for example make + model).
     */
    public function scopeSearchPhrase(Builder $query, ?string $phrase): Builder
    {
        $tokens = collect(preg_split('/\s+/', mb_strtolower(trim((string) $phrase))) ?: [])
            ->filter()
            ->values();

        if ($tokens->isEmpty()) {
            return $query;
        }

        $fields = [
            'make',
            'model',
            'model_variant',
            'engine_code',
            'vin',
            'registration_number',
            'production_year',
            'first_registration_year',
            'fuel_type',
            'body_type',
            'color',
            'drivetrain',
            'steering_side',
            'gearbox_type',
            'gearbox_code',
        ];

        return $query->where(function (Builder $query) use ($tokens, $fields): void {
            foreach ($tokens as $token) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $token).'%';

                $query->where(function (Builder $query) use ($fields, $like): void {
                    foreach ($fields as $field) {
                        $query->orWhereRaw("LOWER(COALESCE({$field}, '')) LIKE ? ESCAPE '\\\\'", [$like]);
                    }
                });
            }
        });
    }

    protected static function booted(): void
    {
        static::creating(function (Car $car): void {
            $userId = Auth::id();

            $car->uuid ??= (string) Str::uuid();
            $car->created_by_user_id ??= $userId;
            $car->updated_by_user_id ??= $userId;
        });

        static::updating(function (Car $car): void {
            $car->updated_by_user_id = Auth::id();
        });
    }

    /**
     * @return array<string, string>
     */
    public static function fuelTypeOptions(): array
    {
        return [
            'benzyna' => 'benzyna',
            'diesel' => 'diesel',
            'hybryda' => 'hybryda',
            'elektryczny' => 'elektryczny',
            'LPG' => 'LPG',
            'inne' => 'inne',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function gearboxTypeOptions(): array
    {
        return [
            'manualna' => 'manualna',
            'automatyczna' => 'automatyczna',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function steeringSideOptions(): array
    {
        return [
            'lewa strona' => 'lewa strona',
            'prawa strona' => 'prawa strona',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function drivetrainOptions(): array
    {
        return [
            'przód' => 'przód',
            'tył' => 'tył',
            'AWD' => 'AWD',
            '4x4' => '4x4',
            'inne' => 'inne',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            'kupiony' => 'kupiony',
            'w demontażu' => 'w demontażu',
            'rozebrany' => 'rozebrany',
            'sprzedany' => 'sprzedany',
            'archiwalny' => 'archiwalny',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
