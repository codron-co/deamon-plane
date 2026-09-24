<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in CI gate. Defaults keep today's behaviour for every existing row:
 * sites keep Coolify auto-deploy (`deploy_gate=coolify`, no canary) and themes
 * keep fanning out on push (`ci_gate=false`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('deploy_gate', 16)->default('coolify')->after('coolify_deploy_settings_at');
            $table->boolean('deploy_canary')->default(false)->after('deploy_gate');
        });

        Schema::table('themes', function (Blueprint $table): void {
            $table->boolean('ci_gate')->default(false)->after('default_ref');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['deploy_gate', 'deploy_canary']);
        });

        Schema::table('themes', function (Blueprint $table): void {
            $table->dropColumn('ci_gate');
        });
    }
};
