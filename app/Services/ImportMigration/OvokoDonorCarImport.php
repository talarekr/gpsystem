<?php

namespace App\Services\ImportMigration;

use App\Models\Car;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OvokoDonorCarImport
{
    public const MODE_DRY_RUN = 'dry_run';
    public const MODE_CREATE_ONLY = 'create_only';
    public const MODE_UPDATE_EXISTING = 'update_existing';

    public function __construct(private CsvReader $csvReader) {}

    public function import(string $csvPath, string $mode = self::MODE_DRY_RUN, ?string $summaryPath = null): ImportReport
    {
        $report = new ImportReport(['created'=>0,'updated'=>0,'skipped_existing'=>0,'conflicts'=>0,'max_imported_ovoko_id'=>0]);
        foreach ($this->csvReader->rows($csvPath) as $line => $row) {
            $report->inc('total_rows');
            $id = (int) ($row['ovoko_car_id'] ?? 0);
            if ($id <= 0) { $report->error("Wiersz {$line}: brak poprawnego ovoko_car_id."); continue; }
            $report->counters['max_imported_ovoko_id'] = max($report->counters['max_imported_ovoko_id'], $id);
            foreach ([['vehicle_model','missing_readable_model'],['vehicle_fuel','missing_readable_fuel'],['vehicle_gearbox_type','missing_readable_gearbox'],['vehicle_body_type','missing_readable_body_type'],['vehicle_color','missing_readable_color']] as [$field,$counter]) {
                if (blank($row[$field] ?? null)) $report->inc($counter);
            }
            $existing = Car::query()->find($id);
            $payload = $this->map($id, $row);
            if ($mode === self::MODE_DRY_RUN) { $existing ? $report->inc('skipped_existing') : $report->inc('created'); continue; }
            if ($existing && $existing->source_system && $existing->source_system !== 'ovoko') { $report->inc('conflicts'); $report->warning("Wiersz {$line}: ID {$id} istnieje dla innego źródła."); continue; }
            if ($existing) {
                if ($mode === self::MODE_UPDATE_EXISTING) { $existing->fill($payload)->save(); $report->inc('updated'); }
                else { $report->inc('skipped_existing'); }
                continue;
            }
            $payload['id'] = $id; $payload['uuid'] = (string) Str::uuid();
            Car::query()->create($payload); $report->inc('created');
        }
        if ($mode !== self::MODE_DRY_RUN) $this->repairAutoIncrement('cars', (int) $report->counters['max_imported_ovoko_id']);
        return $report;
    }

    private function map(int $id, array $row): array
    {
        $legacy = ['ovoko_donor_car'=>$row, 'photo'=>$row['photo'] ?? null, 'car_photo_gallery'=>$row['car_photo_gallery'] ?? null];
        return [
            'source_system'=>'ovoko','external_id'=>(string)$id,
            'make'=>$row['vehicle_make'] ?: null,
            'model'=>$row['vehicle_model'] ?: ('Ovoko pojazd '.$id),
            'model_variant'=>$row['vehicle_generation'] ?: ($row['vehicle_engine_marketing'] ?: null),
            'production_year'=>(int)($row['vehicle_year'] ?: $row['car_years'] ?: 0) ?: null,
            'mileage_km'=>(int)($row['mileage_km'] ?: $row['car_mileage'] ?: 0) ?: null,
            'fuel_type'=>$row['vehicle_fuel'] ?: ($row['car_fuel_raw_id'] ? 'raw:'.$row['car_fuel_raw_id'] : null),
            'engine_power_kw'=>(int)($row['vehicle_engine_power_kw'] ?: 0) ?: null,
            'engine_capacity_cm3'=>(int)($row['vehicle_engine_capacity_cc'] ?: $row['car_engine_cubic_capacity'] ?: 0) ?: null,
            'engine_code'=>$row['vehicle_engine_code'] ?: ($row['car_engine_code'] ?: null),
            'drivetrain'=>$row['vehicle_drive_wheels'] ?: ($row['car_wheel_drive_raw_id'] ? 'raw:'.$row['car_wheel_drive_raw_id'] : null),
            'gearbox_type'=>$row['vehicle_gearbox_type'] ?: ($row['car_gearbox_type_raw_id'] ? 'raw:'.$row['car_gearbox_type_raw_id'] : null),
            'gearbox_code'=>$row['car_gearbox_code'] ?: null,
            'body_type'=>$row['vehicle_body_type'] ?: ($row['car_body_type_raw_id'] ? 'raw:'.$row['car_body_type_raw_id'] : null),
            'color_code'=>$row['vehicle_color_code'] ?: ($row['car_color_code'] ?: null),
            'color'=>$row['vehicle_color'] ?: null,
            'steering_side'=>$row['vehicle_steering_position'] ?: ($row['car_wheel_type_raw_id'] ? 'raw:'.$row['car_wheel_type_raw_id'] : null),
            'interior'=>$row['car_interior'] ?: null,
            'defects_notes'=>$row['defectation_notes'] ?: null,
            'dismantled_at'=>$row['dismantling_at'] ?: null,
            'status'=>'kupiony','legacy_payload'=>$legacy,
        ];
    }

    private function repairAutoIncrement(string $table, int $maxId): void
    {
        if ($maxId < 1) return;
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') DB::statement("UPDATE sqlite_sequence SET seq = MAX(seq, {$maxId}) WHERE name = '{$table}'");
        elseif ($driver === 'mysql') DB::statement('ALTER TABLE '.$table.' AUTO_INCREMENT = '.($maxId + 1));
        elseif ($driver === 'pgsql') DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), GREATEST((SELECT MAX(id) FROM {$table}), {$maxId}))");
    }
}
