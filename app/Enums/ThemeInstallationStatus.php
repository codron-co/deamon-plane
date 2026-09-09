<?php

namespace App\Enums;

enum ThemeInstallationStatus: string
{
    case Pending = 'pending';
    case Installing = 'installing';
    case Active = 'active';
    case Error = 'error';
    case Updating = 'updating';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status) => $status->value, self::cases());
    }
}
