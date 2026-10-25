<?php

namespace App\Console\Commands;

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Issue, list and revoke Plane MCP tokens. The plain token prints once:
 *   php artisan ops:mcp-token create emin@codron.co --name="Claude Code" --ability=write
 *   php artisan ops:mcp-token list
 *   php artisan ops:mcp-token revoke <id>
 */
class McpTokenCommand extends Command
{
    protected $signature = 'ops:mcp-token
        {action : create | list | revoke}
        {subject? : create: user e-mail · revoke: token id}
        {--name=AI agent : label shown in the list}
        {--ability=write : read | write | danger (each includes the ones before it)}
        {--days= : expire after this many days (default: never)}';

    protected $description = 'Manage bearer tokens for the Plane MCP endpoint (/mcp)';

    public function handle(): int
    {
        return match ((string) $this->argument('action')) {
            'create' => $this->create(),
            'list' => $this->listTokens(),
            'revoke' => $this->revoke(),
            default => $this->fail('Action must be create, list or revoke.'),
        };
    }

    private function create(): int
    {
        $user = User::query()->where('email', (string) $this->argument('subject'))->first();
        if (! $user instanceof User) {
            $this->error('No Plane user with that e-mail.');

            return self::FAILURE;
        }

        $abilities = match ((string) $this->option('ability')) {
            McpToken::ABILITY_READ => [McpToken::ABILITY_READ],
            McpToken::ABILITY_WRITE => [McpToken::ABILITY_READ, McpToken::ABILITY_WRITE],
            McpToken::ABILITY_DANGER => McpToken::abilityValues(),
            default => null,
        };
        if ($abilities === null) {
            $this->error('--ability must be read, write or danger.');

            return self::FAILURE;
        }

        $days = $this->option('days');
        [$token, $plain] = McpToken::issue(
            $user,
            (string) $this->option('name'),
            $abilities,
            filled($days) ? now()->addDays((int) $days) : null,
        );

        $this->info("Token {$token->id} for {$user->email} ({$user->opsRole()?->value}), abilities: ".implode(', ', $abilities));
        $this->line('Shown once, store it in the agent config only:');
        $this->line($plain);
        $this->newLine();
        $this->line('Claude Code:');
        $this->line('  claude mcp add --transport http deamon-plane '.route('mcp').' --header "Authorization: Bearer '.$plain.'"');

        return self::SUCCESS;
    }

    private function listTokens(): int
    {
        $rows = McpToken::query()->with('user')->latest()->get()->map(fn (McpToken $token): array => [
            $token->id,
            $token->name,
            $token->user->email ?? '-',
            implode(',', (array) $token->abilities),
            $token->revoked_at ? 'revoked' : ($token->isActive() ? 'active' : 'expired'),
            $token->last_used_at?->diffForHumans() ?? 'never',
            $token->last_used_ip ?? '-',
        ]);

        $this->table(['id', 'name', 'user', 'abilities', 'state', 'last used', 'ip'], $rows->all());

        return self::SUCCESS;
    }

    private function revoke(): int
    {
        $token = McpToken::query()->find((string) $this->argument('subject'));
        if (! $token instanceof McpToken) {
            $this->error('No token with that id.');

            return self::FAILURE;
        }

        $token->revoke();
        $this->info("Revoked {$token->id} ({$token->name}).");

        return self::SUCCESS;
    }
}
