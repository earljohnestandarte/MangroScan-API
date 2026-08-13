<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private const UPDATED_AT_TABLES = [
        'ai_model_versions',
        'ai_models',
        'ai_services',
        'drone_sensors',
        'drones',
        'flight_sessions',
        'geospatial_layers',
        'mangrove_species',
        'mangrove_tree_entities',
        'media_assets',
        'media_upload_sessions',
        'monitoring_plots',
        'organizations',
        'permissions',
        'personal_access_tokens',
        'photogrammetry_products',
        'processing_jobs',
        'reports',
        'role_permissions',
        'roles',
        'sensor_dataset_upload_sessions',
        'sensor_datasets',
        'site_boundaries',
        'species_growth_models',
        'survey_missions',
        'survey_sites',
        'sync_devices',
        'training_datasets',
        'tree_count_summaries',
        'tree_observations',
        'user_roles',
        'users',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app.fn_touch_updated_at()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY INVOKER
            SET search_path = pg_catalog
            AS $$
            BEGIN
                NEW.updated_at := statement_timestamp();

                RETURN NEW;
            END;
            $$;

            REVOKE ALL ON FUNCTION app.fn_touch_updated_at() FROM PUBLIC;
            SQL);

        foreach (self::UPDATED_AT_TABLES as $table) {
            $trigger = "trg_{$table}_touch_updated_at";

            DB::unprepared(<<<SQL
                DROP TRIGGER IF EXISTS {$trigger} ON app.{$table};

                CREATE TRIGGER {$trigger}
                BEFORE UPDATE ON app.{$table}
                FOR EACH ROW
                EXECUTE FUNCTION app.fn_touch_updated_at();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_reverse(self::UPDATED_AT_TABLES) as $table) {
            $trigger = "trg_{$table}_touch_updated_at";

            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger} ON app.{$table}");
        }

        DB::unprepared('DROP FUNCTION IF EXISTS app.fn_touch_updated_at()');
    }
};
