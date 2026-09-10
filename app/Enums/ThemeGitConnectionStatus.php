<?php

namespace App\Enums;

enum ThemeGitConnectionStatus: string
{
    case Pending = 'pending';
    case Connected = 'connected';
    case Error = 'error';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status) => $status->value, self::cases());
    }

    public function label(): string
    {
        return __('ops.theme_git_status.'.$this->value);
    }

    public function chipClass(): string
    {
        return match ($this) {
            self::Pending => 'status-provisioning',
            self::Connected => 'status-active',
            self::Error => 'status-error',
        };
    }
}
