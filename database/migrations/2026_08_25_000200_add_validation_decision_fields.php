<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        Schema::table('validation_matches', function (Blueprint $table) use ($driver) {
            $table->uuid('validation_session_id')->nullable()->after('validation_match_id');
            $table->uuid('accepted_species_id')->nullable()->after('match_status');
            $table->decimal('accepted_height_m', 8, 2)->nullable()->after('accepted_species_id');
            $table->decimal('accepted_age_years', 8, 2)->nullable()->after('accepted_height_m');
            if ($driver === 'pgsql') {
                $table->geometry('corrected_geometry', 'point', 4326)->nullable();
            } else {
                $table->json('corrected_geometry')->nullable();
            }
            $table->text('notes')->nullable();
            $table->jsonb('validation_evidence')->nullable();
        });

        DB::table('validation_matches')
            ->whereNull('validation_session_id')
            ->update([
                'validation_session_id' => DB::raw('(
                    SELECT truth.validation_session_id
                    FROM ground_truth_tree_records AS truth
                    WHERE truth.ground_truth_id = validation_matches.ground_truth_id
                )'),
            ]);

        Schema::table('validation_matches', function (Blueprint $table) use ($driver) {
            $table->uuid('validation_session_id')->nullable(false)->change();
            $table->uuid('ground_truth_id')->nullable()->change();
            $table->foreign('validation_session_id')->references('validation_session_id')->on('validation_sessions')->cascadeOnDelete();
            $table->foreign('accepted_species_id')->references('species_id')->on('mangrove_species')->restrictOnDelete();
            $table->index(['validation_session_id', 'validated_at']);
            $table->index('accepted_species_id');
            if ($driver === 'pgsql') {
                $table->spatialIndex('corrected_geometry');
            }
        });

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE validation_matches
                ADD CONSTRAINT validation_matches_accepted_values_check
                    CHECK (
                        (accepted_height_m IS NULL OR accepted_height_m >= 0)
                        AND (accepted_age_years IS NULL OR accepted_age_years >= 0)
                    ),
                ADD CONSTRAINT validation_matches_reference_shape_check
                    CHECK (
                        (match_status IN ('matched', 'corrected')
                            AND ground_truth_id IS NOT NULL
                            AND tree_observation_id IS NOT NULL)
                        OR (match_status = 'false_positive'
                            AND ground_truth_id IS NULL
                            AND tree_observation_id IS NOT NULL)
                        OR (match_status = 'false_negative'
                            AND ground_truth_id IS NOT NULL
                            AND tree_observation_id IS NULL)
                    );
                SQL);
        }
    }

    public function down(): void
    {
        // Rows without a legacy ground-truth parent cannot be represented by the prior schema.
        DB::table('validation_matches')->whereNull('ground_truth_id')->delete();

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE validation_matches
                    DROP CONSTRAINT validation_matches_accepted_values_check,
                    DROP CONSTRAINT validation_matches_reference_shape_check;
                SQL);
        }

        Schema::table('validation_matches', function (Blueprint $table) {
            $table->dropIndex(['validation_session_id', 'validated_at']);
            $table->dropIndex(['accepted_species_id']);
            $table->dropForeign(['validation_session_id']);
            $table->dropForeign(['accepted_species_id']);
            $table->dropColumn([
                'validation_session_id', 'accepted_species_id', 'accepted_height_m',
                'accepted_age_years', 'corrected_geometry', 'notes', 'validation_evidence',
            ]);
            $table->uuid('ground_truth_id')->nullable(false)->change();
        });
    }
};
