<?php

namespace App\Services\Coolify;

use App\Enums\Channel;
use App\Enums\CoolifyEnvKind;
use App\Enums\CoolifyEnvPack;
use App\Models\CoolifyEnvDefault;
use App\Models\Site;
use App\Services\Coolify\Dto\CoolifyEnvironmentVariable;
use App\Services\Sites\ChannelEnvironmentMap;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CoolifyAppEnvSync
{
    /**
     * Align Coolify application env with the catalog for this site's build pack.
     * Never logs values. Generated secrets that already have a real value are left alone
     * (MySQL volume may already be initialized).
     *
     * @return list<string> keys written (never values)
     */
    public function sync(Site $site, CoolifyApplicationService $coolify, ?CoolifyEnvPack $pack = null): array
    {
        $uuid = trim((string) $site->coolify_app_uuid);
        if ($uuid === '') {
            return [];
        }

        $pack ??= $this->packFor($site);
        $defaults = CoolifyEnvDefault::query()->forPack($pack)->get();
        if ($defaults->isEmpty()) {
            return [];
        }

        $existing = $this->existingMap($coolify->listEnvs($uuid));
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

        if ($desired === []) {
            return [];
        }

        $keys = array_keys($desired);
        $coolify->updateEnvs($uuid, $desired);

        Log::info('coolify.env_synced', [
            'site_id' => $site->id,
            'site_slug' => $site->slug,
            'pack' => $pack->value,
            'keys' => $keys,
        ]);

        return $keys;
    }

    public function packFor(Site $site): CoolifyEnvPack
    {
        return $site->hasDockerfileBuildPackWarning()
            ? CoolifyEnvPack::Dockerfile
            : CoolifyEnvPack::DockerCompose;
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
        $desired = $this->resolveSiteToken($site, $row);
        if ($desired === null || $desired === '') {
            return null;
        }

        if ($current === $desired) {
            return null;
        }

        return $desired;
    }

    private function resolveSiteToken(Site $site, CoolifyEnvDefault $row): ?string
    {
        $channel = $this->channel($site);

        return match ($row->key) {
            'APP_KEY' => filled($site->app_key_encrypted) ? (string) $site->app_key_encrypted : null,
            'CONTROL_PLANE_AGENT_SECRET' => filled($site->agent_secret_encrypted) ? (string) $site->agent_secret_encrypted : null,
            'DEAMON_SITE_NAME' => $site->name,
            'DEAMON_CHANNEL' => $channel->value,
            'APP_ENV' => ChannelEnvironmentMap::appEnv($channel),
            default => $this->interpolate($site, (string) ($row->value ?? '')),
        };
    }

    private function interpolate(Site $site, string $template): ?string
    {
        if ($template === '' || ! str_contains($template, '{{')) {
            return $template !== '' ? $template : null;
        }

        $channel = $this->channel($site);
        $map = [
            '{{site.app_key}}' => filled($site->app_key_encrypted) ? (string) $site->app_key_encrypted : '',
            '{{site.agent_secret}}' => filled($site->agent_secret_encrypted) ? (string) $site->agent_secret_encrypted : '',
            '{{site.name}}' => $site->name,
            '{{site.channel}}' => $channel->value,
            '{{site.app_env}}' => ChannelEnvironmentMap::appEnv($channel),
        ];

        $resolved = strtr($template, $map);

        return ($resolved === '' || str_contains($resolved, '{{')) ? null : $resolved;
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
