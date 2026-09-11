<?php

namespace App\Services\Agent;

final class AdminAgentResult
{
    /**
     * @param  list<array<string, mixed>>  $admins
     * @param  array<string, mixed>|null  $admin
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $safeMessage,
        public readonly ?int $httpStatus = null,
        public readonly array $admins = [],
        public readonly ?array $admin = null,
        public readonly ?string $errorCode = null,
        public readonly bool $needsSecret = false,
        public readonly bool $outdated = false,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $admins
     * @param  array<string, mixed>|null  $admin
     */
    public static function success(
        string $safeMessage = 'Admin agent OK.',
        array $admins = [],
        ?array $admin = null,
        ?int $httpStatus = 200,
    ): self {
        return new self(true, $safeMessage, $httpStatus, $admins, $admin);
    }

    public static function failure(
        string $safeMessage,
        ?int $httpStatus = null,
        ?string $errorCode = null,
        bool $outdated = false,
    ): self {
        return new self(false, $safeMessage, $httpStatus, errorCode: $errorCode, outdated: $outdated);
    }

    public static function needsSecret(): self
    {
        return new self(
            false,
            'Site has no agent secret. Inject CONTROL_PLANE_AGENT_SECRET on the CMS Coolify app.',
            needsSecret: true,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromCmsPayload(array $payload, int $httpStatus): self
    {
        $admins = [];
        if (isset($payload['admins']) && is_array($payload['admins'])) {
            foreach ($payload['admins'] as $row) {
                if (is_array($row)) {
                    $admins[] = self::allowlistedAdmin($row);
                }
            }
        }

        $admin = isset($payload['admin']) && is_array($payload['admin'])
            ? self::allowlistedAdmin($payload['admin'])
            : null;

        return self::success('Admin agent OK.', $admins, $admin, $httpStatus);
    }

    public static function fromCmsError(?array $payload, int $httpStatus): self
    {
        $code = is_array($payload) ? self::stringOrNull($payload['error'] ?? null) : null;
        $cmsMessage = is_array($payload) ? self::stringOrNull($payload['message'] ?? null) : null;

        $message = match (true) {
            $httpStatus === 401 || $httpStatus === 403 => 'Agent rejected the request signature.',
            $httpStatus === 404 && $code === null => 'CMS agent is too old for admin management (needs Deamon 1.2.13+).',
            $code === 'validation_failed' && is_string($cmsMessage) => $cmsMessage,
            $code === 'validation_failed' => 'Admin agent validation failed.',
            default => 'Admin agent returned HTTP '.$httpStatus.'.',
        };

        return self::failure(
            $message,
            $httpStatus,
            $code,
            outdated: $httpStatus === 404 && $code === null,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function allowlistedAdmin(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'is_active' => (bool) ($row['is_active'] ?? false),
            'must_change_password' => (bool) ($row['must_change_password'] ?? false),
            'has_two_factor' => (bool) ($row['has_two_factor'] ?? false),
            'created_at' => self::stringOrNull($row['created_at'] ?? null),
        ];
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
