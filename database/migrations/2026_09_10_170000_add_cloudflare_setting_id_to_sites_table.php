<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites') || Schema::hasColumn('sites', 'cloudflare_setting_id')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->foreignId('cloudflare_setting_id')
                ->nullable()
                ->after('dns_applied_at')
                ->constrained('cloudflare_settings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasColumn('sites', 'cloudflare_setting_id')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cloudflare_setting_id');
        });
    }
};
