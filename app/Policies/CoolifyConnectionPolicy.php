<?php

namespace App\Policies;

use App\Models\CoolifyConnection;
use App\Models\User;

class CoolifyConnectionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CoolifyConnection $connection): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canWriteOps();
    }

    public function update(User $user, CoolifyConnection $connection): bool
    {
        return $user->canWriteOps();
    }

    public function delete(User $user, CoolifyConnection $connection): bool
    {
        return $user->canRunDangerousOps();
    }

    /**
     * Name, base URL, token, webhook secret and enabled state. The defaults
     * (server, project, environment, git source) stay with `update`.
     */
    public function updateCredentials(User $user, CoolifyConnection $connection): bool
    {
        return $user->canRunDangerousOps();
    }

    public function test(User $user, CoolifyConnection $connection): bool
    {
        return $user->canWriteOps();
    }

    public function sync(User $user, CoolifyConnection $connection): bool
    {
        return $user->canWriteOps();
    }
}
