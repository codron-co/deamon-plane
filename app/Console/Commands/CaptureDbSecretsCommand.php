<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyAppEnvSync;
use App\Services\Coolify\CoolifyApplicationService;
use Illuminate\Console\Command;

/**
 * One pass over the fleet that stores Plane's copy of each site's MySQL passwords,
 * so a Coolify env that later comes back blank is restored instead of regenerated.
 * Existing copies are never overwritten; values are never printed.
 */
class CaptureDbSecretsCommand extends Command
{
    protected $signature = 'ops:capture-db-secrets';

    protected $description = 'Store Plane copies of each site\'s DB_PASSWORD / MYSQL_ROOT_PASSWORD from Coolify';

    public function handle(CoolifyAppEnvSync $sync): int
    {
        $captured = 0;
        $failed = 0;

        Site::query()
            ->whereNotNull('coolify_app_uuid')
            ->where('coolify_app_uuid', '!=', '')
            ->orderBy('id')
            ->each(function (Site $site) use ($sync, &$captured, &$failed): void {
                $before = $this->copies($site);

                try {
                    $sync->captureDbSecretsFromCoolify($site, CoolifyApplicationService::forSite($site));
                } catch (CoolifyApiException $exception) {
                    $failed++;
                    $this->warn('  '.$site->slug.': '.$exception->getMessage());

                    return;
                }

                $after = $this->copies($site->refresh());
                if ($after > $before) {
                    $captured++;
                }
                if ($after < count(CoolifyAppEnvSync::DB_SECRET_COLUMNS)) {
                    $this->warn('  '.$site->slug.': '.$after.'/'.count(CoolifyAppEnvSync::DB_SECRET_COLUMNS).' DB secret(s) stored');
                }
            });

        $this->info($captured.' site(s) captured, '.$failed.' failed.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function copies(Site $site): int
    {
        $count = 0;
        foreach (CoolifyAppEnvSync::DB_SECRET_COLUMNS as $column) {
            if (filled($site->getAttribute($column))) {
                $count++;
            }
        }

        return $count;
    }
}
