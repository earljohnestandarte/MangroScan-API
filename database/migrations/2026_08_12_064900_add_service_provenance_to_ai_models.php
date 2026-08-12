<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->uuid('ai_service_id')->nullable()->after('model_id');
            $table->string('external_model_key', 150)->nullable()->after('ai_service_id');

            $table->foreign('ai_service_id')->references('ai_service_id')->on('ai_services')->restrictOnDelete();
            $table->unique(['ai_service_id', 'external_model_key']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropUnique(['ai_service_id', 'external_model_key']);
            $table->dropForeign(['ai_service_id']);
            $table->dropColumn(['ai_service_id', 'external_model_key']);
        });
    }
};
