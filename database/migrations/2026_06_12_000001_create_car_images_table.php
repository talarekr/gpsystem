<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('car_id')->constrained('cars')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['car_id', 'sort_order']);
            $table->index(['car_id', 'is_primary']);
        });

        DB::table('cars')
            ->whereNotNull('main_photo_path')
            ->orderBy('id')
            ->select(['id', 'main_photo_path', 'created_at', 'updated_at'])
            ->chunk(100, function ($cars): void {
                foreach ($cars as $car) {
                    DB::table('car_images')->insert([
                        'car_id' => $car->id,
                        'path' => $car->main_photo_path,
                        'sort_order' => 0,
                        'is_primary' => true,
                        'created_at' => $car->created_at,
                        'updated_at' => $car->updated_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_images');
    }
};
