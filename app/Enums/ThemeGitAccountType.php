<?php

namespace App\Enums;

enum ThemeGitAccountType: string
{
    case User = 'user';
    case Organization = 'organization';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type) => $type->value, self::cases());
    }

    public function label(): string
    {
        return __('ops.theme_git_account.'.$this->value);
    }

    public static function fromGitHub(string $type): self
    {
        return strtolower($type) === 'organization' ? self::Organization : self::User;
    }
}
