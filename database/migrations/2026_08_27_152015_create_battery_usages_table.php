<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('battery_usages', function (Blueprint $table) {
            $table->uuid('battery_usage_id')->primary();
            $table->uuid('flight_session_id');
            $table->uuid('battery_id');
            $table->decimal('start_percentage', 5, 2);
            $table->decimal('end_percentage', 5, 2);
            $table->decimal('usage_minutes', 8, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('flight_session_id')
                ->references('flight_session_id')
                ->on('flight_sessions')
                ->restrictOnDelete();

            $table->foreign('battery_id')
                ->references('battery_id')
                ->on('batteries')
                ->restrictOnDelete();

            $table->unique(['flight_session_id', 'battery_id']);
            $table->index('battery_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('battery_usages');
    }
};