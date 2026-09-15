<?php

namespace App\Jobs;

use App\Models\DeskronSetting;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchDeskronPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(): void
    {
        Site::query()->orderBy('id')->each(function (Site $site): void {
            if ($site->hasAgentSecret()) {
                PushDeskronJob::dispatch((string) $site->id);
            }
        });

        $settings = DeskronSetting::current();
        if ($settings->exists) {
            $settings->last_pushed_at = now();
            $settings->save();
        }
    }
}
