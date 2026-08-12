<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drone_sensors', function (Blueprint $table) {
            $table->uuid('sensor_id')->primary();
            $table->uuid('drone_id');
            $table->string('sensor_name', 100);
            $table->string('sensor_type', 50);
            $table->string('manufacturer', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('resolution', 80)->nullable();
            $table->decimal('range_meters', 8, 2)->nullable();
            $table->boolean('calibration_required')->default(false);
            $table->string('status', 30)->default('active');
            $table->timestampsTz();
            $table->foreign('drone_id')->references('drone_id')->on('drones')->cascadeOnDelete();
            $table->index(['drone_id', 'status']);
            $table->index('serial_number');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE drone_sensors ADD CONSTRAINT drone_sensors_type_check CHECK (sensor_type IN ('rgb_camera','lidar','depth','gps','imu'))");
            DB::statement("ALTER TABLE drone_sensors ADD CONSTRAINT drone_sensors_status_check CHECK (status IN ('active','inactive','maintenance'))");
            DB::statement('ALTER TABLE drone_sensors ADD CONSTRAINT drone_sensors_range_check CHECK (range_meters IS NULL OR range_meters > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('drone_sensors');
    }
};
