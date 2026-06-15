<?php

namespace App\Services\ImportMigration;

use App\Models\Car;
use App\Models\Part;
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
            $this->createCarWithOvokoPrimaryKey($id, $payload); $report->inc('created');
        }
        if ($mode !== self::MODE_DRY_RUN) {
            $this->repairAutoIncrement('cars', (int) $report->counters['max_imported_ovoko_id']);
        }

        $this->addDiagnostics($report);

        return $report;
    }

    public function cleanupBadImport(): ImportReport
    {
        $report = new ImportReport(['deleted'=>0]);
        $ids = Car::query()->where('source_system', 'ovoko')->pluck('id');

        if ($ids->isEmpty()) {
            $this->addDiagnostics($report);
            return $report;
        }

        $linkedParts = Part::query()->whereIn('car_id', $ids)->count();

        if ($linkedParts > 0) {
            $report->warning("Nie usunięto importu Ovoko: {$linkedParts} części jest przypiętych do samochodów Ovoko.");
            $this->addDiagnostics($report);
            return $report;
        }

        $report->counters['deleted'] = Car::query()->whereIn('id', $ids)->delete();
        $this->repairAutoIncrement('cars', (int) Car::query()->max('id'));
        $this->addDiagnostics($report);

        return $report;
    }

    private function createCarWithOvokoPrimaryKey(int $id, array $payload): Car
    {
        $car = new Car();
        $car->forceFill($payload);
        $car->id = $id;
        $car->uuid = (string) Str::uuid();
        $car->save();

        return $car;
    }

    private function addDiagnostics(ImportReport $report): void
    {
        $ovokoCars = Car::query()->where('source_system', 'ovoko')->get(['id', 'external_id']);
        $mismatches = $ovokoCars->filter(fn (Car $car): bool => (int) $car->external_id !== (int) $car->id);

        $report->counters['diagnostic_total_imported_ovoko_cars'] = $ovokoCars->count();
        $report->counters['diagnostic_max_local_car_id'] = (int) Car::query()->max('id');
        $report->counters['diagnostic_max_external_id'] = (int) $ovokoCars->max(fn (Car $car): int => (int) $car->external_id);
        $report->counters['diagnostic_ovoko_source_count'] = $ovokoCars->count();
        $report->counters['diagnostic_id_mismatch_count'] = $mismatches->count();

        if ($report->counters['diagnostic_id_mismatch_count'] > 0) {
            $sample = $mismatches->take(5)->map(fn (Car $car): string => "local {$car->id} / ovoko {$car->external_id}")->implode(', ');
            $report->warning('Import nie zachował zgodności ID: cars.id musi być równe ovoko_car_id. Przykłady: '.$sample);
        }
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
