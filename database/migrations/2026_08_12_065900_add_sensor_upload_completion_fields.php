<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sensor_dataset_upload_sessions', function (Blueprint $table) {
            $table->string('completion_idempotency_key', 100)->nullable();
            $table->string('completion_fingerprint', 64)->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('sensor_dataset_id')->nullable();
            $table->foreign('sensor_dataset_id')->references('sensor_dataset_id')->on('sensor_datasets')->restrictOnDelete();
            $table->unique(['initiated_by_user_id', 'completion_idempotency_key']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("ALTER TABLE sensor_dataset_upload_sessions
                ADD CONSTRAINT sensor_dataset_upload_completion_checksum_check
                    CHECK (checksum_sha256 IS NULL OR checksum_sha256 ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT sensor_dataset_upload_completion_state_check CHECK (
                    (upload_status = 'completed'
                        AND completion_idempotency_key IS NOT NULL
                        AND completion_fingerprint IS NOT NULL
                        AND checksum_sha256 IS NOT NULL
                        AND completed_at IS NOT NULL
                        AND sensor_dataset_id IS NOT NULL)
                    OR
                    (upload_status <> 'completed'
                        AND completion_idempotency_key IS NULL
                        AND completion_fingerprint IS NULL
                        AND checksum_sha256 IS NULL
                        AND completed_at IS NULL
                        AND sensor_dataset_id IS NULL)
                )");
        }
    }

    public function down(): void
    {
        Schema::table('sensor_dataset_upload_sessions', function (Blueprint $table) {
            $table->dropForeign(['sensor_dataset_id']);
            $table->dropUnique(['initiated_by_user_id', 'completion_idempotency_key']);
            $table->dropColumn(['completion_idempotency_key', 'completion_fingerprint', 'checksum_sha256', 'completed_at', 'sensor_dataset_id']);
        });
    }
};
