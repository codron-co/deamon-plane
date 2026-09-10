<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('cloudflare_zone_id')->nullable()->after('coolify_git_source_kind');
            $table->json('cloudflare_nameservers')->nullable()->after('cloudflare_zone_id');
            $table->timestamp('dns_applied_at')->nullable()->after('cloudflare_nameservers');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'cloudflare_zone_id',
                'cloudflare_nameservers',
                'dns_applied_at',
            ]);
        });
    }
};
