<?php

namespace App\Services\Agent;

/**
 * Locked CMS agent contract (Task 8 health + Task 11 themes, Deamon v1.2.7).
 * Theme git install (CMS 1.2.7+): any github.com owner/name (and https / .git).
 *
 * SoT: deamon `docs/modules/control-plane-agent.md`.
 * Canonical string is HMAC-SHA256 of `{timestamp}.{nonce}.{rawBody}`
 * (empty body `""` for GET). POST signs the compact JSON bytes sent
 * (no pretty-print). Signature is lowercase hex, no `sha256=` prefix.
 * Headers are X-Deamon-* (never X-Control-Plane-*).
 */
final class ControlPlaneAgentContract
{
    public const CMS_VERSION = '1.2.7';

    public const BASE_PATH = '/internal/control/v1';

    public const HEALTH_PATH = '/internal/control/v1/health';

    public const THEME_LIST_PATH = '/internal/control/v1/themes';

    public const THEME_INSTALL_PATH = '/internal/control/v1/themes/install';

    public const THEME_UPDATE_PATH = '/internal/control/v1/themes/update';

    public const THEME_ACTIVATE_PATH = '/internal/control/v1/themes/activate';

    public const THEME_SYNC_PATH = '/internal/control/v1/themes/sync';

    public const MAIL_CONFIGURE_PATH = '/internal/control/v1/mail/configure';

    public const HEADER_SITE = 'X-Deamon-Site';

    public const HEADER_TIMESTAMP = 'X-Deamon-Timestamp';

    public const HEADER_NONCE = 'X-Deamon-Nonce';

    public const HEADER_SIGNATURE = 'X-Deamon-Signature';

    public const CANONICAL_FORMAT = 'timestamp.nonce.body';

    public const NONCE_MIN_LENGTH = 8;

    public const NONCE_MAX_LENGTH = 128;

    public const THEME_SOURCE_GIT = 'git';

    public const SYSTEM_THEME_ID = 'default';

    public const SYNC_ACTION_ALL = 'sync_all';

    public const SYNC_ACTION_CAPABILITY = 'capability';

    public const SYNC_MODE_MERGE = 'merge';

    public const SYNC_MODE_RESET = 'reset';

    public const JSON_ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Compact JSON bytes for POST bodies. Never pretty-print — the HMAC
     * is over these exact bytes.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function encodeJson(array $payload): string
    {
        $body = json_encode($payload, self::JSON_ENCODE_FLAGS);

        return is_string($body) ? $body : '';
    }

    public static function healthPath(): string
    {
        return self::configuredPath('ops.agent.health_path', self::HEALTH_PATH);
    }

    public static function themeListPath(): string
    {
        return self::configuredPath('ops.agent.theme_list_path', self::THEME_LIST_PATH);
    }

    public static function themeInstallPath(): string
    {
        return self::configuredPath('ops.agent.theme_install_path', self::THEME_INSTALL_PATH);
    }

    public static function themeUpdatePath(): string
    {
        return self::configuredPath('ops.agent.theme_update_path', self::THEME_UPDATE_PATH);
    }

    public static function themeActivatePath(): string
    {
        return self::configuredPath('ops.agent.theme_activate_path', self::THEME_ACTIVATE_PATH);
    }

    public static function themeSyncPath(): string
    {
        return self::configuredPath('ops.agent.theme_sync_path', self::THEME_SYNC_PATH);
    }

    public static function mailConfigurePath(): string
    {
        return self::configuredPath('ops.agent.mail_configure_path', self::MAIL_CONFIGURE_PATH);
    }

    /**
     * @return array{theme_id: string, repo: string, ref: string, source: string, sha?: string, clone_token?: string}
     */
    public static function installBody(string $themeId, string $repo, string $ref, ?string $sha = null, ?string $cloneToken = null): array
    {
        $payload = [
            'theme_id' => $themeId,
            'repo' => $repo,
            'ref' => $ref,
            'source' => self::THEME_SOURCE_GIT,
        ];

        if (is_string($sha) && $sha !== '') {
            $payload['sha'] = $sha;
        }

        if (is_string($cloneToken) && $cloneToken !== '') {
            $payload['clone_token'] = $cloneToken;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $preserve
     * @return array{action: string, mode: string, theme_id?: string, capability_id?: string, preserve?: array<string, mixed>}
     */
    public static function syncBody(
        string $themeId,
        string $action = self::SYNC_ACTION_ALL,
        string $mode = self::SYNC_MODE_MERGE,
        ?string $capabilityId = null,
        ?array $preserve = null,
    ): array {
        $payload = [
            'action' => $action,
            'mode' => $mode,
        ];

        if ($themeId !== '') {
            $payload['theme_id'] = $themeId;
        }

        if ($action === self::SYNC_ACTION_CAPABILITY && is_string($capabilityId) && $capabilityId !== '') {
            $payload['capability_id'] = $capabilityId;
        }

        if (is_array($preserve) && $preserve !== []) {
            $payload['preserve'] = $preserve;
        }

        return $payload;
    }

    private static function configuredPath(string $configKey, string $fallback): string
    {
        $path = trim((string) config($configKey, $fallback));

        if ($path === '' || $path[0] !== '/') {
            return $fallback;
        }

        return $path;
    }
}
