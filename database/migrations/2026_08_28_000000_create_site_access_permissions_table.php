<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_access_permissions', function (Blueprint $table): void {
            $table->uuid('access_permission_id')->primary();
            $table->uuid('site_id');
            $table->string('permit_title', 150);
            $table->string('issuing_agency', 150);
            $table->string('permit_number', 100)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('document_path')->nullable();
            $table->string('status', 30);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('site_id')->references('site_id')->on('survey_sites')->cascadeOnDelete();
            $table->foreign('created_by')->references('user_id')->on('users')->restrictOnDelete();
            $table->index(['site_id', 'status']);
            $table->index(['site_id', 'valid_until']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE site_access_permissions
                ADD CONSTRAINT site_access_permissions_dates_check
                    CHECK (valid_until IS NULL OR valid_from IS NULL OR valid_until >= valid_from)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_access_permissions');
    }
};
