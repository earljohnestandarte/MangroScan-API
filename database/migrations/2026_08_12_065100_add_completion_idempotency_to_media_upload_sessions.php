<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_upload_sessions', function (Blueprint $table) {
            $table->string('completion_idempotency_key', 100)->nullable()->after('idempotency_key');
            $table->string('completion_fingerprint', 64)->nullable()->after('completion_idempotency_key');
            $table->unique(['initiated_by_user_id', 'completion_idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('media_upload_sessions', function (Blueprint $table) {
            $table->dropUnique(['initiated_by_user_id', 'completion_idempotency_key']);
            $table->dropColumn(['completion_idempotency_key', 'completion_fingerprint']);
        });
    }
};
