<?php

namespace App\Services\Instagram;

use App\Models\InstagramSetting;
use App\Models\Site;
use App\Services\Agent\Concerns\RetriesThrottledAgentRequests;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Support\ControlPlaneAgentSignature;
use App\Support\PublicAppUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plane → CMS (signed agent) for the Instagram feed module: the OAuth connect URL and,
 * after a customer authorises, the long-lived account token.
 */
final class InstagramAgentClient
{
    use RetriesThrottledAgentRequests;

    public function supports(Site $site): bool
    {
        $version = $site->reportedDeamonVersion();

        return $site->hasAgentSecret()
            && $version !== null
            && version_compare(ltrim($version, 'vV'), ControlPlaneAgentContract::INSTAGRAM_MIN_VERSION, '>=');
    }

    /**
     * Enables «Instagram ile bağlan» on the site when the Meta app is configured, disables it otherwise.
     */
    public function pushOAuthConfig(Site $site): bool
    {
        if (! $this->supports($site)) {
            return false;
        }

        $enabled = InstagramSetting::current()->isReady();
        $payload = $enabled
            ? ['enabled' => true, 'connect_url' => PublicAppUrl::forAgents().'/instagram/connect', 'plane_site_id' => (string) $site->id]
            : ['enabled' => false];

        $response = $this->post($site, ControlPlaneAgentContract::instagramOAuthConfigPath(), $payload);
        $ok = $response instanceof Response && $response->successful();

        if (! $ok) {
            Log::warning('Instagram oauth-config push failed', [
                'site_id' => $site->id,
                'site_slug' => $site->slug,
                'status' => $response instanceof Response ? $response->status() : null,
            ]);
        }

        return $ok;
    }

    /**
     * @return array{ok: bool, username?: string, error?: string}
     */
    public function pushToken(Site $site, string $accessToken, int $expiresIn): array
    {
        $response = $this->post($site, ControlPlaneAgentContract::instagramConnectPath(), [
            'access_token' => $accessToken,
            'expires_in' => $expiresIn,
        ]);

        if (! $response instanceof Response) {
            return ['ok' => false, 'error' => 'site_unreachable'];
        }

        if ($response->successful() && $response->json('ok') === true) {
            return ['ok' => true, 'username' => (string) $response->json('instagram.username', '')];
        }

        return ['ok' => false, 'error' => (string) ($response->json('error') ?: 'http_'.$response->status())];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(Site $site, string $path, array $payload): ?Response
    {
        $baseUrl = $site->resolvedAgentBaseUrl();
        $body = ControlPlaneAgentContract::encodeJson($payload);

        if (! $site->hasAgentSecret() || $baseUrl === null || $body === '') {
            return null;
        }

        $signed = ControlPlaneAgentSignature::headers((string) $site->agent_secret_encrypted, $body);
        $timeout = max(1, (int) config('ops.agent.timeout_seconds', 10));

        try {
            return $this->sendWithRetry(
                fn () => Http::timeout($timeout)
                    ->acceptJson()
                    ->withHeaders($signed['headers'])
                    ->withBody($body, 'application/json')
                    ->post($baseUrl.$path),
                retryConnection: true,
            );
        } catch (ConnectionException) {
            return null;
        } catch (Throwable) {
            return null;
        }
    }
}
