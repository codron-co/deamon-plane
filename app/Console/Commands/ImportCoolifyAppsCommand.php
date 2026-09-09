<?php

namespace App\Console\Commands;

use App\Console\Commands\ImportCoolifyApps\CoolifyFleetImporter;
use App\Console\Commands\ImportCoolifyApps\ImportPlanRow;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\CoolifyCredentials;
use Illuminate\Console\Command;

class ImportCoolifyAppsCommand extends Command
{
    protected $signature = 'ops:import-coolify-apps
                            {--apply : Persist site upserts (default is dry-run)}
                            {--tag= : Optional Coolify application tag filter}';

    protected $description = 'Import Deamon customer Coolify apps into sites (dry-run unless --apply)';

    public function handle(CoolifyApplicationService $coolify, CoolifyFleetImporter $importer): int
    {
        $apply = (bool) $this->option('apply');
        $tag = $this->option('tag');
        $tag = is_string($tag) && $tag !== '' ? $tag : null;

        $credentials = CoolifyCredentials::resolve();
        if ($credentials->baseUrl === '' || ! $credentials->hasToken()) {
            $this->error('Coolify is not configured. Save the API URL and token in the Coolify menu.');

            return self::FAILURE;
        }

        try {
            $apps = $coolify->listApps($tag);
        } catch (CoolifyApiException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $plan = $importer->plan($apps);

        $this->line($apply
            ? 'Applying Coolify fleet import.'
            : 'Dry-run (no writes). Pass --apply to persist.');
        $this->newLine();

        $this->table(
            ['uuid', 'name', 'repo', 'branch', 'pack', 'domain', 'action'],
            $plan->map(static fn (ImportPlanRow $row): array => $row->tableRow())->all(),
        );

        $notes = $plan
            ->filter(static fn (ImportPlanRow $row): bool => $row->note !== '')
            ->map(static fn (ImportPlanRow $row): string => $row->uuid.': '.$row->note);

        if ($notes->isNotEmpty()) {
            $this->newLine();
            $this->line('Notes:');
            foreach ($notes as $line) {
                $this->line('  - '.$line);
            }
        }

        $creates = $plan->where('action', ImportPlanRow::ACTION_CREATE)->count();
        $updates = $plan->where('action', ImportPlanRow::ACTION_UPDATE)->count();
        $skips = $plan->where('action', ImportPlanRow::ACTION_SKIP)->count();
        $dockerfile = $plan->where('dockerfileWarning', true)->count();
        $review = $plan->where('needsReview', true)->filter(
            static fn (ImportPlanRow $row): bool => $row->willWrite(),
        )->count();

        $this->newLine();
        $this->info("Summary: {$creates} create, {$updates} update, {$skips} skip. {$dockerfile} dockerfile warning(s), {$review} needs_review.");

        if (! $apply) {
            $this->comment('No database changes were made.');

            return self::SUCCESS;
        }

        $result = $importer->apply($plan);

        $this->info("Wrote {$result['created']} create(s), {$result['updated']} update(s). Agent secrets were not generated.");

        return self::SUCCESS;
    }
}
