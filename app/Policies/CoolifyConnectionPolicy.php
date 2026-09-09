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
        return $user->canWriteOps();
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
