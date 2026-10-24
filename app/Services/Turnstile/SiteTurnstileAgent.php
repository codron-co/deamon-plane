<?php

namespace App\Services\Turnstile;

use App\Models\Site;
use App\Models\SiteTurnstile;
use App\Services\Agent\Concerns\RetriesThrottledAgentRequests;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Support\ControlPlaneAgentSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pushes a site's Turnstile keys, mode and protected forms over the signed
 * agent (POST /internal/control/v1/turnstile). The body carries widget secrets:
 * it is never logged, and a response that echoes one is treated as a failure.
 */
final class SiteTurnstileAgent
{
    use RetriesThrottledAgentRequests;

    public function push(Site $site, bool $retryConnection = false): bool
    {
        $record = $site->turnstile()->first();
        if (! $record instanceof SiteTurnstile) {
            return false;
        }

        if (! $site->hasAgentSecret()) {
            $this->recordPush($record, 'no_agent_secret');

            return false;
        }

        $baseUrl = $site->resolvedAgentBaseUrl();
        if ($baseUrl === null) {
            $this->recordPush($record, 'no_base_url');

            return false;
        }

        // Sürüm henüz bilinmiyorsa (yeni site, ilk health gelmedi) dene: eski CMS 404 döner.
        if ($site->reportedDeamonVersion() !== null && ! $site->supportsTurnstile()) {
            $this->recordPush($record, 'cms_too_old');

            return false;
        }

        $payload = $record->pushPayload();
        $body = ControlPlaneAgentContract::encodeJson($payload);
        $signed = ControlPlaneAgentSignature::headers((string) $site->agent_secret_encrypted, $body);

        try {
            $response = $this->sendWithRetry(
                fn (): Response => Http::timeout(max(1, (int) config('ops.agent.timeout_seconds', 10)))
                    ->acceptJson()
                    ->withHeaders($signed['headers'])
                    ->withBody($body, 'application/json')
                    ->post($baseUrl.ControlPlaneAgentContract::turnstilePath()),
                retryConnection: $retryConnection,
            );
        } catch (ConnectionException) {
            $this->recordPush($record, 'timeout');

            return false;
        } catch (Throwable) {
            $this->recordPush($record, 'http_error');

            return false;
        }

        $raw = (string) $response->body();
        foreach ((array) ($payload['keys'] ?? []) as $pair) {
            if (is_array($pair) && is_string($pair['secret'] ?? null) && $pair['secret'] !== '' && str_contains($raw, $pair['secret'])) {
                $this->recordPush($record, 'secret_echoed');

                return false;
            }
        }

        $json = $response->json();
        if ($response->failed() || ! is_array($json) || ($json['ok'] ?? false) !== true) {
            $this->recordPush($record, $this->failureReason($response, is_array($json) ? $json : null));

            return false;
        }

        $this->recordPush($record, null);

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function failureReason(Response $response, ?array $json): string
    {
        $error = is_string($json['error'] ?? null) ? $json['error'] : null;
        if ($error !== null && $response->status() === 422) {
            $message = is_string($json['message'] ?? null) ? trim($json['message']) : '';

            return Str::limit($message !== '' ? $error.': '.$message : $error, 250, '…');
        }

        return $response->failed() ? 'http_'.$response->status() : 'invalid_response';
    }

    private function recordPush(SiteTurnstile $record, ?string $reason): void
    {
        $attributes = $reason === null
            ? ['pushed_at' => now(), 'push_failed_at' => null, 'push_error' => null]
            : ['push_failed_at' => now(), 'push_error' => $reason];

        // Base query: the outcome is not an operator change, so updated_at stays put.
        SiteTurnstile::query()->whereKey($record->getKey())->toBase()->update($attributes);

        if ($reason !== null) {
            Log::warning('Turnstile push failed', ['site_id' => $record->site_id, 'reason' => $reason]);
        }
    }
}
