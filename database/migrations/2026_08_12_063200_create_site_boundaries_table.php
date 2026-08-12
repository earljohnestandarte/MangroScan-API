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

        Schema::create('site_boundaries', function (Blueprint $table) use ($driver) {
            $table->uuid('boundary_id')->primary();
            $table->uuid('site_id');
            $table->string('boundary_name', 150);
            $table->string('boundary_type', 50);

            if ($driver === 'pgsql') {
                $table->geometry('boundary_geom', 'polygon', 4326);
            } else {
                $table->json('boundary_geom');
            }

            $table->string('source', 100)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('site_id')
                ->references('site_id')
                ->on('survey_sites')
                ->cascadeOnDelete();
            $table->foreign('created_by')
                ->references('user_id')
                ->on('users')
                ->restrictOnDelete();

            $table->index(['site_id', 'boundary_type']);

            if ($driver === 'pgsql') {
                $table->spatialIndex('boundary_geom');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_boundaries');
    }
};
