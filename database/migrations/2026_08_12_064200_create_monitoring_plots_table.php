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

        Schema::create('monitoring_plots', function (Blueprint $table) use ($driver) {
            $table->uuid('plot_id')->primary();
            $table->uuid('site_id');
            $table->string('plot_code', 50);
            $table->string('plot_name', 150)->nullable();

            if ($driver === 'pgsql') {
                $table->geometry('plot_geom', 'polygon', 4326);
            } else {
                $table->json('plot_geom');
            }

            $table->decimal('area_square_meters', 12, 2)->nullable();
            $table->text('description')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('site_id')->references('site_id')->on('survey_sites')->cascadeOnDelete();
            $table->unique(['site_id', 'plot_code']);
            $table->index(['site_id', 'plot_name']);

            if ($driver === 'pgsql') {
                $table->spatialIndex('plot_geom');
            }
        });

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE monitoring_plots
                ADD CONSTRAINT monitoring_plots_area_check
                    CHECK (area_square_meters IS NULL OR area_square_meters > 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_plots');
    }
};
