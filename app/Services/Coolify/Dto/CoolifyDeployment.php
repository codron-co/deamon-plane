<?php

namespace App\Services\Coolify\Dto;

final class CoolifyDeployment
{
    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>|null  $errors
     */
    public function __construct(
        public readonly string $uuid,
        public readonly ?string $status,
        public readonly ?string $commit,
        public readonly ?string $applicationUuid,
        public readonly array $raw,
        public readonly ?string $message = null,
        public readonly ?array $errors = null,
        public readonly ?string $logsExcerpt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $uuid = (string) ($payload['uuid'] ?? $payload['deployment_uuid'] ?? '');
        $raw = $payload;
        unset($raw['logs'], $raw['output']);

        $errors = $payload['errors'] ?? null;
        $errors = is_array($errors) ? $errors : null;

        $message = $payload['message'] ?? $payload['error'] ?? $payload['error_message'] ?? null;
        $message = is_string($message) && trim($message) !== '' ? trim($message) : null;

        return new self(
            uuid: $uuid,
            status: isset($payload['status']) ? (string) $payload['status'] : null,
            commit: isset($payload['commit']) ? (string) $payload['commit'] : (isset($payload['commit_sha']) ? (string) $payload['commit_sha'] : null),
            applicationUuid: isset($payload['application_id'])
                ? (string) $payload['application_id']
                : (isset($payload['application_uuid']) ? (string) $payload['application_uuid'] : null),
            raw: $raw,
            message: $message,
            errors: $errors,
            logsExcerpt: self::excerpt(self::stringifyLogs($payload['logs'] ?? $payload['output'] ?? null)),
        );
    }

    public function mergedWith(self $other): self
    {
        return new self(
            uuid: $this->uuid !== '' ? $this->uuid : $other->uuid,
            status: $this->status ?? $other->status,
            commit: filled($this->commit) ? $this->commit : $other->commit,
            applicationUuid: filled($this->applicationUuid) ? $this->applicationUuid : $other->applicationUuid,
            raw: $this->raw !== [] ? $this->raw : $other->raw,
            message: filled($this->message) ? $this->message : $other->message,
            errors: ($this->errors !== null && $this->errors !== []) ? $this->errors : $other->errors,
            logsExcerpt: filled($this->logsExcerpt) ? $this->logsExcerpt : $other->logsExcerpt,
        );
    }

    private static function stringifyLogs(mixed $logs): ?string
    {
        if (is_string($logs)) {
            $trimmed = trim($logs);

            return $trimmed === '' ? null : $logs;
        }

        if (! is_array($logs) || $logs === []) {
            return null;
        }

        $json = json_encode($logs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : null;
    }

    private static function excerpt(?string $logs): ?string
    {
        if ($logs === null) {
            return null;
        }

        $text = $logs;
        if (trim($text) === '') {
            return null;
        }

        $max = max(1024, (int) config('ops.provision.log_excerpt_bytes', 16000));
        if (strlen($text) <= $max) {
            return $text;
        }

        $omitted = strlen($text) - $max;

        return "[truncated {$omitted} earlier bytes]\n\n".substr($text, -$max);
    }
}
