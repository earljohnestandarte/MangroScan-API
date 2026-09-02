<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batteries', function (Blueprint $table) {
            $table->uuid('battery_id')->primary();
            $table->uuid('organization_id');
            $table->string('battery_code', 100)->unique();
            $table->string('battery_type', 50);
            $table->decimal('capacity_mah', 10, 2)->nullable();
            $table->decimal('voltage', 8, 2)->nullable();
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
                ALTER TABLE batteries
                ADD CONSTRAINT batteries_type_check
                CHECK (battery_type IN ('lipo','li-ion','nimh'))
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE batteries
                ADD CONSTRAINT batteries_status_check
                CHECK (status IN ('available','in_use','charging','maintenance','retired'))
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('batteries');
    }
};
