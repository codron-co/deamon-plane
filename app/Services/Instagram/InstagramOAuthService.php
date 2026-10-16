<?php

namespace App\Services\Instagram;

use App\Models\InstagramSetting;
use App\Support\PublicAppUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Instagram API with Instagram Login (Business Login for Instagram).
 * https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/business-login
 */
final class InstagramOAuthService
{
    public const SCOPE = 'instagram_business_basic';

    public static function redirectUri(): string
    {
        return PublicAppUrl::forAgents().'/instagram/callback';
    }

    public function authorizeUrl(string $state): string
    {
        $settings = $this->settings();

        return 'https://www.instagram.com/oauth/authorize?'.http_build_query([
            'client_id' => $settings->app_id,
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'state' => $state,
            'force_reauth' => 'true',
        ]);
    }

    /**
     * Authorization code → short-lived token → 60-day token.
     *
     * @return array{access_token: string, expires_in: int, user_id: string}
     */
    public function exchangeCode(string $code): array
    {
        $settings = $this->settings();

        try {
            $short = Http::asForm()
                ->timeout(20)
                ->acceptJson()
                ->post('https://api.instagram.com/oauth/access_token', [
                    'client_id' => $settings->app_id,
                    'client_secret' => $settings->app_secret,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => self::redirectUri(),
                    'code' => $code,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('instagram_unreachable', previous: $exception);
        }

        $shortToken = (string) ($short->json('access_token') ?? $short->json('data.0.access_token') ?? '');
        $userId = (string) ($short->json('user_id') ?? $short->json('data.0.user_id') ?? '');

        if (! $short->successful() || $shortToken === '') {
            throw new RuntimeException('code_exchange_failed');
        }

        try {
            $long = Http::timeout(20)
                ->acceptJson()
                ->get('https://graph.instagram.com/access_token', [
                    'grant_type' => 'ig_exchange_token',
                    'client_secret' => $settings->app_secret,
                    'access_token' => $shortToken,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('instagram_unreachable', previous: $exception);
        }

        $longToken = (string) ($long->json('access_token') ?? '');
        if (! $long->successful() || $longToken === '') {
            throw new RuntimeException('long_lived_exchange_failed');
        }

        return [
            'access_token' => $longToken,
            'expires_in' => (int) ($long->json('expires_in') ?? 0),
            'user_id' => $userId,
        ];
    }

    private function settings(): InstagramSetting
    {
        $settings = InstagramSetting::current();

        if (! $settings->isReady()) {
            throw new RuntimeException('not_configured');
        }

        return $settings;
    }
}
