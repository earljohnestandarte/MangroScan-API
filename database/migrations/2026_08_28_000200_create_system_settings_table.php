<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table): void {
            $table->string('setting_key', 150)->primary();
            $table->string('setting_group', 80);
            $table->text('setting_value');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['setting_group', 'setting_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
