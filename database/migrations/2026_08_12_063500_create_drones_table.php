<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drones', function (Blueprint $table) {
            $table->uuid('drone_id')->primary();
            $table->uuid('organization_id');
            $table->string('drone_name', 100);
            $table->string('model', 100)->nullable();
            $table->string('serial_number', 100)->nullable()->unique();
            $table->string('firmware_version', 80)->nullable();
            $table->decimal('max_flight_minutes', 5, 2)->nullable();
            $table->decimal('payload_capacity_grams', 8, 2)->nullable();
            $table->string('status', 30)->default('available');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('organization_id')
                ->references('organization_id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->index(['organization_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE drones
                ADD CONSTRAINT drones_status_check
                CHECK (status IN ('available', 'maintenance', 'retired'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('drones');
    }
};
