<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;

class SitePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Site $site): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canWriteOps();
    }

    public function update(User $user, Site $site): bool
    {
        return $user->canWriteOps();
    }

    public function delete(User $user, Site $site): bool
    {
        return $user->canWriteOps();
    }
}
