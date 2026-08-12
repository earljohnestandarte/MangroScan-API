<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->uuid('notification_id')->primary();
            $table->uuid('user_id');
            $table->string('notification_type', 80);
            $table->string('title', 150);
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('user_id')
                ->references('user_id')
                ->on('users')
                ->restrictOnDelete();

            $table->index(['user_id', 'is_read', 'created_at']);
            $table->index(['user_id', 'notification_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
