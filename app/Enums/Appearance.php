<?php

namespace App\Enums;

enum Appearance: string
{
    case Light = 'light';
    case SemiDark = 'semidark';
    case Dark = 'dark';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $appearance) => $appearance->value, self::cases());
    }

    public function label(): string
    {
        return __('account.appearance_modes.'.$this->value);
    }
}
