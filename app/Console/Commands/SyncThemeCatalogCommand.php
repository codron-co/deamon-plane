<?php

namespace App\Console\Commands;

use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubCredentialsException;
use App\Services\Themes\ThemeCatalogSync;
use Illuminate\Console\Command;

class SyncThemeCatalogCommand extends Command
{
    protected $signature = 'ops:sync-theme-catalog';

    protected $description = 'Sync deamon-theme-* repos from the configured GitHub org into the Plane catalog';

    public function handle(ThemeCatalogSync $sync): int
    {
        try {
            $result = $sync->sync();
        } catch (GitHubCredentialsException|GitHubApiException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Catalog sync: %d created, %d updated, %d skipped.',
            $result['created'],
            $result['updated'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
