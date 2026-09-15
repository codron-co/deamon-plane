<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CMS no longer checks a Plane host allowlist env (CMS 1.2.25), so a stale value can no
 * longer reject mail pushes and Plane does not queue a push after a healing redeploy. Drops
 * the column if the short-lived 2026_09_15_120000 migration already added it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sites', 'mail_push_after_deploy')) {
            Schema::table('sites', function (Blueprint $table): void {
                $table->dropColumn('mail_push_after_deploy');
            });
        }
    }

    public function down(): void
    {
        // Nothing to restore: the column had no reader after this change.
    }
};
