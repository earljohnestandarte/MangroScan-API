<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environment_logs', function (Blueprint $table) {
            $table->uuid('environment_log_id')->primary();
            $table->uuid('flight_session_id');
            $table->timestampTz('recorded_at');
            $table->string('weather_condition', 100);
            $table->decimal('wind_speed_mps', 8, 2)->nullable();
            $table->decimal('temperature_celsius', 8, 2)->nullable();
            $table->decimal('humidity_percent', 5, 2)->nullable();
            $table->string('visibility_status', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('flight_session_id')
                ->references('flight_session_id')
                ->on('flight_sessions')
                ->restrictOnDelete();

            $table->index(['flight_session_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_logs');
    }
};
