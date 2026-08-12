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

        Schema::create('survey_sites', function (Blueprint $table) use ($driver) {
            $table->uuid('site_id')->primary();
            $table->uuid('organization_id');

            $table->string('site_name', 150);
            $table->string('site_code', 50)->unique();
            $table->text('description')->nullable();
            $table->string('province', 100);
            $table->string('city_municipality', 100);
            $table->string('barangay', 100)->nullable();

            if ($driver === 'pgsql') {
                $table->geometry('center_point', 'point', 4326)->nullable();
            } else {
                // SQLite is used only as a fast compatibility test database.
                $table->json('center_point')->nullable();
            }

            $table->decimal('area_hectares', 12, 4)->nullable();
            $table->string('environment_type', 80);
            $table->text('access_notes')->nullable();
            $table->string('status', 30)->default('active');
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')
                ->references('organization_id')
                ->on('organizations')
                ->restrictOnDelete();
            $table->foreign('created_by')
                ->references('user_id')
                ->on('users')
                ->restrictOnDelete();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'province']);

            if ($driver === 'pgsql') {
                $table->spatialIndex('center_point');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_sites');
    }
};
