<?php

namespace App\Enums;

enum OpsRole: string
{
    case SuperAdmin = 'super_admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role) => $role->value, self::cases());
    }

    public function label(): string
    {
        return __('ops.roles.'.$this->value);
    }

    public function canWrite(): bool
    {
        return $this !== self::Viewer;
    }
}
