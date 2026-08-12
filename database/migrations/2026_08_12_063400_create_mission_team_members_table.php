<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_team_members', function (Blueprint $table) {
            $table->uuid('mission_team_id')->primary();
            $table->uuid('mission_id');
            $table->uuid('user_id');
            $table->string('team_role', 80);
            $table->timestampTz('assigned_at')->useCurrent();
            $table->foreign('mission_id')->references('mission_id')->on('survey_missions')->cascadeOnDelete();
            $table->foreign('user_id')->references('user_id')->on('users')->restrictOnDelete();
            $table->unique(['mission_id', 'user_id', 'team_role']);
            $table->index(['mission_id', 'team_role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_team_members');
    }
};
