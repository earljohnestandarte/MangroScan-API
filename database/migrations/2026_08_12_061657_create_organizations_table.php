<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('organization_id')->primary();

            $table->string('organization_name', 150);
            $table->string('organization_type', 50)->nullable();

            $table->string('contact_email', 150)->nullable();
            $table->string('contact_number', 50)->nullable();
            $table->text('address')->nullable();

            $table->string('status', 30)->default('active');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};