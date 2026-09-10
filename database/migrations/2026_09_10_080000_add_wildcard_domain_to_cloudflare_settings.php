<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cloudflare_settings') || Schema::hasColumn('cloudflare_settings', 'wildcard_domain')) {
            return;
        }

        Schema::table('cloudflare_settings', function (Blueprint $table): void {
            $table->string('wildcard_domain', 255)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cloudflare_settings') || ! Schema::hasColumn('cloudflare_settings', 'wildcard_domain')) {
            return;
        }

        Schema::table('cloudflare_settings', function (Blueprint $table): void {
            $table->dropColumn('wildcard_domain');
        });
    }
};
