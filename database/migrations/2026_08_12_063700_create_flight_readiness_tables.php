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

        Schema::create('flight_waypoints', function (Blueprint $table) use ($driver) {
            $table->uuid('waypoint_id')->primary();
            $table->uuid('flight_session_id');
            $table->unsignedInteger('sequence_no');

            if ($driver === 'pgsql') {
                $table->geometry('waypoint_location', 'point', 4326);
            } else {
                // SQLite is used only as a fast compatibility test database.
                $table->json('waypoint_location');
            }

            $table->decimal('altitude_meters', 8, 2)->nullable();
            $table->decimal('speed_mps', 8, 2)->nullable();
            $table->string('action', 80)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('flight_session_id')
                ->references('flight_session_id')
                ->on('flight_sessions')
                ->cascadeOnDelete();
            $table->unique(['flight_session_id', 'sequence_no']);

            if ($driver === 'pgsql') {
                $table->spatialIndex('waypoint_location');
            }
        });

        Schema::create('flight_checklists', function (Blueprint $table) {
            $table->uuid('checklist_id')->primary();
            $table->uuid('flight_session_id');
            $table->uuid('checked_by');
            $table->string('checklist_type', 30);
            $table->boolean('battery_ok');
            $table->boolean('weather_ok');
            $table->boolean('gps_ok');
            $table->boolean('camera_ok');
            $table->boolean('lidar_depth_ok');
            $table->boolean('storage_ok');
            $table->string('overall_status', 30);
            $table->text('remarks')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('flight_session_id')
                ->references('flight_session_id')
                ->on('flight_sessions')
                ->cascadeOnDelete();
            $table->foreign('checked_by')
                ->references('user_id')
                ->on('users')
                ->restrictOnDelete();
            $table->index(['flight_session_id', 'checklist_type', 'created_at']);
        });

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE flight_waypoints
                ADD CONSTRAINT flight_waypoints_action_check
                CHECK (action IS NULL OR action IN ('capture', 'turn', 'hover', 'return_home'))
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE flight_checklists
                ADD CONSTRAINT flight_checklists_type_check
                CHECK (checklist_type IN ('pre_flight', 'post_flight')),
                ADD CONSTRAINT flight_checklists_status_check
                CHECK (overall_status IN ('passed', 'failed', 'conditional'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_checklists');
        Schema::dropIfExists('flight_waypoints');
    }
};
