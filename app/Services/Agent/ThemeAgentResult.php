<?php

namespace App\Services\Agent;

final class ThemeAgentResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $safeMessage,
        public readonly ?int $httpStatus = null,
        public readonly ?string $sha = null,
        public readonly ?string $themeId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $version = null,
        public readonly ?string $activeThemeId = null,
        public readonly bool $autoUpdate = false,
        public readonly bool $queued = false,
        public readonly ?string $taskId = null,
    ) {}

    public static function success(
        ?string $sha = null,
        ?string $themeId = null,
        ?int $httpStatus = 200,
        ?string $version = null,
        ?string $activeThemeId = null,
        bool $queued = false,
        ?string $taskId = null,
    ): self {
        return new self(
            true,
            'Theme agent OK.',
            $httpStatus,
            $sha,
            $themeId,
            null,
            $version,
            $activeThemeId,
            false,
            $queued,
            $taskId,
        );
    }

    public static function failure(string $safeMessage, ?int $httpStatus = null, ?string $errorCode = null): self
    {
        return new self(false, $safeMessage, $httpStatus, errorCode: $errorCode);
    }

    public static function needsSecret(): self
    {
        return new self(false, 'Site has no agent secret. Inject CONTROL_PLANE_AGENT_SECRET on the CMS Coolify app.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromCmsPayload(array $payload, int $httpStatus): self
    {
        $sha = self::stringOrNull($payload['sha'] ?? $payload['commit'] ?? $payload['pinned_sha'] ?? null);
        $themeId = self::stringOrNull($payload['theme_id'] ?? null);
        $activeThemeId = self::stringOrNull($payload['active_theme_id'] ?? null);
        $version = self::stringOrNull($payload['version'] ?? null);
        $taskId = self::stringOrNull(isset($payload['task_id']) ? (string) $payload['task_id'] : null);
        $queued = (bool) ($payload['queued'] ?? false);

        return self::success(
            $sha,
            $themeId ?? $activeThemeId,
            $httpStatus,
            $version,
            $activeThemeId,
            $queued,
            $taskId,
        );
    }

    public static function fromCmsError(?array $payload, int $httpStatus): self
    {
        $code = is_array($payload) ? self::stringOrNull($payload['error'] ?? null) : null;

        $message = match (true) {
            $httpStatus === 401 || $httpStatus === 403 => 'Agent rejected the request signature.',
            $httpStatus === 404 && $code === null => 'Theme agent is not registered on this CMS (secret missing or CMS older than 1.2.5).',
            $code === 'theme_not_found' => 'Theme was not found on the CMS instance.',
            $code === 'unsupported_source' => 'CMS rejected a non-git theme source.',
            $code === 'path_traversal' => 'Theme id failed the CMS path guard.',
            $code === 'system_theme' => 'The default system theme cannot be installed or updated.',
            $code === 'validation_failed' => 'Theme agent validation failed.',
            $code === 'git_failed' || $httpStatus === 502 => 'CMS git install failed.',
            default => 'Theme agent returned HTTP '.$httpStatus.'.',
        };

        return self::failure($message, $httpStatus, $code);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
