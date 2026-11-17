<?php

namespace App\Services\Agent;

use App\Models\Site;
use App\Services\Agent\Concerns\RetriesThrottledAgentRequests;
use App\Support\ControlPlaneAgentSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Site admin "iki adımlı doğrulama zorunlu" politikası (CMS 1.2.119+):
 * GET/POST /internal/control/v1/security/two-factor. Gövdede gizli veri yoktur.
 */
final class SiteTwoFactorPolicyAgent
{
    use RetriesThrottledAgentRequests;

    /**
     * @return array{ok: bool, policy: array<string, mixed>|null, error: string|null}
     */
    public function read(Site $site): array
    {
        return $this->call($site, 'GET', '');
    }

    /**
     * @param  array{required?: bool, grace_days?: int}  $payload
     * @return array{ok: bool, policy: array<string, mixed>|null, error: string|null}
     */
    public function apply(Site $site, array $payload): array
    {
        return $this->call($site, 'POST', ControlPlaneAgentContract::encodeJson($payload));
    }

    /**
     * @return array{ok: bool, policy: array<string, mixed>|null, error: string|null}
     */
    private function call(Site $site, string $method, string $body): array
    {
        if (! $site->hasAgentSecret()) {
            return $this->failure('no_agent_secret');
        }

        $baseUrl = $site->resolvedAgentBaseUrl();
        if ($baseUrl === null) {
            return $this->failure('no_base_url');
        }

        $signed = ControlPlaneAgentSignature::headers((string) $site->agent_secret_encrypted, $body);
        $url = $baseUrl.ControlPlaneAgentContract::twoFactorPolicyPath();

        try {
            $response = $this->sendWithRetry(function () use ($method, $signed, $body, $url): Response {
                $pending = Http::timeout(max(1, (int) config('ops.agent.timeout_seconds', 10)))
                    ->acceptJson()
                    ->withHeaders($signed['headers']);

                return $method === 'GET'
                    ? $pending->get($url)
                    : $pending->withBody($body, 'application/json')->post($url);
            });
        } catch (ConnectionException) {
            return $this->failure('timeout');
        } catch (Throwable) {
            return $this->failure('http_error');
        }

        $json = $response->json();

        if ($response->status() === 404) {
            return $this->failure('cms_too_old');
        }

        if ($response->failed() || ! is_array($json) || ($json['ok'] ?? false) !== true || ! is_array($json['two_factor'] ?? null)) {
            $message = is_array($json) && is_string($json['message'] ?? null) ? trim($json['message']) : '';

            return $this->failure($message !== '' ? Str::limit($message, 200, '…') : ($response->failed() ? 'http_'.$response->status() : 'invalid_response'));
        }

        return ['ok' => true, 'policy' => $json['two_factor'], 'error' => null];
    }

    /**
     * @return array{ok: bool, policy: array<string, mixed>|null, error: string|null}
     */
    private function failure(string $reason): array
    {
        return ['ok' => false, 'policy' => null, 'error' => $reason];
    }
}
