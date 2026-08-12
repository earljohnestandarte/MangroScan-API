<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('audit_log_id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('action', 150);
            $table->string('table_name', 100);
            $table->uuid('record_id')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('ip_address', 60)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['user_id', 'created_at']);
            $table->index(['table_name', 'record_id', 'created_at']);
            $table->index('request_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION app.fn_reject_audit_mutation()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_logs is append-only'
                        USING ERRCODE = '55000';
                END;
                $$;

                CREATE TRIGGER trg_audit_logs_append_only
                BEFORE UPDATE OR DELETE ON app.audit_logs
                FOR EACH ROW
                EXECUTE FUNCTION app.fn_reject_audit_mutation();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_logs_append_only ON app.audit_logs');
            DB::unprepared('DROP FUNCTION IF EXISTS app.fn_reject_audit_mutation()');
        }

        Schema::dropIfExists('audit_logs');
    }
};
