<?php

namespace App\Jobs;

use App\Models\PlatformMailSetting;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchPlatformMailPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(): void
    {
        Site::query()->orderBy('id')->each(function (Site $site): void {
            if (! $site->hasAgentSecret()) {
                return;
            }
            PushPlatformMailJob::dispatch($site->id);
        });

        $settings = PlatformMailSetting::current();
        if ($settings->exists) {
            $settings->last_pushed_at = now();
            $settings->save();
        }
    }
}
