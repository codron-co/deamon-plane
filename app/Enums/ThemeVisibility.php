<?php

namespace App\Enums;

enum ThemeVisibility: string
{
    case PublicCatalog = 'public_catalog';
    case Allowlist = 'allowlist';
    case Private = 'private';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $visibility) => $visibility->value, self::cases());
    }

    public function label(): string
    {
        return __('ops.theme_visibility.'.$this->value);
    }
}
