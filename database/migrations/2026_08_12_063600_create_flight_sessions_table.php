<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        Schema::create('flight_sessions', function (Blueprint $table) use ($driver) {
            $table->uuid('flight_session_id')->primary();
            $table->uuid('mission_id');
            $table->uuid('drone_id');
            $table->uuid('pilot_user_id')->nullable();
            $table->string('flight_code', 50)->unique();

            if ($driver === 'pgsql') {
                $table->geometry('takeoff_location', 'point', 4326)->nullable();
                $table->geometry('landing_location', 'point', 4326)->nullable();
            } else {
                // SQLite is used only as a fast compatibility test database.
                $table->json('takeoff_location')->nullable();
                $table->json('landing_location')->nullable();
            }

            $table->decimal('planned_altitude_meters', 8, 2)->nullable();
            $table->decimal('actual_avg_altitude_meters', 8, 2)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->decimal('flight_duration_minutes', 8, 2)->nullable();
            $table->string('flight_status', 30)->default('planned');
            $table->string('quality_status', 30)->default('pending');
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('mission_id')
                ->references('mission_id')
                ->on('survey_missions')
                ->restrictOnDelete();
            $table->foreign('drone_id')
                ->references('drone_id')
                ->on('drones')
                ->restrictOnDelete();
            $table->foreign('pilot_user_id')
                ->references('user_id')
                ->on('users')
                ->restrictOnDelete();

            $table->index(['mission_id', 'flight_status']);
            $table->index(['mission_id', 'quality_status']);
            $table->index('drone_id');
            $table->index('pilot_user_id');

            if ($driver === 'pgsql') {
                $table->spatialIndex('takeoff_location');
                $table->spatialIndex('landing_location');
            }
        });

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE flight_sessions
                ADD CONSTRAINT flight_sessions_status_check
                CHECK (flight_status IN ('planned', 'flying', 'completed', 'aborted', 'failed')),
                ADD CONSTRAINT flight_sessions_quality_status_check
                CHECK (quality_status IN ('pending', 'acceptable', 'rejected', 'needs_recapture'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_sessions');
    }
};
