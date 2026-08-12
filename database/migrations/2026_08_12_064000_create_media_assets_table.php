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

        Schema::create('media_assets', function (Blueprint $table) use ($driver) {
            $table->uuid('media_asset_id')->primary();
            $table->uuid('flight_session_id');
            $table->uuid('uploaded_by_user_id')->nullable();
            $table->string('file_name', 255);
            $table->string('file_type', 20);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('storage_key', 1024)->unique();
            $table->string('checksum_sha256', 64)->nullable();

            if ($driver === 'pgsql') {
                $table->geometry('capture_location', 'point', 4326)->nullable();
            } else {
                $table->json('capture_location')->nullable();
            }

            $table->timestampTz('captured_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->decimal('quality_score', 5, 2)->nullable();
            $table->string('quality_status', 30)->default('pending');
            $table->text('notes')->nullable();
            $table->string('processing_status', 30)->default('pending');
            $table->unsignedBigInteger('sync_version')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('flight_session_id')
                ->references('flight_session_id')
                ->on('flight_sessions')
                ->restrictOnDelete();
            $table->foreign('uploaded_by_user_id')
                ->references('user_id')
                ->on('users')
                ->restrictOnDelete();

            $table->index(['flight_session_id', 'file_type']);
            $table->index(['flight_session_id', 'quality_status']);
            $table->index(['flight_session_id', 'processing_status']);
            $table->index(['captured_at', 'media_asset_id']);

            if ($driver === 'pgsql') {
                $table->spatialIndex('capture_location');
            }
        });

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE media_assets
                ADD CONSTRAINT media_assets_file_type_check
                    CHECK (file_type IN ('image', 'video')),
                ADD CONSTRAINT media_assets_quality_status_check
                    CHECK (quality_status IN ('pending', 'acceptable', 'rejected', 'needs_recapture')),
                ADD CONSTRAINT media_assets_processing_status_check
                    CHECK (processing_status IN ('pending', 'queued', 'processing', 'completed', 'failed')),
                ADD CONSTRAINT media_assets_quality_score_check
                    CHECK (quality_score IS NULL OR (quality_score >= 0 AND quality_score <= 100)),
                ADD CONSTRAINT media_assets_checksum_check
                    CHECK (checksum_sha256 IS NULL OR checksum_sha256 ~ '^[0-9a-f]{64}$')
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
