<?php

use App\Jobs\DispatchSiteHealthChecksJob;
use App\Jobs\ReconcileDeskronPushJob;
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

// Coolify env catalogs follow the CMS `.env.production.example` per branch. The GitHub push
// webhook refreshes immediately; this hourly pass covers missed webhooks.
Schedule::command('ops:sync-env-catalog')
    ->hourly()
    ->name('ops-sync-env-catalog')
    ->withoutOverlapping();

// A CMS that was not yet running POST /deskron/configure answered 405 and
// stayed unconfigured with nothing to try again, so its support broke on the
// next deploy. This pass re-pushes to whatever the key has not reached yet.
Schedule::job(new ReconcileDeskronPushJob)
    ->hourly()
    ->name('ops-deskron-push-reconcile')
    ->withoutOverlapping();

// Plane's own database holds every connection credential and site agent secret.
// A nightly dump on its own volume; restore steps: docs/runbooks/plane-db-backup.md.
Schedule::command('ops:backup-db')
    ->dailyAt('03:30')
    ->timezone(config('app.timezone'))
    ->name('ops-backup-db')
    ->withoutOverlapping();

// Sites summary tiles show the change since the previous day. Late evening so
// the row reflects the day's end state; an upsert, so a manual run is harmless.
Schedule::command('ops:snapshot-fleet')
    ->dailyAt('23:50')
    ->timezone(config('app.timezone'))
    ->name('ops-snapshot-fleet')
    ->withoutOverlapping();
