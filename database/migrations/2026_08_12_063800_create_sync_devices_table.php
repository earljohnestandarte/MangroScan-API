<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_devices', function (Blueprint $table) {
            $table->uuid('device_id')->primary();
            $table->uuid('user_id');
            $table->string('platform', 20);
            $table->string('app_version', 50);
            $table->string('device_name', 100)->nullable();
            $table->text('last_cursor')->nullable();
            $table->timestampTz('last_sync_at')->nullable();
            $table->timestampsTz();

            $table->foreign('user_id')
                ->references('user_id')
                ->on('users')
                ->cascadeOnDelete();
            $table->index(['user_id', 'updated_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE sync_devices
                ADD CONSTRAINT sync_devices_platform_check
                CHECK (platform IN ('android', 'ios', 'web'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_devices');
    }
};
