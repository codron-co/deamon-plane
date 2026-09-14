<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outcome of the last CMS `mail/configure` push, so a timeout is visible on the
 * site instead of vanishing with the flash. Mirrors the platform mail push state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->timestamp('mail_configured_at')->nullable()->after('mail_domain');
            $table->timestamp('mail_configure_failed_at')->nullable()->after('mail_configured_at');
            $table->string('mail_configure_error', 64)->nullable()->after('mail_configure_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['mail_configured_at', 'mail_configure_failed_at', 'mail_configure_error']);
        });
    }
};
