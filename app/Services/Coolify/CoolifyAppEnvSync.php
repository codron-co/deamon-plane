<?php

namespace App\Services\Coolify;

use App\Enums\Channel;
use App\Enums\CoolifyEnvKind;
use App\Models\CoolifyEnvDefault;
use App\Models\DeskronSetting;
use App\Models\Site;
use App\Services\Coolify\Dto\CoolifyEnvironmentVariable;
use App\Services\Coolify\EnvCatalog\CoolifyEnvCatalogException;
use App\Services\Coolify\EnvCatalog\CoolifyEnvCatalogSync;
use App\Services\Coolify\EnvCatalog\DeamonRepo;
use App\Services\Sites\ChannelEnvironmentMap;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CoolifyAppEnvSync
{
    /**
     * Coolify-owned inject keys — never written by Plane and never pruned.
     *
     * @var list<string>
     */
    public const PROTECTED_PREFIXES = [
        'SERVICE_',
        'COOLIFY_',
    ];

    /**
     * Exact Coolify inject keys that do not share a prefix above.
     *
     * @var list<string>
     */
    public const PROTECTED_KEYS = [
        'SOURCE_COMMIT',
        'APP_URL',
        'DEAMON_SITE_HOST',
    ];

    /**
     * Align Coolify application env with the catalog for this site's git channel
     * (synced from the CMS `.env.production.example` on that branch).
     * Upserts catalog keys, then deletes leftovers that are no longer in the catalog
     * (except Coolify-managed injects). Never logs values. Generated secrets that
     * already have a real value are left alone (MySQL volume may already be initialized).
     *
     * @return list<string> keys written or deleted (never values)
     */
    public function sync(Site $site, CoolifyApplicationService $coolify, ?Channel $channel = null): array
    {
        $uuid = trim((string) $site->coolify_app_uuid);
        if ($uuid === '') {
            return [];
        }

        $channel ??= $this->channelFor($site);
        $defaults = $this->catalog($channel);
        if ($defaults->isEmpty()) {
            return [];
        }

        $existingVars = $coolify->listEnvs($uuid);
        $existing = $this->existingMap($existingVars);
        $desired = [];

        foreach ($defaults as $row) {
            if (! $row instanceof CoolifyEnvDefault || $row->kind === CoolifyEnvKind::Skip) {
                continue;
            }

            $current = $existing[$row->key] ?? null;
            $next = $this->resolveValue($site, $row, $current);
            if ($next === null || $next === $current) {
                continue;
            }

            $desired[$row->key] = $next;
        }

        $written = [];
        if ($desired !== []) {
            $written = array_keys($desired);
            $coolify->updateEnvs($uuid, $desired);
        }

        $deleted = $this->prune($coolify, $uuid, $defaults, $existingVars);

        $touched = array_values(array_unique([...$written, ...$deleted]));

        if ($touched !== []) {
            Log::info('coolify.env_synced', [
                'site_id' => $site->id,
                'site_slug' => $site->slug,
                'channel' => $channel->value,
                'keys' => $written,
                'deleted' => $deleted,
            ]);
        }

        return $touched;
    }

    public function channelFor(Site $site): Channel
    {
        return $this->channel($site);
    }

    /**
     * Catalog rows for a channel. An empty catalog (fresh Plane, never synced) is fetched
     * on demand when GitHub credentials exist; failures are recorded on the source row.
     *
     * @return Collection<int, CoolifyEnvDefault>
     */
    public function catalog(Channel $channel): Collection
    {
        $rows = CoolifyEnvDefault::query()->forChannel($channel)->get();
        if ($rows->isNotEmpty() || ! DeamonRepo::hasCredentials()) {
            return $rows;
        }

        try {
            app(CoolifyEnvCatalogSync::class)->sync($channel);
        } catch (CoolifyEnvCatalogException) {
            return $rows;
        }

        return CoolifyEnvDefault::query()->forChannel($channel)->get();
    }

    public static function isProtectedKey(string $key): bool
    {
        if (in_array($key, self::PROTECTED_KEYS, true)) {
            return true;
        }

        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, CoolifyEnvDefault>  $defaults
     * @param  Collection<int, mixed>  $existingVars
     * @return list<string> deleted keys
     */
    private function prune(
        CoolifyApplicationService $coolify,
        string $uuid,
        Collection $defaults,
        Collection $existingVars,
    ): array {
        $catalogKeys = [];
        foreach ($defaults as $row) {
            if ($row instanceof CoolifyEnvDefault && $row->kind !== CoolifyEnvKind::Skip) {
                $catalogKeys[$row->key] = true;
            }
        }

        $deleted = [];

        foreach ($existingVars as $env) {
            if (! $env instanceof CoolifyEnvironmentVariable || $env->key === '' || $env->isPreview) {
                continue;
            }

            if (isset($catalogKeys[$env->key]) || self::isProtectedKey($env->key)) {
                continue;
            }

            $envUuid = trim((string) ($env->uuid ?? ''));
            if ($envUuid === '') {
                Log::warning('coolify.env_prune_skipped_no_uuid', [
                    'key' => $env->key,
                    'app_uuid' => $uuid,
                ]);

                continue;
            }

            $coolify->deleteEnv($uuid, $envUuid);
            $deleted[] = $env->key;
        }

        return $deleted;
    }

    /**
     * @param  Collection<int, mixed>  $envs
     * @return array<string, string>
     */
    private function existingMap(Collection $envs): array
    {
        $map = [];

        foreach ($envs as $env) {
            if (! $env instanceof CoolifyEnvironmentVariable || $env->key === '' || $env->isPreview) {
                continue;
            }

            $map[$env->key] = (string) ($env->value() ?? '');
        }

        return $map;
    }

    private function resolveValue(Site $site, CoolifyEnvDefault $row, ?string $current): ?string
    {
        return match ($row->kind) {
            CoolifyEnvKind::Static => $this->staticValue($row, $current),
            CoolifyEnvKind::Required => $this->requiredValue($row, $current),
            CoolifyEnvKind::Generated => $this->generatedValue($row, $current),
            CoolifyEnvKind::Site => $this->siteValue($site, $row, $current),
            CoolifyEnvKind::Skip => null,
        };
    }

    private function staticValue(CoolifyEnvDefault $row, ?string $current): ?string
    {
        $desired = (string) ($row->value ?? '');
        if ($desired === '' || $this->isTemplate($desired)) {
            return null;
        }

        if ($current === $desired) {
            return null;
        }

        return $desired;
    }

    private function requiredValue(CoolifyEnvDefault $row, ?string $current): ?string
    {
        if (! $this->isBlank($current)) {
            return null;
        }

        $fallback = (string) ($row->value ?? '');
        if ($fallback === '' || $this->isTemplate($fallback) || $this->isPlaceholder($fallback)) {
            return null;
        }

        return $fallback;
    }

    private function generatedValue(CoolifyEnvDefault $row, ?string $current): ?string
    {
        if (! $this->isBlank($current) && ! $this->isPlaceholder((string) $current)) {
            return null;
        }

        return Str::password(40, symbols: false);
    }

    private function siteValue(Site $site, CoolifyEnvDefault $row, ?string $current): ?string
    {
        $desired = $this->interpolate($site, (string) ($row->value ?? ''));
        if ($desired === null || $desired === '') {
            return null;
        }

        if ($current === $desired) {
            return null;
        }

        return $desired;
    }

    /**
     * `{{site.*}}` / `{{plane.*}}` tokens from the CMS example → live values.
     */
    private function interpolate(Site $site, string $template): ?string
    {
        if ($template === '' || ! str_contains($template, '{{')) {
            return $template !== '' ? $template : null;
        }

        $channel = $this->channel($site);
        $map = [
            '{{site.app_key}}' => filled($site->app_key_encrypted) ? (string) $site->app_key_encrypted : '',
            '{{site.agent_secret}}' => filled($site->agent_secret_encrypted) ? (string) $site->agent_secret_encrypted : '',
            '{{site.name}}' => (string) $site->name,
            '{{site.channel}}' => $channel->value,
            '{{site.app_env}}' => ChannelEnvironmentMap::appEnv($channel),
            '{{plane.host}}' => $this->planeHost(),
        ];

        if (str_contains($template, '{{plane.deskron_')) {
            // Unset values resolve to empty: the row is then left alone (never blanked, never pruned).
            $deskron = DeskronSetting::current();
            $map += [
                '{{plane.deskron_application_id}}' => (string) ($deskron->application_id ?? ''),
                '{{plane.deskron_api_key}}' => (string) ($deskron->api_key ?? ''),
                '{{plane.deskron_webhook_secret}}' => (string) ($deskron->webhook_secret ?? ''),
            ];
        }

        $resolved = strtr($template, $map);

        return ($resolved === '' || str_contains($resolved, '{{')) ? null : $resolved;
    }

    private function planeHost(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }

    private function channel(Site $site): Channel
    {
        if ($site->desired_channel instanceof Channel) {
            return $site->desired_channel;
        }

        if ($site->channel instanceof Channel) {
            return $site->channel;
        }

        return Channel::from((string) $site->channel);
    }

    private function isBlank(?string $value): bool
    {
        return trim((string) $value) === '';
    }

    private function isPlaceholder(?string $value): bool
    {
        $trim = strtolower(trim((string) $value));
        if ($trim === '') {
            return true;
        }

        foreach (['change-me', 'changeme', '__her_'] as $needle) {
            if (str_starts_with($trim, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isTemplate(string $value): bool
    {
        return str_contains($value, '{{');
    }
}
