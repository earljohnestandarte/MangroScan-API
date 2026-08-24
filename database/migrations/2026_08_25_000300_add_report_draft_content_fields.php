<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->text('audience')->nullable()->after('summary');
            $table->text('interpretation')->nullable()->after('audience');
            $table->text('limitations')->nullable()->after('interpretation');
            $table->text('recommendations')->nullable()->after('limitations');
            $table->jsonb('formats')->nullable()->after('recommendations');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['audience', 'interpretation', 'limitations', 'recommendations', 'formats']);
        });
    }
};
