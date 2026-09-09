<?php

namespace App\Enums;

use InvalidArgumentException;

enum Channel: string
{
    case Main = 'main';
    case Beta = 'beta';
    case Alpha = 'alpha';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $channel) => $channel->value, self::cases());
    }

    /**
     * Allowlist from config/ops.php (SoT). Enum cases that are not listed are rejected.
     */
    public function isAllowed(): bool
    {
        return in_array($this->value, config('ops.channels', []), true);
    }

    public static function assertAllowed(self $channel): void
    {
        if (! $channel->isAllowed()) {
            throw new InvalidArgumentException(
                "Channel [{$channel->value}] is not in config('ops.channels')."
            );
        }
    }
}
