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
        /** @var array{kept: list<string>, conflicts: list<string>}|null */
        public readonly ?array $customizations = null,
    ) {}

    public static function success(
        ?string $sha = null,
        ?string $themeId = null,
        ?int $httpStatus = 200,
        ?string $version = null,
        ?string $activeThemeId = null,
        bool $queued = false,
        ?string $taskId = null,
        ?array $customizations = null,
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
            $customizations,
        );
    }

    public static function failure(string $safeMessage, ?int $httpStatus = null, ?string $errorCode = null): self
    {
        return new self(false, $safeMessage, $httpStatus, errorCode: $errorCode);
    }

    public static function needsSecret(): self
    {
        return new self(false, (string) __('agent.needs_secret'));
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
            self::customizations($payload['customizations'] ?? null),
        );
    }

    /**
     * @return array{kept: list<string>, conflicts: list<string>}|null
     */
    private static function customizations(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $paths = static fn (mixed $list): array => is_array($list)
            ? array_values(array_filter($list, static fn (mixed $path): bool => is_string($path) && $path !== ''))
            : [];

        return [
            'kept' => $paths($value['kept'] ?? null),
            'conflicts' => $paths($value['conflicts'] ?? null),
        ];
    }

    public static function fromCmsError(?array $payload, int $httpStatus): self
    {
        $code = is_array($payload) ? self::stringOrNull($payload['error'] ?? null) : null;
        $cmsMessage = is_array($payload) ? self::stringOrNull($payload['message'] ?? null) : null;

        $message = match (true) {
            $httpStatus === 429 => (string) __('sites.agent.rate_limited'),
            $httpStatus === 401 || $httpStatus === 403 => (string) __('agent.bad_signature'),
            $httpStatus === 404 && $code === null => (string) __('agent.theme.not_registered'),
            $code === 'theme_not_found' => (string) __('agent.theme.not_found'),
            $code === 'theme_active' => (string) __('agent.theme.active'),
            $code === 'theme_protected' => (string) __('agent.theme.protected'),
            $code === 'unsupported_source' => (string) __('agent.theme.unsupported_source'),
            $code === 'path_traversal' => (string) __('agent.theme.path_traversal'),
            $code === 'system_theme' => (string) __('agent.theme.system_theme'),
            // CMS ships an actionable Turkish message for this one; show it verbatim.
            $code === 'data_package_missing' => is_string($cmsMessage)
                ? $cmsMessage
                : (string) __('agent.theme.data_package_missing'),
            $code === 'validation_failed' && is_string($cmsMessage) => $cmsMessage,
            $code === 'validation_failed' => (string) __('agent.theme.validation_failed'),
            $code === 'git_failed' || $httpStatus === 502 => (string) __('agent.theme.git_failed'),
            default => is_string($cmsMessage) ? $cmsMessage : (string) __('agent.theme.http', ['status' => $httpStatus]),
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
