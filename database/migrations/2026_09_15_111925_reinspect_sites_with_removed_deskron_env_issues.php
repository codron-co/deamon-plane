<?php

use App\Jobs\InspectSiteAppHealthJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * DESKRON_* briefly sat in the env catalog; App health stored "missing env"
     * for them on every site. The catalog no longer carries them, so re-inspect
     * those sites once instead of leaving the stale verdict on the Sites page.
     */
    public function up(): void
    {
        DB::table('sites')
            ->whereNotNull('coolify_app_uuid')
            ->where('last_app_health_payload', 'like', '%DESKRON_%')
            ->pluck('id')
            ->each(static fn (string $siteId) => InspectSiteAppHealthJob::dispatch($siteId));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
