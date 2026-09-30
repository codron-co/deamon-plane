<?php

namespace App\Services\Sites;

use App\Enums\Channel;
use App\Enums\CoolifyEnvKind;
use App\Models\CoolifyEnvDefault;
use App\Models\Site;
use App\Services\Coolify\CoolifyAppEnvSync;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\Dto\CoolifyEnvironmentVariable;
use Illuminate\Support\Collection;

/**
 * The site detail env editor: compares a site's Coolify application env with the
 * env catalog of a chosen branch, and writes single keys. Values are never logged;
 * secret values are masked before they reach a view.
 */
class SiteEnvEditor
{
    public const STATUS_OK = 'ok';

    public const STATUS_DIFFERS = 'differs';

    public const STATUS_MISSING = 'missing';

    public const STATUS_EXTRA = 'extra';

    public const STATUS_PROTECTED = 'protected';

    public const STATUS_BOOTSTRAP = 'bootstrap';

    public const KEY_PATTERN = '/^[A-Z][A-Z0-9_]*$/';

    private const SECRET_PATTERN = '/(PASSWORD|SECRET|KEY|TOKEN)/';

    public function __construct(private readonly CoolifyAppEnvSync $sync) {}

    /**
     * Channels an operator may compare against (config('ops.channels') order).
     *
     * @return list<Channel>
     */
    public function channels(): array
    {
        return array_values(array_filter(Channel::cases(), static fn (Channel $channel): bool => $channel->isAllowed()));
    }

    /**
     * The requested channel when allowed, else the site's own branch.
     */
    public function resolveChannel(Site $site, ?string $requested): Channel
    {
        $channel = Channel::tryFrom(trim((string) $requested));
        if ($channel instanceof Channel && $channel->isAllowed()) {
            return $channel;
        }

        return $this->sync->channelFor($site);
    }

    /**
     * One row per Coolify key and per catalog key missing from Coolify.
     *
     * @return list<array{key: string, status: string, bootstrap: bool, secret: bool, value: ?string, expected: ?string, has_value: bool, editable: bool, deletable: bool}>
     */
    public function rows(Site $site, CoolifyApplicationService $coolify, Channel $channel): array
    {
        $uuid = trim((string) $site->coolify_app_uuid);
        if ($uuid === '') {
            return [];
        }

        $existing = [];
        foreach ($coolify->listEnvs($uuid) as $env) {
            if (! $env instanceof CoolifyEnvironmentVariable || $env->key === '' || $env->isPreview) {
                continue;
            }
            $existing[$env->key] = (string) ($env->value() ?? '');
        }

        /** @var array<string, CoolifyEnvDefault> $catalog */
        $catalog = [];
        foreach ($this->sync->catalog($channel) as $row) {
            if ($row instanceof CoolifyEnvDefault && $row->kind !== CoolifyEnvKind::Skip) {
                $catalog[$row->key] = $row;
            }
        }

        $rows = [];
        foreach ($existing as $key => $value) {
            $row = $catalog[$key] ?? null;
            $rows[] = $this->row($site, $key, $value, $row);
        }

        foreach ($catalog as $key => $row) {
            if (! array_key_exists($key, $existing)) {
                $rows[] = $this->row($site, $key, null, $row);
            }
        }

        $order = [
            self::STATUS_MISSING => 0,
            self::STATUS_DIFFERS => 1,
            self::STATUS_EXTRA => 2,
            self::STATUS_OK => 3,
            self::STATUS_BOOTSTRAP => 4,
            self::STATUS_PROTECTED => 5,
        ];
        usort($rows, static fn (array $a, array $b): int => [$order[$a['status']], $a['key']] <=> [$order[$b['status']], $b['key']]);

        return $rows;
    }

    /**
     * @param  list<array{status: string}>  $rows
     * @return array<string, int>
     */
    public function counts(array $rows): array
    {
        return collect($rows)->countBy('status')->all();
    }

    /**
     * Align the Coolify env with the chosen branch's catalog.
     *
     * @return list<string> keys written or deleted (never values)
     */
    public function fix(Site $site, CoolifyApplicationService $coolify, Channel $channel): array
    {
        return $this->sync->sync($site, $coolify, $channel);
    }

    /**
     * Why a key may not be written or deleted by hand, or null when it may.
     */
    public function refusal(string $key): ?string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            return __('site_env.errors.key_format');
        }

        if (CoolifyAppEnvSync::isProtectedKey($key)) {
            return __('site_env.errors.protected', ['key' => $key]);
        }

        if (in_array($key, CoolifyAppEnvSync::BOOTSTRAP_KEYS, true)) {
            return __('site_env.errors.bootstrap', ['key' => $key]);
        }

        return null;
    }

    public function set(Site $site, CoolifyApplicationService $coolify, string $key, string $value): void
    {
        $coolify->updateEnvs($this->uuid($site), [$key => $value]);
    }

    /**
     * @return bool false when Coolify has no such key
     */
    public function delete(Site $site, CoolifyApplicationService $coolify, string $key): bool
    {
        $uuid = $this->uuid($site);

        /** @var Collection<int, mixed> $envs */
        $envs = $coolify->listEnvs($uuid);
        $deleted = false;
        foreach ($envs as $env) {
            if (! $env instanceof CoolifyEnvironmentVariable || $env->key !== $key || $env->isPreview) {
                continue;
            }
            $envUuid = trim((string) ($env->uuid ?? ''));
            if ($envUuid === '') {
                continue;
            }
            $coolify->deleteEnv($uuid, $envUuid);
            $deleted = true;
        }

        return $deleted;
    }

    public function isSecret(string $key, ?CoolifyEnvDefault $row = null): bool
    {
        return ($row instanceof CoolifyEnvDefault && $row->is_secret)
            || preg_match(self::SECRET_PATTERN, $key) === 1;
    }

    /**
     * @return array{key: string, status: string, bootstrap: bool, secret: bool, value: ?string, expected: ?string, has_value: bool, editable: bool, deletable: bool}
     */
    private function row(Site $site, string $key, ?string $current, ?CoolifyEnvDefault $row): array
    {
        $protected = CoolifyAppEnvSync::isProtectedKey($key);
        $bootstrap = in_array($key, CoolifyAppEnvSync::BOOTSTRAP_KEYS, true);
        $secret = $this->isSecret($key, $row);

        $status = match (true) {
            $protected => self::STATUS_PROTECTED,
            $row instanceof CoolifyEnvDefault => $this->catalogStatus($site, $row, $current),
            $bootstrap => $current === null || trim($current) === '' ? self::STATUS_MISSING : self::STATUS_BOOTSTRAP,
            default => self::STATUS_EXTRA,
        };

        $expected = null;
        if (! $secret && $row instanceof CoolifyEnvDefault) {
            $expected = $this->expectedValue($site, $row);
        }

        $locked = $protected || $bootstrap;

        return [
            'key' => $key,
            'status' => $status,
            'bootstrap' => $bootstrap,
            'secret' => $secret,
            'value' => $secret ? null : $current,
            'expected' => $expected,
            'has_value' => $current !== null && trim($current) !== '',
            'editable' => ! $locked,
            'deletable' => ! $locked && $current !== null,
        ];
    }

    private function catalogStatus(Site $site, CoolifyEnvDefault $row, ?string $current): string
    {
        $blank = $current === null || trim($current) === '';

        return match ($row->kind) {
            CoolifyEnvKind::Required, CoolifyEnvKind::Generated => $blank ? self::STATUS_MISSING : self::STATUS_OK,
            CoolifyEnvKind::Static, CoolifyEnvKind::Site => $this->compare($this->expectedValue($site, $row), $current),
            CoolifyEnvKind::Skip => self::STATUS_EXTRA,
        };
    }

    private function compare(?string $expected, ?string $current): string
    {
        if ($current === null) {
            return $expected === null ? self::STATUS_OK : self::STATUS_MISSING;
        }

        if ($expected === null || $expected === $current) {
            return self::STATUS_OK;
        }

        return self::STATUS_DIFFERS;
    }

    /**
     * What a sync would write for this row, or null when it would leave any value alone.
     */
    private function expectedValue(Site $site, CoolifyEnvDefault $row): ?string
    {
        if ($row->kind === CoolifyEnvKind::Site) {
            $value = $this->sync->expectedSiteValue($site, $row);

            return $value === '' ? null : $value;
        }

        if ($row->kind !== CoolifyEnvKind::Static) {
            return null;
        }

        $value = (string) ($row->value ?? '');

        return ($value === '' || str_contains($value, '{{')) ? null : $value;
    }

    private function uuid(Site $site): string
    {
        return trim((string) $site->coolify_app_uuid);
    }
}
