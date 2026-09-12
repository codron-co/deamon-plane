<?php

namespace App\Services\Agent;

final class AgentHealthResult
{
    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly string $status,
        public readonly bool $ok,
        public readonly ?string $reason,
        public readonly ?string $deamonVersion,
        public readonly ?string $channelHint,
        public readonly ?string $activeThemeId,
        public readonly ?string $php,
        public readonly ?bool $queueOk,
        public readonly ?int $httpStatus,
        public readonly string $safeMessage,
        public readonly array $summary,
    ) {}

    public static function needsSecret(): self
    {
        return self::skipped(
            AgentHealthStatus::NeedsSecret,
            AgentHealthReason::NeedsSecret,
            'This site has no agent secret. Import does not generate secrets.',
        );
    }

    public static function unknown(string $reason, string $message): self
    {
        return self::skipped(AgentHealthStatus::Unknown, $reason, $message);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromCmsPayload(array $payload, int $httpStatus): self
    {
        $queueOk = self::nullableBool($payload['queue_ok'] ?? null);
        $version = self::nullableString($payload['deamon_version'] ?? $payload['version'] ?? null);
        $reason = $queueOk === false ? AgentHealthReason::QueueUnhealthy : null;
        $ok = $queueOk !== false;

        return new self(
            status: $ok ? AgentHealthStatus::Ok : AgentHealthStatus::Unhealthy,
            ok: $ok,
            reason: $reason,
            deamonVersion: $version,
            channelHint: self::nullableString($payload['channel_hint'] ?? null),
            activeThemeId: self::nullableString($payload['active_theme_id'] ?? null),
            php: self::nullableString($payload['php'] ?? null),
            queueOk: $queueOk,
            httpStatus: $httpStatus,
            safeMessage: $ok ? 'Agent health OK.' : 'CMS reported queue_ok=false.',
            summary: self::summary([
                'ok' => $ok,
                'status' => $ok ? AgentHealthStatus::Ok : AgentHealthStatus::Unhealthy,
                'reason' => $reason,
                'deamon_version' => $version,
                'channel_hint' => self::nullableString($payload['channel_hint'] ?? null),
                'active_theme_id' => self::nullableString($payload['active_theme_id'] ?? null),
                'php' => self::nullableString($payload['php'] ?? null),
                'queue_ok' => $queueOk,
                // CMS publish state (`draft` | `published`), mirrored onto the site
                // by SiteHealthChecker. Absent on CMS older than 1.2.x.
                'site_status' => self::nullableString($payload['site_status'] ?? null),
                'http_status' => $httpStatus,
            ]),
        );
    }

    public static function failure(string $reason, string $message, ?int $httpStatus = null): self
    {
        return new self(
            status: AgentHealthStatus::Unhealthy,
            ok: false,
            reason: $reason,
            deamonVersion: null,
            channelHint: null,
            activeThemeId: null,
            php: null,
            queueOk: null,
            httpStatus: $httpStatus,
            safeMessage: $message,
            summary: self::summary([
                'ok' => false,
                'status' => AgentHealthStatus::Unhealthy,
                'reason' => $reason,
                'http_status' => $httpStatus,
            ]),
        );
    }

    private static function skipped(string $status, string $reason, string $message): self
    {
        return new self(
            status: $status,
            ok: false,
            reason: $reason,
            deamonVersion: null,
            channelHint: null,
            activeThemeId: null,
            php: null,
            queueOk: null,
            httpStatus: null,
            safeMessage: $message,
            summary: self::summary([
                'ok' => false,
                'status' => $status,
                'reason' => $reason,
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private static function summary(array $summary): array
    {
        unset($summary['agent_secret'], $summary['secret'], $summary['CONTROL_PLANE_AGENT_SECRET']);

        return $summary;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function nullableBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }

        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        return null;
    }
}
