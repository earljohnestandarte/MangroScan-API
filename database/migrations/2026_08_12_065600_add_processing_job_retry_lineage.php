<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processing_jobs', function (Blueprint $table) {
            $table->uuid('retry_of_job_id')->nullable()->after('request_fingerprint');
            $table->text('retry_reason')->nullable()->after('retry_of_job_id');
            $table->foreign('retry_of_job_id')->references('processing_job_id')->on('processing_jobs')->restrictOnDelete();
            $table->index(['retry_of_job_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('processing_jobs', function (Blueprint $table) {
            $table->dropForeign(['retry_of_job_id']);
            $table->dropIndex(['retry_of_job_id', 'created_at']);
            $table->dropColumn(['retry_of_job_id', 'retry_reason']);
        });
    }
};
