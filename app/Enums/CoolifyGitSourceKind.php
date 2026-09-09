<?php

namespace App\Enums;

enum CoolifyGitSourceKind: string
{
    case GithubApp = 'github_app';
    case DeployKey = 'deploy_key';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind) => $kind->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::GithubApp => 'GitHub App',
            self::DeployKey => 'Deploy key',
        };
    }
}
