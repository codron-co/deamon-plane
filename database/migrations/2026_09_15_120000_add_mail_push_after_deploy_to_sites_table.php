<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set when the CMS rejected Plane's host (stale CONTROL_PLANE_HOST_ALLOWLIST): Plane rewrote the
 * env, and the CMS reads it only after a redeploy, so mail settings are pushed again then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->boolean('mail_push_after_deploy')->default(false)->after('mail_configure_message');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('mail_push_after_deploy');
        });
    }
};
