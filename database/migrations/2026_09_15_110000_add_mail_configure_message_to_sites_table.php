<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CMS explains a rejected mail configure (e.g. "URL host is not on the allowlist.")
 * but Plane only kept "http_422". Store its message so the operator sees the cause.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('mail_configure_message', 500)->nullable()->after('mail_configure_error');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('mail_configure_message');
        });
    }
};
