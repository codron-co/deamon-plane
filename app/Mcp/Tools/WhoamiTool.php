<?php

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\McpPresenter;

class WhoamiTool extends ReadTool
{
    public function name(): string
    {
        return 'whoami';
    }

    public function description(): string
    {
        return 'Who this token acts as: Plane user, role (super_admin / operator / viewer) and token abilities (read / write / danger). Call first.';
    }

    public function inputSchema(): array
    {
        return self::schema();
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $user = $context->user;

        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'role' => $user->opsRole()?->value,
            'can_write' => $user->canWriteOps(),
            'can_run_dangerous' => $user->canRunDangerousOps(),
            'token' => [
                'name' => $context->token->name,
                'abilities' => $context->token->abilities,
                'expires_at' => McpPresenter::time($context->token->expires_at),
            ],
            'plane_url' => rtrim((string) config('app.url'), '/'),
            'channels' => config('ops.channels', []),
            'test_site' => 'test',
        ];
    }
}
