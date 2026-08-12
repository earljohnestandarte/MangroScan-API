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
        Schema::create('media_upload_sessions', function (Blueprint $table) use ($driver) {
            $table->uuid('upload_id')->primary();
            $table->uuid('flight_session_id');
            $table->uuid('initiated_by_user_id');
            $table->string('idempotency_key', 100);
            $table->string('request_fingerprint', 64);
            $table->string('storage_disk', 100);
            $table->string('storage_key', 1024)->unique();
            $table->string('file_name', 255);
            $table->string('file_type', 20);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('checksum_sha256', 64)->nullable();

            if ($driver === 'pgsql') {
                $table->geometry('capture_location', 'point', 4326)->nullable();
            } else {
                $table->json('capture_location')->nullable();
            }

            $table->timestampTz('captured_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('upload_status', 30)->default('initiated');
            $table->timestampTz('expires_at');
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('media_asset_id')->nullable();
            $table->timestampsTz();

            $table->foreign('flight_session_id')->references('flight_session_id')->on('flight_sessions')->restrictOnDelete();
            $table->foreign('initiated_by_user_id')->references('user_id')->on('users')->restrictOnDelete();
            $table->foreign('media_asset_id')->references('media_asset_id')->on('media_assets')->restrictOnDelete();
            $table->unique(['initiated_by_user_id', 'idempotency_key']);
            $table->index(['flight_session_id', 'upload_status']);

            if ($driver === 'pgsql') {
                $table->spatialIndex('capture_location');
            }
        });

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE media_upload_sessions
                ADD CONSTRAINT media_upload_sessions_file_type_check
                    CHECK (file_type IN ('image', 'video')),
                ADD CONSTRAINT media_upload_sessions_status_check
                    CHECK (upload_status IN ('initiated', 'completed', 'expired', 'failed')),
                ADD CONSTRAINT media_upload_sessions_checksum_check
                    CHECK (checksum_sha256 IS NULL OR checksum_sha256 ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT media_upload_sessions_expiry_check
                    CHECK (expires_at > created_at),
                ADD CONSTRAINT media_upload_sessions_completion_check
                    CHECK (
                        (upload_status = 'completed' AND completed_at IS NOT NULL AND media_asset_id IS NOT NULL)
                        OR
                        (upload_status <> 'completed' AND completed_at IS NULL AND media_asset_id IS NULL)
                    )
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_upload_sessions');
    }
};
