<?php

use App\Jobs\DispatchSiteHealthChecksJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$pollMinutes = (int) config('ops.agent.poll_minutes', 10);
$pollMinutes = min(15, max(5, $pollMinutes));

Schedule::job(new DispatchSiteHealthChecksJob)
    ->cron(sprintf('*/%d * * * *', $pollMinutes))
    ->name('ops-site-agent-health')
    ->withoutOverlapping();
