<?php

namespace App\Mcp;

use App\Models\McpToken;
use App\Models\User;

/**
 * Who is calling: the token's user (role and policies apply), the token
 * (abilities narrow the role) and the client IP for audit rows.
 */
final class McpContext
{
    public function __construct(
        public readonly User $user,
        public readonly McpToken $token,
        public readonly ?string $ip,
    ) {}
}
