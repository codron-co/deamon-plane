<?php

namespace App\Policies;

use App\Models\Theme;
use App\Models\User;

class ThemePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Theme $theme): bool
    {
        return true;
    }

    public function update(User $user, Theme $theme): bool
    {
        return $user->canWriteOps();
    }

    public function sync(User $user): bool
    {
        return $user->canWriteOps();
    }

    public function assign(User $user): bool
    {
        return $user->canWriteOps();
    }
}
