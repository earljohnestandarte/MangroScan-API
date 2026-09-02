<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_saved_views', function (Blueprint $table): void {
            $table->uuid('saved_view_id')->primary();
            $table->uuid('user_id');
            $table->uuid('site_id')->nullable();
            $table->uuid('mission_id')->nullable();
            $table->string('view_name', 150);
            $table->json('filter_config');
            $table->json('map_config');
            $table->timestamps();
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->foreign('site_id')->references('site_id')->on('survey_sites')->restrictOnDelete();
            $table->foreign('mission_id')->references('mission_id')->on('survey_missions')->restrictOnDelete();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_saved_views');
    }
};
