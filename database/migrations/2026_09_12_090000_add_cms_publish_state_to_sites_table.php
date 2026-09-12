<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirror of the CMS publish state (`sites.status` over there: draft | published).
     * The CMS stays the source of truth; these columns exist so the sites list can
     * sort and filter in SQL instead of reaching into `last_health_payload` JSON,
     * the same way `last_live_http_status` mirrors the live probe.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('cms_site_status', 32)->nullable()->after('status');
            $table->timestamp('cms_site_status_at')->nullable()->after('cms_site_status');

            $table->index('cms_site_status');
        });

        // Health polls already stored site_status; seed the mirror from what we have
        // so the new column is not empty for the whole fleet until the next poll.
        foreach (DB::table('sites')->select('id', 'last_health_payload', 'last_health_at')->cursor() as $row) {
            $payload = json_decode((string) $row->last_health_payload, true);
            $status = is_array($payload) ? ($payload['site_status'] ?? null) : null;

            if (! in_array($status, ['draft', 'published'], true)) {
                continue;
            }

            DB::table('sites')->where('id', $row->id)->update([
                'cms_site_status' => $status,
                'cms_site_status_at' => $row->last_health_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['cms_site_status']);
            $table->dropColumn(['cms_site_status', 'cms_site_status_at']);
        });
    }
};
