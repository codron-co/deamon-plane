<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Last known Coolify auto-deploy switch and pinned commit, written whenever
 * Plane reads or changes them. The Sites list shows them from here instead of
 * asking Coolify once per row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->boolean('coolify_auto_deploy')->nullable()->after('coolify_git_source_kind');
            $table->string('coolify_pinned_sha', 64)->nullable()->after('coolify_auto_deploy');
            $table->timestamp('coolify_deploy_settings_at')->nullable()->after('coolify_pinned_sha');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['coolify_auto_deploy', 'coolify_pinned_sha', 'coolify_deploy_settings_at']);
        });
    }
};
