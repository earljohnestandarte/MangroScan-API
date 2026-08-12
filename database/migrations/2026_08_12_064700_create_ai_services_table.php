<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_services', function (Blueprint $table) {
            $table->uuid('ai_service_id')->primary();
            $table->string('service_name', 150);
            $table->string('base_url', 2048)->unique();
            $table->text('encrypted_api_key');
            $table->string('environment', 50);
            $table->boolean('enabled')->default(true);
            $table->string('health_status', 30)->default('unknown');
            $table->string('service_version', 100)->nullable();
            $table->jsonb('capabilities')->nullable();
            $table->timestampTz('last_health_checked_at')->nullable();
            $table->timestampTz('last_synchronized_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('created_by')->references('user_id')->on('users')->restrictOnDelete();
            $table->unique(['service_name', 'environment']);
            $table->index(['enabled', 'health_status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE ai_services
                ADD CONSTRAINT ai_services_health_status_check
                    CHECK (health_status IN ('unknown', 'healthy', 'degraded', 'unavailable'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_services');
    }
};
