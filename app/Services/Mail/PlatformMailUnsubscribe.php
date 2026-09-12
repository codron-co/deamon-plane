<?php

namespace App\Services\Mail;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

final class PlatformMailUnsubscribe
{
    /**
     * @var list<string>
     */
    private const KEYS = [
        PlatformNotificationCatalog::SITE_DOWN,
        PlatformNotificationCatalog::SITE_UP,
        PlatformNotificationCatalog::SITE_VERSION_UPDATE,
        PlatformNotificationCatalog::DEPLOY_FAILED,
    ];

    public function url(User $user, string $key): string
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException('Unsupported Plane mail notification key.');
        }

        return URL::temporarySignedRoute(
            'ops.platform-mail.unsubscribe',
            now()->addDays(60),
            ['user' => $user->getKey(), 'key' => $key],
        );
    }

    public function apply(string $token): bool
    {
        $request = Request::create($token);
        if (! URL::hasValidSignature($request)) {
            return false;
        }

        $userId = $request->query('user');
        $key = $request->query('key');
        if (! is_numeric($userId) || ! is_string($key) || ! in_array($key, self::KEYS, true)) {
            return false;
        }

        $user = User::query()->find((int) $userId);
        if ($user === null) {
            return false;
        }

        $user->optOutMail($key);

        return true;
    }
}
