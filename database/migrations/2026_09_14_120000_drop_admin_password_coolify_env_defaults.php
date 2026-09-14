<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plane no longer seeds a CMS admin password through Coolify env. CMS 1.2.18+
 * creates the first admin passwordless and Plane sends invite mail over the agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('coolify_env_defaults')) {
            return;
        }

        DB::table('coolify_env_defaults')
            ->where('key', 'DEAMON_DEFAULT_ADMIN_PASSWORD')
            ->delete();
    }

    public function down(): void
    {
        // Intentionally empty: the row is not restored. Operators can add it back in Settings.
    }
};
