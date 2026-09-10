<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('cloudflare_zone_id')->nullable()->after('coolify_app_uuid');
            $table->json('cloudflare_nameservers')->nullable();
            $table->timestamp('dns_applied_at')->nullable();
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
