<?php

namespace App\Policies;

use App\Models\ThemeGitConnection;
use App\Models\User;

class ThemeGitConnectionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ThemeGitConnection $connection): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canWriteOps();
    }

    public function update(User $user, ThemeGitConnection $connection): bool
    {
        return $user->canWriteOps();
    }

    public function delete(User $user, ThemeGitConnection $connection): bool
    {
        return $user->canWriteOps();
    }
}
