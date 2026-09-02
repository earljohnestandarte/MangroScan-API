<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_calibrations', function (Blueprint $table) {
            $table->uuid('calibration_id')->primary();
            $table->uuid('sensor_id');
            $table->date('calibration_date');
            $table->string('calibration_method', 100);
            $table->string('calibration_file_path', 500)->nullable();
            $table->text('calibration_notes')->nullable();
            $table->boolean('is_valid');
            $table->timestampsTz();

            $table->foreign('sensor_id')
                ->references('sensor_id')
                ->on('drone_sensors')
                ->restrictOnDelete();

            $table->index(['sensor_id', 'calibration_date']);
            $table->index(['sensor_id', 'is_valid']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE sensor_calibrations ADD CONSTRAINT sensor_calibrations_method_check CHECK (char_length(trim(calibration_method)) > 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_calibrations');
    }
};
