<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('sync_version')->default(1);
        });

        Schema::create('sync_requests', function (Blueprint $table) {
            $table->uuid('sync_request_id')->primary();
            $table->uuid('device_id');
            $table->string('idempotency_key', 100);
            $table->string('request_hash', 64);
            $table->jsonb('response_payload')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();

            $table->foreign('device_id')->references('device_id')->on('sync_devices')->cascadeOnDelete();
            $table->unique(['device_id', 'idempotency_key']);
        });

        Schema::create('sync_change_log', function (Blueprint $table) {
            $table->uuid('sync_change_id')->primary();
            $table->uuid('device_id');
            $table->string('client_id', 150);
            $table->string('entity_type', 50);
            $table->string('operation', 30);
            $table->unsignedBigInteger('client_version');
            $table->string('payload_hash', 64);
            $table->string('result_status', 20);
            $table->uuid('server_id')->nullable();
            $table->unsignedBigInteger('server_version')->nullable();
            $table->jsonb('result_payload');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('device_id')->references('device_id')->on('sync_devices')->cascadeOnDelete();
            $table->unique(['device_id', 'client_id']);
            $table->index(['entity_type', 'server_id']);
            $table->index(['created_at', 'sync_change_id']);
        });

        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->uuid('sync_conflict_id')->primary();
            $table->uuid('sync_change_id');
            $table->uuid('device_id');
            $table->string('client_id', 150);
            $table->string('entity_type', 50);
            $table->string('conflict_code', 80);
            $table->jsonb('client_payload');
            $table->jsonb('server_payload')->nullable();
            $table->text('message');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('device_id')->references('device_id')->on('sync_devices')->cascadeOnDelete();
            $table->foreign('sync_change_id')->references('sync_change_id')->on('sync_change_log')->cascadeOnDelete();
            $table->index(['device_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE sync_change_log
                ADD CONSTRAINT sync_change_log_entity_check
                    CHECK (entity_type IN ('flight_checklist', 'flight_session', 'media', 'validation_record')),
                ADD CONSTRAINT sync_change_log_operation_check
                    CHECK (operation IN ('create', 'update', 'upsert')),
                ADD CONSTRAINT sync_change_log_result_status_check
                    CHECK (result_status IN ('applied', 'conflict'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('sync_change_log');
        Schema::dropIfExists('sync_requests');
        Schema::table('flight_sessions', function (Blueprint $table) {
            $table->dropColumn('sync_version');
        });
    }
};
