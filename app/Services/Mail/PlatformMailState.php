<?php

namespace App\Services\Mail;

use App\Models\PlatformMailSetting;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * One answer to "is the product SMTP working?", shared by the nav-adjacent
 * pages so the mail servers list and the platform mail page cannot disagree.
 */
final class PlatformMailState
{
    public const UNCONFIGURED = 'unconfigured';

    public const PUSH_FAILED = 'push_failed';

    public const NOT_PUSHED = 'not_pushed';

    public const ACTIVE = 'active';

    private function __construct(
        public readonly string $key,
        public readonly int $failedSites,
        public readonly ?Carbon $lastPushedAt,
    ) {}

    public static function current(?PlatformMailSetting $settings = null): self
    {
        $settings ??= PlatformMailSetting::current();
        $lastPushedAt = $settings->exists ? $settings->last_pushed_at : null;

        if (! $settings->exists || ! $settings->isReady()) {
            return new self(self::UNCONFIGURED, 0, $lastPushedAt);
        }

        $failed = Site::query()->whereNotNull('platform_mail_push_failed_at')->count();
        if ($failed > 0) {
            return new self(self::PUSH_FAILED, $failed, $lastPushedAt);
        }

        if ($lastPushedAt === null) {
            return new self(self::NOT_PUSHED, 0, $lastPushedAt);
        }

        return new self(self::ACTIVE, 0, $lastPushedAt);
    }

    public function label(): string
    {
        return (string) __('platform_mail.state.'.$this->key, ['count' => $this->failedSites]);
    }

    public function hint(): string
    {
        return (string) __('platform_mail.state_hint.'.$this->key, ['count' => $this->failedSites]);
    }

    public function chipClass(): string
    {
        return match ($this->key) {
            self::ACTIVE => 'status-active',
            self::PUSH_FAILED => 'status-error',
            self::NOT_PUSHED => 'status-warning',
            default => 'status-draft',
        };
    }

    public function isHealthy(): bool
    {
        return $this->key === self::ACTIVE;
    }
}
