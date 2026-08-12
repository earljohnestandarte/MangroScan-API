<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('role_id')->primary();

            $table->uuid('organization_id')->nullable();

            $table->string('role_name', 100);
            $table->string('role_code', 100);

            $table->text('description')->nullable();
            $table->boolean('is_system_role')->default(false);

            $table->timestamps();

            $table->foreign('organization_id')
                ->references('organization_id')
                ->on('organizations')
                ->cascadeOnDelete();

            $table->unique([
                'organization_id',
                'role_code'
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};