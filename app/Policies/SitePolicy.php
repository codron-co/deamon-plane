<?php

namespace App\Policies;

use App\Enums\OpsRole;
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

    /**
     * Bringing an archived site back re-exposes a running Coolify app to fleet
     * operations, so it is a Super Admin decision.
     */
    public function restore(User $user, Site $site): bool
    {
        return $user->hasRole(OpsRole::SuperAdmin->value);
    }

    /**
     * Hard delete removes the Coolify app with its volumes; it cannot be undone,
     * while restore (reversible) already needs Super Admin.
     */
    public function forceDelete(User $user, Site $site): bool
    {
        return $user->canRunDangerousOps();
    }

    public function provision(User $user, Site $site): bool
    {
        return $user->canWriteOps();
    }

    public function switchChannel(User $user, Site $site): bool
    {
        return $user->canWriteOps();
    }

    public function checkHealth(User $user, Site $site): bool
    {
        return $user->canWriteOps();
    }

    public function manageAdmins(User $user, Site $site): bool
    {
        return $user->canWriteOps();
    }

    public function toggleAdminActive(User $user, Site $site): bool
    {
        return $user->hasRole(OpsRole::SuperAdmin->value);
    }

    public function destroyAdmin(User $user, Site $site): bool
    {
        return $user->hasRole(OpsRole::SuperAdmin->value);
    }
}
