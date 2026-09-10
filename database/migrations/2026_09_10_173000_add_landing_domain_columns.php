<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sites')) {
            Schema::table('sites', function (Blueprint $table): void {
                if (! Schema::hasColumn('sites', 'temporary_domain')) {
                    $table->string('temporary_domain')->nullable()->after('primary_domain');
                }
                if (! Schema::hasColumn('sites', 'cloudflare_zone_status')) {
                    $table->string('cloudflare_zone_status', 32)->nullable()->after('cloudflare_nameservers');
                }
            });
        }

        if (Schema::hasTable('site_domains')) {
            Schema::table('site_domains', function (Blueprint $table): void {
                if (! Schema::hasColumn('site_domains', 'is_www')) {
                    $table->boolean('is_www')->default(false)->after('is_primary');
                }
                if (! Schema::hasColumn('site_domains', 'is_temporary')) {
                    $table->boolean('is_temporary')->default(false)->after('is_www');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sites')) {
            Schema::table('sites', function (Blueprint $table): void {
                if (Schema::hasColumn('sites', 'temporary_domain')) {
                    $table->dropColumn('temporary_domain');
                }
                if (Schema::hasColumn('sites', 'cloudflare_zone_status')) {
                    $table->dropColumn('cloudflare_zone_status');
                }
            });
        }

        if (Schema::hasTable('site_domains')) {
            Schema::table('site_domains', function (Blueprint $table): void {
                $drop = [];
                if (Schema::hasColumn('site_domains', 'is_www')) {
                    $drop[] = 'is_www';
                }
                if (Schema::hasColumn('site_domains', 'is_temporary')) {
                    $drop[] = 'is_temporary';
                }
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }
    }
};
