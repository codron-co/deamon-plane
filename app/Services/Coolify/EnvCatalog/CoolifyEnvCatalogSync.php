<?php

namespace App\Services\Coolify\EnvCatalog;

use App\Enums\Channel;
use App\Jobs\InspectSiteAppHealthJob;
use App\Models\CoolifyEnvCatalogSource;
use App\Models\CoolifyEnvDefault;
use App\Models\Site;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubCredentialsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls the CMS `.env.production.example` for a git channel and replaces that channel's
 * Coolify env catalog with the parsed rows. Never logs values.
 */
class CoolifyEnvCatalogSync
{
    public function __construct(
        private readonly EnvExampleParser $parser = new EnvExampleParser,
    ) {}

    /**
     * @return array<string, CoolifyEnvCatalogSource> keyed by channel value
     */
    public function syncAll(): array
    {
        $out = [];
        foreach (Channel::cases() as $channel) {
            if (! $channel->isAllowed()) {
                continue;
            }

            $out[$channel->value] = $this->sync($channel);
        }

        return $out;
    }

    /**
     * Fetch + replace. Failures are recorded on the source row and rethrown as
     * CoolifyEnvCatalogException so callers can flash a message.
     *
     * @throws CoolifyEnvCatalogException
     */
    public function sync(Channel $channel): CoolifyEnvCatalogSource
    {
        $source = CoolifyEnvCatalogSource::forChannel($channel);
        $source->last_attempt_at = now();

        $repo = DeamonRepo::fullName();
        if ($repo === null) {
            $this->fail($source, __('settings.env.errors.repo_missing'));
        }

        $client = DeamonRepo::client();
        if ($client === null) {
            $this->fail($source, __('settings.env.errors.no_credentials'));
        }

        try {
            $contents = $client->fetchTextFile($repo, DeamonRepo::ENV_EXAMPLE_PATH, $channel->value);
            $sha = $contents !== null ? $client->latestCommitSha($repo, $channel->value) : null;
        } catch (GitHubCredentialsException|GitHubApiException $exception) {
            $this->fail($source, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            $this->fail($source, __('settings.env.errors.fetch_failed'));
        }

        if ($contents === null) {
            $this->fail($source, __('settings.env.errors.file_missing', [
                'path' => DeamonRepo::ENV_EXAMPLE_PATH,
                'branch' => $channel->value,
            ]));
        }

        $rows = $this->parser->parse($contents);
        if ($rows === []) {
            $this->fail($source, __('settings.env.errors.empty', ['branch' => $channel->value]));
        }

        $this->replace($channel, $rows);

        $source->fill([
            'repo_full_name' => $repo,
            'path' => DeamonRepo::ENV_EXAMPLE_PATH,
            'commit_sha' => $sha,
            'row_count' => count($rows),
            'fetched_at' => now(),
            'last_error' => null,
        ]);
        $source->save();

        Log::info('coolify.env_catalog_synced', [
            'channel' => $channel->value,
            'repo' => $repo,
            'sha' => $sha,
            'rows' => count($rows),
        ]);

        return $source;
    }

    /**
     * Replace a channel's rows from already-parsed content (tests, fixtures, webhook payload replay).
     *
     * @param  list<ParsedEnvRow>  $rows
     */
    public function replace(Channel $channel, array $rows): void
    {
        $previousKeys = CoolifyEnvDefault::query()->where('channel', $channel->value)->orderBy('key')->pluck('key')->all();
        $nextKeys = array_map(static fn (ParsedEnvRow $row): string => $row->key, $rows);
        sort($nextKeys);

        DB::transaction(function () use ($channel, $rows): void {
            CoolifyEnvDefault::query()->where('channel', $channel->value)->delete();

            $now = now();
            $sort = 10;
            foreach ($rows as $row) {
                CoolifyEnvDefault::query()->create([
                    'channel' => $channel->value,
                    ...$row->toArray(),
                    'sort' => $sort,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $sort += 10;
            }
        });

        // Stored App health lists missing catalog keys. When the key set changes, those
        // verdicts are stale (a removed key would keep showing as "missing"), so
        // re-inspect the channel's apps against the new catalog.
        if ($previousKeys !== [] && $previousKeys !== $nextKeys) {
            $this->reinspectChannelApps($channel);
        }
    }

    public function reinspectChannelApps(Channel $channel): void
    {
        Site::query()
            ->where('channel', $channel->value)
            ->whereNotNull('coolify_app_uuid')
            ->pluck('id')
            ->each(static fn (string $siteId) => InspectSiteAppHealthJob::dispatch($siteId));
    }

    /**
     * Seed a channel from raw example contents (no GitHub round-trip).
     */
    public function importContents(Channel $channel, string $contents, ?string $sha = null, ?string $repo = null): CoolifyEnvCatalogSource
    {
        $rows = $this->parser->parse($contents);
        $this->replace($channel, $rows);

        $source = CoolifyEnvCatalogSource::forChannel($channel);
        $source->fill([
            'repo_full_name' => $repo ?? DeamonRepo::fullName(),
            'path' => DeamonRepo::ENV_EXAMPLE_PATH,
            'commit_sha' => $sha,
            'row_count' => count($rows),
            'fetched_at' => now(),
            'last_attempt_at' => now(),
            'last_error' => null,
        ]);
        $source->save();

        return $source;
    }

    private function fail(CoolifyEnvCatalogSource $source, string $message): never
    {
        $source->last_error = mb_substr($message, 0, 1000);
        $source->save();

        Log::warning('coolify.env_catalog_sync_failed', [
            'channel' => $source->channel instanceof Channel ? $source->channel->value : (string) $source->channel,
            'error' => $source->last_error,
        ]);

        throw new CoolifyEnvCatalogException($message);
    }
}
