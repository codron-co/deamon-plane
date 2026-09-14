<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A site may now carry hosts on more than one registrable apex. Hosts that do not
 * live under the primary zone record their own Cloudflare zone here; the site row
 * keeps the primary zone as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_domains', function (Blueprint $table): void {
            $table->string('cloudflare_zone_id', 64)->nullable()->after('coolify_domain_id');
            $table->string('cloudflare_zone_status', 32)->nullable()->after('cloudflare_zone_id');
            $table->json('cloudflare_nameservers')->nullable()->after('cloudflare_zone_status');
        });
    }

    public function down(): void
    {
        Schema::table('site_domains', function (Blueprint $table): void {
            $table->dropColumn(['cloudflare_zone_id', 'cloudflare_zone_status', 'cloudflare_nameservers']);
        });
    }
};
