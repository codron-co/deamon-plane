<?php

namespace App\Enums;

enum ThemeGitConnectionKind: string
{
    case GithubApp = 'github_app';
    case Pat = 'pat';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind) => $kind->value, self::cases());
    }

    public function label(): string
    {
        return __('ops.theme_git_kind.'.$this->value);
    }
}
