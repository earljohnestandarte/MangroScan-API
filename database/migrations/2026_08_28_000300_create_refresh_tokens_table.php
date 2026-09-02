<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table): void {
            $table->uuid('refresh_token_id')->primary();
            $table->uuid('user_id');
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('replaced_by')->nullable();
            $table->timestampsTz();
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'expires_at']);
        });

        Schema::table('refresh_tokens', function (Blueprint $table): void {
            $table->foreign('replaced_by')
                ->references('refresh_token_id')
                ->on('refresh_tokens')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
