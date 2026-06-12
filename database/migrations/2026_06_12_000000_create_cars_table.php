<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cars', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('vin', 32)->nullable()->index();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->string('model_variant')->nullable();
            $table->unsignedSmallInteger('production_year')->nullable();
            $table->unsignedSmallInteger('first_registration_year')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('steering_side')->nullable();
            $table->unsignedInteger('mileage_km')->nullable();
            $table->string('fuel_type')->nullable();
            $table->unsignedSmallInteger('engine_power_kw')->nullable();
            $table->unsignedInteger('engine_capacity_cm3')->nullable();
            $table->string('engine_code')->nullable();
            $table->string('drivetrain')->nullable();
            $table->string('gearbox_type')->nullable();
            $table->string('gearbox_code')->nullable();
            $table->string('body_type')->nullable();
            $table->string('color_code')->nullable();
            $table->string('color')->nullable();
            $table->string('interior')->nullable();
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->boolean('includes_vat')->default(false);
            $table->string('status')->default('kupiony')->index();
            $table->date('purchase_date')->nullable();
            $table->date('dismantled_at')->nullable();
            $table->text('defects_notes')->nullable();
            $table->string('seller_name')->nullable();
            $table->string('seller_identifier')->nullable();
            $table->text('seller_address')->nullable();
            $table->string('deregistration_responsibility')->nullable();
            $table->string('documents_storage')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('purchase_place')->nullable();
            $table->string('vehicle_location')->nullable();
            $table->string('main_photo_path')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cars');
    }
};
