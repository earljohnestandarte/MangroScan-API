<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_dataset_upload_sessions', function (Blueprint $table) {
            $table->uuid('upload_id')->primary();
            $table->uuid('flight_session_id');
            $table->uuid('sensor_id');
            $table->uuid('initiated_by_user_id');
            $table->string('idempotency_key', 100);
            $table->string('request_fingerprint', 64);
            $table->string('storage_disk', 100);
            $table->string('storage_key', 1024)->unique();
            $table->string('file_name', 255);
            $table->string('dataset_type', 50);
            $table->string('file_format', 50);
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('spatial_reference', 80)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('upload_status', 30)->default('initiated');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->foreign('flight_session_id')->references('flight_session_id')->on('flight_sessions')->restrictOnDelete();
            $table->foreign('sensor_id')->references('sensor_id')->on('drone_sensors')->restrictOnDelete();
            $table->foreign('initiated_by_user_id')->references('user_id')->on('users')->restrictOnDelete();
            $table->unique(['initiated_by_user_id', 'idempotency_key']);
            $table->index(['flight_session_id', 'upload_status']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("ALTER TABLE sensor_dataset_upload_sessions ADD CONSTRAINT sensor_dataset_upload_type_check CHECK (dataset_type IN ('lidar_point_cloud','depth_map','gps_log','imu_log')), ADD CONSTRAINT sensor_dataset_upload_status_check CHECK (upload_status IN ('initiated','completed','expired','failed')), ADD CONSTRAINT sensor_dataset_upload_expiry_check CHECK (expires_at > created_at)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_dataset_upload_sessions');
    }
};
