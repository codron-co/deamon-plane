<?php

namespace App\Services\Sites;

use App\Models\Deployment;
use App\Models\Site;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\Dto\CoolifyDeployment;
use App\Support\SecretRedactor;
use Throwable;

final class DeploymentFailureText
{
    /**
     * @return array{error_message: string, log_excerpt: ?string}
     */
    public static function fromRemote(Site $site, CoolifyDeployment $remote, string $fallback): array
    {
        $message = trim((string) ($remote->message ?: $fallback));
        $error = self::withErrorsJson($message, $remote->errors);

        return [
            'error_message' => self::redact($site, $error),
            'log_excerpt' => self::redactNullable($site, $remote->logsExcerpt),
        ];
    }

    /**
     * @return array{error_message: string, log_excerpt: ?string}
     */
    public static function fromException(Site $site, Throwable $exception, string $fallback): array
    {
        $message = $exception instanceof CoolifyApiException
            ? $exception->getMessage()
            : $fallback;

        $errors = $exception instanceof CoolifyApiException
            ? ($exception->payload['errors'] ?? null)
            : null;

        $error = self::withErrorsJson($message, is_array($errors) ? $errors : null);

        return [
            'error_message' => self::redact($site, $error),
            'log_excerpt' => null,
        ];
    }

    public static function redact(Site $site, string $text): string
    {
        return SecretRedactor::redactSensitive($text, self::secrets($site));
    }

    public static function pasteable(Deployment $deployment): string
    {
        $tz = (string) config('app.timezone');
        $lines = [];

        $site = $deployment->site;
        if ($site instanceof Site) {
            $lines[] = 'Site: '.$site->name.' ('.$site->slug.')';
            $lines[] = 'Domain: '.($site->primary_domain ?: '—');
            $lines[] = 'Coolify app UUID: '.($site->coolify_app_uuid ?: '—');
            $lines[] = '';
        }

        $lines[] = 'Status: '.($deployment->status?->value ?? 'unknown');
        $lines[] = 'Channel: '.($deployment->channel?->value ?? '—');
        $lines[] = 'Trigger: '.($deployment->trigger?->value ?? '—');
        $lines[] = 'Commit: '.($deployment->commit_sha ?: '—');
        $lines[] = 'Duration: '.$deployment->durationLabel();
        $lines[] = 'Started: '.($deployment->started_at?->timezone($tz)->format('Y-m-d H:i:s T') ?? '—');
        $lines[] = 'Finished: '.($deployment->finished_at?->timezone($tz)->format('Y-m-d H:i:s T') ?? '—');

        if (filled($deployment->coolify_deployment_uuid)) {
            $lines[] = 'Coolify deployment UUID: '.$deployment->coolify_deployment_uuid;
        }

        $body = implode("\n", $lines);

        if (filled($deployment->error_message)) {
            $body .= "\n\nCoolify error:\n".$deployment->error_message;
        }

        if (filled($deployment->log_excerpt)) {
            $body .= "\n\nLogs:\n".$deployment->log_excerpt;
        }

        return trim($body)."\n";
    }

    /**
     * @param  array<string, mixed>|null  $errors
     */
    private static function withErrorsJson(string $message, ?array $errors): string
    {
        $json = self::errorsJson($errors);
        if ($json === null || str_contains($message, $json)) {
            return $message;
        }

        return trim($message."\n\nCoolify errors:\n".$json);
    }

    /**
     * @param  array<string, mixed>|null  $errors
     */
    private static function errorsJson(?array $errors): ?string
    {
        if ($errors === null || $errors === []) {
            return null;
        }

        $json = json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : null;
    }

    private static function redactNullable(Site $site, ?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        return self::redact($site, $text);
    }

    /**
     * @return list<string>
     */
    private static function secrets(Site $site): array
    {
        $out = [];
        foreach ([$site->app_key_encrypted, $site->agent_secret_encrypted] as $secret) {
            if (is_string($secret) && $secret !== '') {
                $out[] = $secret;
            }
        }

        $site->loadMissing('coolifyConnection');
        $token = (string) ($site->coolifyConnection?->api_token ?? '');
        if ($token !== '') {
            $out[] = $token;
        }

        return $out;
    }
}
