<?php

namespace App\Enums;

enum ThemeGitSelectionMode: string
{
    case All = 'all';
    case Selected = 'selected';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $mode) => $mode->value, self::cases());
    }

    public function label(): string
    {
        return __('ops.theme_git_selection.'.$this->value);
    }
}
